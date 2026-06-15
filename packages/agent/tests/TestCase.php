<?php

namespace LaraMon\Agent\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use LaraMon\Agent\MonitorAgentServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MonitorAgentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('monitor-agent.enabled', true);
        $app['config']->set('monitor-agent.hub_url', 'https://hub.test');
        $app['config']->set('monitor-agent.token', 'lm_1_'.str_repeat('a', 40));
        $app['config']->set('monitor-agent.collectors', []);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate')->run();

        // Minimal stand-in for Pulse's aggregates table — the exporter only
        // reads, never writes, so the generated key_hash column is irrelevant.
        Schema::create('pulse_aggregates', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('bucket');
            $table->unsignedMediumInteger('period');
            $table->string('type');
            $table->text('key');
            $table->string('aggregate');
            $table->decimal('value', 20, 2);
            $table->unsignedInteger('count')->nullable();
        });
    }

    protected function seedAggregate(int $bucket, string $type, string $key, string $aggregate, float $value, ?int $count = null): void
    {
        $this->app['db']->table('pulse_aggregates')->insert([
            'bucket' => $bucket,
            'period' => 60,
            'type' => $type,
            'key' => $key,
            'aggregate' => $aggregate,
            'value' => $value,
            'count' => $count,
        ]);
    }
}
