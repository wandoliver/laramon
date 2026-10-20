<?php

namespace Tests\Feature;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Instance;
use App\Models\MetricBucket;
use App\Services\AlertEmailNotifier;
use App\Services\TeamsNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AlertNotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-19 12:22:00', 'UTC'));
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
    }

    public function test_internal_only_breaches_are_saved_once_without_external_requests(): void
    {
        $instance = Instance::factory()->create();
        $this->breach($instance);
        $this->rule($instance);

        $this->evaluate();
        $this->evaluate();

        $event = AlertEvent::query()->sole();
        $this->assertNull($event->resolved_at);
        $this->assertFalse($event->notified);
        $this->assertSame(['triggered' => ['teams' => 'disabled', 'email' => 'disabled']], $event->notification_status);
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_internal_data_flow_incident_requires_confirmed_recovery_and_remains_internal(): void
    {
        $instance = Instance::factory()->create();
        $this->rule($instance, ['metric_type' => AlertRule::TYPE_DATA_FLOW, 'threshold' => 0]);
        $this->evaluate();
        $event = AlertEvent::query()->sole();

        $instance->forceFill(['last_heartbeat_at' => now(), 'last_ingest_at' => now()])->save();
        $this->evaluate();
        $this->assertNull($event->refresh()->resolved_at);

        $this->travel(1)->seconds();
        $this->confirm($instance);
        $this->evaluate();

        $this->assertNotNull($event->refresh()->resolved_at);
        $this->assertSame(['teams' => 'disabled', 'email' => 'disabled'], $event->notification_status['resolved']);
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_email_only_sends_trigger_and_recovery_after_the_event_is_persisted(): void
    {
        $instance = Instance::factory()->create();
        $this->breach($instance);
        $this->rule($instance, ['email_recipients' => ['ops@example.test']]);
        $this->mock(AlertEmailNotifier::class, function ($mock) {
            $mock->shouldReceive('send')->once()->withArgs(function ($recipients, $phase, $variables) {
                $event = AlertEvent::query()->sole();
                $this->assertNull($event->resolved_at);
                $this->assertSame('pending', $event->notification_status['triggered']['email']);
                $this->assertSame('Alert', $variables['status']);
                $this->assertSame('', $variables['resolved_at']);
                $this->assertSame('20 (count, last 15 min)', $variables['value']);

                return $recipients === ['ops@example.test'] && $phase === 'triggered';
            })->andReturn(true);
            $mock->shouldReceive('send')->once()->withArgs(function ($recipients, $phase, $variables) {
                $this->assertNotNull(AlertEvent::query()->sole()->resolved_at);
                $this->assertSame('Resolved', $variables['status']);
                $this->assertSame('20 (count, last 15 min)', $variables['previous_value']);
                $this->assertNotSame('', $variables['resolved_at']);

                return $recipients === ['ops@example.test'] && $phase === 'resolved';
            })->andReturn(true);
        });

        $this->evaluate();
        $event = AlertEvent::query()->sole();
        $this->assertTrue($event->notified);
        $this->assertSame(['teams' => 'disabled', 'email' => 'sent'], $event->notification_status['triggered']);

        $this->travel(20)->minutes();
        $this->confirm($instance);
        $this->evaluate();
        $this->assertSame(['teams' => 'disabled', 'email' => 'sent'], $event->refresh()->notification_status['resolved']);
        Http::assertNothingSent();
    }

    public function test_both_channels_work_and_the_internal_incident_precedes_teams_delivery(): void
    {
        $instance = Instance::factory()->create();
        $this->breach($instance);
        $this->rule($instance, ['webhook_url' => 'https://teams.test/webhook', 'email_recipients' => ['ops@example.test']]);
        $this->mock(TeamsNotifier::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andReturnUsing(function () {
                $this->assertSame(1, AlertEvent::query()->count());

                return true;
            });
        });
        $this->mock(AlertEmailNotifier::class, fn ($mock) => $mock->shouldReceive('send')->once()->andReturn(true));

        $this->evaluate();
        $this->evaluate();

        $event = AlertEvent::query()->sole();
        $this->assertTrue($event->notified);
        $this->assertSame(['teams' => 'sent', 'email' => 'sent'], $event->notification_status['triggered']);
    }

    public function test_teams_exception_does_not_prevent_email_or_lose_the_internal_incident(): void
    {
        $instance = Instance::factory()->create();
        $this->breach($instance);
        $this->rule($instance, ['webhook_url' => 'https://teams.test/webhook', 'email_recipients' => ['ops@example.test']]);
        $this->mock(TeamsNotifier::class, fn ($mock) => $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('transport secret')));
        $this->mock(AlertEmailNotifier::class, fn ($mock) => $mock->shouldReceive('send')->once()->andReturn(true));

        $this->evaluate();

        $event = AlertEvent::query()->sole();
        $this->assertFalse($event->notified);
        $this->assertSame(['teams' => 'failed', 'email' => 'sent'], $event->notification_status['triggered']);
    }

    public function test_email_exception_does_not_change_successful_teams_delivery_or_repeat_the_alarm(): void
    {
        $instance = Instance::factory()->create();
        $this->breach($instance);
        $this->rule($instance, ['webhook_url' => 'https://teams.test/webhook', 'email_recipients' => ['ops@example.test']]);
        $this->mock(TeamsNotifier::class, fn ($mock) => $mock->shouldReceive('send')->once()->andReturn(true));
        $this->mock(AlertEmailNotifier::class, fn ($mock) => $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('smtp password')));

        $this->evaluate();
        $this->evaluate();

        $event = AlertEvent::query()->sole();
        $this->assertFalse($event->notified);
        $this->assertSame(['teams' => 'sent', 'email' => 'failed'], $event->notification_status['triggered']);
    }

    public function test_recovery_delivery_failure_is_recorded_separately_from_trigger_delivery(): void
    {
        $instance = Instance::factory()->create();
        $this->breach($instance);
        $this->rule($instance, ['email_recipients' => ['ops@example.test']]);
        $this->mock(AlertEmailNotifier::class, function ($mock) {
            $mock->shouldReceive('send')->once()->withArgs(fn ($recipients, $phase) => $phase === 'triggered')->andReturn(true);
            $mock->shouldReceive('send')->once()->withArgs(fn ($recipients, $phase) => $phase === 'resolved')->andReturn(false);
        });
        $this->evaluate();
        $this->travel(20)->minutes();
        $this->confirm($instance);
        $this->evaluate();

        $event = AlertEvent::query()->sole();
        $this->assertNotNull($event->resolved_at);
        $this->assertTrue($event->notified);
        $this->assertSame('sent', $event->notification_status['triggered']['email']);
        $this->assertSame('failed', $event->notification_status['resolved']['email']);
    }

    public function test_channel_migration_rolls_back_with_internal_only_rules_and_keeps_existing_webhooks(): void
    {
        $instance = Instance::factory()->create();
        $internal = $this->rule($instance);
        $teams = $this->rule($instance, ['webhook_url' => 'https://teams.test/webhook']);
        $migration = require database_path('migrations/2026_10_19_000001_add_alert_notification_channels.php');
        $migration->down();

        try {
            $this->assertFalse(Schema::hasColumn('alert_rules', 'email_recipients'));
            $this->assertFalse(Schema::hasColumn('alert_events', 'notification_status'));
            $this->assertSame('', $internal->fresh()->webhook_url);
            $this->assertSame('https://teams.test/webhook', $teams->fresh()->webhook_url);
        } finally {
            $migration->up();
        }
    }

    public function test_recovery_preserves_legacy_teams_results_even_after_rule_channels_changed(): void
    {
        foreach ([false, true] as $notified) {
            $instance = Instance::factory()->create();
            $rule = $this->rule($instance);
            $event = AlertEvent::query()->create([
                'alert_rule_id' => $rule->id, 'instance_id' => $instance->id, 'value' => 20,
                'triggered_at' => now()->subMinutes(20), 'notified' => $notified,
            ]);
            $this->confirm($instance);
            $this->evaluate();

            $event->refresh();
            $this->assertNotNull($event->resolved_at);
            $this->assertSame(['teams' => $notified ? 'sent' : 'failed', 'email' => 'disabled'], $event->notification_status['triggered']);
            $this->assertSame(['teams' => 'disabled', 'email' => 'disabled'], $event->notification_status['resolved']);
        }

        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    private function rule(Instance $instance, array $overrides = []): AlertRule
    {
        return AlertRule::query()->create([
            'name' => 'Application errors', 'instance_id' => $instance->id,
            'metric_type' => 'exception', 'aggregate' => 'count', 'operator' => '>', 'threshold' => 10,
            'window_minutes' => 15, 'cooldown_minutes' => 5,
            'webhook_url' => null, 'email_recipients' => [], 'enabled' => true,
            'trigger_delay_minutes' => 0, 'recovery_delay_minutes' => 0,
            ...$overrides,
        ]);
    }

    private function breach(Instance $instance): void
    {
        $start = intdiv(now()->getTimestamp() - 600, 300) * 300;
        MetricBucket::query()->create([
            'instance_id' => $instance->id, 'type' => 'exception', 'key' => 'fingerprint',
            'key_hash' => MetricBucket::hashKey('fingerprint'), 'bucket_start' => $start,
            'bucket_seconds' => 300, 'count' => 20,
        ]);
    }

    private function confirm(Instance $instance): void
    {
        $through = intdiv(now()->getTimestamp() - 60, 300) * 300;
        $instance->forceFill([
            'last_heartbeat_at' => now(), 'last_ingest_at' => now(),
            'meta' => ['data_flow' => [
                'version' => 1, 'covered_from' => $through - 900, 'covered_through' => $through,
                'types' => ['exception'], 'confirmed_at' => now()->getTimestamp(), 'clock_skew_seconds' => 0,
            ]],
        ])->save();
    }

    private function evaluate(): void
    {
        $this->artisan('monitor:evaluate-alerts')->assertExitCode(0);
    }
}
