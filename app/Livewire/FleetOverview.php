<?php

namespace App\Livewire;

use App\Models\AlertEvent;
use App\Models\Instance;
use App\Services\BucketQuery;
use App\Services\DataFlowStatus;
use Illuminate\Support\Collection;
use Livewire\Component;

class FleetOverview extends Component
{
    public function render(BucketQuery $buckets, DataFlowStatus $dataFlow)
    {
        $to = now()->getTimestamp();
        $from = $to - 86400;

        $cards = Instance::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Instance $instance) => $this->card($instance, $buckets, $dataFlow, $from, $to))
            ->sortBy(fn (array $card) => match ($card['health']) {
                'down' => 0,
                'unknown' => 1,
                'degraded' => 2,
                default => 3,
            })
            ->values();

        return view('livewire.fleet-overview', ['cards' => $cards])
            ->title('Fleet — '.config('app.name'));
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Instance $instance, BucketQuery $buckets, DataFlowStatus $dataFlow, int $from, int $to): array
    {
        $flow = $instance->dataFlow();
        if ($flow['state'] === 'healthy') {
            // Verified totals stop at the acknowledged boundary. The open
            // tail must never be presented as a confirmed zero-error period.
            $to = $flow['coverage']['through'] - 1;
            $from = $flow['coverage']['through'] - 86400;
        }
        $covered = $flow['state'] === 'healthy'
            && is_int($flow['coverage']['from']) && $flow['coverage']['from'] <= $from
            && is_int($flow['coverage']['through']) && $flow['coverage']['through'] > $to;
        $verified = fn (string $type): bool => $covered && in_array($type, $flow['coverage']['types'], true);
        $exceptions = $buckets->series($instance->id, 'exception', null, $from, $to, 48);
        $sparkStep = $buckets->step($from, $to, 48);
        $openAlerts = AlertEvent::query()
            ->where('instance_id', $instance->id)
            ->whereNull('resolved_at')
            ->count();
        $requests = $buckets->total($instance->id, 'request', $from, $to);

        $onlineUsers = count(array_filter(
            $buckets->lastSeenPerKey($instance->id, 'active_user', $to - 3600, $to),
            fn (int $seen) => $to - $seen < 600,
        ));
        $failedJobs = $buckets->total($instance->id, 'job:failed', $from, $to);
        $gap = $buckets->total($instance->id, 'counter:agent.export_gap_minutes', $from, $to);

        // Measure the same settled 24-hour range as the totals. Reuse the
        // confirmation checks so stale exports or gaps never imply progress.
        $confirmedSeconds = $flow['state'] === 'healthy'
            ? max(0, min(86400, $flow['coverage']['through'] - max($from, $flow['coverage']['from'])))
            : 0;
        $confirmedMinutes = intdiv($confirmedSeconds, 60);
        $historyAvailable = $confirmedMinutes >= 5
            && $dataFlow->window($instance, $confirmedMinutes) !== null;
        if (! $historyAvailable) {
            $confirmedSeconds = $confirmedMinutes = 0;
        }
        $unconfirmedMinutes = 1440 - $confirmedMinutes;

        return [
            'instance' => $instance,
            'health' => $instance->health(),
            'data_flow' => $flow,
            'totals_through' => $flow['state'] === 'healthy' ? $flow['coverage']['through'] : null,
            'history_coverage_available' => $historyAvailable,
            'history_confirmed_seconds' => $confirmedSeconds,
            'history_confirmed_minutes' => $confirmedMinutes,
            'history_unconfirmed_minutes' => $unconfirmedMinutes,
            'history_confirmed_label' => $this->durationLabel($confirmedMinutes),
            'history_unconfirmed_label' => $this->durationLabel($unconfirmedMinutes),
            'history_coverage_percent' => intdiv($confirmedMinutes * 100, 1440),
            'requests_verified' => $historyAvailable && $gap->count === 0 && $verified('request'),
            'exceptions_verified' => $historyAvailable && $gap->count === 0 && $verified('exception'),
            'failed_jobs_verified' => $historyAvailable && $gap->count === 0 && $verified('job:failed'),
            'heartbeat_age' => $instance->last_heartbeat_at?->diffForHumans(short: true),
            'exception_count' => $exceptions->sum('count'),
            'exception_spark' => $this->sparkValues($exceptions, $from, $to, $sparkStep),
            'request_count' => $requests->count,
            'request_avg' => $requests->avg,
            'request_max' => $requests->max,
            'failed_jobs' => $failedJobs->count,
            'queue' => $instance->meta['queue'] ?? null,
            'queue_verified' => $flow['heartbeat']['state'] === 'healthy'
                && ($instance->meta['queue_available'] ?? false) === true
                && ($instance->meta['queue_reported_at'] ?? null) === $instance->last_heartbeat_at?->getTimestamp(),
            'app_version' => $instance->meta['app_version'] ?? null,
            'has_gap' => $gap->count > 0,
            'open_alerts' => $openAlerts,
            'online_users' => $onlineUsers,
        ];
    }

    private function durationLabel(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return match (true) {
            $hours === 0 => "{$remainingMinutes}m",
            $remainingMinutes === 0 => "{$hours}h",
            default => "{$hours}h {$remainingMinutes}m",
        };
    }

    /**
     * Zero-filled values across the range so sparklines show flatlines, not
     * gaps, for quiet periods.
     *
     * @param  Collection<int, object>  $series
     * @return list<float>
     */
    private function sparkValues(Collection $series, int $from, int $to, int $step): array
    {
        $byTime = $series->keyBy('t');
        $values = [];

        for ($t = $from - ($from % $step); $t <= $to; $t += $step) {
            $values[] = (float) ($byTime[$t]->count ?? 0);
        }

        return $values;
    }
}
