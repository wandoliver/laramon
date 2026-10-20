<?php

namespace Tests\Feature;

use App\Livewire\Alerts;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Instance;
use App\Models\MetricBucket;
use App\Models\User;
use App\Services\AlertEmailNotifier;
use App\Services\AlertEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class AlertNotificationDelayTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $startedAt;

    private array $deliveries = [];

    private bool $emailSucceeds = true;

    private int $teamsStatus = 200;

    private ?\Closure $beforeEmail = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startedAt = Carbon::parse('2026-10-20 03:25:00', 'UTC');
        $this->travelTo($this->startedAt);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response('', $this->teamsStatus));
        Mail::fake();
        $this->mock(AlertEmailNotifier::class, function ($mock) {
            $mock->shouldReceive('send')->andReturnUsing(function ($recipients, $phase, $variables) {
                $this->deliveries[] = compact('recipients', 'phase', 'variables');
                ($this->beforeEmail)?->__invoke();

                return $this->emailSucceeds;
            });
        });
    }

    public function test_an_incident_is_saved_immediately_but_not_mailed_before_ten_continuous_minutes(): void
    {
        $instance = Instance::factory()->create();
        $this->rule($instance);
        $this->evaluate();

        $event = AlertEvent::query()->sole();
        $this->assertNull($event->resolved_at);
        $this->assertSame('waiting', $event->notification_status['triggered']['email']);
        $this->assertSame($this->startedAt->getTimestamp(), $event->breach_started_at->getTimestamp());
        $this->assertNull($event->notification_attempted_at);
        $this->assertSame([], $this->deliveries);

        foreach (range(1, 9) as $minute) {
            $this->at($minute);
            $this->evaluate();
        }
        $this->at(9, 59);
        $this->evaluate();
        $this->assertSame([], $this->deliveries);

        $this->at(10);
        $this->evaluate();
        $this->evaluate();

        $this->assertSame(['triggered'], $this->phases());
        $this->assertSame('sent', $event->refresh()->notification_status['triggered']['email']);
        $this->assertSame(now()->getTimestamp(), $event->notification_attempted_at->getTimestamp());
        $this->assertSame(1, AlertEvent::query()->count());
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_delivery_claim_is_persisted_before_transport_and_reentrant_evaluation_cannot_send_twice(): void
    {
        // End RefreshDatabase's empty wrapper before creating fixtures so this
        // test can assert the same transaction boundary as production delivery.
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::rollBack();
        $this->assertSame(0, DB::transactionLevel());

        $instance = Instance::factory()->create();
        $this->rule($instance);
        $callbackCompleted = false;
        $this->beforeEmail = function () use (&$callbackCompleted) {
            // A delivery callback may reenter evaluation before SMTP returns.
            // The durable claim must already exclude any second attempt.
            $this->assertSame(0, DB::transactionLevel());
            $event = AlertEvent::query()->sole();
            $this->assertNotNull($event->notification_attempted_at);
            $this->assertSame(now()->getTimestamp(), $event->notification_attempted_at->getTimestamp());
            $this->assertSame('pending', $event->notification_status['triggered']['email']);
            app(AlertEvaluator::class)->evaluate();
            $this->assertSame(1, AlertEvent::query()->count());
            $this->assertSame(['triggered'], $this->phases());
            $callbackCompleted = true;
        };

        $this->observeBreachesThrough(10);
        $this->evaluate();

        // Assertions inside a transport callback can be caught as delivery
        // failures; this assertion also makes incomplete callbacks fail loudly.
        $this->assertTrue($callbackCompleted);
        $this->assertSame(['triggered'], $this->phases());
        $this->assertSame(1, AlertEvent::query()->count());
        $this->assertSame('sent', AlertEvent::query()->sole()->notification_status['triggered']['email']);
        $this->assertSame(0, DB::transactionLevel());
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_disabling_a_rule_after_claim_cancels_delivery_without_leaving_pending_status(): void
    {
        $instance = Instance::factory()->create();
        $rule = $this->rule($instance, ['trigger_delay_minutes' => 0]);
        $eventName = 'eloquent.saved: '.AlertEvent::class;
        Event::listen($eventName, function (AlertEvent $event) use ($rule): void {
            if ($event->wasChanged('notification_attempted_at') && $event->notification_attempted_at !== null) {
                AlertRule::query()->whereKey($rule->id)->update(['enabled' => false]);
            }
        });

        try {
            $this->evaluate();
        } finally {
            Event::forget($eventName);
        }

        $event = AlertEvent::query()->sole();
        $this->assertNotNull($event->notification_attempted_at);
        $this->assertSame('cancelled', $event->notification_status['triggered']['email']);
        $this->assertFalse($event->notified);
        $this->assertSame([], $this->deliveries);
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_a_six_minute_incident_is_recorded_and_recovers_without_an_external_mail_pair(): void
    {
        $instance = Instance::factory()->create();
        $this->rule($instance);
        $this->observeBreachesThrough(5);
        $event = AlertEvent::query()->sole();

        foreach (range(6, 10) as $minute) {
            $this->observeHealthy($instance, $minute);
            $this->assertNull($event->refresh()->resolved_at);
            $this->assertSame([], $this->deliveries);
        }
        $this->observeHealthy($instance, 11);

        $this->assertNotNull($event->refresh()->resolved_at);
        $this->assertFalse($event->notified);
        $this->assertNull($event->notification_attempted_at);
        $this->assertSame([], $this->deliveries);
        Http::assertNothingSent();
    }

    public function test_recovery_waits_exactly_five_continuous_minutes_and_sends_only_once(): void
    {
        $instance = Instance::factory()->create();
        $this->rule($instance);
        $this->observeBreachesThrough(10);
        $event = AlertEvent::query()->sole();
        $this->assertSame(['triggered'], $this->phases());

        foreach (range(11, 15) as $minute) {
            $this->observeHealthy($instance, $minute);
        }
        $this->observeHealthy($instance, 15, 59);
        $this->assertNull($event->refresh()->resolved_at);
        $this->assertSame(['triggered'], $this->phases());

        $this->observeHealthy($instance, 16);
        $this->evaluate();
        $this->assertNotNull($event->refresh()->resolved_at);
        $this->assertSame(['triggered', 'resolved'], $this->phases());
        $this->assertSame('sent', $event->notification_status['resolved']['email']);
    }

    public function test_a_short_unnotified_incident_cannot_recover_from_stale_coverage(): void
    {
        $instance = Instance::factory()->create();
        $this->rule($instance);
        $this->observeBreachesThrough(5);
        $this->observeHealthy($instance, 6);
        $this->observeHealthy($instance, 7);
        $meta = $instance->fresh()->meta;
        $meta['data_flow']['confirmed_at'] = $this->startedAt->copy()->subMinutes(16)->getTimestamp();

        foreach (range(8, 14) as $minute) {
            $this->at($minute);
            $instance->forceFill(['last_heartbeat_at' => now(), 'last_ingest_at' => now(), 'meta' => $meta])->save();
            $this->evaluate();
        }

        $event = AlertEvent::query()->sole();
        $this->assertNull($event->resolved_at);
        $this->assertNull($event->recovery_started_at);
        $this->assertSame([], $this->deliveries);
        Http::assertNothingSent();
    }

    public function test_reusing_one_fresh_confirmation_cannot_prove_stable_recovery(): void
    {
        $instance = Instance::factory()->create();
        $this->rule($instance);
        $this->observeBreachesThrough(10);
        $this->observeHealthy($instance, 11);
        $event = AlertEvent::query()->sole();
        $proof = $instance->fresh()->meta;

        foreach (range(12, 16) as $minute) {
            $this->at($minute);
            $instance->forceFill([
                'last_heartbeat_at' => now(), 'last_ingest_at' => now(), 'meta' => $proof,
            ])->save();
            $this->evaluate();
        }

        $this->assertNull($event->refresh()->resolved_at);
        $this->assertSame(['triggered'], $this->phases());

        $this->observeHealthy($instance, 16, 1);
        $this->assertNotNull($event->refresh()->resolved_at);
        $this->assertSame(['triggered', 'resolved'], $this->phases());
    }

    public function test_stale_proof_resets_recovery_instead_of_giving_an_all_clear(): void
    {
        $instance = Instance::factory()->create();
        $this->rule($instance);
        $this->observeBreachesThrough(10);
        $this->observeHealthy($instance, 11);
        $this->observeHealthy($instance, 12);
        $this->at(13);
        $meta = $instance->fresh()->meta;
        $meta['data_flow']['confirmed_at'] = now()->subMinutes(16)->getTimestamp();
        $instance->forceFill(['last_heartbeat_at' => now(), 'last_ingest_at' => now(), 'meta' => $meta])->save();
        $this->evaluate();
        $event = AlertEvent::query()->sole();
        $this->assertNull($event->refresh()->recovery_started_at);
        $this->assertNull($event->resolved_at);
        $this->assertSame(['triggered'], $this->phases());
    }

    public function test_missing_coverage_restarts_the_full_recovery_wait(): void
    {
        $instance = Instance::factory()->create();
        $this->rule($instance);
        $this->observeBreachesThrough(10);
        $this->observeHealthy($instance, 11);
        $this->observeHealthy($instance, 12);
        $this->at(13);
        $instance->forceFill(['last_heartbeat_at' => now(), 'last_ingest_at' => now(), 'meta' => []])->save();
        $this->evaluate();
        $event = AlertEvent::query()->sole();
        $this->assertNull($event->recovery_started_at);
        $this->assertNull($event->resolved_at);

        foreach (range(14, 18) as $minute) {
            $this->observeHealthy($instance, $minute);
            $this->assertNull($event->refresh()->resolved_at);
        }
        $this->observeHealthy($instance, 19);
        $this->assertNotNull($event->refresh()->resolved_at);
        $this->assertSame(['triggered', 'resolved'], $this->phases());
    }

    public function test_an_evaluation_gap_restarts_the_alarm_observation_period(): void
    {
        $instance = Instance::factory()->create();
        $this->rule($instance);
        $this->observeBreachesThrough(4);

        // No evaluator observations exist for minutes five and six.
        $this->at(7);
        $this->evaluate();
        foreach (range(8, 16) as $minute) {
            $this->at($minute);
            $this->evaluate();
        }
        $this->assertSame([], $this->deliveries);

        $this->at(17);
        $this->evaluate();
        $this->assertSame(['triggered'], $this->phases());
    }

    public function test_an_evaluation_gap_restarts_the_recovery_observation_period(): void
    {
        $instance = Instance::factory()->create();
        $this->rule($instance);
        $this->observeBreachesThrough(10);
        $this->observeHealthy($instance, 11);
        $this->observeHealthy($instance, 12);

        // A new valid proof cannot fill a gap in monitoring observations.
        foreach (range(15, 19) as $minute) {
            $this->observeHealthy($instance, $minute);
            $this->assertNull(AlertEvent::query()->sole()->resolved_at);
        }
        $this->assertSame(['triggered'], $this->phases());
        $this->observeHealthy($instance, 20);
        $this->assertNotNull(AlertEvent::query()->sole()->resolved_at);
        $this->assertSame(['triggered', 'resolved'], $this->phases());
    }

    public function test_unknown_metric_observations_reset_the_continuous_alarm_timer(): void
    {
        $instance = Instance::factory()->create();
        $this->rule($instance, ['metric_type' => 'gauge:queue.pending', 'key' => 'critical', 'aggregate' => 'max', 'threshold' => 10]);
        $this->gauge($instance, 20);
        $this->observeBreachesThrough(4);

        $this->at(5);
        MetricBucket::query()->delete();
        $this->evaluate();
        $this->assertNull(AlertEvent::query()->sole()->breach_started_at);
        $this->assertNull(AlertEvent::query()->sole()->resolved_at);
        $this->at(6);
        $this->gauge($instance, 20);
        $this->evaluate();
        foreach (range(7, 15) as $minute) {
            $this->at($minute);
            $this->evaluate();
        }
        $this->assertSame([], $this->deliveries);
        $this->at(16);
        $this->evaluate();
        $this->assertSame(['triggered'], $this->phases());
    }

    public function test_partial_nonbreaching_metric_data_neither_resolves_nor_keeps_the_alarm_timer_running(): void
    {
        $instance = Instance::factory()->create();
        $this->rule($instance, ['metric_type' => 'gauge:queue.pending', 'key' => 'critical', 'aggregate' => 'max', 'threshold' => 10]);
        $this->gauge($instance, 20);
        $this->observeBreachesThrough(4);
        $this->at(5);
        MetricBucket::query()->update(['sum' => 0, 'min' => 0, 'max' => 0]);
        $this->evaluate();
        $event = AlertEvent::query()->sole();
        $this->assertNull($event->breach_started_at);
        $this->assertNull($event->recovery_started_at);
        $this->assertNull($event->resolved_at);

        $this->at(6);
        MetricBucket::query()->update([
            'sum' => 20, 'min' => 20, 'max' => 20,
            'bucket_start' => intdiv(now()->getTimestamp() - 60, 300) * 300,
        ]);
        $this->evaluate();
        foreach (range(7, 15) as $minute) {
            $this->at($minute);
            $this->evaluate();
        }
        $this->assertSame([], $this->deliveries);
        $this->at(16);
        $this->evaluate();
        $this->assertSame(['triggered'], $this->phases());
    }

    public function test_failed_initial_delivery_is_not_retried_or_followed_by_an_orphan_recovery_mail(): void
    {
        $this->emailSucceeds = false;
        $instance = Instance::factory()->create();
        $this->rule($instance);
        $this->observeBreachesThrough(12);
        $event = AlertEvent::query()->sole();
        $this->assertSame('failed', $event->notification_status['triggered']['email']);
        $this->assertSame(['triggered'], $this->phases());

        $this->emailSucceeds = true;
        foreach (range(13, 18) as $minute) {
            $this->observeHealthy($instance, $minute);
        }
        $this->assertNotNull($event->refresh()->resolved_at);
        $this->assertSame(['triggered'], $this->phases());
    }

    public function test_recovery_is_sent_only_to_channels_that_received_the_initial_alarm(): void
    {
        $this->teamsStatus = 500;
        $instance = Instance::factory()->create();
        $this->rule($instance, ['webhook_url' => 'https://teams.test/old-webhook']);
        $this->observeBreachesThrough(10);
        $event = AlertEvent::query()->sole();
        $this->assertSame(['teams' => 'failed', 'email' => 'sent'], $event->notification_status['triggered']);
        Http::assertSentCount(1);

        $this->teamsStatus = 200;
        foreach (range(11, 16) as $minute) {
            $this->observeHealthy($instance, $minute);
        }

        $this->assertNotNull($event->refresh()->resolved_at);
        $this->assertSame(['triggered', 'resolved'], $this->phases());
        Http::assertSentCount(1);
    }

    public function test_changed_destinations_do_not_receive_a_recovery_for_an_alarm_they_never_received(): void
    {
        $instance = Instance::factory()->create();
        $rule = $this->rule($instance, ['webhook_url' => 'https://teams.test/old-webhook']);
        $this->observeBreachesThrough(10);
        Http::assertSentCount(1);

        $rule->update(['webhook_url' => 'https://teams.test/new-webhook', 'email_recipients' => ['new@example.test']]);
        foreach (range(11, 16) as $minute) {
            $this->observeHealthy($instance, $minute);
        }

        $this->assertNotNull(AlertEvent::query()->sole()->resolved_at);
        $this->assertSame(['triggered'], $this->phases());
        Http::assertSentCount(1);
        $targets = json_encode(AlertEvent::query()->sole()->notification_targets, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('ops@example.test', $targets);
        $this->assertStringNotContainsString('old-webhook', $targets);
    }

    public function test_successful_teams_alarm_can_recover_even_when_the_email_alarm_failed(): void
    {
        $this->emailSucceeds = false;
        $instance = Instance::factory()->create();
        $this->rule($instance, ['webhook_url' => 'https://teams.test/webhook']);
        $this->observeBreachesThrough(10);
        $event = AlertEvent::query()->sole();
        $this->assertSame(['teams' => 'sent', 'email' => 'failed'], $event->notification_status['triggered']);

        $this->emailSucceeds = true;
        foreach (range(11, 16) as $minute) {
            $this->observeHealthy($instance, $minute);
        }

        $this->assertNotNull($event->refresh()->resolved_at);
        $this->assertSame('sent', $event->notification_status['resolved']['teams']);
        $this->assertSame(['triggered'], $this->phases());
        Http::assertSentCount(2);
    }

    public function test_recovery_recipients_are_the_intersection_of_successful_alarm_targets_and_current_configuration(): void
    {
        $instance = Instance::factory()->create();
        $rule = $this->rule($instance, ['email_recipients' => ['ops@example.test', 'retained@example.test']]);
        $this->observeBreachesThrough(10);
        $rule->update(['email_recipients' => ['retained@example.test', 'new@example.test']]);

        foreach (range(11, 16) as $minute) {
            $this->observeHealthy($instance, $minute);
        }

        $this->assertSame(['triggered', 'resolved'], $this->phases());
        $this->assertSame(['retained@example.test'], $this->deliveries[1]['recipients']);
        $targets = json_encode(AlertEvent::query()->sole()->notification_targets, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('@example.test', $targets);
    }

    public function test_disabled_periods_do_not_count_towards_a_deferred_alarm(): void
    {
        $instance = Instance::factory()->create();
        $rule = $this->rule($instance);
        $this->observeBreachesThrough(4);
        $rule->update(['enabled' => false]);
        foreach (range(5, 15) as $minute) {
            $this->at($minute);
            $this->evaluate();
        }
        $rule->update(['enabled' => true]);
        foreach (range(16, 25) as $minute) {
            $this->at($minute);
            $this->evaluate();
        }
        $this->assertSame([], $this->deliveries);

        $this->at(26);
        $this->evaluate();
        $this->assertSame(['triggered'], $this->phases());
    }

    public function test_fleet_wide_delays_are_tracked_independently_for_each_instance(): void
    {
        $first = Instance::factory()->create(['name' => 'First']);
        $second = Instance::factory()->create(['name' => 'Second']);
        $this->rule($first, ['instance_id' => null]);
        foreach (range(0, 12) as $minute) {
            $this->at($minute);
            if ($minute < 3) {
                $this->confirm($second);
            } else {
                $second->forceFill(['meta' => []])->save();
            }
            $this->evaluate();
        }

        $this->assertSame(['First'], array_column(array_column($this->deliveries, 'variables'), 'instance_name'));
        $this->assertSame(2, AlertEvent::query()->count());
        $this->at(13);
        $this->evaluate();
        $this->assertSame(['First', 'Second'], array_column(array_column($this->deliveries, 'variables'), 'instance_name'));
    }

    public function test_manually_resolving_a_waiting_incident_cancels_its_deferred_notifications(): void
    {
        $instance = Instance::factory()->create();
        $this->rule($instance);
        $this->observeBreachesThrough(4);
        $event = AlertEvent::query()->sole();
        Livewire::actingAs(User::factory()->create())
            ->test(Alerts::class)
            ->call('startResolving', $event->id)
            ->set('resolutionComment', 'Reviewed manually.')
            ->call('resolveEvent')
            ->assertHasNoErrors();

        foreach (range(5, 16) as $minute) {
            $this->observeHealthy($instance, $minute);
        }

        $this->assertNotNull($event->refresh()->resolved_at);
        $this->assertSame('Reviewed manually.', $event->resolved_comment);
        $this->assertSame(1, AlertEvent::query()->count());
        $this->assertSame([], $this->deliveries);
    }

    public function test_zero_delays_allow_immediate_alarm_and_proven_recovery(): void
    {
        $instance = Instance::factory()->create();
        $this->rule($instance, ['trigger_delay_minutes' => 0, 'recovery_delay_minutes' => 0]);
        $this->evaluate();
        $this->assertSame(['triggered'], $this->phases());
        $this->observeHealthy($instance, 0, 1);

        $this->assertNotNull(AlertEvent::query()->sole()->resolved_at);
        $this->assertSame(['triggered', 'resolved'], $this->phases());
    }

    public function test_cooldown_suppresses_external_delivery_but_never_loses_the_new_internal_incident(): void
    {
        $instance = Instance::factory()->create();
        $this->rule($instance, ['trigger_delay_minutes' => 0, 'recovery_delay_minutes' => 0, 'cooldown_minutes' => 30]);
        $this->evaluate();
        $this->observeHealthy($instance, 1);
        $this->assertSame(['triggered', 'resolved'], $this->phases());

        $this->at(2);
        $instance->forceFill(['meta' => []])->save();
        $this->evaluate();
        $this->assertSame(2, AlertEvent::query()->count());
        $this->assertSame(1, AlertEvent::query()->whereNull('resolved_at')->count());
        $this->assertSame(['triggered', 'resolved'], $this->phases());

        $this->observeHealthy($instance, 3);
        $this->assertSame(0, AlertEvent::query()->whereNull('resolved_at')->count());
        $this->assertSame(['triggered', 'resolved'], $this->phases());
    }

    private function rule(Instance $instance, array $overrides = []): AlertRule
    {
        return AlertRule::query()->create([
            'name' => 'Data flow unavailable', 'instance_id' => $instance->id,
            'metric_type' => AlertRule::TYPE_DATA_FLOW, 'aggregate' => 'count', 'operator' => '>', 'threshold' => 0,
            'window_minutes' => 15, 'cooldown_minutes' => 30,
            'trigger_delay_minutes' => 10, 'recovery_delay_minutes' => 5,
            'webhook_url' => null, 'email_recipients' => ['ops@example.test'], 'enabled' => true,
            ...$overrides,
        ]);
    }

    private function at(int $minutes, int $seconds = 0): void
    {
        $this->travelTo($this->startedAt->copy()->addMinutes($minutes)->addSeconds($seconds));
    }

    private function observeBreachesThrough(int $minutes): void
    {
        foreach (range(0, $minutes) as $minute) {
            $this->at($minute);
            $this->evaluate();
        }
    }

    private function observeHealthy(Instance $instance, int $minute, int $second = 0): void
    {
        $this->at($minute, $second);
        $this->confirm($instance);
        $this->evaluate();
    }

    private function confirm(Instance $instance): void
    {
        $through = intdiv(now()->getTimestamp() - 60, 300) * 300;
        $previous = $instance->fresh()->meta['data_flow'] ?? [];
        // Like real ingest, repeated coverage of the same interval does not
        // manufacture a newer confirmation time.
        $confirmedAt = ($previous['covered_through'] ?? null) === $through
            ? $previous['confirmed_at']
            : now()->getTimestamp();
        $instance->forceFill([
            'last_heartbeat_at' => now(), 'last_ingest_at' => now(),
            'meta' => ['data_flow' => [
                'version' => 1, 'covered_from' => $through - 1800, 'covered_through' => $through,
                'types' => ['exception'], 'confirmed_at' => $confirmedAt, 'clock_skew_seconds' => 0,
            ]],
        ])->save();
    }

    private function gauge(Instance $instance, float $value): void
    {
        MetricBucket::query()->create([
            'instance_id' => $instance->id, 'type' => 'gauge:queue.pending', 'key' => 'critical',
            'key_hash' => MetricBucket::hashKey('critical'), 'bucket_seconds' => 300,
            'bucket_start' => intdiv(now()->getTimestamp() - 60, 300) * 300,
            'count' => 1, 'sum' => $value, 'min' => $value, 'max' => $value,
        ]);
    }

    private function phases(): array
    {
        return array_column($this->deliveries, 'phase');
    }

    private function evaluate(): void
    {
        $this->artisan('monitor:evaluate-alerts')->assertExitCode(0);
    }
}
