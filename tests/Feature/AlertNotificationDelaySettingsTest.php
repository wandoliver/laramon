<?php

namespace Tests\Feature;

use App\Livewire\Alerts;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AlertNotificationDelaySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_rule_and_form_have_safe_delay_defaults(): void
    {
        $rule = new AlertRule;
        $this->assertSame(10, $rule->trigger_delay_minutes);
        $this->assertSame(5, $rule->recovery_delay_minutes);

        $this->form()
            ->assertSet('trigger_delay_minutes', 10)
            ->assertSet('recovery_delay_minutes', 5)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('alarm delay 10m')
            ->assertSee('recovery delay 5m');

        $rule = AlertRule::query()->sole();
        $this->assertSame(10, $rule->trigger_delay_minutes);
        $this->assertSame(5, $rule->recovery_delay_minutes);
    }

    public function test_delays_can_be_saved_edited_and_reset_with_zero_for_immediate_action(): void
    {
        $form = $this->form()
            ->set('trigger_delay_minutes', '0')
            ->set('recovery_delay_minutes', '1440')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('trigger_delay_minutes', 10)
            ->assertSet('recovery_delay_minutes', 5);

        $rule = AlertRule::query()->sole();
        $this->assertSame(0, $rule->trigger_delay_minutes);
        $this->assertSame(1440, $rule->recovery_delay_minutes);

        $form->call('edit', $rule->id)
            ->assertSet('trigger_delay_minutes', 0)
            ->assertSet('recovery_delay_minutes', 1440)
            ->set('trigger_delay_minutes', '12')
            ->set('recovery_delay_minutes', '0')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(12, $rule->fresh()->trigger_delay_minutes);
        $this->assertSame(0, $rule->fresh()->recovery_delay_minutes);

        $form->call('edit', $rule->id)->call('resetForm')
            ->assertSet('trigger_delay_minutes', 10)
            ->assertSet('recovery_delay_minutes', 5);
    }

    public function test_data_flow_normalization_preserves_configured_delays(): void
    {
        $this->form()
            ->set('metric_type', AlertRule::TYPE_DATA_FLOW)
            ->set('window_minutes', 25)
            ->set('trigger_delay_minutes', 17)
            ->set('recovery_delay_minutes', 8)
            ->call('save')
            ->assertHasNoErrors();

        $rule = AlertRule::query()->sole();
        $this->assertSame(5, $rule->window_minutes);
        $this->assertSame(17, $rule->trigger_delay_minutes);
        $this->assertSame(8, $rule->recovery_delay_minutes);
    }

    #[DataProvider('invalidDelays')]
    public function test_invalid_delay_settings_are_rejected(string $field, mixed $value): void
    {
        $this->form()->set($field, $value)->call('save')->assertHasErrors($field);

        $this->assertDatabaseCount('alert_rules', 0);
    }

    public static function invalidDelays(): iterable
    {
        foreach (['trigger_delay_minutes', 'recovery_delay_minutes'] as $field) {
            foreach (['negative' => -1, 'over limit' => 1441, 'fraction' => '1.5', 'numeric float' => 1.5, 'text' => 'ten', 'empty' => '', 'null' => null, 'array' => [10]] as $label => $value) {
                yield $field.' '.$label => [$field, $value];
            }
        }
    }

    public function test_recent_events_distinguish_waiting_and_suppressed_delivery_from_failure(): void
    {
        $this->form()->call('save')->assertHasNoErrors();
        $rule = AlertRule::query()->sole();
        $instance = Instance::factory()->create();

        foreach (['waiting', 'recovering', 'cooldown', 'suppressed', 'skipped', 'cancelled'] as $status) {
            AlertEvent::query()->create([
                'alert_rule_id' => $rule->id,
                'instance_id' => $instance->id,
                'value' => 20,
                'triggered_at' => now(),
                'recovery_started_at' => $status === 'recovering' ? now() : null,
                'notification_status' => ['triggered' => ['teams' => $status === 'recovering' ? 'waiting' : $status, 'email' => 'disabled']],
            ]);
        }

        $this->form()
            ->assertSee('Recovery being confirmed')
            ->assertSee('Teams: waiting for delay')
            ->assertSee('Teams: held during recovery')
            ->assertSee('Teams: waiting for cooldown')
            ->assertSee('Teams: not sent (closed before delivery)')
            ->assertSee('Teams: not sent (no matching delivered alarm)')
            ->assertSee('Teams: not sent (cancelled)')
            ->assertDontSee('delivery failed');
    }

    public function test_editing_or_toggling_a_rule_restarts_only_open_event_continuity(): void
    {
        $form = $this->form()->call('save')->assertHasNoErrors();
        $rule = AlertRule::query()->sole();
        $instance = Instance::factory()->create();
        $statuses = ['triggered' => ['teams' => 'sent', 'email' => 'failed']];
        $targets = ['teams' => str_repeat('a', 64), 'email' => str_repeat('b', 64)];
        $attempted = now()->subMinutes(10)->startOfSecond();
        $event = AlertEvent::query()->create([
            'alert_rule_id' => $rule->id,
            'instance_id' => $instance->id,
            'value' => 20,
            'triggered_at' => now()->subMinutes(20),
            'notification_status' => $statuses,
            'notification_targets' => $targets,
            'notification_attempted_at' => $attempted,
            'breach_started_at' => now()->subMinutes(10),
            'recovery_started_at' => now()->subMinute(),
            'last_evaluated_at' => now(),
        ]);
        $resolved = AlertEvent::query()->create([
            'alert_rule_id' => $rule->id,
            'instance_id' => $instance->id,
            'value' => 20,
            'triggered_at' => now()->subHour(),
            'resolved_at' => now()->subMinutes(30),
            'notification_status' => $statuses,
            'notification_attempted_at' => $attempted,
            'last_evaluated_at' => now()->subMinutes(30),
        ]);
        $history = $resolved->fresh()->getAttributes();

        $form->call('edit', $rule->id)->set('trigger_delay_minutes', 15)->call('save')->assertHasNoErrors();
        $this->assertNull($event->refresh()->breach_started_at);
        $this->assertNull($event->recovery_started_at);
        $this->assertNull($event->last_evaluated_at);

        foreach ([false, true] as $enabled) {
            $event->forceFill([
                'breach_started_at' => now()->subMinute(),
                'recovery_started_at' => now()->subMinute(),
                'last_evaluated_at' => now(),
            ])->save();
            $form->call('toggle', $rule->id);

            $this->assertSame($enabled, $rule->fresh()->enabled);
            $this->assertNull($event->refresh()->breach_started_at);
            $this->assertNull($event->recovery_started_at);
            $this->assertNull($event->last_evaluated_at);
            $this->assertSame($statuses, $event->notification_status);
            $this->assertSame($targets, $event->notification_targets);
            $this->assertTrue($attempted->equalTo($event->notification_attempted_at));
        }
        $this->assertSame($history, $resolved->refresh()->getAttributes());
    }

    public function test_manual_resolution_cancels_waiting_delivery_without_sending_or_erasing_attempt_history(): void
    {
        Http::fake();
        Mail::fake();
        $form = $this->form()->call('save')->assertHasNoErrors();
        $rule = AlertRule::query()->sole();
        $instance = Instance::factory()->create();
        $attempted = now()->subMinutes(5)->startOfSecond();
        $event = AlertEvent::query()->create([
            'alert_rule_id' => $rule->id,
            'instance_id' => $instance->id,
            'value' => 20,
            'triggered_at' => now()->subMinutes(10),
            'breach_started_at' => now()->subMinutes(10),
            'recovery_started_at' => now()->subMinute(),
            'last_evaluated_at' => now(),
            'notification_attempted_at' => $attempted,
            'notification_targets' => ['teams' => str_repeat('a', 64)],
            'notification_status' => ['triggered' => ['teams' => 'waiting', 'email' => 'cooldown']],
        ]);

        $form->call('startResolving', $event->id)
            ->set('resolutionComment', 'Investigated and closed manually')
            ->call('resolveEvent')
            ->assertHasNoErrors();

        $event->refresh();
        $this->assertNotNull($event->resolved_at);
        $this->assertSame(User::query()->sole()->id, $event->resolved_by_user_id);
        $this->assertSame('Investigated and closed manually', $event->resolved_comment);
        $this->assertNull($event->breach_started_at);
        $this->assertNull($event->recovery_started_at);
        $this->assertNull($event->last_evaluated_at);
        $this->assertTrue($attempted->equalTo($event->notification_attempted_at));
        $this->assertSame(['teams' => str_repeat('a', 64)], $event->notification_targets);
        $this->assertSame(['triggered' => ['teams' => 'suppressed', 'email' => 'suppressed']], $event->notification_status);
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_delay_migration_defaults_and_backfill_preserve_all_existing_incident_history(): void
    {
        $this->form()->call('save')->assertHasNoErrors();
        $rule = AlertRule::query()->sole();
        $instance = Instance::factory()->create();
        $events = [];

        foreach ([null, ['triggered' => ['teams' => 'failed', 'email' => 'sent']], ['triggered' => ['teams' => 'disabled', 'email' => 'disabled']]] as $statuses) {
            foreach ([false, true] as $resolved) {
                $events[] = AlertEvent::query()->create([
                    'alert_rule_id' => $rule->id,
                    'instance_id' => $instance->id,
                    'value' => 20,
                    'triggered_at' => now()->subHour(),
                    'resolved_at' => $resolved ? now()->subMinutes(30) : null,
                    'resolved_by_user_id' => null,
                    'resolved_comment' => $resolved ? 'Existing resolution' : null,
                    'notified' => $statuses === null,
                    'notification_status' => $statuses,
                ]);
            }
        }
        $history = DB::table('alert_events')->orderBy('id')->get([
            'id', 'value', 'triggered_at', 'resolved_at', 'resolved_comment', 'notified', 'notification_status',
        ])->map(fn ($row) => (array) $row)->all();

        $migration = require database_path('migrations/2026_10_20_000001_add_alert_notification_delays.php');
        $migration->down();

        try {
            $this->assertFalse(Schema::hasColumn('alert_rules', 'trigger_delay_minutes'));
            $this->assertFalse(Schema::hasColumn('alert_rules', 'recovery_delay_minutes'));
            foreach (['breach_started_at', 'recovery_started_at', 'last_evaluated_at', 'notification_attempted_at', 'notification_targets'] as $column) {
                $this->assertFalse(Schema::hasColumn('alert_events', $column));
            }
            $this->assertDatabaseCount('alert_events', 6);
        } finally {
            $migration->up();
        }

        $this->assertSame(10, $rule->fresh()->trigger_delay_minutes);
        $this->assertSame(5, $rule->fresh()->recovery_delay_minutes);
        foreach ($events as $event) {
            $event->refresh();
            $this->assertTrue($event->notification_attempted_at->equalTo($event->triggered_at));
            $this->assertNull($event->breach_started_at);
            $this->assertNull($event->recovery_started_at);
            $this->assertNull($event->last_evaluated_at);
            $this->assertNull($event->notification_targets);
        }
        $this->assertSame($history, DB::table('alert_events')->orderBy('id')->get([
            'id', 'value', 'triggered_at', 'resolved_at', 'resolved_comment', 'notified', 'notification_status',
        ])->map(fn ($row) => (array) $row)->all());
    }

    private function form()
    {
        return Livewire::actingAs(User::factory()->create())
            ->test(Alerts::class)
            ->set('name', 'Instance down')
            ->set('threshold', '10');
    }
}
