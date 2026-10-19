<?php

namespace LaraMon\Agent\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LaraMon\Agent\Export\Coverage;
use LaraMon\Agent\Export\PulseExporter;
use LaraMon\Agent\Export\Watermark;
use LaraMon\Agent\MonitorAgentServiceProvider;
use LaraMon\Agent\Recorders\ActiveUsers;
use LaraMon\Agent\Recorders\Requests;
use LaraMon\Agent\Recorders\ScheduledTasks;
use Laravel\Pulse\Recorders;

class CoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['pulse.enabled' => true, 'pulse.recorders' => [
            Requests::class => [],
            Recorders\Exceptions::class => [],
            Recorders\SlowQueries::class => [],
            ScheduledTasks::class => [],
        ]]);
    }

    private function beginCapture(): Coverage
    {
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(10, 0));
        $coverage = app(Coverage::class);
        $this->assertNull($coverage->build(now()->getTimestamp() - 600));
        $this->advanceCapture($coverage, 11);

        return $coverage;
    }

    private function advanceCapture(Coverage $coverage, int $minutes): void
    {
        while ($minutes > 5) {
            $this->travel(5)->minutes();
            $coverage->build(now()->getTimestamp() - 600);
            $minutes -= 5;
        }

        $this->travel($minutes)->minutes();
    }

    public function test_only_complete_known_recorder_types_are_attested_in_settled_windows(): void
    {
        $coverage = $this->beginCapture()->build(now()->getTimestamp() - 600);

        $this->assertSame(now()->setTime(10, 5)->getTimestamp(), $coverage['from']);
        $this->assertSame(now()->setTime(10, 10)->getTimestamp(), $coverage['through']);
        $this->assertContains('exception', $coverage['types']);
        $this->assertContains('request', $coverage['types']);
        $this->assertContains('scheduled_task:failed', $coverage['types']);
        $this->assertContains('scheduled_task:runtime', $coverage['types']);
        $this->assertNotContains('gauge:active_users', $coverage['types']);
    }

    public function test_lower_boundary_is_ceiled_after_a_trim_gap_and_upper_boundary_is_exporters(): void
    {
        $coverage = $this->beginCapture();
        $this->advanceCapture($coverage, 60);
        $this->travel(42)->seconds();
        $readFrom = now()->getTimestamp() - PulseExporter::TRIM_HORIZON_SECONDS;
        $readThrough = now()->setTime(11, 0)->getTimestamp();

        $result = $coverage->build($readFrom, $readThrough);

        $this->assertSame(now()->setTime(10, 25)->getTimestamp(), $result['from']);
        $this->assertSame($readThrough, $result['through']);
        $this->assertGreaterThan($readFrom, $result['from']);
    }

    public function test_legacy_mappings_never_claim_missing_or_remapped_types(): void
    {
        config(['monitor-agent.pulse_types' => [
            'scheduled_task' => 'scheduled_task',
            'scheduled_task_failed' => 'scheduled_task:failed',
            'exception' => 'custom_exception',
            'untrusted' => 'exception',
            'gauge:appointments' => 'gauge:appointments',
        ]]);

        $result = $this->beginCapture()->build(now()->getTimestamp() - 600);

        $this->assertSame(['scheduled_task', 'scheduled_task:failed', 'scheduled_task:runtime'], $result['types']);
    }

    public function test_missing_disabled_and_sampled_recorders_do_not_attest_zeroes(): void
    {
        config(['pulse.recorders' => [
            Requests::class => ['sample_rate' => 0.5],
            Recorders\Exceptions::class => ['enabled' => false],
            Recorders\SlowQueries::class => false,
            ScheduledTasks::class => [],
        ]]);

        $result = $this->beginCapture()->build(now()->getTimestamp() - 600);

        $this->assertSame([
            'scheduled_task', 'scheduled_task:failed', 'scheduled_task:last_failure',
            'scheduled_task:last_success', 'scheduled_task:runtime',
        ], $result['types']);
    }

    public function test_full_catalog_normalizes_known_sources_and_excludes_arbitrary_metrics(): void
    {
        config(['pulse.recorders' => [
            Requests::class => [],
            Recorders\Exceptions::class => [],
            Recorders\SlowRequests::class => [],
            Recorders\SlowQueries::class => [],
            Recorders\SlowJobs::class => [],
            Recorders\SlowOutgoingRequests::class => [],
            Recorders\CacheInteractions::class => [],
            ActiveUsers::class => [],
            Recorders\Queues::class => [],
            ScheduledTasks::class => [],
            'CustomRecorder' => [],
        ], 'monitor-agent.pulse_types.untrusted' => 'gauge:appointments']);
        $result = $this->beginCapture()->build(now()->getTimestamp() - 600);

        $this->assertCount(19, $result['types']);
        $this->assertContains('cache:hit', $result['types']);
        $this->assertContains('cache:miss', $result['types']);
        $this->assertContains('job:failed', $result['types']);
        $this->assertNotContains('gauge:appointments', $result['types']);
    }

    public function test_missing_recorder_configuration_never_attests_completeness(): void
    {
        config(['pulse.recorders' => []]);

        $this->assertNull($this->beginCapture()->build(now()->getTimestamp() - 600));
    }

    public function test_capture_configuration_change_restarts_baseline_without_storing_config(): void
    {
        $coverage = $this->beginCapture();
        $this->assertNotNull($coverage->build(now()->getTimestamp() - 600));
        config(['pulse.recorders.'.Recorders\SlowQueries::class.'.ignore' => ['secret-value']]);
        $this->assertNull($coverage->build(now()->getTimestamp() - 600));
        $this->assertStringNotContainsString('secret-value', $this->app['db']->table('monitor_agent_state')->pluck('value')->implode(' '));
        $this->advanceCapture($coverage, 11);
        $this->assertSame(now()->setTime(10, 15)->getTimestamp(), $coverage->build(now()->getTimestamp() - 600)['from']);
    }

    public function test_disabled_capture_resets_baseline_and_requires_a_new_full_window(): void
    {
        $coverage = $this->beginCapture();
        config(['pulse.enabled' => false]);
        $this->assertNull($coverage->build(now()->getTimestamp() - 600));
        $this->assertSame(0, $this->app['db']->table('monitor_agent_state')->where('key', 'like', 'coverage_%')->count());
        config(['pulse.enabled' => true]);
        $this->assertNull($coverage->build(now()->getTimestamp() - 600));
        $this->advanceCapture($coverage, 11);
        $this->assertNotNull($coverage->build(now()->getTimestamp() - 600));
    }

    public function test_unsupported_pulse_pipelines_reset_capture_and_require_a_new_full_window(): void
    {
        $coverage = $this->beginCapture();

        foreach ([
            ['pulse.ingest.driver' => 'redis'],
            ['pulse.ingest.driver' => 'custom'],
            ['pulse.storage.driver' => 'redis'],
            ['pulse.storage.driver' => 'custom'],
            ['pulse.ingest.driver' => new \stdClass],
            ['pulse.storage.driver' => new \stdClass],
            ['pulse.storage.database.connection' => new \stdClass],
            ['pulse.storage.database.connection' => []],
            ['pulse.storage.database.connection' => 1],
            ['pulse.storage.database.connection' => ''],
        ] as $configuration) {
            $this->assertNotNull($coverage->build(now()->getTimestamp() - 600));
            config($configuration);

            $this->assertNull($coverage->build(now()->getTimestamp() - 600));
            $this->assertSame(0, $this->app['db']->table('monitor_agent_state')->where('key', 'like', 'coverage_%')->count());

            config([
                'pulse.ingest.driver' => 'storage',
                'pulse.storage.driver' => 'database',
                'pulse.storage.database.connection' => null,
            ]);
            $this->assertNull($coverage->build(now()->getTimestamp() - 600));
            $this->advanceCapture($coverage, 11);
        }

        $this->assertNotNull($coverage->build(now()->getTimestamp() - 600));
    }

    public function test_pulse_pipeline_callbacks_are_never_executed_or_attested(): void
    {
        $called = false;
        $callback = function () use (&$called) {
            $called = true;

            return 'storage';
        };
        config(['pulse.ingest.driver' => $callback]);

        $this->assertNull($this->beginCapture()->build(now()->getTimestamp() - 600));
        $this->assertFalse($called);

        config([
            'pulse.ingest.driver' => 'storage',
            'pulse.storage.driver' => 'database',
            'pulse.storage.database.connection' => $callback,
        ]);

        $this->assertNull(app(Coverage::class)->build(now()->getTimestamp() - 600));
        $this->assertFalse($called);
    }

    public function test_named_pulse_database_is_exported_while_capture_state_stays_in_the_app_database(): void
    {
        config([
            'database.connections.pulse_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'pulse.storage.driver' => 'database',
            'pulse.ingest.driver' => 'storage',
            'pulse.storage.database.connection' => 'pulse_fixture',
        ]);
        Schema::connection('pulse_fixture')->create('pulse_aggregates', function ($table): void {
            $table->unsignedInteger('bucket');
            $table->unsignedInteger('period');
            $table->string('type');
            $table->string('key');
            $table->string('aggregate');
            $table->double('value');
            $table->unsignedInteger('count')->nullable();
        });
        $this->beginCapture();
        $start = now()->setTime(10, 5)->getTimestamp();
        app(Watermark::class)->set($start);
        $this->app['db']->connection('pulse_fixture')->table('pulse_aggregates')->insert([
            'bucket' => $start, 'period' => 60, 'type' => 'request', 'key' => 'named-db-route',
            'aggregate' => 'count', 'value' => 7, 'count' => null,
        ]);
        Http::fake(['hub.test/*' => Http::response(['status' => 'ok'])]);

        $this->artisan('monitor-agent:export')->assertExitCode(0);

        Http::assertSent(fn ($request) => collect($request['buckets'])->contains(
            fn ($bucket) => $bucket['type'] === 'request' && $bucket['key'] === 'named-db-route' && $bucket['count'] === 7,
        ));
        Http::assertSent(fn ($request) => isset($request['coverage']) && $request['coverage']['from'] === $start);
        $this->assertSame(0, $this->app['db']->table('pulse_aggregates')->count());
        $this->assertFalse(Schema::connection('pulse_fixture')->hasTable('monitor_agent_state'));
        $this->assertSame(now()->setTime(10, 10)->getTimestamp(), app(Watermark::class)->get());
        $this->app['db']->disconnect('pulse_fixture');
    }

    public function test_disabled_provider_invalidates_capture_even_when_no_export_runs(): void
    {
        $coverage = $this->beginCapture();
        $this->assertNotNull($coverage->build(now()->getTimestamp() - 600));

        config(['monitor-agent.enabled' => false]);
        (new MonitorAgentServiceProvider($this->app))->boot();

        $this->assertSame(0, $this->app['db']->table('monitor_agent_state')->where('key', 'like', 'coverage_%')->count());
        config(['monitor-agent.enabled' => true]);
        $this->assertNull($coverage->build(now()->getTimestamp() - 600));
        $this->advanceCapture($coverage, 11);
        $this->assertSame(now()->setTime(10, 15)->getTimestamp(), $coverage->build(now()->getTimestamp() - 600)['from']);
    }

    public function test_an_unobserved_capture_gap_cannot_attest_backlog_inside_the_trim_horizon(): void
    {
        $coverage = $this->beginCapture();
        $this->assertNotNull($coverage->build(now()->getTimestamp() - 600));
        $readFrom = now()->setTime(10, 10)->getTimestamp();

        $this->travel(601)->seconds();

        $this->assertNull($coverage->build($readFrom));
        $this->assertSame(now()->setTime(10, 25)->getTimestamp(), (int) $this->app['db']->table('monitor_agent_state')->where('key', 'coverage_capture_from')->value('value'));
    }

    public function test_disabled_provider_database_or_logger_failures_never_break_app_startup(): void
    {
        config(['monitor-agent.enabled' => false]);
        $this->mock(Coverage::class)->shouldReceive('reset')->once()->andThrow(new \RuntimeException('credential detail'));
        Log::shouldReceive('warning')
            ->once()->with('Monitor agent capture state invalidation failed.')
            ->andThrow(new \RuntimeException('logger unavailable'));

        (new MonitorAgentServiceProvider($this->app))->boot();

        $this->assertTrue(true);
    }

    public function test_config_callbacks_are_never_executed_or_attested(): void
    {
        $called = false;
        config(['pulse.recorders' => [
            Recorders\Exceptions::class => ['ignore' => function () use (&$called) {
                $called = true;
            }],
            ScheduledTasks::class => [],
        ]]);
        $result = $this->beginCapture()->build(now()->getTimestamp() - 600);

        $this->assertFalse($called);
        $this->assertNotContains('exception', $result['types']);
    }

    public function test_invalid_numeric_bounds_and_open_intervals_are_never_attested(): void
    {
        $coverage = $this->beginCapture();

        $this->assertNull($coverage->build(-1));
        $this->assertNull($coverage->build(PHP_INT_MAX));
        $this->assertNull($coverage->build(now()->getTimestamp() + 1));
        $this->assertNull($coverage->build(now()->getTimestamp() - 600, -300));
        $this->assertNull($coverage->build(now()->getTimestamp() - 600, now()->getTimestamp() - 1));
        $this->assertNull($coverage->build(now()->setTime(10, 10)->getTimestamp()));
        $this->travelTo(now()->setDate(2101, 1, 1));
        $this->assertNull($coverage->build(0));
    }
}
