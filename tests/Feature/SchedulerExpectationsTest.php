<?php

namespace Tests\Feature;

use App\Livewire\InstanceDetail;
use App\Models\Instance;
use App\Models\MetricBucket;
use App\Models\User;
use App\Services\SchedulerExpectations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

class SchedulerExpectationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-19 12:20:00', 'UTC'));
    }

    public function test_missing_or_empty_manifests_leave_the_current_panel_absent(): void
    {
        foreach ([[], ['scheduler' => null], ['scheduler' => ['tasks' => []]]] as $meta) {
            $instance = Instance::factory()->create(['meta' => $meta]);

            $this->assertTrue($this->current($instance)->isEmpty());
            $this->actingAs(User::factory()->create())
                ->get('/instances/'.$instance->slug)
                ->assertOk()
                ->assertDontSee('Critical scheduler tasks');
        }
    }

    public function test_a_never_run_task_becomes_overdue_after_a_settled_deadline(): void
    {
        $instance = $this->createInstance();
        $task = $this->current($instance)->first();

        $this->assertSame('overdue', $task->status);
        $this->assertSame($this->timestamp('12:00'), $task->due);
        $this->assertSame($this->timestamp('12:15'), $task->deadline);
        $this->assertSame($this->timestamp('13:15'), $task->next_deadline);
        $this->assertNull($task->last_success);

        $this->actingAs(User::factory()->create())
            ->get('/instances/'.$instance->slug)
            ->assertOk()
            ->assertSee('Critical scheduler tasks')
            ->assertSee('Overdue');
    }

    public function test_no_retrospective_deadline_is_assumed_before_observation_began(): void
    {
        $instance = $this->createInstance(['observed_since' => $this->timestamp('12:10')]);

        $row = $this->current($instance)->first();

        $this->assertSame('awaiting_first_run', $row->status);
        $this->assertNull($row->due);
        $this->assertSame($this->timestamp('13:15'), $row->next_deadline);
    }

    public function test_next_deadline_includes_a_first_due_run_still_inside_its_grace_period(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:05:00', 'UTC'));
        $instance = $this->createInstance(['observed_since' => $this->timestamp('12:00')]);
        $row = $this->current($instance)->first();

        $this->assertSame('awaiting_first_run', $row->status);
        $this->assertSame($this->timestamp('12:15'), $row->next_deadline);
    }

    public function test_overdue_waits_for_an_export_after_the_deadline(): void
    {
        $instance = $this->createInstance();
        $meta = $instance->meta;
        $meta['data_flow']['covered_through'] = $this->timestamp('12:10');
        $instance->forceFill(['last_ingest_at' => Carbon::createFromTimestamp($this->timestamp('12:14')), 'meta' => $meta])->save();

        $this->assertSame('awaiting_data', $this->current($instance)->first()->status);
    }

    public function test_a_heartbeat_watermark_cannot_replace_server_confirmed_completeness(): void
    {
        foreach (['missing', null, $this->timestamp('12:14'), now()->addSecond()->getTimestamp(), (string) now()->getTimestamp()] as $watermark) {
            $instance = $this->createInstance();
            $meta = $instance->meta;
            unset($meta['data_flow']);

            if ($watermark === 'missing') {
                unset($meta['scheduler']['exported_through']);
            } else {
                $meta['scheduler']['exported_through'] = $watermark;
            }

            $instance->forceFill(['last_ingest_at' => now(), 'meta' => $meta])->save();

            $this->assertSame('unknown', $this->current($instance)->first()->status);

            $this->completion($instance, 'critical-task', 'success', $this->timestamp('12:05'));

            $this->assertSame('unknown', $this->current($instance)->first()->status);
        }
    }

    public function test_server_confirmed_completeness_does_not_depend_on_a_heartbeat_watermark(): void
    {
        $instance = $this->createInstance();
        $meta = $instance->meta;
        unset($meta['scheduler']['exported_through']);
        $instance->forceFill(['meta' => $meta])->save();
        $this->completion($instance, 'critical-task', 'success', $this->timestamp('12:05'));

        $this->assertSame('succeeded', $this->current($instance)->first()->status);
    }

    public function test_stale_or_unavailable_monitoring_and_clock_skew_leave_state_unknown(): void
    {
        foreach (['heartbeat', 'ingest', 'manifest', 'unavailable', 'version', 'ingest_skew', 'heartbeat_skew', 'future_heartbeat', 'future_manifest'] as $case) {
            $instance = $this->createInstance();
            $meta = $instance->meta;

            match ($case) {
                'heartbeat' => $instance->last_heartbeat_at = now()->subSeconds(121),
                'ingest' => $instance->last_ingest_at = now()->subSeconds(901),
                'manifest' => $meta['scheduler']['reported_at'] = now()->subSeconds(121)->getTimestamp(),
                'unavailable' => $meta['scheduler']['available'] = false,
                'version' => $meta['scheduler']['version'] = 2,
                'ingest_skew' => $meta['clock_skew_seconds'] = -61,
                'heartbeat_skew' => $meta['scheduler']['clock_skew_seconds'] = 61,
                'future_heartbeat' => $instance->last_heartbeat_at = now()->addSecond(),
                'future_manifest' => $meta['scheduler']['reported_at'] = now()->addSecond()->getTimestamp(),
            };

            $instance->meta = $meta;
            $instance->save();
            $this->completion($instance, 'critical-task', 'success', $this->timestamp('12:05'));

            $this->assertSame('unknown', $this->current($instance)->first()->status, $case);
        }
    }

    public function test_exact_completion_before_due_in_the_same_bucket_cannot_imply_success(): void
    {
        $this->travelTo(now()->addMinutes(5));
        $instance = $this->createInstance(['expression' => '2 * * * *']);
        $this->completion($instance, 'critical-task', 'success', $this->timestamp('12:01'));
        $row = $this->current($instance)->first();

        $this->assertSame($this->timestamp('12:02'), $row->due);
        $this->assertSame('overdue', $row->status);

        $this->completion($instance, 'critical-task', 'success', $this->timestamp('12:03'));

        $this->assertSame('succeeded', $this->current($instance)->first()->status);
    }

    public function test_latest_completion_after_due_determines_failure_or_recovery(): void
    {
        $instance = $this->createInstance();
        $this->completion($instance, 'critical-task', 'success', $this->timestamp('12:03'));
        $this->completion($instance, 'critical-task', 'failure', $this->timestamp('12:04'));
        $this->assertSame('failed', $this->current($instance)->first()->status);

        $this->completion($instance, 'critical-task', 'success', $this->timestamp('12:04'));
        $this->assertSame('failed', $this->current($instance)->first()->status);

        $this->completion($instance, 'critical-task', 'success', $this->timestamp('12:05'));
        $this->assertSame('succeeded', $this->current($instance)->first()->status);
    }

    public function test_current_completions_are_independent_of_the_selected_historical_range(): void
    {
        $instance = $this->createInstance(['expression' => '0 0 * * *', 'observed_since' => $this->timestamp('00:00')]);
        $this->completion($instance, 'critical-task', 'success', $this->timestamp('00:05'), resolution: 3600);

        Livewire::actingAs(User::factory()->create())
            ->test(InstanceDetail::class, ['instance' => $instance])
            ->set('range', '1h')
            ->assertViewHas('schedulerExpectations', fn ($rows) => $rows->first()->status === 'succeeded')
            ->assertSee('independent of the selected historical range')
            ->assertSee('No scheduled task runs in this range.');
    }

    public function test_a_known_export_gap_keeps_a_daily_task_unknown_until_the_next_expected_run(): void
    {
        $baseline = $this->timestamp('00:00');
        $instance = $this->createInstance(['expression' => '0 0 * * *', 'observed_since' => $baseline]);
        $this->exportGap($instance, $this->timestamp('01:00'));

        $row = $this->current($instance)->first();
        $this->assertSame('unknown', $row->status);
        $this->assertStringContainsString('data since the expected run was lost', $row->reason);

        $this->travelTo(now()->addDay());
        $meta = $instance->meta;
        $meta['scheduler']['reported_at'] = now()->getTimestamp();
        $meta['scheduler']['exported_through'] = now()->subMinute()->getTimestamp();
        $meta['data_flow'] = $this->coverageProof();
        $instance->forceFill(['meta' => $meta, 'last_heartbeat_at' => now(), 'last_ingest_at' => now()->subMinute()])->save();

        $this->assertSame('overdue', $this->current($instance)->first()->status);
    }

    public function test_an_exact_success_cannot_confirm_recovery_when_later_outcomes_may_be_missing_in_a_gap(): void
    {
        $instance = $this->createInstance(['expression' => '0 0 * * *', 'observed_since' => $this->timestamp('00:00')]);
        $this->exportGap($instance, $this->timestamp('01:00'), resolution: 3600);
        $this->completion($instance, 'critical-task', 'success', $this->timestamp('00:05'), resolution: 3600);

        $this->assertSame('unknown', $this->current($instance)->first()->status);
    }

    public function test_export_gap_evidence_is_scoped_and_zero_or_older_buckets_do_not_hide_overdue(): void
    {
        $instance = $this->createInstance();
        $other = $this->createInstance();
        $this->exportGap($other, $this->timestamp('12:05'));
        $this->exportGap($instance, $this->timestamp('11:55'));
        $this->exportGap($instance, $this->timestamp('12:05'), count: 0);

        $this->assertSame('overdue', $this->current($instance)->first()->status);
        $this->assertSame('unknown', $this->current($other)->first()->status);
    }

    public function test_other_instances_and_other_task_keys_cannot_satisfy_an_expectation(): void
    {
        $instance = $this->createInstance();
        $other = $this->createInstance();
        $this->completion($other, 'critical-task', 'success', $this->timestamp('12:05'));
        $this->completion($instance, 'different-task', 'success', $this->timestamp('12:05'));

        $this->assertSame('overdue', $this->current($instance)->first()->status);
        $this->assertSame('succeeded', $this->current($other)->first()->status);
    }

    public function test_completion_timestamps_must_be_integral_nonfuture_and_inside_their_bucket(): void
    {
        foreach (['fractional', 'before_bucket', 'after_bucket', 'future'] as $case) {
            $instance = $this->createInstance();
            $timestamp = $case === 'future' ? now()->getTimestamp() + 60 : $this->timestamp('12:05');
            $start = $timestamp - ($timestamp % 300);
            $max = match ($case) {
                'fractional' => $timestamp + 0.5,
                'before_bucket' => $start - 1,
                'after_bucket' => $start + 300,
                default => $timestamp,
            };

            $this->completion($instance, 'critical-task', 'success', $timestamp, max: $max);

            $this->assertSame('overdue', $this->current($instance)->first()->status, $case);
        }
    }

    public function test_declared_timezone_controls_due_dates_across_daylight_saving_time(): void
    {
        // New York is on daylight time in October, then standard time in November.
        foreach (['2026-10-19 14:20:00' => '2026-10-19 13:00:00', '2026-11-02 15:20:00' => '2026-11-02 14:00:00'] as $now => $due) {
            $this->travelTo(Carbon::parse($now, 'UTC'));
            $instance = $this->createInstance([
                'expression' => '0 9 * * *',
                'timezone' => 'America/New_York',
                'observed_since' => now()->subDay()->getTimestamp(),
            ]);

            $this->assertSame(Carbon::parse($due, 'UTC')->getTimestamp(), $this->current($instance)->first()->due);
            $this->assertSame('overdue', $this->current($instance)->first()->status);
        }
    }

    public function test_invalid_legacy_schedule_metadata_does_not_break_the_dashboard_or_imply_success(): void
    {
        foreach ([['expression' => 'not cron'], ['expression' => '0 0 31 2 *'], ['timezone' => 'bad-zone'], ['grace_seconds' => 0], ['observed_since' => 'yesterday']] as $override) {
            $instance = $this->createInstance($override);

            $this->assertSame('unknown', $this->current($instance)->first()->status);
        }

        $instance = $this->createInstance(['name' => '<script>alert("task")</script>']);
        $this->assertTrue($this->current($instance)->isEmpty());
    }

    public function test_problem_expectations_precede_successful_tasks(): void
    {
        $instance = $this->createInstance(['name' => 'a-success']);
        $meta = $instance->meta;
        $meta['scheduler']['tasks'][] = array_replace($meta['scheduler']['tasks'][0], ['name' => 'z-overdue']);
        $instance->meta = $meta;
        $instance->save();
        $this->completion($instance, 'a-success', 'success', $this->timestamp('12:05'));

        $this->assertSame(['z-overdue', 'a-success'], $this->current($instance)->pluck('key')->all());
    }

    public function test_missing_stale_or_incomplete_outcome_coverage_cannot_turn_an_old_success_green(): void
    {
        foreach (['missing', 'stale', 'missing_success_type', 'missing_failure_type', 'late_start', 'unconfirmed_tail', 'skew'] as $case) {
            $instance = $this->createInstance();
            $meta = $instance->meta;
            match ($case) {
                'missing' => $meta['data_flow'] = null,
                'stale' => $meta['data_flow']['confirmed_at'] = now()->subMinutes(20)->getTimestamp(),
                'missing_success_type' => $meta['data_flow']['types'] = ['scheduled_task:last_failure'],
                'missing_failure_type' => $meta['data_flow']['types'] = ['scheduled_task:last_success'],
                'late_start' => $meta['data_flow']['covered_from'] = $this->timestamp('12:05'),
                'unconfirmed_tail' => $meta['data_flow']['covered_through'] = $this->timestamp('12:10'),
                'skew' => $meta['data_flow']['clock_skew_seconds'] = 61,
            };
            $instance->forceFill(['meta' => $meta])->save();
            $this->completion($instance, 'critical-task', 'success', $this->timestamp('12:05'));

            $this->assertContains($this->current($instance)->first()->status, ['unknown', 'awaiting_data'], $case);
        }
    }

    public function test_success_in_a_new_unconfirmed_bucket_cannot_confirm_recovery(): void
    {
        $instance = $this->createInstance();
        $this->completion($instance, 'critical-task', 'failure', $this->timestamp('12:05'));
        $this->completion($instance, 'critical-task', 'success', $this->timestamp('12:16'));

        $this->assertSame('awaiting_data', $this->current($instance)->first()->status);
    }

    public function test_an_explicit_failure_is_visible_even_if_completeness_is_unknown(): void
    {
        $instance = $this->createInstance();
        $meta = $instance->meta;
        unset($meta['data_flow']);
        $instance->forceFill(['meta' => $meta])->save();
        $this->completion($instance, 'critical-task', 'failure', $this->timestamp('12:05'));

        $this->assertSame('failed', $this->current($instance)->first()->status);
    }

    public function test_coverage_must_include_the_entire_bucket_containing_a_non_aligned_due_time(): void
    {
        $this->travelTo(now()->addMinutes(5));
        $instance = $this->createInstance(['expression' => '2 * * * *']);
        $meta = $instance->meta;
        $meta['data_flow']['covered_from'] = $this->timestamp('12:05');
        $instance->forceFill(['meta' => $meta])->save();
        $this->completion($instance, 'critical-task', 'success', $this->timestamp('12:06'));

        $this->assertSame('awaiting_data', $this->current($instance)->first()->status);
    }

    private function current(Instance $instance): Collection
    {
        return app(SchedulerExpectations::class)->current($instance);
    }

    private function createInstance(array $task = []): Instance
    {
        return Instance::factory()->create([
            'last_heartbeat_at' => now(),
            'last_ingest_at' => now()->subMinute(),
            'meta' => ['data_flow' => $this->coverageProof(), 'scheduler' => [
                'version' => 1,
                'available' => true,
                'reported_at' => now()->getTimestamp(),
                'exported_through' => now()->subMinute()->getTimestamp(),
                'tasks' => [array_replace([
                    'name' => 'critical-task',
                    'expression' => '0 * * * *',
                    'timezone' => 'UTC',
                    'grace_seconds' => 900,
                    'observed_since' => now()->subHours(2)->getTimestamp(),
                ], $task)],
            ]],
        ]);
    }

    private function coverageProof(): array
    {
        return [
            'version' => 1,
            'covered_from' => (int) (floor(now()->subDays(2)->getTimestamp() / 300) * 300),
            'covered_through' => (int) (floor((now()->getTimestamp() - 60) / 300) * 300),
            'confirmed_at' => now()->getTimestamp(),
            'clock_skew_seconds' => 0,
            'types' => ['scheduled_task:last_success', 'scheduled_task:last_failure'],
        ];
    }

    private function completion(Instance $instance, string $name, string $outcome, int $timestamp, int $resolution = 300, ?float $max = null): void
    {
        MetricBucket::query()->updateOrCreate([
            'instance_id' => $instance->id,
            'type' => 'scheduled_task:last_'.$outcome,
            'key_hash' => MetricBucket::hashKey($name),
            'bucket_start' => $timestamp - ($timestamp % $resolution),
            'bucket_seconds' => $resolution,
        ], [
            'key' => $name,
            'count' => 0,
            'max' => $max ?? $timestamp,
        ]);
    }

    private function timestamp(string $time): int
    {
        return Carbon::parse('2026-10-19 '.$time.':00', 'UTC')->getTimestamp();
    }

    private function exportGap(Instance $instance, int $timestamp, int $count = 60, int $resolution = 300): void
    {
        MetricBucket::query()->create([
            'instance_id' => $instance->id,
            'type' => 'counter:agent.export_gap_minutes',
            'key' => 'agent.export_gap_minutes',
            'key_hash' => MetricBucket::hashKey('agent.export_gap_minutes'),
            'bucket_start' => $timestamp - ($timestamp % $resolution),
            'bucket_seconds' => $resolution,
            'count' => $count,
        ]);
    }
}
