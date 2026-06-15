<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Rollup extends Command
{
    protected $signature = 'monitor:rollup';

    protected $description = 'Aggregate fine-grained 300s metric buckets into hourly buckets';

    public function handle(): int
    {
        $grammar = DB::query()->getGrammar();
        $wrap = fn (string $column): string => $grammar->wrap($column);

        // Re-rolling an already-processed window produces identical rows
        // (upsert replaces), so the generous window — including the current
        // partial hour, which converges on later runs — is safe.
        $from = now()->subDays(8)->getTimestamp();
        $to = now()->getTimestamp();

        $hourExpr = 'bucket_start - (bucket_start % 3600)';

        $rows = DB::table('metric_buckets')
            ->selectRaw(implode(', ', [
                'instance_id',
                'type',
                $wrap('key'),
                'key_hash',
                "{$hourExpr} as hour_start",
                "sum({$wrap('count')}) as total_count",
                "sum({$wrap('sum')}) as total_sum",
                "min({$wrap('min')}) as total_min",
                "max({$wrap('max')}) as total_max",
            ]))
            ->where('bucket_seconds', 300)
            ->whereBetween('bucket_start', [$from, $to])
            ->groupBy('instance_id', 'type', 'key_hash', 'key', DB::raw($hourExpr))
            ->cursor();

        $upserts = 0;
        $chunk = [];

        foreach ($rows as $row) {
            $chunk[] = [
                'instance_id' => $row->instance_id,
                'type' => $row->type,
                'key' => $row->key,
                'key_hash' => $row->key_hash,
                'bucket_start' => $row->hour_start,
                'bucket_seconds' => 3600,
                'count' => $row->total_count,
                'sum' => $row->total_sum,
                'min' => $row->total_min,
                'max' => $row->total_max,
            ];

            if (count($chunk) === 500) {
                $upserts += $this->flush($chunk);
                $chunk = [];
            }
        }

        $upserts += $this->flush($chunk);

        $this->info("Rolled up {$upserts} hourly buckets.");

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     */
    private function flush(array $chunk): int
    {
        if ($chunk === []) {
            return 0;
        }

        DB::table('metric_buckets')->upsert(
            $chunk,
            ['instance_id', 'type', 'key_hash', 'bucket_seconds', 'bucket_start'],
            ['key', 'count', 'sum', 'min', 'max'],
        );

        return count($chunk);
    }
}
