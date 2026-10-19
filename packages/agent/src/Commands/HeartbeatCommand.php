<?php

namespace LaraMon\Agent\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraMon\Agent\Export\Watermark;
use LaraMon\Agent\Http\HubClient;
use LaraMon\Agent\ScheduledTaskExpectations;

class HeartbeatCommand extends Command
{
    protected $signature = 'monitor-agent:heartbeat';

    protected $description = 'Send a heartbeat to the monitor hub';

    public function handle(HubClient $client, ScheduledTaskExpectations $expectations): int
    {
        if (! config('monitor-agent.enabled')
            || ! config('monitor-agent.hub_url')
            || ! config('monitor-agent.token')) {
            return self::SUCCESS;
        }

        $scheduler = $expectations->manifest($this->laravel->make(Schedule::class));
        $scheduler['exported_through'] = $this->exportedThrough();

        $client->heartbeat(array_filter([
            'app_version' => config('monitor-agent.app_version'),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'queue' => $this->queueStats(),
            'scheduler' => $scheduler,
        ], fn ($value) => $value !== null));

        return self::SUCCESS;
    }

    private function exportedThrough(): ?int
    {
        try {
            return $this->laravel->make(Watermark::class)->get();
        } catch (\Throwable) {
            // Missing storage must never make a heartbeat fail or claim coverage.
            return null;
        }
    }

    /**
     * @return array{pending: int, oldest_pending_seconds: int, failed: int}|null
     */
    private function queueStats(): ?array
    {
        try {
            if (! Schema::hasTable('jobs')) {
                return null;
            }

            $oldest = DB::table('jobs')->min('available_at');

            return [
                'pending' => DB::table('jobs')->count(),
                'oldest_pending_seconds' => $oldest !== null
                    ? max(0, now()->getTimestamp() - (int) $oldest)
                    : 0,
                'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
