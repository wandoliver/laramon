<?php

namespace App\Services;

use App\Models\Instance;
use App\Models\MetricBucket;
use Illuminate\Support\Facades\DB;

class MetricWriter
{
    /**
     * Upsert a batch of metric buckets. The agent only ships closed,
     * complete buckets, so the latest payload for a bucket identity is
     * authoritative — replacing (not adding) makes retries trivially safe.
     *
     * @param  list<array{type: string, key: string, bucket_start: int, bucket_seconds: int, count: int, sum: float|null, min: float|null, max: float|null}>  $buckets
     */
    public function write(Instance $instance, array $buckets): int
    {
        $rows = [];

        foreach ($buckets as $bucket) {
            $key = mb_substr($bucket['key'], 0, 500);

            $rows[] = [
                'instance_id' => $instance->id,
                'type' => $bucket['type'],
                'key' => $key,
                'key_hash' => MetricBucket::hashKey($key),
                'bucket_start' => $bucket['bucket_start'],
                'bucket_seconds' => $bucket['bucket_seconds'],
                'count' => $bucket['count'],
                'sum' => $bucket['sum'] ?? null,
                'min' => $bucket['min'] ?? null,
                'max' => $bucket['max'] ?? null,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('metric_buckets')->upsert(
                $chunk,
                ['instance_id', 'type', 'key_hash', 'bucket_seconds', 'bucket_start'],
                ['key', 'count', 'sum', 'min', 'max'],
            );
        }

        return count($rows);
    }
}
