<?php

namespace App\Livewire;

use App\Livewire\Concerns\BuildsCharts;
use App\Models\Instance;
use App\Models\MetricBucket;
use App\Models\Sample;
use App\Services\BucketQuery;
use App\Support\TimeRange;
use Livewire\Attributes\Url;
use Livewire\Component;

class QueryDetail extends Component
{
    use BuildsCharts;

    public Instance $instance;

    /** md5 hex of the metric key */
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
            ->where('type', 'slow_query')
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

        $series = $buckets->series($this->instance->id, 'slow_query', $this->key, $from, $to);
        $step = $buckets->step($from, $to);

        $chart = $series->isEmpty() ? null : [
            'labels' => $this->chartLabels($from, $to, $step),
            'stacked' => false,
            'dualAxis' => true,
            'datasets' => [
                [
                    'label' => 'Occurrences',
                    'type' => 'bar',
                    'color' => '#fbbf24',
                    'fill' => false,
                    'stack' => null,
                    'yAxisID' => 'y',
                    'data' => $this->chartValues($series, $from, $to, $step, 'count'),
                ],
                [
                    'label' => 'Slowest ms',
                    'type' => 'line',
                    'color' => '#f472b6',
                    'fill' => false,
                    'stack' => null,
                    'yAxisID' => 'y1',
                    'data' => $this->chartValues($series, $from, $to, $step, 'max', gapAware: true),
                ],
            ],
        ];

        $samples = Sample::query()
            ->where('instance_id', $this->instance->id)
            ->where('kind', 'slow_query')
            ->where('fingerprint', $this->hash)
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        return view('livewire.query-detail', [
            'chart' => $chart,
            'samples' => $samples,
            'rangeCount' => $series->sum('count'),
            'rangeMax' => $series->max('max'),
        ])->title('Slow query — '.config('app.name'));
    }
}
