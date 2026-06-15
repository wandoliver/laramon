<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single read path over metric_buckets. Picks the stored resolution
 * (300s for short ranges, hourly for long ones) and regroups into display
 * steps so charts get a sane number of points.
 */
class BucketQuery
{
    public function resolution(int $from, int $to): int
    {
        return ($to - $from) <= 172800 ? 300 : 3600; // <= 48h: fine buckets
    }

    public function step(int $from, int $to, int $points = 96): int
    {
        $resolution = $this->resolution($from, $to);

        return max($resolution, (int) (ceil(($to - $from) / $points / $resolution) * $resolution));
    }

    /**
     * Time series for a type (optionally a single key), regrouped per step.
     *
     * @return Collection<int, object{t: int, count: int, sum: float|null, min: float|null, max: float|null, avg: float|null}>
     */
    public function series(int $instanceId, string $type, ?string $key, int $from, int $to, int $points = 96): Collection
    {
        $step = $this->step($from, $to, $points);

        $query = DB::table('metric_buckets')
            ->selectRaw("bucket_start - (bucket_start % {$step}) as t")
            ->selectRaw($this->aggregateSelect())
            ->where('instance_id', $instanceId)
            ->where('type', $type)
            ->where('bucket_seconds', $this->resolution($from, $to))
            ->whereBetween('bucket_start', [$from, $to])
            ->groupBy('t')
            ->orderBy('t');

        if ($key !== null) {
            $query->where('key_hash', md5($key, true));
        }

        return $query->get()->map($this->withAvg(...));
    }

    /**
     * Aggregate totals per key, ordered by a metric, for "top N" tables.
     *
     * @return Collection<int, object{key: string, count: int, sum: float|null, min: float|null, max: float|null, avg: float|null}>
     */
    public function topKeys(int $instanceId, string $type, int $from, int $to, string $order = 'count', int $limit = 10): Collection
    {
        [$count, $sum, $min, $max, $key] = $this->wrapped();

        $orderExpr = match ($order) {
            'max' => "max({$max})",
            'avg' => "sum({$sum}) / nullif(sum({$count}), 0)",
            default => "sum({$count})",
        };

        return DB::table('metric_buckets')
            ->selectRaw($key.', '.$this->aggregateSelect())
            ->where('instance_id', $instanceId)
            ->where('type', $type)
            ->where('bucket_seconds', $this->resolution($from, $to))
            ->whereBetween('bucket_start', [$from, $to])
            ->groupBy('key')
            ->orderByRaw("{$orderExpr} desc")
            ->limit($limit)
            ->get()
            ->map($this->withAvg(...));
    }

    /**
     * Single aggregate over the whole range.
     */
    public function total(int $instanceId, string $type, int $from, int $to): object
    {
        $row = DB::table('metric_buckets')
            ->selectRaw($this->aggregateSelect())
            ->where('instance_id', $instanceId)
            ->where('type', $type)
            ->where('bucket_seconds', $this->resolution($from, $to))
            ->whereBetween('bucket_start', [$from, $to])
            ->first();

        $row->count = (int) ($row->count ?? 0);

        return $this->withAvg($row);
    }

    /**
     * Distinct metric types matching a prefix (gauge:* / counter:*) so
     * instance-specific business metrics appear without hub changes.
     *
     * @return list<string>
     */
    public function typesWithPrefix(int $instanceId, string $prefix, int $from, int $to): array
    {
        return DB::table('metric_buckets')
            ->where('instance_id', $instanceId)
            ->where('type', 'like', $prefix.'%')
            ->where('bucket_seconds', $this->resolution($from, $to))
            ->whereBetween('bucket_start', [$from, $to])
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->all();
    }

    /**
     * The most recent gauge value in range.
     */
    public function latestGauge(int $instanceId, string $type, int $from, int $to): ?float
    {
        $row = DB::table('metric_buckets')
            ->where('instance_id', $instanceId)
            ->where('type', $type)
            ->where('bucket_seconds', $this->resolution($from, $to))
            ->whereBetween('bucket_start', [$from, $to])
            ->orderByDesc('bucket_start')
            ->first(['max']);

        return $row?->max !== null ? (float) $row->max : null;
    }

    /**
     * Series broken down per key: step timestamp → key → count. One query;
     * used for histogram bins where every key matters per step.
     *
     * @return array<int, array<string, int>>
     */
    public function seriesByKey(int $instanceId, string $type, int $from, int $to, int $points = 96): array
    {
        $step = $this->step($from, $to, $points);
        [$count, , , , $key] = $this->wrapped();

        $rows = DB::table('metric_buckets')
            ->selectRaw("bucket_start - (bucket_start % {$step}) as t, {$key}, sum({$count}) as count")
            ->where('instance_id', $instanceId)
            ->where('type', $type)
            ->where('bucket_seconds', $this->resolution($from, $to))
            ->whereBetween('bucket_start', [$from, $to])
            ->groupBy('t', 'key')
            ->orderBy('t')
            ->get();

        $series = [];

        foreach ($rows as $row) {
            $series[(int) $row->t][$row->key] = (int) $row->count;
        }

        return $series;
    }

    /**
     * Latest bucket end per key — "when did this last happen".
     *
     * @return array<string, int>
     */
    public function lastSeenPerKey(int $instanceId, string $type, int $from, int $to): array
    {
        $resolution = $this->resolution($from, $to);

        return DB::table('metric_buckets')
            ->selectRaw(DB::query()->getGrammar()->wrap('key').', max(bucket_start) as last_start')
            ->where('instance_id', $instanceId)
            ->where('type', $type)
            ->where('bucket_seconds', $resolution)
            ->whereBetween('bucket_start', [$from, $to])
            ->groupBy('key')
            ->pluck('last_start', 'key')
            ->map(fn ($start) => (int) $start + $resolution)
            ->all();
    }

    private function aggregateSelect(): string
    {
        [$count, $sum, $min, $max] = $this->wrapped();

        return "sum({$count}) as count, sum({$sum}) as sum, min({$min}) as min, max({$max}) as max";
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    private function wrapped(): array
    {
        $grammar = DB::query()->getGrammar();

        return [
            $grammar->wrap('count'),
            $grammar->wrap('sum'),
            $grammar->wrap('min'),
            $grammar->wrap('max'),
            $grammar->wrap('key'),
        ];
    }

    private function withAvg(object $row): object
    {
        $row->count = (int) $row->count;
        $row->avg = $row->sum !== null && $row->count > 0 ? (float) $row->sum / $row->count : null;

        return $row;
    }
}
