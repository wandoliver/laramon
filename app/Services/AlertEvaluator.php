<?php

namespace App\Services;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Instance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Evaluates every enabled alert rule against every applicable instance.
 * Breaches open an event and notify once; recoveries resolve it and notify
 * again. A failing rule or webhook never blocks the others.
 */
class AlertEvaluator
{
    public function __construct(
        protected BucketQuery $buckets,
        protected AlertNotificationSender $notifications,
        protected DataFlowStatus $dataFlow,
    ) {}

    public function evaluate(): void
    {
        $rules = AlertRule::query()->where('enabled', true)->get();

        foreach ($rules as $rule) {
            foreach ($rule->applicableInstances() as $instance) {
                try {
                    $this->evaluateRuleForInstance($rule, $instance);
                } catch (\Throwable $e) {
                    Log::warning("Alert rule [{$rule->name}] failed for [{$instance->slug}]: {$e->getMessage()}");
                }
            }
        }
    }

    protected function evaluateRuleForInstance(AlertRule $rule, Instance $instance): void
    {
        $observation = $this->currentObservation($rule, $instance);
        ['value' => $value, 'complete' => $complete] = $observation;

        if ($value === null || ! is_finite($value) || ! is_finite($rule->threshold)
            || ! in_array($rule->operator, AlertRule::OPERATORS, true)) {
            return;
        }

        $breached = $this->isBreached($rule, $value);

        $openEvent = AlertEvent::query()
            ->where('alert_rule_id', $rule->id)
            ->where('instance_id', $instance->id)
            ->whereNull('resolved_at')
            ->latest('triggered_at')
            ->first();

        if ($breached && $openEvent === null && ($complete || $this->canBreachWithPartialData($rule, $value))) {
            $this->trigger($rule, $instance, $value);
        }

        if (! $breached && $openEvent !== null && $complete
            && ($observation['through'] ?? 0) > $openEvent->triggered_at->getTimestamp()) {
            $this->resolve($rule, $instance, $openEvent, $value);
        }
    }

    protected function trigger(AlertRule $rule, Instance $instance, float $value): void
    {
        // Cooldown: a recently triggered (even resolved) event suppresses a
        // fresh notification so flapping metrics don't spam the channel.
        $recentlyTriggered = AlertEvent::query()
            ->where('alert_rule_id', $rule->id)
            ->where('instance_id', $instance->id)
            ->where('triggered_at', '>', now()->subMinutes($rule->cooldown_minutes))
            ->exists();

        if ($recentlyTriggered) {
            return;
        }

        // Persist the incident before attempting any external notification.
        $event = AlertEvent::query()->create([
            'alert_rule_id' => $rule->id,
            'instance_id' => $instance->id,
            'value' => $value,
            'triggered_at' => now(),
            'notified' => false,
            'notification_status' => ['triggered' => $this->notifications->pending($rule)],
        ]);

        $status = $this->notifications->send($rule, 'triggered', [
            'title' => "🔴 {$rule->name} — {$instance->name}",
            'tone' => 'attention',
            'facts' => [
                'Instance' => $instance->name,
                'Condition' => $rule->describeCondition(),
                'Observed' => $this->formatValue($rule, $value),
                'Triggered' => now()->format('d.m.Y H:i').' UTC',
            ],
            'link' => route('instance', $instance),
            'variables' => $this->emailVariables($rule, $instance, $event, $value, 'Alert'),
        ]);

        $event->forceFill([
            'notified' => AlertNotificationSender::allConfiguredSent($status),
            'notification_status' => ['triggered' => $status],
        ]);
        $event->save();
    }

    protected function resolve(AlertRule $rule, Instance $instance, AlertEvent $event, float $value): void
    {
        // Before channel-specific statuses existed, every notification was
        // a Teams attempt. Preserve that historical result on recovery.
        $delivery = $event->notification_status ?? ['triggered' => [
            'teams' => $event->notified ? 'sent' : 'failed', 'email' => 'disabled',
        ]];
        $event->forceFill([
            'resolved_at' => now(),
            'notification_status' => [...$delivery, 'resolved' => $this->notifications->pending($rule)],
        ])->save();

        $status = $this->notifications->send($rule, 'resolved', [
            'title' => "🟢 Resolved: {$rule->name} — {$instance->name}",
            'tone' => 'good',
            'facts' => [
                'Instance' => $instance->name,
                'Condition' => $rule->describeCondition(),
                'Now' => $this->formatValue($rule, $value),
                'Was triggered' => $event->triggered_at->format('d.m.Y H:i').' UTC',
            ],
            'link' => route('instance', $instance),
            'variables' => $this->emailVariables($rule, $instance, $event, $value, 'Resolved'),
        ]);

        $event->forceFill(['notification_status' => [...$delivery, 'resolved' => $status]])->save();
    }

    /** @return array<string, string> */
    private function emailVariables(AlertRule $rule, Instance $instance, AlertEvent $event, float $value, string $status): array
    {
        return [
            'app_name' => (string) config('app.name'), 'status' => $status,
            'rule_name' => $rule->name, 'instance_name' => $instance->name,
            'condition' => $rule->describeCondition(), 'value' => $this->formatValue($rule, $value),
            'previous_value' => $status === 'Resolved' ? $this->formatValue($rule, $event->value) : '',
            'triggered_at' => $event->triggered_at->copy()->utc()->format('d.m.Y H:i').' UTC',
            'resolved_at' => $event->resolved_at ? $event->resolved_at->copy()->utc()->format('d.m.Y H:i').' UTC' : '',
            'dashboard_url' => route('instance', $instance),
        ];
    }

