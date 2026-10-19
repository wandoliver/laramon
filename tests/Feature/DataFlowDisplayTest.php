<?php

namespace Tests\Feature;

use App\Livewire\Alerts;
use App\Livewire\FleetOverview;
use App\Livewire\InstanceDetail;
use App\Models\AlertRule;
use App\Models\Instance;
use App\Models\MetricBucket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DataFlowDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(12, 5));
        $this->user = User::factory()->create();
    }

    private function healthyAttributes(): array
    {
        $through = (int) (floor((now()->getTimestamp() - 60) / 300) * 300);

        return [
            'last_heartbeat_at' => now(),
            'last_ingest_at' => now(),
            'meta' => ['data_flow' => [
                'version' => 1,
                'covered_from' => $through - 90000,
                'covered_through' => $through,
                'confirmed_at' => now()->getTimestamp(),
                'clock_skew_seconds' => 0,
                'types' => ['exception', 'job:failed', 'request'],
            ]],
        ];
    }

    public function test_a_fresh_heartbeat_without_coverage_does_not_show_healthy_or_verified_zeroes(): void
    {
        Instance::factory()->create(['last_heartbeat_at' => now()]);

        Livewire::actingAs($this->user)->test(FleetOverview::class)
            ->assertViewHas('cards', fn ($cards) => $cards->sole()['health'] === 'unknown'
                && ! $cards->sole()['exceptions_verified'] && ! $cards->sole()['requests_verified'])
            ->assertSee('Heartbeat')
            ->assertSee('Fresh')
            ->assertSee('Accepted export')
            ->assertSee('Completeness')
            ->assertSee('Unknown')
            ->assertSee('unavailable')
            ->assertSee('Missing data cannot confirm recovery.')
            ->assertDontSee('title="Healthy"', false);
    }

    public function test_fresh_exports_without_completeness_are_unverified(): void
    {
        Instance::factory()->create(['last_heartbeat_at' => now(), 'last_ingest_at' => now()]);

        Livewire::actingAs($this->user)->test(FleetOverview::class)
            ->assertViewHas('cards', fn ($cards) => $cards->sole()['health'] === 'unknown'
                && $cards->sole()['data_flow']['export']['state'] === 'healthy'
                && $cards->sole()['data_flow']['coverage']['state'] === 'unknown')
            ->assertSee('Partial history')
            ->assertDontSee('title="Healthy"', false);
    }

    public function test_stale_exports_remain_non_green_with_a_fresh_heartbeat(): void
    {
        Instance::factory()->create(array_merge($this->healthyAttributes(), ['last_ingest_at' => now()->subMinutes(20)]));

        Livewire::actingAs($this->user)->test(FleetOverview::class)
            ->assertViewHas('cards', fn ($cards) => $cards->sole()['health'] === 'degraded'
                && ! $cards->sole()['exceptions_verified'])
            ->assertSee('Stale')
            ->assertDontSee('title="Healthy"', false);
    }

    public function test_confirmed_fresh_complete_history_can_show_verified_totals(): void
    {
        Instance::factory()->create($this->healthyAttributes());

        Livewire::actingAs($this->user)->test(FleetOverview::class)
            ->assertViewHas('cards', fn ($cards) => $cards->sole()['health'] === 'healthy'
                && $cards->sole()['requests_verified'] && $cards->sole()['exceptions_verified']
                && $cards->sole()['failed_jobs_verified'])
            ->assertSee('Complete')
            ->assertSee('title="Healthy"', false)
            ->assertDontSee('Missing data cannot confirm recovery.')
            ->assertDontSee('Partial history');
    }

    public function test_an_undeclared_metric_type_stays_partial_despite_recent_confirmed_flow(): void
    {
        $attributes = $this->healthyAttributes();
        $attributes['meta']['data_flow']['types'] = ['request'];
        Instance::factory()->create($attributes);

        Livewire::actingAs($this->user)->test(FleetOverview::class)
            ->assertViewHas('cards', fn ($cards) => $cards->sole()['requests_verified']
                && ! $cards->sole()['exceptions_verified'] && ! $cards->sole()['failed_jobs_verified'])
            ->assertSee('Partial history')
            ->assertSee('unavailable');
    }

    public function test_a_recorded_gap_prevents_verified_historical_totals(): void
    {
        $instance = Instance::factory()->create($this->healthyAttributes());
        MetricBucket::query()->create([
            'instance_id' => $instance->id,
            'type' => 'counter:agent.export_gap_minutes',
            'key' => 'agent.export_gap_minutes',
            'key_hash' => MetricBucket::hashKey('agent.export_gap_minutes'),
            'bucket_start' => (int) (floor(now()->subHour()->getTimestamp() / 300) * 300),
            'bucket_seconds' => 300,
            'count' => 10,
        ]);

        Livewire::actingAs($this->user)->test(FleetOverview::class)
            ->assertViewHas('cards', fn ($cards) => ! $cards->sole()['requests_verified']
                && ! $cards->sole()['exceptions_verified'])
            ->assertSee('data gap')
            ->assertSee('Partial history');
    }

    public function test_detail_refresh_reads_new_metadata_and_retains_a_clear_history_warning(): void
    {
        $instance = Instance::factory()->create();
        $component = Livewire::actingAs($this->user)->test(InstanceDetail::class, ['instance' => $instance])
            ->assertViewHas('dataFlow', fn ($flow) => $flow['state'] === 'unknown')
            ->assertSee('Missing or stale data cannot confirm recovery.')
            ->assertDontSee('No open exceptions. 🎉');

        $instance->forceFill($this->healthyAttributes())->save();

        $component->call('$refresh')
            ->assertViewHas('dataFlow', fn ($flow) => $flow['state'] === 'healthy')
            ->assertSee('Complete')
            ->assertDontSee('Missing or stale data cannot confirm recovery.')
            ->assertSee('Unconfirmed periods may contain gaps');
    }

    public function test_instance_strings_are_escaped_and_agent_supplied_status_text_is_ignored(): void
    {
        $name = '<script id="name-payload">alert(1)</script>';
        $environment = '<img src=x onerror=alert(2)>';
        $attributes = $this->healthyAttributes();
        $attributes['name'] = $name;
        $attributes['environment'] = $environment;
        $attributes['meta']['data_flow']['reason'] = '<script id="reason-payload">alert(3)</script>';
        $instance = Instance::factory()->create($attributes);

        foreach (['/', '/instances/'.$instance->slug] as $url) {
            $this->actingAs($this->user)->get($url)->assertOk()
                ->assertSee(e($name), false)
                ->assertSee(e($environment), false)
                ->assertDontSee($name, false)
                ->assertDontSee($environment, false)
                ->assertDontSee('reason-payload', false);
        }
    }

    public function test_data_flow_rule_can_be_created_with_fixed_safe_condition(): void
    {
        Livewire::actingAs($this->user)->test(Alerts::class)
            ->assertSee('data flow (missing, stale or incomplete)')
            ->set('name', 'Data flow unavailable')
            ->set('metric_type', AlertRule::TYPE_DATA_FLOW)
            ->set('threshold', '999')
            ->set('operator', '<')
            ->set('key', 'ignored')
            ->set('webhook_url', 'https://teams.test/webhook')
            ->call('save')
            ->assertHasNoErrors();

        $rule = AlertRule::query()->sole();
        $this->assertSame(AlertRule::TYPE_DATA_FLOW, $rule->metric_type);
        $this->assertNull($rule->key);
        $this->assertSame('>', $rule->operator);
        $this->assertSame(0.0, $rule->threshold);
    }

    public function test_queue_values_require_an_explicit_receipt_in_the_current_heartbeat(): void
    {
        $attributes = $this->healthyAttributes();
        $attributes['meta']['queue'] = ['pending' => 0, 'failed' => 0];
        $attributes['meta']['queue_available'] = true;
        $attributes['meta']['queue_reported_at'] = now()->subHour()->getTimestamp();
        $instance = Instance::factory()->create($attributes);
        $component = Livewire::actingAs($this->user)->test(FleetOverview::class)
            ->assertViewHas('cards', fn ($cards) => ! $cards->sole()['queue_verified']);

        $meta = $instance->meta;
        $meta['queue_reported_at'] = now()->getTimestamp();
        $instance->update(['meta' => $meta]);
        $component->call('$refresh')->assertViewHas('cards', fn ($cards) => $cards->sole()['queue_verified']);
        $meta['queue_available'] = false;
        $instance->update(['meta' => $meta]);
        $component->call('$refresh')->assertViewHas('cards', fn ($cards) => ! $cards->sole()['queue_verified']);
    }

    public function test_verified_totals_stop_before_the_unconfirmed_tail(): void
    {
        $instance = Instance::factory()->create($this->healthyAttributes());
        $through = $instance->meta['data_flow']['covered_through'];
        MetricBucket::query()->create([
            'instance_id' => $instance->id, 'type' => 'exception', 'key' => 'unconfirmed-tail',
            'key_hash' => MetricBucket::hashKey('unconfirmed-tail'), 'bucket_seconds' => 300,
            'bucket_start' => $through, 'count' => 5,
        ]);
        Livewire::actingAs($this->user)->test(FleetOverview::class)
            ->assertViewHas('cards', fn ($cards) => $cards->sole()['totals_through'] === $through
                && $cards->sole()['exception_count'] === 0)
            ->assertSee('24-hour totals through');
    }
}
