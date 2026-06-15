<?php

namespace App\Livewire;

use App\Livewire\Concerns\BuildsCharts;
use App\Models\Instance;
use App\Models\MetricBucket;
use App\Services\BucketQuery;
use App\Support\TimeRange;
use Livewire\Attributes\Url;
use Livewire\Component;

class RouteDetail extends Component
{
    use BuildsCharts;

    public Instance $instance;

    /** md5 hex of the route metric key */
    public string $hash;

    public string $key;

    #[Url]
    public string $range = '24h';

    public function mount(Instance $instance, string $hash): void
    {
        abort_unless(preg_match('/^[a-f0-9]{32}$/', $hash) === 1, 404);

        $this->instance = $instance;
        $this->hash = $hash;

        $bucket = MetricBucket::query()
            ->where('instance_id', $instance->id)
            ->where('type', 'request')
            ->where('key_hash', hex2bin($hash))
            ->first();

        abort_if($bucket === null, 404);

        $this->key = $bucket->key;
    }

    public function render(BucketQuery $buckets)
    {
        $this->range = TimeRange::valid($this->range);

        $to = now()->getTimestamp();
        $from = $to - TimeRange::seconds($this->range);

        $series = $buckets->series($this->instance->id, 'request', $this->key, $from, $to);
        $step = $buckets->step($from, $to);

        $hist = $buckets->seriesByKey($this->instance->id, 'request_hist', $from, $to);

        $routeBinsTotal = [];
        $p95Data = [];

        for ($t = $from - ($from % $step); $t <= $to; $t += $step) {
            $stepBins = isset($hist[$t]) ? \App\Support\Histogram::bins($hist[$t], $this->key) : [];

            foreach ($stepBins as $bin => $count) {
                $routeBinsTotal[$bin] = ($routeBinsTotal[$bin] ?? 0) + $count;
            }

            $p95Data[] = $stepBins !== [] ? \App\Support\Histogram::percentile($stepBins, 0.95) : null;
        }

        $chart = $series->isEmpty() ? null : [
            'labels' => $this->chartLabels($from, $to, $step),
            'stacked' => false,
            'dualAxis' => true,
            'datasets' => [
                [
                    'label' => 'Requests',
                    'type' => 'bar',
                    'color' => '#38bdf8',
                    'fill' => false,
                    'stack' => null,
                    'yAxisID' => 'y',
                    'data' => $this->chartValues($series, $from, $to, $step, 'count'),
                ],
                [
                    'label' => 'Avg ms',
                    'type' => 'line',
                    'color' => '#a78bfa',
                    'fill' => false,
                    'stack' => null,
                    'yAxisID' => 'y1',
                    'data' => $this->chartValues($series, $from, $to, $step, 'avg', gapAware: true),
                ],
                [
                    'label' => 'Max ms',
                    'type' => 'line',
                    'color' => '#f472b6',
                    'fill' => false,
                    'stack' => null,
                    'yAxisID' => 'y1',
                    'data' => $this->chartValues($series, $from, $to, $step, 'max', gapAware: true),
                ],
                ...(array_filter($p95Data) !== [] ? [[
                    'label' => 'p95 ms',
                    'type' => 'line',
                    'color' => '#34d399',
                    'fill' => false,
                    'stack' => null,
                    'yAxisID' => 'y1',
                    'data' => $p95Data,
                ]] : []),
            ],
        ];

        return view('livewire.route-detail', [
            'chart' => $chart,
            'rangeCount' => $series->sum('count'),
            'rangeAvg' => $series->sum('count') > 0 ? $series->sum('sum') / $series->sum('count') : null,
            'rangeMax' => $series->max('max'),
            'rangeP95' => $routeBinsTotal !== [] ? \App\Support\Histogram::percentile($routeBinsTotal, 0.95) : null,
            'slowRequests' => $this->relatedSlowRequests($buckets, $from, $to),
        ])->title($this->key.' — '.config('app.name'));
    }

    /**
     * Slow-request entries belonging to this route. Their keys are
     * "METHOD path via" — match on the path segment.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function relatedSlowRequests(BucketQuery $buckets, int $from, int $to): \Illuminate\Support\Collection
    {
        return $buckets->topKeys($this->instance->id, 'slow_request', $from, $to, 'max', 100)
            ->filter(function (object $row) {
                $parts = explode(' ', $row->key, 3);

                return ($parts[1] ?? null) === $this->key;
            })
            ->values();
    }
}
