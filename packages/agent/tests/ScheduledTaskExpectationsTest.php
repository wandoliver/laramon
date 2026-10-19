<?php

namespace LaraMon\Agent\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use LaraMon\Agent\Export\Watermark;
use LaraMon\Agent\Recorders\ScheduledTasks;
use LaraMon\Agent\ScheduledTaskExpectations;

class ScheduledTaskExpectationsTest extends TestCase
{
    public function test_only_explicit_named_tasks_are_reported_without_executing_filters_or_callbacks(): void
    {
        config(['monitor-agent.expected_scheduled_tasks' => ['critical-sync' => 900]]);
        $schedule = app(Schedule::class);
        $schedule->call(fn () => $this->fail('Task callback must never execute.'))
            ->name('critical-sync')->hourlyAt(17)->timezone('Europe/Rome')
            ->when(fn () => $this->fail('Task filter must never execute.'));
        $schedule->exec('secret-command --password=private')->hourly();
        $schedule->call(fn () => null)->name('unconfigured')->daily();

        $this->assertSame(['version' => 1, 'tasks' => [[
            'name' => 'critical-sync',
            'expression' => '17 * * * *',
            'timezone' => 'Europe/Rome',
            'grace_seconds' => 900,
        ]]], app(ScheduledTaskExpectations::class)->manifest($schedule));
    }

    public function test_disabled_expectations_and_duplicate_names_do_not_claim_a_unique_schedule(): void
    {
        $schedule = app(Schedule::class);
        $schedule->call(fn () => null)->name('duplicate')->hourly();
        $schedule->call(fn () => null)->name('duplicate')->daily();
        $this->assertSame([], app(ScheduledTaskExpectations::class)->manifest($schedule)['tasks']);
        config(['monitor-agent.expected_scheduled_tasks' => ['duplicate' => 900]]);
        $this->assertSame([], app(ScheduledTaskExpectations::class)->manifest($schedule)['tasks']);
    }

    public function test_invalid_configuration_is_excluded_and_manifest_size_is_bounded(): void
    {
        $schedule = app(Schedule::class);
        $configuration = ['unsafe --password=x' => 900, 'too-short' => 30, 'invalid-timezone' => 900];
        foreach (array_keys($configuration) as $name) {
            $schedule->call(fn () => null)->name($name)->hourly()
                ->timezone($name === 'invalid-timezone' ? 'Not/AZone' : 'UTC');
        }
        for ($index = 0; $index < 101; $index++) {
            $name = 'task-'.$index;
            $configuration[$name] = 900;
            $schedule->call(fn () => null)->name($name)->hourly()->timezone('UTC');
        }
        config(['monitor-agent.expected_scheduled_tasks' => $configuration]);
        $manifest = app(ScheduledTaskExpectations::class)->manifest($schedule);
        $this->assertCount(100, $manifest['tasks']);
        $this->assertNotContains('unsafe --password=x', array_column($manifest['tasks'], 'name'));
        $this->assertNotContains('too-short', array_column($manifest['tasks'], 'name'));
        $this->assertNotContains('invalid-timezone', array_column($manifest['tasks'], 'name'));
    }

    public function test_heartbeat_transmits_only_safe_schedule_metadata(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'])]);
        config(['monitor-agent.expected_scheduled_tasks' => ['critical-sync' => 900]]);
        app(Schedule::class)->exec('do-not-run --token=private')->name('critical-sync')->hourly()->timezone('UTC');

        $this->artisan('monitor-agent:heartbeat')->assertExitCode(0);

        Http::assertSent(function ($request) {
            $this->assertSame('critical-sync', $request['scheduler']['tasks'][0]['name']);
            $this->assertSame('0 * * * *', $request['scheduler']['tasks'][0]['expression']);
            $this->assertStringNotContainsString('private', $request->body());
            $this->assertStringNotContainsString('do-not-run', $request->body());
            $this->assertNull($request['scheduler']['exported_through']);

            return true;
        });
    }

    public function test_disabled_capture_or_legacy_export_mapping_does_not_claim_task_coverage(): void
    {
        config(['monitor-agent.expected_scheduled_tasks' => ['critical-sync' => 900]]);
        $schedule = app(Schedule::class);
        $schedule->call(fn () => null)->name('critical-sync')->hourly()->timezone('UTC');
        $manifest = app(ScheduledTaskExpectations::class);

        config(['pulse.enabled' => false]);
        $this->assertSame([], $manifest->manifest($schedule)['tasks']);
        config(['pulse.enabled' => 0]);
        $this->assertSame([], $manifest->manifest($schedule)['tasks']);
        config(['pulse.enabled' => true, 'pulse.recorders.'.ScheduledTasks::class => false]);
        $this->assertSame([], $manifest->manifest($schedule)['tasks']);
        config(['pulse.enabled' => true, 'pulse.recorders.'.ScheduledTasks::class.'.enabled' => false]);
        $this->assertSame([], $manifest->manifest($schedule)['tasks']);
        config(['pulse.recorders.'.ScheduledTasks::class.'.enabled' => true, 'monitor-agent.pulse_types.scheduled_task_last_success' => null]);
        $this->assertSame([], $manifest->manifest($schedule)['tasks']);
    }

    public function test_heartbeat_reports_only_a_confirmed_existing_export_watermark(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'])]);
        $through = now()->subMinutes(10)->getTimestamp();
        app(Watermark::class)->set($through);
        $this->artisan('monitor-agent:heartbeat')->assertExitCode(0);
        Http::assertSent(fn ($request) => $request['scheduler']['exported_through'] === $through);
    }
}