    /**
     * An observation may prove a breach before a full window is available.
     * Only fresh, complete observations can prove recovery.
     *
     * @return array{value: ?float, complete: bool, through: ?int}
     */
    protected function currentObservation(AlertRule $rule, Instance $instance): array
    {
        $status = $this->dataFlow->snapshot($instance);
        $healthy = $status['state'] === 'healthy';

        if ($rule->isDataFlowRule()) {
            return ['value' => $healthy ? 0.0 : 1.0, 'complete' => $healthy, 'through' => $status['coverage']['confirmed_at']];
        }

        if ($rule->isHeartbeatRule()) {
            $age = $instance->heartbeatAgeSeconds();

            return ['value' => $age !== null ? round($age / 60, 2) : null, 'complete' => $healthy, 'through' => $status['coverage']['confirmed_at']];
        }

        $sampled = str_starts_with($rule->metric_type, 'gauge:') || str_starts_with($rule->metric_type, 'counter:');
        $window = $this->dataFlow->window($instance, $rule->window_minutes, $sampled ? null : $rule->metric_type);
        $now = now()->getTimestamp();
        if ($window !== null) {
            // A newer chunk may already prove another breach while its final
            // confirmation is still pending. An older clean window cannot
            // overrule that positive evidence or hide a new incident.
            $recent = $this->valueInRange($rule, $instance, $now - $rule->window_minutes * 60, $now);
            if ($recent !== null && is_finite($recent) && $this->canBreachWithPartialData($rule, $recent)
                && $this->isBreached($rule, $recent)) {
                return ['value' => $recent, 'complete' => false, 'through' => null];
            }
        }

        $to = $window['to'] ?? $now;
        $from = $window['from'] ?? $to - $rule->window_minutes * 60;
        $complete = $window !== null && (! $sampled || $this->hasCompleteSamples($rule, $instance, $from, $to));
        $value = $this->valueInRange($rule, $instance, $from, $to, $complete && ! $sampled);

        return ['value' => $value, 'complete' => $complete, 'through' => $window['through'] ?? null];
    }

    protected function valueInRange(AlertRule $rule, Instance $instance, int $from, int $to, bool $allowEmptyCount = false): ?float
    {
        $series = $this->buckets->series($instance->id, $rule->metric_type, $rule->key, $from, $to);

        if ($series->isEmpty()) {
            return $allowEmptyCount && $rule->aggregate === 'count' ? 0.0 : null;
        }

        if ($rule->key !== null) {
            $value = match ($rule->aggregate) {
                'count' => (float) $series->sum('count'),
                'avg' => $series->avg('avg'),
                'max' => $series->max('max'),
                'min' => $series->min('min'),
                default => null,
            };
        } else {
            $total = $this->buckets->total($instance->id, $rule->metric_type, $from, $to);
            $value = $total->{$rule->aggregate} ?? null;
        }

        return $value !== null ? (float) $value : null;
    }

    /** Business collectors can skip a metric; every bucket needs a real sample. */
    protected function hasCompleteSamples(AlertRule $rule, Instance $instance, int $from, int $to): bool
    {
        if ($rule->key === null) {
            return false;
        }

        $rows = DB::table('metric_buckets')
            ->where('instance_id', $instance->id)
            ->where('type', $rule->metric_type)
            ->where('key_hash', md5($rule->key, true))
            ->where('key', $rule->key)
            ->where('bucket_seconds', 300)
            ->whereBetween('bucket_start', [$from, $to])
            ->get(['bucket_start', 'count', 'sum', 'min', 'max']);

        if ($rows->count() !== (int) (($to + 1 - $from) / 300)) {
            return false;
        }

        foreach ($rows as $row) {
            if (((int) $row->bucket_start - $from) % 300 !== 0) {
                return false;
            }

            $value = match ($rule->aggregate) {
                'count' => $row->count,
                'avg' => $row->count > 0 && $row->sum !== null ? $row->sum / $row->count : null,
                'max' => $row->max,
                'min' => $row->min,
                default => null,
            };

            if ($value === null || ! is_numeric($value) || ! is_finite((float) $value)) {
                return false;
            }
        }

        return true;
    }

    protected function canBreachWithPartialData(AlertRule $rule, float $value): bool
    {
        if ($rule->isHeartbeatRule() || $rule->isDataFlowRule()) {
            return true;
        }

        // Missing events can only increase the final count/max, or decrease min.
        return match ($rule->aggregate) {
            'count' => $value > 0 && in_array($rule->operator, ['>', '>='], true),
            'max' => in_array($rule->operator, ['>', '>='], true),
            'min' => in_array($rule->operator, ['<', '<='], true),
            default => false,
        };
    }

    protected function isBreached(AlertRule $rule, float $value): bool
    {
        return match ($rule->operator) {
            '>' => $value > $rule->threshold,
            '>=' => $value >= $rule->threshold,
            '<' => $value < $rule->threshold,
            '<=' => $value <= $rule->threshold,
            default => false,
        };
    }

    protected function formatValue(AlertRule $rule, float $value): string
    {
        if ($rule->isDataFlowRule()) {
            return $value === 0.0 ? 'Verified monitoring data flow' : 'Monitoring data flow is unverified or unhealthy';
        }

        return $rule->isHeartbeatRule()
            ? round($value).' min since last heartbeat'
            : rtrim(rtrim(number_format($value, 2), '0'), '.')." ({$rule->aggregate}, last {$rule->window_minutes} min)";
    }
}
