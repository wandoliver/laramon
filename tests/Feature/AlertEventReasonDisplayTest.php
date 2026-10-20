<?php

namespace Tests\Feature;

use App\Livewire\Alerts;
use App\Livewire\FleetOverview;
use App\Livewire\InstanceDetail;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class AlertEventReasonDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(15, 56));
        $this->user = User::factory()->create();
    }

    public function test_incident_reasons_remain_the_recorded_causes_rather_than_the_current_instance_state(): void
    {
        $instance = Instance::factory()->create($this->transferringAttributes());
        $this->event($instance, [
            'reason_code' => 'heartbeat_stale',
            'last_reason_code' => 'export_overdue',
            'last_reason_at' => now()->subMinutes(2),
        ]);

        Livewire::actingAs($this->user)->test(Alerts::class)
            ->assertSee('Reason: Heartbeat is stale.')
            ->assertSee('Latest issue: The export has not received its final confirmation within five minutes.')
            ->assertSee('20.10. 15:54')
            ->assertDontSee('An export is in progress; waiting for its final confirmation.');
    }

    public function test_legacy_or_unknown_reason_codes_use_a_fixed_fallback_without_rendering_agent_text(): void
    {
        $payload = '<script id="reason-payload">alert(1)</script>';
        foreach ([null, $payload] as $reason) {
            $instance = Instance::factory()->create([
                'meta' => ['data_flow' => ['reason' => $payload]],
            ]);
            $event = $this->event($instance, ['reason_code' => $reason]);

            Livewire::actingAs($this->user)->test(Alerts::class)
                ->assertSee('Reason: Reason was not recorded.')
                ->assertDontSee('reason-payload', false)
                ->assertDontSee('Latest issue:');

            $event->delete();
        }
    }

    public function test_unknown_latest_issue_also_uses_the_fixed_fallback_and_names_are_escaped(): void
    {
        $name = '<script id="instance-payload">alert(2)</script>';
        $payload = '<img id="latest-payload" src=x onerror=alert(3)>';
        $instance = Instance::factory()->create(['name' => $name]);
        $this->event($instance, [
            'reason_code' => 'heartbeat_stale',
            'last_reason_code' => $payload,
            'last_reason_at' => now(),
        ]);

        Livewire::actingAs($this->user)->test(Alerts::class)
            ->assertSee(e($name), false)
            ->assertDontSee($name, false)
            ->assertSee('Latest issue: Reason was not recorded.')
            ->assertDontSee('latest-payload', false);
    }

    public function test_repeated_reason_does_not_show_a_duplicate_latest_issue(): void
    {
        $this->event(Instance::factory()->create(), [
            'reason_code' => 'heartbeat_stale',
            'last_reason_code' => 'heartbeat_stale',
            'last_reason_at' => now(),
        ]);

        Livewire::actingAs($this->user)->test(Alerts::class)
            ->assertSee('Reason: Heartbeat is stale.')
            ->assertDontSee('Latest issue:');
    }

    public function test_non_data_flow_incidents_do_not_show_a_data_flow_reason(): void
    {
        $event = $this->event(Instance::factory()->create());
        $event->rule->update(['metric_type' => 'exception']);

        Livewire::actingAs($this->user)->test(Alerts::class)
            ->assertDontSee('Reason:')
            ->assertDontSee('Latest issue:');
    }

    public function test_editing_a_rules_metric_does_not_hide_recorded_incident_reasons(): void
    {
        $event = $this->event(Instance::factory()->create(), [
            'reason_code' => 'heartbeat_stale',
            'last_reason_code' => 'export_overdue',
            'last_reason_at' => now(),
        ]);
        $event->rule->update(['metric_type' => 'exception']);

        Livewire::actingAs($this->user)->test(Alerts::class)
            ->assertSee('Reason: Heartbeat is stale.')
            ->assertSee('Latest issue: The export has not received its final confirmation within five minutes.');
    }

    public function test_pending_alarm_is_held_during_recovery_and_waits_for_delay_only_during_a_breach(): void
    {
        $event = $this->event(Instance::factory()->create(), [
            'recovery_started_at' => now()->subMinute(),
            'notification_status' => ['triggered' => ['teams' => 'disabled', 'email' => 'waiting']],
        ]);

        $component = Livewire::actingAs($this->user)->test(Alerts::class)
            ->assertSee('Recovery being confirmed')
            ->assertSee('Email: held during recovery')
            ->assertDontSee('Email: waiting for delay');

        $event->update(['recovery_started_at' => null]);
        $component->call('$refresh')
            ->assertSee('Email: waiting for delay')
            ->assertDontSee('Email: held during recovery');
    }

    public function test_normal_transfer_is_neutral_but_does_not_advertise_healthy_or_verified_zeroes(): void
    {
        $attributes = $this->transferringAttributes();
        $attributes['meta']['data_flow']['reason'] = '<script id="transfer-reason">alert(4)</script>';
        $instance = Instance::factory()->create($attributes);

        Livewire::actingAs($this->user)->test(FleetOverview::class)
            ->assertViewHas('cards', fn ($cards) => $cards->sole()['health'] === 'transferring'
                && $cards->sole()['data_flow']['coverage']['state'] === 'transferring'
                && ! $cards->sole()['requests_verified'] && ! $cards->sole()['exceptions_verified']
                && ! $cards->sole()['failed_jobs_verified'] && ! $cards->sole()['history_coverage_available'])
            ->assertSee('Export in progress. Waiting for completion confirmation.')
            ->assertSee('Totals remain unconfirmed until the export completes.')
            ->assertSee('title="Export in progress"', false)
            ->assertSee('bg-sky-400', false)
            ->assertSee('24h total unconfirmed')
            ->assertDontSee('title="Healthy"', false)
            ->assertDontSee('bg-emerald-400', false)
            ->assertDontSee('transfer-reason', false);

        Livewire::actingAs($this->user)->test(InstanceDetail::class, ['instance' => $instance])
            ->assertViewHas('dataFlow', fn ($flow) => $flow['state'] === 'transferring')
            ->assertViewHas('requestsVerified', false)
            ->assertSee('Export in progress. Waiting for completion confirmation.')
            ->assertSee('Totals remain unconfirmed until the export completes.')
            ->assertDontSee('title="Healthy"', false)
            ->assertDontSee('No open exceptions. 🎉')
            ->assertDontSee('transfer-reason', false);
    }

    public function test_reason_migration_preserves_incident_history_and_does_not_invent_legacy_reasons(): void
    {
        $event = $this->event(Instance::factory()->create(), [
            'reason_code' => 'heartbeat_stale',
            'last_reason_code' => 'export_stale',
            'last_reason_at' => now(),
            'notification_status' => ['triggered' => ['teams' => 'disabled', 'email' => 'sent']],
            'notified' => true,
        ]);
        $columns = ['id', 'alert_rule_id', 'instance_id', 'value', 'triggered_at', 'resolved_at', 'notified', 'notification_status'];
        $history = (array) DB::table('alert_events')->where('id', $event->id)->first($columns);
        $migration = require database_path('migrations/2026_10_20_000002_add_alert_event_reasons.php');
        $migration->down();

        try {
            foreach (['reason_code', 'last_reason_code', 'last_reason_at'] as $column) {
                $this->assertFalse(Schema::hasColumn('alert_events', $column));
            }
            $this->assertSame($history, (array) DB::table('alert_events')->where('id', $event->id)->first($columns));
        } finally {
            $migration->up();
        }

        $event->refresh();
        $this->assertNull($event->reason_code);
        $this->assertNull($event->last_reason_code);
        $this->assertNull($event->last_reason_at);
        $this->assertSame($history, (array) DB::table('alert_events')->where('id', $event->id)->first($columns));
    }

    private function event(Instance $instance, array $overrides = []): AlertEvent
    {
        $rule = AlertRule::query()->create([
            'name' => 'Data flow unavailable', 'instance_id' => $instance->id,
            'metric_type' => AlertRule::TYPE_DATA_FLOW, 'aggregate' => 'count', 'operator' => '>', 'threshold' => 0,
            'window_minutes' => 15, 'cooldown_minutes' => 5,
            'webhook_url' => null, 'email_recipients' => ['ops@example.test'], 'enabled' => true,
        ]);

        return AlertEvent::query()->create([
            'alert_rule_id' => $rule->id, 'instance_id' => $instance->id, 'value' => 1,
            'triggered_at' => now()->subMinutes(6), 'notified' => false,
            'notification_status' => ['triggered' => ['teams' => 'disabled', 'email' => 'waiting']],
            ...$overrides,
        ]);
    }

    private function transferringAttributes(): array
    {
        $through = intdiv(now()->getTimestamp() - 360, 300) * 300;

        return [
            'last_heartbeat_at' => now(), 'last_ingest_at' => now(),
            'meta' => [
                'data_flow_pending' => true,
                'data_flow_pending_since' => now()->subMinute()->getTimestamp(),
                'data_flow' => [
                    'version' => 1, 'covered_from' => $through - 90000, 'covered_through' => $through,
                    'confirmed_at' => now()->subMinutes(2)->getTimestamp(), 'clock_skew_seconds' => 0,
                    'types' => ['request', 'exception', 'job:failed'],
                ],
            ],
        ];
    }
}
