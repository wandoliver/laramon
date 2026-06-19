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

class RequestDetail extends Component
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
            ->where('type', 'slow_request')
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

        $series = $buckets->series($this->instance->id, 'slow_request', $this->key, $from, $to);
        $step = $buckets->step($from, $to);

        $chart = $series->isEmpty() ? null : [
            'labels' => $this->chartLabels($from, $to, $step),
            'stacked' => false,
            'dualAxis' => true,
            'datasets' => [
                [
                    'label' => 'Occurrences',
                    'type' => 'bar',
                    'color' => '#38bdf8',
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
            ->where('kind', 'slow_request')
            ->where('fingerprint', $this->hash)
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        return view('livewire.request-detail', [
            'chart' => $chart,
            'samples' => $samples,
            'sampleDiagnostics' => $samples->mapWithKeys(fn (Sample $sample) => [
                $sample->id => $this->sampleDiagnostics($sample->payload),
            ]),
            'rangeCount' => $series->sum('count'),
            'rangeMax' => $series->max('max'),
        ])->title('Slow request — '.config('app.name'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{metrics: list<array{label: string, value: string, tone: string}>, context: list<array{label: string, value: string}>, raw: string}
     */
    private function sampleDiagnostics(array $payload): array
    {
        $metrics = [];

        foreach ([
            'duration_ms' => ['Duration', ' ms', 'amber'],
            'db_query_count' => ['DB queries', '', 'sky'],
            'db_ms' => ['DB time', ' ms', 'sky'],
            'memory_peak_mb' => ['Memory peak', ' MB', 'emerald'],
        ] as $key => [$label, $suffix, $tone]) {
            $value = $this->payloadValue($payload, $key);

            if ($value === null || $value === '') {
                continue;
            }

            $metrics[] = [
                'label' => $label,
                'value' => $this->formatPayloadValue($value, $suffix),
                'tone' => $tone,
            ];
        }

        return [
            'metrics' => $metrics,
            'context' => $this->contextDiagnostics($payload),
            'raw' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{label: string, value: string}>
     */
    private function contextDiagnostics(array $payload): array
    {
        $context = [];

        if (isset($payload['context']) && is_array($payload['context'])) {
            foreach ($this->flattenPayload($payload['context']) as $key => $value) {
                $context['context.'.$key] = $value;
            }
        }

        foreach ($payload as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'context.')) {
                $context[$key] = $value;
            }
        }

        return collect($context)
            ->sortKeys()
            ->map(fn (mixed $value, string $key) => [
                'label' => str($key)->after('context.')->replace(['_', '.'], ' ')->headline()->toString(),
                'value' => $this->formatPayloadValue($value),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function flattenPayload(array $payload, string $prefix = ''): array
    {
        $flat = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flattenPayload($value, $path);

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadValue(array $payload, string $key): mixed
    {
        return array_key_exists($key, $payload) ? $payload[$key] : data_get($payload, $key);
    }

    private function formatPayloadValue(mixed $value, string $suffix = ''): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_int($value) || is_float($value)) {
            $formatted = is_float($value) && floor($value) !== $value
                ? rtrim(rtrim(number_format($value, 1), '0'), '.')
                : (string) round($value);

            return $formatted.$suffix;
        }

        if ($value === null || $value === '') {
            return '—';
        }

        return (string) $value;
    }
}
