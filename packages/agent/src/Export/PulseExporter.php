<?php

namespace LaraMon\Agent\Export;

use Illuminate\Support\Facades\DB;
use LaraMon\Agent\Collectors\CollectorRegistry;
use LaraMon\Agent\Support\Counter;
use LaraMon\Agent\Support\Gauge;

/**
 * Reads closed 60-second Pulse aggregate buckets past the export watermark,
 * re-buckets them into closed 300-second buckets, and produces the hub
 * payload. Pulse trims 60s aggregates after one hour, so anything older than
 * the trim horizon is skipped and reported as a data gap instead.
 */
class PulseExporter
{
    /**
     * Pulse keeps 60s buckets for 1 hour; stay safely inside that window.
     */
    public const TRIM_HORIZON_SECONDS = 3000; // 50 minutes

    public function __construct(
        protected Watermark $watermark,
        protected CollectorRegistry $collectors,
    ) {}

    /**
     * @return array{
     *     buckets: list<array<string, mixed>>,
     *     exceptions: list<array<string, mixed>>,
     *     samples: list<array<string, mixed>>,
     *     sample_ids: list<int>,
     *     watermark: int|null,
     * }
     */
    public function build(): array
    {
        $now = now()->getTimestamp();
        $bucketSeconds = (int) config('monitor-agent.bucket_seconds', 300);

        // Data older than this is considered settled — the 60s grace covers
        // Pulse's lazy buffers flushing slightly after the event timestamp.
        $cutoff = $now - 60;

        $horizon = $now - self::TRIM_HORIZON_SECONDS;

        $watermark = $this->watermark->get() ?? $horizon;

        $gapMinutes = 0;

        if ($watermark < $horizon) {
            $gapMinutes = (int) floor(($horizon - $watermark) / 60);
            $watermark = $horizon;
        }

        $typeMap = (array) config('monitor-agent.pulse_types', []);

        $rows = $this->pulseConnection()
            ->table('pulse_aggregates')
            ->where('period', 60)
            ->whereIn('type', array_keys($typeMap))
            ->where('bucket', '>=', $watermark)
            ->where('bucket', '<=', $cutoff - 60)
            ->orderBy('bucket')
            ->get(['bucket', 'type', 'key', 'aggregate', 'value', 'count']);

        // Pivot the per-aggregate rows into one metric per (type, key, 300s bucket).
        $metrics = [];
        $exceptionMeta = [];

        foreach ($rows as $row) {
            $bucketStart = (int) (floor($row->bucket / $bucketSeconds) * $bucketSeconds);

            // Only ship fully settled coarse buckets; the partial tail window
            // is re-read on the next run (the watermark stays behind it).
            if ($bucketStart + $bucketSeconds > $cutoff) {
                continue;
            }

            [$type, $key] = $this->normalize($typeMap[$row->type], $row->key, $exceptionMeta, $bucketStart + $bucketSeconds);

            $id = $type."\0".$key."\0".$bucketStart;

            $metric = $metrics[$id] ?? [
                'type' => $type,
                'key' => $key,
                'bucket_start' => $bucketStart,
                'bucket_seconds' => $bucketSeconds,
                'count' => 0,
                'sum' => null,
                'min' => null,
                'max' => null,
            ];

            $value = (float) $row->value;

            match ($row->aggregate) {
                'count' => $metric['count'] += (int) $value,
                'sum' => $metric['sum'] = ($metric['sum'] ?? 0) + $value,
                'avg' => $metric['sum'] = ($metric['sum'] ?? 0) + $value * (int) ($row->count ?? 1),
                'min' => $metric['min'] = $metric['min'] === null ? $value : min($metric['min'], $value),
                'max' => $metric['max'] = $metric['max'] === null ? $value : max($metric['max'], $value),
                default => null,
            };

            $metrics[$id] = $metric;
        }

        $buckets = $this->labelActiveUsers(array_values($metrics));

        // Everything before the start of the current (still-open) window is
        // now settled — shipped if present, empty otherwise.
        $newWatermark = (int) (floor($cutoff / $bucketSeconds) * $bucketSeconds);

        // Exception counts double as group metadata for the hub.
        $exceptions = [];

        foreach ($exceptionMeta as $fingerprint => $meta) {
            $count = 0;

            foreach ($buckets as $bucket) {
                if ($bucket['type'] === 'exception' && $bucket['key'] === $fingerprint) {
                    $count += $bucket['count'];
                }
            }

            if ($count > 0) {
                $exceptions[] = [
                    'fingerprint' => $fingerprint,
                    'class' => $meta['class'],
                    'location' => $meta['location'],
                    'count' => $count,
                    'last_seen_at' => date(DATE_ATOM, $meta['last_seen']),
                ];
            }
        }

        // Business metrics land in the most recent closed bucket.
        $businessBucket = (int) (floor($now / $bucketSeconds) * $bucketSeconds) - $bucketSeconds;

        foreach ($this->collectors->collect() as $metric) {
            $buckets[] = match (true) {
                $metric instanceof Gauge => [
                    'type' => 'gauge:'.$metric->key,
                    'key' => $metric->key,
                    'bucket_start' => $businessBucket,
                    'bucket_seconds' => $bucketSeconds,
                    'count' => 1,
                    'sum' => $metric->value,
                    'min' => $metric->value,
                    'max' => $metric->value,
                ],
                $metric instanceof Counter => [
                    'type' => 'counter:'.$metric->key,
                    'key' => $metric->key,
                    'bucket_start' => $businessBucket,
                    'bucket_seconds' => $bucketSeconds,
                    'count' => $metric->delta,
                    'sum' => null,
                    'min' => null,
                    'max' => null,
                ],
            };
        }

        if ($gapMinutes > 0) {
            $buckets[] = [
                'type' => 'counter:agent.export_gap_minutes',
                'key' => 'agent.export_gap_minutes',
                'bucket_start' => $businessBucket,
                'bucket_seconds' => $bucketSeconds,
                'count' => $gapMinutes,
                'sum' => null,
                'min' => null,
                'max' => null,
            ];
        }

        $samples = DB::table('monitor_agent_samples')
            ->orderBy('occurred_at')
            ->limit(50)
            ->get();

        return [
            'buckets' => $buckets,
            'exceptions' => $exceptions,
            'samples' => array_values($samples->map(fn (object $row) => [
                'kind' => (string) $row->kind,
                'fingerprint' => (string) $row->fingerprint,
                'occurred_at' => date(DATE_ATOM, (int) $row->occurred_at),
                'payload' => (array) (json_decode((string) $row->payload, true) ?: []),
            ])->all()),
            'sample_ids' => array_values($samples->pluck('id')->map(fn ($id) => (int) $id)->all()),
            'watermark' => $newWatermark > $watermark ? $newWatermark : null,
        ];
    }

