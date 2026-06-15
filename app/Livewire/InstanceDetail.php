<?php

namespace App\Livewire;

use App\Models\Instance;
use App\Services\BucketQuery;
use App\Support\TimeRange;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

class InstanceDetail extends Component
{
    public Instance $instance;

    #[Url]
    public string $range = '24h';

    #[Url]
    public string $routesSort = 'count';

    public function mount(Instance $instance): void
    {
        $this->instance = $instance;
    }

    public function render(BucketQuery $buckets)
    {
        $this->range = TimeRange::valid($this->range);

        $to = now()->getTimestamp();
        $from = $to - TimeRange::seconds($this->range);

        return view('livewire.instance-detail', [
            'activeUsers' => $this->activeUsers($buckets, $from, $to),
            'requestsChart' => $this->requestsChart($buckets, $from, $to),
            'requestStats' => $this->requestStats($buckets, $from, $to),
            'topRoutes' => $this->topRoutes($buckets, $from, $to),
            'slowRequests' => $buckets->topKeys($this->instance->id, 'slow_request', $from, $to, 'max', 10),
            'slowQueries' => $buckets->topKeys($this->instance->id, 'slow_query', $from, $to, 'max', 10),
            'jobsChart' => $this->jobsChart($buckets, $from, $to),
            'queueChart' => $this->gaugeChart($buckets, 'gauge:queue.pending', 'Pending jobs', '#38bdf8', $from, $to),
            'slowJobs' => $buckets->topKeys($this->instance->id, 'slow_job', $from, $to, 'max', 10),
            'scheduledTasks' => $this->scheduledTasks($buckets, $from, $to),
            'exceptionsChart' => $this->exceptionsChart($buckets, $from, $to),
            'exceptionGroups' => $this->exceptionGroups($buckets, $from, $to),
            'business' => $this->business($buckets, $from, $to),
            'cacheChart' => $this->cacheChart($buckets, $from, $to),
        ])->title($this->instance->name.' — '.config('app.name'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestsChart(BucketQuery $buckets, int $from, int $to): ?array
    {
        $series = $buckets->series($this->instance->id, 'request', null, $from, $to);

        if ($series->isEmpty()) {
            return null;
        }

        $step = $buckets->step($from, $to);

        $datasets = [
            ['label' => 'Requests', 'series' => $series, 'metric' => 'count', 'color' => '#38bdf8', 'type' => 'bar', 'yAxisID' => 'y'],
            ['label' => 'Avg ms', 'series' => $series, 'metric' => 'avg', 'color' => '#a78bfa', 'type' => 'line', 'yAxisID' => 'y1'],
            ['label' => 'Max ms', 'series' => $series, 'metric' => 'max', 'color' => '#f472b6', 'type' => 'line', 'yAxisID' => 'y1'],
        ];

        // p95 from the latency histogram — only present for agents >= 0.2.
        $hist = $buckets->seriesByKey($this->instance->id, 'request_hist', $from, $to);

        if ($hist !== []) {
            $data = [];

            for ($t = $from - ($from % $step); $t <= $to; $t += $step) {
                $data[] = isset($hist[$t])
                    ? \App\Support\Histogram::percentile(\App\Support\Histogram::bins($hist[$t]), 0.95)
                    : null;
            }

            $datasets[] = ['label' => 'p95 ms', 'data' => $data, 'color' => '#34d399', 'type' => 'line', 'yAxisID' => 'y1'];
        }

        return $this->chart($from, $to, $step, $datasets, dualAxis: true);
    }

    /**
     * Top routes with per-route p95, joined and sorted in PHP so every
     * column (count, avg, max, p95) sorts consistently.
     *
     * @return Collection<int, object>
     */
    private function topRoutes(BucketQuery $buckets, int $from, int $to): Collection
    {
        $histTotals = $buckets->topKeys($this->instance->id, 'request_hist', $from, $to, 'count', 10000)
            ->pluck('count', 'key')
            ->all();

        $binsByRoute = \App\Support\Histogram::binsByRoute($histTotals);

        $routes = $buckets->topKeys($this->instance->id, 'request', $from, $to, 'count', 500)
            ->map(function (object $row) use ($binsByRoute) {
                $row->p95 = isset($binsByRoute[$row->key])
                    ? \App\Support\Histogram::percentile($binsByRoute[$row->key], 0.95)
                    : null;

                return $row;
            });

        $sort = in_array($this->routesSort, ['count', 'avg', 'max', 'p95'], true) ? $this->routesSort : 'count';

        return $routes->sortByDesc(fn (object $row) => $row->{$sort} ?? -1)->take(10)->values();
    }

    /**
     * @return array{count: int, avg: float|null, p95: float|null}
     */
    private function requestStats(BucketQuery $buckets, int $from, int $to): array
    {
        $total = $buckets->total($this->instance->id, 'request', $from, $to);

        $keyCounts = [];

        foreach ($buckets->seriesByKey($this->instance->id, 'request_hist', $from, $to) as $stepBins) {
            foreach ($stepBins as $key => $count) {
                $keyCounts[$key] = ($keyCounts[$key] ?? 0) + $count;
            }
        }

        $bins = \App\Support\Histogram::bins($keyCounts);

        return [
            'count' => $total->count,
            'avg' => $total->avg,
            'p95' => $bins !== [] ? \App\Support\Histogram::percentile($bins, 0.95) : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jobsChart(BucketQuery $buckets, int $from, int $to): ?array
    {
        $processed = $buckets->series($this->instance->id, 'job:processed', null, $from, $to);
        $failed = $buckets->series($this->instance->id, 'job:failed', null, $from, $to);
        $released = $buckets->series($this->instance->id, 'job:released', null, $from, $to);

        if ($processed->isEmpty() && $failed->isEmpty() && $released->isEmpty()) {
            return null;
        }

        return $this->chart($from, $to, $buckets->step($from, $to), [
            ['label' => 'Processed', 'series' => $processed, 'metric' => 'count', 'color' => '#34d399', 'type' => 'bar', 'stack' => 'jobs'],
            ['label' => 'Released', 'series' => $released, 'metric' => 'count', 'color' => '#fbbf24', 'type' => 'bar', 'stack' => 'jobs'],
            ['label' => 'Failed', 'series' => $failed, 'metric' => 'count', 'color' => '#fb7185', 'type' => 'bar', 'stack' => 'jobs'],
        ], stacked: true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function gaugeChart(BucketQuery $buckets, string $type, string $label, string $color, int $from, int $to): ?array
    {
        $series = $buckets->series($this->instance->id, $type, null, $from, $to);

        if ($series->isEmpty()) {
            return null;
        }

        return $this->chart($from, $to, $buckets->step($from, $to), [
            ['label' => $label, 'series' => $series, 'metric' => 'max', 'color' => $color, 'type' => 'line', 'fill' => true],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function exceptionsChart(BucketQuery $buckets, int $from, int $to): ?array
    {
        $series = $buckets->series($this->instance->id, 'exception', null, $from, $to);

        if ($series->isEmpty()) {
            return null;
        }

        return $this->chart($from, $to, $buckets->step($from, $to), [
            ['label' => 'Exceptions', 'series' => $series, 'metric' => 'count', 'color' => '#fb7185', 'type' => 'bar'],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cacheChart(BucketQuery $buckets, int $from, int $to): ?array
    {
        $hits = $buckets->series($this->instance->id, 'cache:hit', null, $from, $to);
        $misses = $buckets->series($this->instance->id, 'cache:miss', null, $from, $to);

        if ($hits->isEmpty() && $misses->isEmpty()) {
            return null;
        }

        return $this->chart($from, $to, $buckets->step($from, $to), [
            ['label' => 'Hits', 'series' => $hits, 'metric' => 'count', 'color' => '#34d399', 'type' => 'bar', 'stack' => 'cache'],
            ['label' => 'Misses', 'series' => $misses, 'metric' => 'count', 'color' => '#fb7185', 'type' => 'bar', 'stack' => 'cache'],
        ], stacked: true);
    }

    /**
     * Active users in range: key format is "id|label" (the label is chosen
     * by the instance's resolver, so the hub shows exactly what the host
     * app decided to expose).
     *
     * @return Collection<int, object{label: string, count: int, last_seen: int|null, online: bool}>
     */
    private function activeUsers(BucketQuery $buckets, int $from, int $to): Collection
    {
        $lastSeen = $buckets->lastSeenPerKey($this->instance->id, 'active_user', $from, $to);

        return $buckets->topKeys($this->instance->id, 'active_user', $from, $to, 'count', 100)
            ->map(function (object $row) use ($lastSeen) {
                $seen = $lastSeen[$row->key] ?? null;

                return (object) [
                    'label' => str_contains($row->key, '|') ? explode('|', $row->key, 2)[1] : $row->key,
                    'count' => $row->count,
                    'last_seen' => $seen,
                    'online' => $seen !== null && now()->getTimestamp() - $seen < 600,
                ];
            })
            ->sortBy([['online', 'desc'], ['last_seen', 'desc']])
            ->values()
            ->take(30);
    }

    /**
     * @return Collection<int, object>
     */
    private function scheduledTasks(BucketQuery $buckets, int $from, int $to): Collection
    {
        $tasks = $buckets->topKeys($this->instance->id, 'scheduled_task', $from, $to, 'count', 50);
        $failures = $buckets->topKeys($this->instance->id, 'scheduled_task:failed', $from, $to, 'count', 50)
            ->keyBy('key');
        $lastSeen = $buckets->lastSeenPerKey($this->instance->id, 'scheduled_task', $from, $to);

        return $tasks->map(function (object $task) use ($failures, $lastSeen) {
            $task->failures = (int) ($failures[$task->key]->count ?? 0);
            $task->last_seen = $lastSeen[$task->key] ?? null;

            return $task;
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function exceptionGroups(BucketQuery $buckets, int $from, int $to): Collection
    {
        return $this->instance->exceptionGroups()
            ->orderByDesc('last_seen_at')
            ->limit(25)
            ->get()
            ->map(function ($group) use ($buckets, $from, $to) {
                $series = $buckets->series($this->instance->id, 'exception', $group->fingerprint, $from, $to, 48);

                return [
                    'group' => $group,
                    'recent_count' => $series->sum('count'),
                    'spark' => $this->zeroFill($series, $from, $to, $buckets->step($from, $to, 48), 'count'),
                ];
            })
            ->sortByDesc('recent_count')
            ->values();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function business(BucketQuery $buckets, int $from, int $to): array
    {
        $cards = [];

        foreach ($buckets->typesWithPrefix($this->instance->id, 'gauge:', $from, $to) as $type) {
            if ($type === 'gauge:queue.pending' || $type === 'gauge:queue.oldest_pending_seconds' || $type === 'gauge:queue.failed') {
                continue; // already shown in the queue section
            }

            $series = $buckets->series($this->instance->id, $type, null, $from, $to, 48);

            $cards[] = [
                'label' => str_replace(['gauge:', '.', '_'], ['', ' › ', ' '], $type),
                'value' => $buckets->latestGauge($this->instance->id, $type, $from, $to),
                'kind' => 'gauge',
                'spark' => $this->zeroFill($series, $from, $to, $buckets->step($from, $to, 48), 'max', gapAware: true),
            ];
        }

        foreach ($buckets->typesWithPrefix($this->instance->id, 'counter:', $from, $to) as $type) {
            if ($type === 'counter:agent.export_gap_minutes') {
                continue;
            }

            $series = $buckets->series($this->instance->id, $type, null, $from, $to, 48);

            $cards[] = [
                'label' => str_replace(['counter:', '.', '_'], ['', ' › ', ' '], $type),
                'value' => $series->sum('count'),
                'kind' => 'counter',
                'spark' => $this->zeroFill($series, $from, $to, $buckets->step($from, $to, 48), 'count'),
            ];
        }

        return $cards;
    }

    /**
     * Build the JSON payload our Chart.js bootstrapper understands.
     *
     * @param  list<array<string, mixed>>  $datasets
     * @return array<string, mixed>
     */
    private function chart(int $from, int $to, int $step, array $datasets, bool $stacked = false, bool $dualAxis = false): array
    {
        $labels = [];
        $format = ($to - $from) <= 86400 ? 'H:i' : 'd.m H:i';

        $start = $from - ($from % $step);

        for ($t = $start; $t <= $to; $t += $step) {
            $labels[] = date($format, $t);
        }

        return [
            'labels' => $labels,
            'stacked' => $stacked,
            'dualAxis' => $dualAxis,
            'datasets' => array_map(function (array $dataset) use ($from, $to, $step) {
                return [
                    'label' => $dataset['label'],
                    'type' => $dataset['type'],
                    'color' => $dataset['color'],
                    'fill' => $dataset['fill'] ?? false,
                    'stack' => $dataset['stack'] ?? null,
                    'yAxisID' => $dataset['yAxisID'] ?? 'y',
                    'data' => $dataset['data'] ?? $this->zeroFill(
                        $dataset['series'],
                        $from,
                        $to,
                        $step,
                        $dataset['metric'],
                        gapAware: $dataset['metric'] !== 'count',
                    ),
                ];
            }, $datasets),
        ];
    }

    /**
     * Align a series to fixed steps. Count metrics zero-fill (no data means
     * nothing happened); value metrics gap-fill with null so charts show a
     * hole instead of a fake zero.
     *
     * @param  Collection<int, object>  $series
     * @return list<float|null>
     */
    private function zeroFill(Collection $series, int $from, int $to, int $step, string $metric, bool $gapAware = false): array
    {
        $byTime = $series->keyBy('t');
        $values = [];

        for ($t = $from - ($from % $step); $t <= $to; $t += $step) {
            $value = $byTime[$t]->{$metric} ?? null;

            $values[] = $value !== null
                ? round((float) $value, 1)
                : ($gapAware ? null : 0.0);
        }

        return $values;
    }
}
