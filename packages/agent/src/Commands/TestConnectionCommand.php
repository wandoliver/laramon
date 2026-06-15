<?php

namespace LaraMon\Agent\Commands;

use Illuminate\Console\Command;
use LaraMon\Agent\Http\HubClient;

class TestConnectionCommand extends Command
{
    protected $signature = 'monitor-agent:test';

    protected $description = 'Validate the monitor agent configuration and hub connectivity';

    public function handle(HubClient $client): int
    {
        if (! config('monitor-agent.enabled')) {
            $this->error('The agent is disabled. Set MONITOR_AGENT_ENABLED=true.');

            return self::FAILURE;
        }

        if (! config('monitor-agent.hub_url') || ! config('monitor-agent.token')) {
            $this->error('Set MONITOR_HUB_URL and MONITOR_HUB_TOKEN.');

            return self::FAILURE;
        }

        $this->line('Hub: '.config('monitor-agent.hub_url'));

        if ($client->heartbeat(['php_version' => PHP_VERSION, 'laravel_version' => app()->version()])) {
            $this->info('Heartbeat accepted — the hub can hear this instance.');

            return self::SUCCESS;
        }

        $this->error('Heartbeat rejected or unreachable. Check the URL, token, and hub logs.');

        return self::FAILURE;
    }
}