    /**
     * Rewrite active_user keys from raw ids to "id|label" using the host
     * app's resolver, so only labels the app chose to expose leave the
     * instance.
     *
     * @param  list<array<string, mixed>>  $buckets
     * @return list<array<string, mixed>>
     */
    protected function labelActiveUsers(array $buckets): array
    {
        $ids = array_values(array_unique(array_column(
            array_filter($buckets, fn (array $bucket) => $bucket['type'] === 'active_user'),
            'key',
        )));

        if ($ids === []) {
            return $buckets;
        }

        $labels = $this->collectors->resolveUsers($ids);

        foreach ($buckets as &$bucket) {
            if ($bucket['type'] === 'active_user') {
                $bucket['key'] = mb_substr($bucket['key'].'|'.($labels[$bucket['key']] ?? 'User #'.$bucket['key']), 0, 500);
            }
        }

        return $buckets;
    }

    /**
     * Map a Pulse type/key pair onto the hub's type catalog. JSON-array keys
     * (slow queries, slow requests, exceptions, …) are flattened into a
     * single readable string; exceptions become fingerprints.
     *
     * @param  array<string, array{class: string, location: string|null, last_seen: int}>  $exceptionMeta
     * @return array{0: string, 1: string}
     */
    protected function normalize(string $type, string $key, array &$exceptionMeta, int $bucketEnd): array
    {
        $parts = str_starts_with($key, '[') ? json_decode($key, true) : null;

        if ($type === 'exception' && is_array($parts)) {
            $class = (string) ($parts[0] ?? 'UnknownException');
            $location = isset($parts[1]) && $parts[1] !== '' ? (string) $parts[1] : null;
            $fingerprint = md5($class.'|'.($location ?? ''));

            $existing = $exceptionMeta[$fingerprint] ?? null;

            $exceptionMeta[$fingerprint] = [
                'class' => $class,
                'location' => $location,
                'last_seen' => max($existing['last_seen'] ?? 0, $bucketEnd),
            ];

            return [$type, $fingerprint];
        }

        if (is_array($parts)) {
            $key = implode(' ', array_filter($parts, fn ($part) => is_string($part) && $part !== ''));
        }

        return [$type, mb_substr($key, 0, 500)];
    }

    protected function pulseConnection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('pulse.storage.database.connection'));
    }
}
