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
                && ! $cards->sole()['exceptions_verified'] && ! $cards->sole()['requests_verified']
                && ! $cards->sole()['history_coverage_available']
                && $cards->sole()['history_confirmed_seconds'] === 0
                && $cards->sole()['history_confirmed_minutes'] === 0
                && $cards->sole()['history_unconfirmed_minutes'] === 1440
                && $cards->sole()['history_coverage_percent'] === 0)
            ->assertSee('Heartbeat')
            ->assertSee('Fresh')
            ->assertSee('Accepted export')
            ->assertSee('Export coverage')
            ->assertSee('Unknown')
            ->assertSee('24-hour history: unverified')
            ->assertDontSee('role="progressbar"', false)
            ->assertSee('24h total unconfirmed')
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
                && $cards->sole()['failed_jobs_verified']
                && $cards->sole()['history_coverage_available']
                && $cards->sole()['history_confirmed_seconds'] === 86400
                && $cards->sole()['history_confirmed_minutes'] === 1440
                && $cards->sole()['history_unconfirmed_minutes'] === 0
                && $cards->sole()['history_coverage_percent'] === 100)
            ->assertSee('24-hour history: 24h of 24h confirmed')
            ->assertSee('aria-valuenow="1440"', false)
            ->assertSee('Confirmed')
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
                && ! $cards->sole()['exceptions_verified'] && ! $cards->sole()['failed_jobs_verified']
                && $cards->sole()['history_coverage_available']
                && $cards->sole()['history_confirmed_seconds'] === 86400
                && $cards->sole()['history_coverage_percent'] === 100)
            ->assertSee('Partial history')
            ->assertSee('Some metric totals remain unverified despite full time coverage.')
            ->assertSee('24h total unconfirmed');
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
                && ! $cards->sole()['exceptions_verified']
                && ! $cards->sole()['history_coverage_available']
                && $cards->sole()['history_confirmed_seconds'] === 0
                && $cards->sole()['history_confirmed_minutes'] === 0
                && $cards->sole()['history_unconfirmed_minutes'] === 1440
                && $cards->sole()['history_coverage_percent'] === 0)
            ->assertSee('24-hour history: unverified')
            ->assertDontSee('role="progressbar"', false)
            ->assertSee('data gap')
            ->assertSee('Partial history');
    }

    public function test_partial_history_shows_the_confirmed_duration_in_the_24_hour_totals_window(): void
    {
        $attributes = $this->healthyAttributes();
        $through = $attributes['meta']['data_flow']['covered_through'];
        $attributes['meta']['data_flow']['covered_from'] = $through - 400 * 60;
        $instance = Instance::factory()->create($attributes);
        MetricBucket::query()->create([
            'instance_id' => $instance->id, 'type' => 'request', 'key' => 'GET /',
            'key_hash' => MetricBucket::hashKey('GET /'), 'bucket_seconds' => 300,
            'bucket_start' => $through - 300, 'count' => 7000,
        ]);

        Livewire::actingAs($this->user)->test(FleetOverview::class)
            ->assertViewHas('cards', fn ($cards) => $cards->sole()['history_coverage_available']
                && $cards->sole()['history_confirmed_seconds'] === 24000
                && $cards->sole()['history_confirmed_minutes'] === 400
                && $cards->sole()['history_unconfirmed_minutes'] === 1040
                && $cards->sole()['history_coverage_percent'] === 27
                && $cards->sole()['totals_through'] === $through
                && ! $cards->sole()['requests_verified'] && ! $cards->sole()['exceptions_verified']
                && ! $cards->sole()['failed_jobs_verified'] && $cards->sole()['request_count'] === 7000
                && $cards->sole()['exception_count'] === 0)
            ->assertSee('Export coverage')
            ->assertSee('Confirmed')
            ->assertSee('24-hour history: 6h 40m of 24h confirmed')
            ->assertSee('17h 20m still unconfirmed.')
            ->assertSee('role="progressbar"', false)
            ->assertSee('aria-valuenow="400"', false)
            ->assertSee('aria-valuemax="1440"', false)
            ->assertSee('observed · 24h unconfirmed')
            ->assertSee('24h failure history unconfirmed')
            ->assertSee('Partial history')
            ->assertSee('24h total unconfirmed')
            ->assertDontSee('Complete');
    }

    public function test_exactly_24_hours_are_fully_confirmed_without_counting_the_open_tail(): void
    {
        $attributes = $this->healthyAttributes();
        $through = $attributes['meta']['data_flow']['covered_through'];
        $attributes['meta']['data_flow']['covered_from'] = $through - 86400;
        Instance::factory()->create($attributes);

        Livewire::actingAs($this->user)->test(FleetOverview::class)
            ->assertViewHas('cards', fn ($cards) => $cards->sole()['history_coverage_available']
                && $cards->sole()['history_confirmed_seconds'] === 86400
                && $cards->sole()['history_confirmed_minutes'] === 1440
                && $cards->sole()['history_unconfirmed_minutes'] === 0
                && $cards->sole()['history_coverage_percent'] === 100
                && $cards->sole()['requests_verified'] && $cards->sole()['exceptions_verified']
                && $cards->sole()['failed_jobs_verified'] && $cards->sole()['totals_through'] === $through)
            ->assertDontSee('Partial history');
    }

    public function test_unusable_current_flow_never_advertises_confirmed_history_progress(): void
    {
        $healthy = $this->healthyAttributes();
        $missing = $healthy;
        unset($missing['meta']['data_flow']);
        $staleExport = array_merge($healthy, ['last_ingest_at' => now()->subMinutes(20)]);
        $staleHeartbeat = array_merge($healthy, ['last_heartbeat_at' => now()->subMinutes(3)]);
        $staleProof = $healthy;
        $staleProof['meta']['data_flow']['covered_through'] -= 1200;
        $pending = $healthy;
        $pending['meta']['data_flow_pending'] = true;
        $clockSkew = $healthy;
        $clockSkew['meta']['data_flow']['clock_skew_seconds'] = 61;
        $warmingUp = $healthy;
        $warmingUp['meta']['data_flow']['covered_from'] = $warmingUp['meta']['data_flow']['covered_through'];
        $future = $healthy;
        $future['meta']['data_flow']['covered_through'] += 600;

        foreach (compact('missing', 'staleExport', 'staleHeartbeat', 'staleProof', 'pending', 'clockSkew', 'warmingUp', 'future') as $attributes) {
            $instance = Instance::factory()->create($attributes);
            Livewire::actingAs($this->user)->test(FleetOverview::class)
                ->assertViewHas('cards', function ($cards) use ($instance) {
                    $card = $cards->firstWhere('instance.id', $instance->id);

                    return ! $card['history_coverage_available']
                        && $card['history_confirmed_seconds'] === 0
                        && $card['history_confirmed_minutes'] === 0
                        && $card['history_unconfirmed_minutes'] === 1440
                        && $card['history_coverage_percent'] === 0
                        && ! $card['requests_verified'] && ! $card['exceptions_verified']
                        && ! $card['failed_jobs_verified'];
                })
                ->assertSee('24-hour history: unverified')
                ->assertDontSee('role="progressbar"', false)
                ->assertDontSee('0h of 24h confirmed');
            $instance->delete();
        }
    }

    public function test_gaps_reported_only_as_sum_or_max_also_prevent_confirmation(): void
    {
        foreach (['sum', 'max'] as $signal) {
            $instance = Instance::factory()->create($this->healthyAttributes());
            MetricBucket::query()->create([
                'instance_id' => $instance->id,
                'type' => 'counter:agent.export_gap_minutes',
                'key' => 'agent.export_gap_minutes',
                'key_hash' => MetricBucket::hashKey('agent.export_gap_minutes'),
                'bucket_start' => $instance->meta['data_flow']['covered_through'] - 300,
                'bucket_seconds' => 300,
                'count' => 0,
                $signal => 10,
            ]);

            Livewire::actingAs($this->user)->test(FleetOverview::class)
                ->assertViewHas('cards', fn ($cards) => ! $cards->sole()['history_coverage_available']
                    && $cards->sole()['history_confirmed_seconds'] === 0
                    && $cards->sole()['history_unconfirmed_minutes'] === 1440
                    && ! $cards->sole()['requests_verified'] && ! $cards->sole()['exceptions_verified']
                    && ! $cards->sole()['failed_jobs_verified'])
                ->assertSee('24-hour history: unverified')
                ->assertDontSee('role="progressbar"', false);
            $instance->delete();
        }
    }

    public function test_an_older_gap_does_not_erase_the_later_confirmed_interval(): void
    {
        $attributes = $this->healthyAttributes();
        $through = $attributes['meta']['data_flow']['covered_through'];
        $attributes['meta']['data_flow']['covered_from'] = $through - 400 * 60;
        $instance = Instance::factory()->create($attributes);
        MetricBucket::query()->create([
            'instance_id' => $instance->id,
            'type' => 'counter:agent.export_gap_minutes',
            'key' => 'agent.export_gap_minutes',
            'key_hash' => MetricBucket::hashKey('agent.export_gap_minutes'),
            'bucket_start' => $attributes['meta']['data_flow']['covered_from'] - 300,
            'bucket_seconds' => 300,
            'count' => 10,
        ]);

        Livewire::actingAs($this->user)->test(FleetOverview::class)
            ->assertViewHas('cards', fn ($cards) => $cards->sole()['history_coverage_available']
                && $cards->sole()['history_confirmed_seconds'] === 24000
                && $cards->sole()['history_confirmed_minutes'] === 400
                && $cards->sole()['history_unconfirmed_minutes'] === 1040
                && $cards->sole()['history_coverage_percent'] === 27
                && ! $cards->sole()['requests_verified'] && ! $cards->sole()['exceptions_verified']
                && ! $cards->sole()['failed_jobs_verified'])
            ->assertSee('24-hour history: 6h 40m of 24h confirmed')
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
            ->assertSee('Confirmed')
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
