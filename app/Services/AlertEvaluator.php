<?php

namespace App\Services;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Instance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Records incidents immediately and delays external notifications until stable. */
class AlertEvaluator
{
    private const MAX_EVALUATION_GAP_SECONDS = 120;

    public function __construct(
        protected BucketQuery $buckets,
        protected AlertNotificationSender $notifications,
        protected DataFlowStatus $dataFlow,
    ) {}

    public function evaluate(): void
    {
        foreach (AlertRule::query()->where('enabled', true)->get() as $rule) {
            foreach ($rule->applicableInstances() as $instance) {
                try {
                    $this->evaluateRuleForInstance($rule, $instance);
                } catch (\Throwable) {
                    // Database and transport exceptions can contain private configuration.
                    Log::warning('Alert evaluation failed.', ['rule_id' => $rule->id, 'instance_id' => $instance->id]);
                }
            }
        }
    }

    protected function evaluateRuleForInstance(AlertRule $rule, Instance $instance): void
    {
        $delivery = DB::transaction(function () use ($rule, $instance) {
            // Lock before reading the observation or timers. All evaluator runs
            // and rule edits use this order; no external I/O occurs under lock.
            $rule = AlertRule::query()->whereKey($rule->id)->lockForUpdate()->first();
            $instance = Instance::query()->find($instance->id);
            if (! $rule?->enabled || $instance === null
                || ($rule->instance_id !== null && (int) $rule->instance_id !== $instance->id)) {
                return null;
            }

            $event = AlertEvent::query()->where('alert_rule_id', $rule->id)
                ->where('instance_id', $instance->id)->whereNull('resolved_at')
                ->latest('triggered_at')->lockForUpdate()->first();
            $observation = $this->currentObservation($rule, $instance);
            ['value' => $value, 'complete' => $complete] = $observation;
            $valid = $value !== null && is_finite($value) && is_finite($rule->threshold)
                && in_array($rule->operator, AlertRule::OPERATORS, true);
            $breached = $valid && $this->isBreached($rule, $value);
            $provenBreach = $breached && ($complete || $this->canBreachWithPartialData($rule, $value));
            $now = now();

            if ($event === null) {
                if (! $provenBreach) {
                    return null;
                }
                $event = AlertEvent::query()->create([
                    'alert_rule_id' => $rule->id, 'instance_id' => $instance->id,
                    'value' => $value, 'triggered_at' => $now, 'notified' => false,
                    'breach_started_at' => $now, 'last_evaluated_at' => $now,
                    'notification_status' => ['triggered' => $this->waitingStatus($rule)],
                ]);
            }

            $elapsed = $event->last_evaluated_at === null ? null
                : $now->getTimestamp() - $event->last_evaluated_at->getTimestamp();
            if ($elapsed === null || $elapsed < 0 || $elapsed > self::MAX_EVALUATION_GAP_SECONDS) {
                $event->breach_started_at = $event->recovery_started_at = null;
            }
            $event->last_evaluated_at = $now;

            if ($provenBreach) {
                $event->recovery_started_at = null;
                $event->breach_started_at ??= $now;
                $event->save();
                if ($this->alarmAttempted($event)
                    || $now->lt($event->breach_started_at->copy()->addMinutes($this->delay($rule->trigger_delay_minutes, 10)))) {
                    return null;
                }

                // Cooldown affects delivery only; every internal incident remains visible.
                $recentAttempt = AlertEvent::query()->where('alert_rule_id', $rule->id)
                    ->where('instance_id', $instance->id)->whereKeyNot($event->id)
                    ->where('notification_attempted_at', '>', $now->copy()->subMinutes($this->delay($rule->cooldown_minutes, 30)))
                    ->exists();
                if ($recentAttempt) {
                    $event->notification_status = ['triggered' => $this->waitingStatus($rule, 'cooldown')];
                    $event->save();

                    return null;
                }

                // Claim once before sending. A crash or failed send is never retried automatically.
                $event->notification_attempted_at = $now;
                $event->notification_targets = $this->targetFingerprints($rule);
                $event->notification_status = ['triggered' => $this->notifications->pending($rule)];
                $event->save();

                return $this->delivery($rule, $instance, $event, $value, 'triggered');
            }

            $event->breach_started_at = null;
            $canRecover = $valid && ! $breached && $complete
                && ($observation['through'] ?? 0) > $event->triggered_at->getTimestamp();
            if (! $canRecover) {
                $event->recovery_started_at = null;
                $event->save();

                return null;
            }

            $event->recovery_started_at ??= $now;
            $recoveryDelay = $this->delay($rule->recovery_delay_minutes, 5);
            if ($now->lt($event->recovery_started_at->copy()->addMinutes($recoveryDelay))
                || ($recoveryDelay > 0 && ($observation['through'] ?? 0) <= $event->recovery_started_at->getTimestamp())) {
                $event->save();

                return null;
            }

            $history = $event->notification_status ?? ['triggered' => [
                'teams' => $event->notified ? 'sent' : 'failed', 'email' => 'disabled',
            ]];
            if (! $this->alarmAttempted($event)) {
                $history['triggered'] = $this->waitingStatus($rule, 'suppressed');
            }
            $recoveryRule = $this->recoveryRule($rule, $event, $history['triggered'] ?? []);
            $recoveryStatus = $this->waitingStatus($rule, 'skipped');
            foreach ($this->notifications->pending($recoveryRule) as $channel => $status) {
                if ($status === 'pending') {
                    $recoveryStatus[$channel] = $status;
                }
            }
            $event->resolved_at = $now;
            $event->notification_status = [...$history, 'resolved' => $recoveryStatus];
            $event->save();

            return $this->delivery($recoveryRule, $instance, $event, $value, 'resolved');
        }, 3);

        if ($delivery !== null) {
            $this->deliver($delivery);
        }
    }

