<?php

namespace Tests\Feature;

use App\Livewire\InstanceDetail;
use App\Models\Instance;
use App\Models\MetricBucket;
use App\Models\User;
use App\Services\ScheduledTaskQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Livewire;
use Tests\TestCase;

class ScheduledTasksTest extends TestCase
{
    use RefreshDatabase;

    private int $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->to = now()->getTimestamp();
        $this->to -= $this->to % 3600;
    }

    public function test_failure_only_tasks_render_with_zero_successes_and_no_runtime(): void
    {
        $instance = Instance::factory()->create();
        $this->bucket($instance, 'scheduled_task:failed', 'office365-subscriptions', count: 3);

        $row = $this->tasks($instance)->first();

        $this->assertSame('office365-subscriptions', $row->key);
        $this->assertSame(0, $row->successes);
        $this->assertSame(3, $row->failures);
        $this->assertNull($row->avg);
        $this->assertNull($row->max);
        $this->assertNull($row->last_success);
        $this->assertSame($this->to - 300, $row->last_failure);
        $this->assertSame('failed', $row->status);

        $this->actingAs(User::factory()->create())
            ->get('/instances/'.$instance->slug)
            ->assertOk()
            ->assertSee('office365-subscriptions')
            ->assertSee('Failed')
            ->assertSee('Successes')
            ->assertSee('Last failure bucket');
    }

    public function test_outcomes_are_aggregated_separately_and_runtime_ignores_runs_without_duration(): void
    {
        $instance = Instance::factory()->create();
        $this->bucket($instance, 'scheduled_task', 'mixed', count: 2, offset: 900, sum: 6001, max: 4000);
        $this->bucket($instance, 'scheduled_task', 'mixed', count: 3, offset: 600);
        $this->bucket($instance, 'scheduled_task:failed', 'mixed', count: 4, sum: 90000, max: 50000);

        $row = $this->tasks($instance)->first();

        $this->assertSame(5, $row->successes);
        $this->assertSame(4, $row->failures);
        $this->assertSame(3000.5, $row->avg);
        $this->assertSame(4000.0, $row->max);
        $this->assertSame($this->to - 600, $row->last_success);
        $this->assertSame($this->to - 300, $row->last_failure);
        $this->assertSame('failed', $row->status);
    }

    public function test_successes_without_duration_have_no_fabricated_runtime(): void
    {
        $instance = Instance::factory()->create();
        $this->bucket($instance, 'scheduled_task', 'background', count: 2);

        $row = $this->tasks($instance)->first();

        $this->assertSame(2, $row->successes);
        $this->assertSame(0, $row->failures);
        $this->assertNull($row->avg);
        $this->assertNull($row->max);
        $this->assertSame('succeeded', $row->status);
    }

    public function test_timed_sample_counts_preserve_runtime_when_a_bucket_also_has_background_successes(): void
    {
        $instance = Instance::factory()->create();
        $other = Instance::factory()->create();
        $this->bucket($instance, 'scheduled_task', 'mixed', count: 3, sum: 6001, max: 4000);
        $this->bucket($instance, 'scheduled_task:runtime', 'mixed', count: 2, sum: 6001);
        $this->bucket($other, 'scheduled_task:runtime', 'mixed', count: 50, sum: 90000);
        // A bucket from an old agent has no separate timing count.
        $this->bucket($instance, 'scheduled_task', 'mixed', count: 1, offset: 600, sum: 2999, max: 2999);

        $row = $this->tasks($instance)->first();
        $this->assertSame(4, $row->successes);
        $this->assertSame(3000.0, $row->avg);
        $this->assertSame(4000.0, $row->max);
        $this->assertSame(1, $this->tasks($instance)->total());
    }

    public function test_latest_outcome_distinguishes_recovery_and_ambiguous_bucket_order(): void
    {
        $instance = Instance::factory()->create();
        $this->bucket($instance, 'scheduled_task:failed', 'recovered', offset: 600);
        $this->bucket($instance, 'scheduled_task', 'recovered');
        $this->bucket($instance, 'scheduled_task', 'ambiguous');
        $this->bucket($instance, 'scheduled_task:failed', 'ambiguous');
        $this->bucket($instance, 'scheduled_task', 'clean');

        $rows = $this->tasks($instance)->keyBy('key');

        $this->assertSame(['ambiguous', 'recovered', 'clean'], $rows->keys()->all());
        $this->assertSame('succeeded', $rows['recovered']->status);
        $this->assertSame('failure_recorded', $rows['ambiguous']->status);

        $this->actingAs(User::factory()->create())
            ->get('/instances/'.$instance->slug)
            ->assertOk()
            ->assertSee('Failure recorded')
            ->assertSee('Outcomes in the same bucket have no known order.');
    }

    public function test_failure_tasks_are_prioritized_and_all_tasks_remain_accessible_beyond_fifty(): void
    {
        $instance = Instance::factory()->create();

        for ($i = 0; $i < 60; $i++) {
            $this->bucket($instance, 'scheduled_task', sprintf('healthy-%03d', $i), count: 100);
        }

        $this->bucket($instance, 'scheduled_task:failed', 'rare-failure');

        $first = $this->tasks($instance);
        $last = $this->tasks($instance, page: 3);

        $this->assertSame(61, $first->total());
        $this->assertSame(25, $first->count());
        $this->assertSame('rare-failure', $first->first()->key);
        $this->assertSame(11, $last->count());
        $this->assertSame('healthy-059', $last->last()->key);

        Livewire::actingAs(User::factory()->create())
            ->test(InstanceDetail::class, ['instance' => $instance])
            ->assertSee('rare-failure')
            ->assertDontSee('healthy-059')
            ->call('setPage', 3, 'tasksPage')
            ->assertSee('healthy-059')
            ->assertDontSee('rare-failure')
            ->set('range', '7d')
            ->assertSet('paginators.tasksPage', 1);
    }

    public function test_outcomes_are_scoped_to_instance_time_range_metric_type_and_resolution(): void
    {
        $instance = Instance::factory()->create();
        $other = Instance::factory()->create();
        $this->bucket($instance, 'scheduled_task:failed', 'included');
        $this->bucket($other, 'scheduled_task:failed', 'other-instance');
        $this->bucket($instance, 'job:failed', 'wrong-type');
        $this->bucket($instance, 'scheduled_task:failed', 'too-old', offset: 90000);
        $this->bucket($instance, 'scheduled_task:failed', 'future', offset: -300);
        $this->bucket($instance, 'scheduled_task:failed', 'hourly', offset: 3600, resolution: 3600);
        $this->bucket($instance, 'scheduled_task', 'no-recorded-outcome', count: 0);

        $this->assertSame(['included'], $this->tasks($instance)->pluck('key')->all());

        $hourly = app(ScheduledTaskQuery::class)->paginate($instance->id, $this->to - 604800, $this->to);

        $this->assertSame(['hourly'], $hourly->pluck('key')->all());
        $this->assertSame(3600, $hourly->first()->resolution);
    }

    public function test_task_keys_are_rendered_as_escaped_text(): void
    {
        $instance = Instance::factory()->create();
        $key = '<script>alert("scheduler")</script>';
        $this->bucket($instance, 'scheduled_task:failed', $key);

        $this->actingAs(User::factory()->create())
            ->get('/instances/'.$instance->slug)
            ->assertOk()
            ->assertSee($key)
            ->assertDontSee($key, escape: false);
    }

    public function test_empty_range_keeps_the_empty_state(): void
    {
        $instance = Instance::factory()->create();

        $this->assertSame(0, $this->tasks($instance)->total());

        $this->actingAs(User::factory()->create())
            ->get('/instances/'.$instance->slug)
            ->assertOk()
            ->assertSee('No scheduled task runs in this range.');
    }

    private function tasks(Instance $instance, int $page = 1): LengthAwarePaginator
    {
        return app(ScheduledTaskQuery::class)->paginate($instance->id, $this->to - 86400, $this->to, page: $page);
    }

    private function bucket(Instance $instance, string $type, string $key, int $count = 1, int $offset = 300, ?float $sum = null, ?float $max = null, int $resolution = 300): void
    {
        MetricBucket::query()->create([
            'instance_id' => $instance->id,
            'type' => $type,
            'key' => $key,
            'key_hash' => MetricBucket::hashKey($key),
            'bucket_start' => $this->to - $offset,
            'bucket_seconds' => $resolution,
            'count' => $count,
            'sum' => $sum,
            'max' => $max,
        ]);
    }
}
