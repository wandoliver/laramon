<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class Prune extends Command
{
    protected $signature = 'monitor:prune';

    protected $description = 'Delete expired metric buckets, ingest batches, and stale exception groups';

    public function handle(): int
    {
        $deleted = 0;

        $deleted += $this->chunkedDelete(
            DB::table('metric_buckets')
                ->where('bucket_seconds', 300)
                ->where('bucket_start', '<', now()->subDays(7)->getTimestamp()),
        );

        $deleted += $this->chunkedDelete(
            DB::table('metric_buckets')
                ->where('bucket_seconds', 3600)
                ->where('bucket_start', '<', now()->subDays(90)->getTimestamp()),
        );

        $deleted += $this->chunkedDelete(
            DB::table('ingest_batches')->where('received_at', '<', now()->subHours(48)),
        );

        $deleted += $this->chunkedDelete(
            DB::table('exception_groups')->where('last_seen_at', '<', now()->subDays(90)),
        );

        $deleted += $this->chunkedDelete(
            DB::table('samples')->where('occurred_at', '<', now()->subDays(14)),
        );

        $this->info("Pruned {$deleted} rows.");

        return self::SUCCESS;
    }

    /**
     * Delete in id-chunks to avoid long-running locks on large tables.
     */
    private function chunkedDelete(Builder $query): int
    {
        $total = 0;

        do {
            $ids = (clone $query)->limit(10000)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $total += DB::table($query->from)->whereIntegerInRaw('id', $ids->all())->delete();
        } while ($ids->count() === 10000);

        return $total;
    }
}