    private function alarmAttempted(AlertEvent $event): bool
    {
        if ($event->notification_attempted_at !== null || $event->notification_status === null) {
            return true;
        }

        return count(array_intersect($event->notification_status['triggered'] ?? [], ['pending', 'sent', 'failed'])) > 0;
    }

    private function delay(?int $minutes, int $default): int
    {
        return $minutes !== null && $minutes >= 0 && $minutes <= 1440 ? $minutes : $default;
    }

    private function waitingStatus(AlertRule $rule, string $state = 'waiting'): array
    {
        return array_map(fn (string $status) => $status === 'disabled' ? $status : $state, $this->notifications->pending($rule));
    }

    private function targetFingerprints(AlertRule $rule): array
    {
        return [
            'teams' => $rule->webhook_url ? hash('sha256', trim($rule->webhook_url)) : null,
            'email' => array_values(array_map(fn (string $address) => hash('sha256', strtolower(trim($address))),
                array_filter($rule->email_recipients ?? [], 'is_string'))),
        ];
    }

    private function recoveryRule(AlertRule $rule, AlertEvent $event, array $triggered): AlertRule
    {
        $scoped = clone $rule;
        $targets = $event->notification_targets ?? [];
        $current = $this->targetFingerprints($rule);
        if (($triggered['teams'] ?? null) !== 'sent' || ! is_string($targets['teams'] ?? null)
            || ! is_string($current['teams']) || ! hash_equals($targets['teams'], $current['teams'])) {
            $scoped->webhook_url = null;
        }
        $sentEmails = ($triggered['email'] ?? null) === 'sent' && is_array($targets['email'] ?? null) ? $targets['email'] : [];
        $scoped->email_recipients = array_values(array_filter($rule->email_recipients ?? [],
            fn ($address) => is_string($address) && in_array(hash('sha256', strtolower(trim($address))), $sentEmails, true)));

        return $scoped;
    }

    private function delivery(AlertRule $rule, Instance $instance, AlertEvent $event, float $value, string $phase): array
    {
        $resolved = $phase === 'resolved';

        return ['rule' => $rule, 'event_id' => $event->id, 'phase' => $phase, 'message' => [
            'title' => ($resolved ? '🟢 Resolved: ' : '🔴 ')."{$rule->name} — {$instance->name}",
            'tone' => $resolved ? 'good' : 'attention',
            'facts' => $resolved ? [
                'Instance' => $instance->name, 'Condition' => $rule->describeCondition(),
                'Now' => $this->formatValue($rule, $value), 'Was triggered' => $event->triggered_at->format('d.m.Y H:i').' UTC',
            ] : [
                'Instance' => $instance->name, 'Condition' => $rule->describeCondition(),
                'Observed' => $this->formatValue($rule, $value), 'Triggered' => $event->triggered_at->format('d.m.Y H:i').' UTC',
            ],
            'link' => route('instance', $instance),
            'variables' => $this->emailVariables($rule, $instance, $event, $value, $resolved ? 'Resolved' : 'Alert'),
        ]];
    }

    private function deliver(array $delivery): void
    {
        $event = AlertEvent::query()->find($delivery['event_id']);
        if ($event === null) {
            return;
        }
        if ($event->resolved_by_user_id !== null
            || ($delivery['phase'] === 'triggered' && $event->resolved_at !== null)
            || ! AlertRule::query()->whereKey($delivery['rule']->id)->where('enabled', true)->exists()) {
            $this->cancelDelivery($event->id, $delivery['phase']);

            return;
        }
        $result = $this->notifications->send($delivery['rule'], $delivery['phase'], $delivery['message']);

        DB::transaction(function () use ($delivery, $result) {
            $event = AlertEvent::query()->whereKey($delivery['event_id'])->lockForUpdate()->first();
            if ($event === null) {
                return;
            }
            $history = $event->notification_status ?? [];
            $status = $history[$delivery['phase']] ?? [];
            foreach ($result as $channel => $outcome) {
                if ($outcome !== 'disabled') {
                    $status[$channel] = $outcome;
                }
            }
            $event->notification_status = [...$history, $delivery['phase'] => $status];
            if ($delivery['phase'] === 'triggered') {
                $event->notified = AlertNotificationSender::allConfiguredSent($status);
            }
            $event->save();
        }, 3);
    }

    private function cancelDelivery(int $eventId, string $phase): void
    {
        DB::transaction(function () use ($eventId, $phase) {
            $event = AlertEvent::query()->whereKey($eventId)->lockForUpdate()->first();
            if ($event === null) {
                return;
            }
            $history = $event->notification_status ?? [];
            $status = $history[$phase] ?? [];
            foreach ($status as $channel => $outcome) {
                if ($outcome === 'pending') {
                    $status[$channel] = 'cancelled';
                }
            }
            $event->notification_status = [...$history, $phase => $status];
            $event->save();
        }, 3);
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
