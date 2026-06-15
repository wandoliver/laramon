<?php

namespace LaraMon\Agent\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraMon\Agent\Http\HubClient;

class HeartbeatCommand extends Command
{
    protected $signature = 'monitor-agent:heartbeat';

    protected $description = 'Send a heartbeat to the monitor hub';

    public function handle(HubClient $client): int
    {
        if (! config('monitor-agent.enabled')
            || ! config('monitor-agent.hub_url')
            || ! config('monitor-agent.token')) {
            return self::SUCCESS;
        }

        $client->heartbeat(array_filter([
            'app_version' => config('monitor-agent.app_version'),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'queue' => $this->queueStats(),
        ], fn ($value) => $value !== null));

        return self::SUCCESS;
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
