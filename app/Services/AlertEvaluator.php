<?php

namespace App\Services;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Instance;
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
        protected TeamsNotifier $teams,
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
        $value = $this->currentValue($rule, $instance);

        if ($value === null) {
            return; // No data — not a breach.
        }

        $breached = match ($rule->operator) {
            '>' => $value > $rule->threshold,
            '>=' => $value >= $rule->threshold,
            '<' => $value < $rule->threshold,
            '<=' => $value <= $rule->threshold,
            default => false,
        };

        $openEvent = AlertEvent::query()
            ->where('alert_rule_id', $rule->id)
            ->where('instance_id', $instance->id)
            ->whereNull('resolved_at')
            ->latest('triggered_at')
            ->first();

        if ($breached && $openEvent === null) {
            $this->trigger($rule, $instance, $value);
        }

        if (! $breached && $openEvent !== null) {
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

        $notified = $this->teams->send(
            $rule->webhook_url,
            "🔴 {$rule->name} — {$instance->name}",
            'attention',
            [
                'Instance' => $instance->name,
                'Condition' => $rule->describeCondition(),
                'Observed' => $this->formatValue($rule, $value),
                'Triggered' => now()->format('d.m.Y H:i').' UTC',
            ],
            route('instance', $instance),
        );

        AlertEvent::query()->create([
            'alert_rule_id' => $rule->id,
            'instance_id' => $instance->id,
            'value' => $value,
            'triggered_at' => now(),
            'notified' => $notified,
        ]);
    }

    protected function resolve(AlertRule $rule, Instance $instance, AlertEvent $event, float $value): void
    {
        $event->forceFill(['resolved_at' => now()])->save();

        $this->teams->send(
            $rule->webhook_url,
            "🟢 Resolved: {$rule->name} — {$instance->name}",
            'good',
            [
                'Instance' => $instance->name,
                'Condition' => $rule->describeCondition(),
                'Now' => $this->formatValue($rule, $value),
                'Was triggered' => $event->triggered_at->format('d.m.Y H:i').' UTC',
            ],
            route('instance', $instance),
        );
    }

    /**
     * The rule's current observed value, or null when there is nothing to
     * evaluate (no data for value aggregates, or a never-seen instance).
     */
    protected function currentValue(AlertRule $rule, Instance $instance): ?float
    {
        if ($rule->isHeartbeatRule()) {
            $age = $instance->heartbeatAgeSeconds();

            return $age !== null ? round($age / 60, 2) : null;
        }

        $to = now()->getTimestamp();
        $from = $to - $rule->window_minutes * 60;

        if ($rule->key !== null) {
            $series = $this->buckets->series($instance->id, $rule->metric_type, $rule->key, $from, $to);

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

        // Counts are zero when nothing happened — that is a real observation
        // (needed for "fewer than X" rules). Value metrics without data are
        // not evaluated.
        if ($value === null && $rule->aggregate === 'count') {
            return 0.0;
        }

        return $value !== null ? (float) $value : null;
    }

    protected function formatValue(AlertRule $rule, float $value): string
    {
        return $rule->isHeartbeatRule()
            ? round($value).' min since last heartbeat'
            : rtrim(rtrim(number_format($value, 2), '0'), '.')." ({$rule->aggregate}, last {$rule->window_minutes} min)";
    }
}
