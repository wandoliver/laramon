<?php

namespace LaraMon\Agent\Collectors;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraMon\Agent\Contracts\BusinessMetricCollector;
use LaraMon\Agent\Support\Gauge;

/**
 * Queue depth gauges for the database queue driver. Instances using other
 * drivers can disable this collector in the config.
 */
class QueueBacklogCollector implements BusinessMetricCollector
{
    public function collect(): array
    {
        $metrics = [];

        if (Schema::hasTable('jobs')) {
            $metrics[] = new Gauge('queue.pending', (float) DB::table('jobs')->count());

            $oldest = DB::table('jobs')->min('available_at');
            $metrics[] = new Gauge(
                'queue.oldest_pending_seconds',
                $oldest !== null ? (float) max(0, now()->getTimestamp() - (int) $oldest) : 0.0,
            );
        }

        if (Schema::hasTable('failed_jobs')) {
            $metrics[] = new Gauge('queue.failed', (float) DB::table('failed_jobs')->count());
        }

        return $metrics;
    }
}
