<?php

namespace App\Livewire;

use App\Models\AlertEvent;
use App\Models\Instance;
use App\Services\BucketQuery;
use Illuminate\Support\Collection;
use Livewire\Component;

class FleetOverview extends Component
{
    public function render(BucketQuery $buckets)
    {
        $to = now()->getTimestamp();
        $from = $to - 86400;

        $cards = Instance::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Instance $instance) => $this->card($instance, $buckets, $from, $to))
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
    private function card(Instance $instance, BucketQuery $buckets, int $from, int $to): array
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

        return [
            'instance' => $instance,
            'health' => $instance->health(),
            'data_flow' => $flow,
            'totals_through' => $flow['state'] === 'healthy' ? $flow['coverage']['through'] : null,
            'requests_verified' => $gap->count === 0 && $verified('request'),
            'exceptions_verified' => $gap->count === 0 && $verified('exception'),
            'failed_jobs_verified' => $gap->count === 0 && $verified('job:failed'),
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
