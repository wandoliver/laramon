<?php

namespace Tests\Feature;

use App\Livewire\FleetOverview;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Instance;
use App\Models\MetricBucket;
use App\Models\User;
use App\Services\DataFlowStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DataFlowTransferTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $startedAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startedAt = Carbon::parse('2026-10-20 12:02:00', 'UTC');
        $this->travelTo($this->startedAt);
        Http::preventStrayRequests();
        Http::fake(['teams.test/*' => Http::response('ok')]);
        Mail::fake();
    }

    public function test_a_normal_chunk_uses_server_receipt_time_and_leaves_totals_unverified(): void
    {
        $instance = $this->verifiedInstance();
        $token = $instance->rotateToken();
        $payload = $this->payload();
        $payload['sent_at'] = now()->subMinute()->toIso8601String();
        $payload['data_flow_pending_since'] = now()->addDay()->getTimestamp();

        $this->withToken($token)->postJson('/api/v1/ingest', $payload)->assertOk();

        $instance->refresh();
        $status = $instance->dataFlow();
        $this->assertTrue($instance->meta['data_flow_pending']);
        $this->assertSame(now()->getTimestamp(), $instance->meta['data_flow_pending_since']);
        $this->assertSame('transferring', $status['state']);
        $this->assertSame('export_in_progress', $status['reason_code']);
        $this->assertSame('transferring', $status['transfer']['state']);
        $this->assertSame(now()->getTimestamp() + 300, $status['transfer']['deadline_at']);
        $this->assertNull(app(DataFlowStatus::class)->window($instance, 5, 'exception'));

        Livewire::actingAs(User::factory()->create())->test(FleetOverview::class)
            ->assertViewHas('cards', fn ($cards) => $cards->sole()['health'] === 'transferring'
                && ! $cards->sole()['requests_verified']
                && ! $cards->sole()['exceptions_verified']
                && ! $cards->sole()['failed_jobs_verified']
                && ! $cards->sole()['history_coverage_available']);
    }

    public function test_later_chunks_retries_and_old_final_acknowledgements_cannot_extend_the_deadline(): void
    {
        $instance = $this->verifiedInstance();
        $token = $instance->rotateToken();
        $oldProof = $instance->meta['data_flow'];
        $first = $this->payload();
        $this->withToken($token)->postJson('/api/v1/ingest', $first)->assertOk();
        $startedAt = $instance->fresh()->meta['data_flow_pending_since'];

        $this->at(1);
        $this->heartbeat($instance);
        $this->withToken($token)->postJson('/api/v1/ingest', $this->payload())->assertOk();
        $this->assertSame($startedAt, $instance->fresh()->meta['data_flow_pending_since']);
        $receivedAt = $instance->fresh()->last_ingest_at;

        $this->at(2);
        $this->heartbeat($instance);
        $this->withToken($token)->postJson('/api/v1/ingest', $first)->assertJson(['status' => 'duplicate']);
        $this->assertTrue($receivedAt->eq($instance->fresh()->last_ingest_at));
        $this->assertSame($startedAt, $instance->fresh()->meta['data_flow_pending_since']);

        $this->at(3);
        $this->heartbeat($instance);
        $oldFinal = $this->payload($this->coverage($oldProof['covered_through']));
        $this->withToken($token)->postJson('/api/v1/ingest', $oldFinal)->assertOk();
        $this->assertSame($oldProof, $instance->fresh()->meta['data_flow']);
        $this->assertTrue($instance->fresh()->meta['data_flow_pending']);
        $this->assertSame($startedAt, $instance->fresh()->meta['data_flow_pending_since']);

        $this->at(4, 59);
        $this->heartbeat($instance);
        $this->assertSame('transferring', $instance->fresh()->dataFlow()['state']);
        $this->at(5);
        $this->heartbeat($instance);
        $this->withToken($token)->postJson('/api/v1/ingest', $this->payload())->assertOk();
        $status = $instance->fresh()->dataFlow();
        $this->assertNotSame('transferring', $status['state']);
        $this->assertSame('export_overdue', $status['reason_code']);
        $this->assertSame('overdue', $status['transfer']['state']);
        $this->assertSame($startedAt, $instance->fresh()->meta['data_flow_pending_since']);
    }

    public function test_only_an_advancing_final_acknowledgement_clears_the_transfer(): void
    {
        $instance = $this->verifiedInstance();
        $token = $instance->rotateToken();
        $this->withToken($token)->postJson('/api/v1/ingest', $this->payload())->assertOk();

        $this->at(4);
        $this->heartbeat($instance);
        $final = $this->payload($this->coverage());
        $this->withToken($token)->postJson('/api/v1/ingest', $final)->assertOk();
        $instance->refresh();
        $proof = $instance->meta['data_flow'];
        $this->assertFalse($instance->meta['data_flow_pending']);
        $this->assertArrayNotHasKey('data_flow_pending_since', $instance->meta);
        $this->assertSame('healthy', $instance->dataFlow()['state']);

        // A previously accepted final batch cannot acknowledge a later transfer.
        $this->at(5);
        $this->heartbeat($instance);
        $this->withToken($token)->postJson('/api/v1/ingest', $this->payload())->assertOk();
        $newStartedAt = $instance->fresh()->meta['data_flow_pending_since'];
        $this->withToken($token)->postJson('/api/v1/ingest', $final)->assertJson(['status' => 'duplicate']);
        $this->assertTrue($instance->fresh()->meta['data_flow_pending']);
        $this->assertSame($newStartedAt, $instance->fresh()->meta['data_flow_pending_since']);
        $this->assertSame($proof, $instance->fresh()->meta['data_flow']);
    }

    public function test_malformed_previous_coverage_cannot_block_a_valid_final_acknowledgement(): void
    {
        $instance = $this->verifiedInstance([
            'data_flow_pending' => true, 'data_flow_pending_since' => now()->getTimestamp(),
        ]);
        $meta = $instance->meta;
        $meta['data_flow']['covered_through'] = PHP_INT_MAX;
        $instance->update(['meta' => $meta]);
        $token = $instance->rotateToken();
        $this->assertSame('coverage_unverified', $instance->dataFlow()['reason_code']);

        $this->withToken($token)->postJson('/api/v1/ingest', $this->payload($this->coverage()))->assertOk();

        $instance->refresh();
        $this->assertFalse($instance->meta['data_flow_pending']);
        $this->assertArrayNotHasKey('data_flow_pending_since', $instance->meta);
        $this->assertSame($this->through(), $instance->meta['data_flow']['covered_through']);
        // Resetting invalid proof still requires a new observed complete interval.
        $this->assertSame('coverage_warming_up', $instance->dataFlow()['reason_code']);
        $this->assertNotContains($instance->dataFlow()['state'], ['healthy', 'transferring']);
        $this->assertNull(app(DataFlowStatus::class)->window($instance, 5, 'exception'));
    }

    #[DataProvider('untrustedPendingStates')]
    public function test_legacy_or_malformed_pending_state_never_receives_a_new_grace_period(array $pending): void
    {
        $instance = $this->verifiedInstance($pending);
        $token = $instance->rotateToken();
        $status = $instance->dataFlow();
        $this->assertNotContains($status['state'], ['healthy', 'transferring']);
        $this->assertSame('export_unconfirmed', $status['reason_code']);

        $this->withToken($token)->postJson('/api/v1/ingest', $this->payload())->assertOk();
        $status = $instance->fresh()->dataFlow();
        $this->assertNotContains($status['state'], ['healthy', 'transferring']);
        $this->assertSame('export_unconfirmed', $status['reason_code']);
        $this->assertNull(app(DataFlowStatus::class)->window($instance->fresh(), 5, 'exception'));
    }

    public static function untrustedPendingStates(): array
    {
        $now = Carbon::parse('2026-10-20 12:02:00', 'UTC')->getTimestamp();

        return [
            'legacy missing start' => [['data_flow_pending' => true]],
            'string start' => [['data_flow_pending' => true, 'data_flow_pending_since' => (string) $now]],
            'future start' => [['data_flow_pending' => true, 'data_flow_pending_since' => $now + 1]],
            'array start' => [['data_flow_pending' => true, 'data_flow_pending_since' => [$now]]],
            'integer pending flag' => [['data_flow_pending' => 1, 'data_flow_pending_since' => $now]],
            'string pending flag' => [['data_flow_pending' => 'true', 'data_flow_pending_since' => $now]],
        ];
    }

    public function test_pending_chunks_without_prior_complete_proof_are_still_a_real_unverified_condition(): void
    {
        $instance = Instance::factory()->create();
        $token = $instance->rotateToken();
        $rule = $this->rule($instance);

        $this->withToken($token)->postJson('/api/v1/ingest', $this->payload())->assertOk();
        $this->assertNotContains($instance->fresh()->dataFlow()['state'], ['healthy', 'transferring']);
        $this->evaluate();

        $event = AlertEvent::query()->where('alert_rule_id', $rule->id)->sole();
        $this->assertNull($event->resolved_at);
        $this->assertNull($event->recovery_started_at);
        $this->assertSame('coverage_unverified', $event->reason_code);
    }

    public function test_pending_transfer_does_not_mask_missing_stale_warming_or_skewed_observations(): void
    {
        foreach (['missing-proof', 'malformed-proof', 'warming-up', 'stale-proof', 'stale-confirmation', 'future-confirmation', 'future-coverage', 'missing-heartbeat', 'stale-heartbeat', 'future-heartbeat', 'missing-export', 'stale-export', 'future-export', 'proof-clock', 'heartbeat-clock', 'export-clock'] as $case) {
            $instance = $this->verifiedInstance([
                'data_flow_pending' => true, 'data_flow_pending_since' => now()->getTimestamp(),
            ]);
            $meta = $instance->meta;
            match ($case) {
                'missing-proof' => $meta['data_flow'] = null,
                'malformed-proof' => $meta['data_flow']['version'] = 2,
                'warming-up' => $meta['data_flow']['covered_from'] = $meta['data_flow']['covered_through'],
                'stale-proof' => $meta['data_flow']['covered_through'] -= 1800,
                'stale-confirmation' => $meta['data_flow']['confirmed_at'] = now()->subSeconds(901)->getTimestamp(),
                'future-confirmation' => $meta['data_flow']['confirmed_at'] = now()->addSecond()->getTimestamp(),
                'future-coverage' => $meta['data_flow']['covered_through'] += 300,
                'missing-heartbeat' => $instance->last_heartbeat_at = null,
                'stale-heartbeat' => $instance->last_heartbeat_at = now()->subSeconds(120),
                'future-heartbeat' => $instance->last_heartbeat_at = now()->addSecond(),
                'missing-export' => $instance->last_ingest_at = null,
                'stale-export' => $instance->last_ingest_at = now()->subSeconds(900),
                'future-export' => $instance->last_ingest_at = now()->addSecond(),
                'proof-clock' => $meta['data_flow']['clock_skew_seconds'] = 61,
                'heartbeat-clock' => $meta['heartbeat_clock_skew_seconds'] = 61,
                'export-clock' => $meta['clock_skew_seconds'] = 61,
            };
            $instance->meta = $meta;
            $instance->save();
            $status = $instance->dataFlow();
            $this->assertNotContains($status['state'], ['healthy', 'transferring'], $case);
            $this->assertNotSame('export_in_progress', $status['reason_code'], $case);
            $this->assertNull(app(DataFlowStatus::class)->window($instance, 5, 'exception'), $case);
        }
    }

    public function test_repeated_healthy_export_cycles_do_not_open_incidents_even_with_zero_alarm_delay(): void
    {
        $instance = $this->verifiedInstance();
        $token = $instance->rotateToken();
        $this->rule($instance, ['trigger_delay_minutes' => 0, 'recovery_delay_minutes' => 0]);

        foreach (range(0, 2) as $cycle) {
            // The evaluator can run between each data chunk and final acknowledgement.
            $this->at($cycle * 5);
            $this->heartbeat($instance);
            $this->withToken($token)->postJson('/api/v1/ingest', $this->payload())->assertOk();
            $this->assertSame('transferring', $instance->fresh()->dataFlow()['state']);
            $this->evaluate();
            $this->assertDatabaseCount('alert_events', 0);

            $this->at($cycle * 5 + 4);
            $this->heartbeat($instance);
            $this->withToken($token)->postJson('/api/v1/ingest', $this->payload($this->coverage()))->assertOk();
            $this->assertSame('healthy', $instance->fresh()->dataFlow()['state']);
            $this->evaluate();
            $this->assertDatabaseCount('alert_events', 0);
        }

        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_timeout_opens_one_incident_with_fixed_server_reason_and_preserves_its_initial_cause(): void
    {
        $instance = $this->verifiedInstance();
        $token = $instance->rotateToken();
        $this->rule($instance);
        $this->withToken($token)->postJson('/api/v1/ingest', $this->payload())->assertOk();
        $this->at(5);
        $this->heartbeat($instance);
        $this->evaluate();
        $event = AlertEvent::query()->sole();
        $this->assertSame('export_overdue', $event->reason_code);
        $this->assertSame('export_overdue', $event->last_reason_code);
        $this->assertSame(now()->getTimestamp(), $event->last_reason_at->getTimestamp());
        $this->assertNull($event->notification_attempted_at);

        $this->at(6);
        $meta = $instance->fresh()->meta;
        $meta['data_flow']['reason'] = '<script>agent-controlled text</script>';
        $meta['data_flow']['reason_code'] = 'healthy';
        $instance->update(['last_heartbeat_at' => now()->subMinutes(3), 'meta' => $meta]);
        $this->evaluate();
        $this->assertDatabaseCount('alert_events', 1);
        $this->assertSame('export_overdue', $event->refresh()->reason_code);
        $this->assertSame('heartbeat_stale', $event->last_reason_code);
        $this->assertSame(now()->getTimestamp(), $event->last_reason_at->getTimestamp());
        $this->assertStringNotContainsString('agent-controlled', $event->toJson());
    }

    public function test_normal_transfer_preserves_a_recovery_candidate_but_cannot_resolve_until_fresh_final_proof(): void
    {
        $instance = Instance::factory()->create();
        $token = $instance->rotateToken();
        $this->rule($instance, ['trigger_delay_minutes' => 0, 'webhook_url' => 'https://teams.test/webhook']);
        $this->evaluate();
        $event = AlertEvent::query()->sole();
        Http::assertSentCount(1);

        $this->at(1);
        $this->confirm($instance);
        $this->evaluate();
        $recoveryStartedAt = $event->refresh()->recovery_started_at;
        $this->assertNotNull($recoveryStartedAt);

        foreach (range(2, 9) as $minute) {
            $this->at($minute);
            $this->heartbeat($instance);
            if (in_array($minute, [2, 6], true)) {
                $this->withToken($token)->postJson('/api/v1/ingest', $this->payload())->assertOk();
            }
            if (in_array($minute, [4, 9], true)) {
                $this->withToken($token)->postJson('/api/v1/ingest', $this->payload($this->coverage()))->assertOk();
            }
            $this->evaluate();
            if ($minute < 9) {
                $this->assertNull($event->refresh()->resolved_at, 'minute '.$minute);
                $this->assertTrue($recoveryStartedAt->eq($event->recovery_started_at), 'minute '.$minute);
                Http::assertSentCount(1);
            }
        }

        $this->assertNotNull($event->refresh()->resolved_at);
        $this->assertSame('healthy', $instance->fresh()->dataFlow()['state']);
        Http::assertSentCount(2);
        $this->evaluate();
        Http::assertSentCount(2);
    }

    public function test_normal_transfer_cannot_start_a_recovery_or_continue_the_alarm_delay(): void
    {
        $instance = $this->verifiedInstance();
        $token = $instance->rotateToken();
        $rule = $this->rule($instance);
        $event = $this->openEvent($instance, $rule, [
            'breach_started_at' => now()->subMinutes(15), 'recovery_started_at' => null,
        ]);
        $this->withToken($token)->postJson('/api/v1/ingest', $this->payload())->assertOk();
        $this->evaluate();

        $this->assertNull($event->refresh()->breach_started_at);
        $this->assertNull($event->recovery_started_at);
        $this->assertNull($event->notification_attempted_at);
        $this->assertNull($event->resolved_at);

        // A real timeout starts a new continuous breach period.
        $this->at(5);
        $this->heartbeat($instance);
        $this->evaluate();
        $this->assertSame(now()->getTimestamp(), $event->refresh()->breach_started_at->getTimestamp());
        $this->assertNull($event->notification_attempted_at);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_missing_or_stale_proof_during_transfer_resets_recovery_instead_of_resolving(): void
    {
        foreach (['missing', 'stale'] as $case) {
            $instance = $this->verifiedInstance();
            $rule = $this->rule($instance);
            $event = $this->openEvent($instance, $rule, ['recovery_started_at' => now()->subMinutes(6)]);
            $token = $instance->rotateToken();
            $this->withToken($token)->postJson('/api/v1/ingest', $this->payload())->assertOk();
            $this->evaluate();
            $this->assertNotNull($event->refresh()->recovery_started_at);
            $this->assertNull($event->resolved_at);

            $meta = $instance->fresh()->meta;
            if ($case === 'missing') {
                unset($meta['data_flow']);
            } else {
                $meta['data_flow']['confirmed_at'] = now()->subSeconds(901)->getTimestamp();
            }
            $instance->update(['meta' => $meta]);
            $this->evaluate();
            $this->assertNull($event->refresh()->recovery_started_at, $case);
            $this->assertNull($event->resolved_at, $case);
        }

        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_a_missing_evaluator_observation_resets_recovery_even_during_normal_transfer(): void
    {
        $instance = $this->verifiedInstance([
            'data_flow_pending' => true, 'data_flow_pending_since' => now()->getTimestamp(),
        ]);
        $event = $this->openEvent($instance, $this->rule($instance), [
            'recovery_started_at' => now()->subMinutes(6),
            'last_evaluated_at' => now()->subSeconds(121),
        ]);

        $this->evaluate();

        $this->assertNull($event->refresh()->recovery_started_at);
        $this->assertNull($event->resolved_at);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_a_missing_final_acknowledgement_resets_recovery_at_the_transfer_deadline(): void
    {
        $instance = $this->verifiedInstance([
            'data_flow_pending' => true, 'data_flow_pending_since' => now()->getTimestamp(),
        ]);
        $event = $this->openEvent($instance, $this->rule($instance), [
            'recovery_started_at' => now()->subMinutes(6),
        ]);
        $candidate = $event->recovery_started_at;

        foreach (range(0, 11) as $minute) {
            $this->at($minute);
            $this->heartbeat($instance);
            $this->evaluate();
            $event->refresh();
            $this->assertNull($event->resolved_at, 'minute '.$minute);
            if ($minute < 5) {
                $this->assertTrue($candidate->eq($event->recovery_started_at), 'minute '.$minute);
            } else {
                $this->assertNull($event->recovery_started_at, 'minute '.$minute);
                $this->assertSame('export_overdue', $event->last_reason_code);
            }
        }

        $this->assertDatabaseCount('alert_events', 1);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_positive_exception_evidence_during_transfer_still_opens_its_own_alert(): void
    {
        $instance = $this->verifiedInstance([
            'data_flow_pending' => true, 'data_flow_pending_since' => now()->getTimestamp(),
        ]);
        $this->rule($instance, [
            'metric_type' => 'exception', 'threshold' => 0, 'trigger_delay_minutes' => 0,
            'webhook_url' => 'https://teams.test/webhook',
        ]);
        MetricBucket::query()->create([
            'instance_id' => $instance->id, 'type' => 'exception', 'key' => 'received-error',
            'key_hash' => MetricBucket::hashKey('received-error'), 'bucket_seconds' => 300,
            'bucket_start' => $this->through(), 'count' => 1,
        ]);

        $this->evaluate();

        $event = AlertEvent::query()->sole();
        $this->assertSame(1.0, $event->value);
        $this->assertNull($event->resolved_at);
        $this->assertNull($event->reason_code);
        Http::assertSentCount(1);
    }

    private function verifiedInstance(array $meta = []): Instance
    {
        $instance = Instance::factory()->create();
        $this->confirm($instance, $meta);

        return $instance->refresh();
    }

    private function confirm(Instance $instance, array $meta = []): void
    {
        $through = $this->through();
        $instance->forceFill([
            'last_heartbeat_at' => now(), 'last_ingest_at' => now(),
            'meta' => [
                'data_flow' => [
                    'version' => 1, 'covered_from' => $through - 90000, 'covered_through' => $through,
                    'confirmed_at' => now()->getTimestamp(), 'clock_skew_seconds' => 0,
                    'types' => ['exception', 'job:failed', 'request'],
                ],
                ...$meta,
            ],
        ])->save();
    }

    private function payload(?array $coverage = null): array
    {
        return [
            'schema_version' => 1, 'agent_version' => '0.4.0', 'batch_uuid' => (string) Str::uuid(),
            'sent_at' => now()->toIso8601String(), 'buckets' => [], 'exceptions' => [], 'samples' => [],
            ...($coverage === null ? [] : ['coverage' => $coverage]),
        ];
    }

    private function coverage(?int $through = null): array
    {
        $through ??= $this->through();

        return ['version' => 1, 'from' => $through - 900, 'through' => $through, 'types' => ['exception', 'job:failed', 'request']];
    }

    private function through(): int
    {
        return intdiv(now()->getTimestamp() - 60, 300) * 300;
    }

    private function heartbeat(Instance $instance): void
    {
        $instance->update(['last_heartbeat_at' => now()]);
    }

    private function at(int $minutes, int $seconds = 0): void
    {
        $this->travelTo($this->startedAt->copy()->addMinutes($minutes)->addSeconds($seconds));
    }

    private function rule(Instance $instance, array $overrides = []): AlertRule
    {
        return AlertRule::query()->create([
            'name' => 'Data flow', 'instance_id' => $instance->id,
            'metric_type' => AlertRule::TYPE_DATA_FLOW, 'aggregate' => 'count', 'operator' => '>', 'threshold' => 0,
            'window_minutes' => 15, 'cooldown_minutes' => 30,
            'trigger_delay_minutes' => 10, 'recovery_delay_minutes' => 5,
            'webhook_url' => null, 'email_recipients' => [], 'enabled' => true,
            ...$overrides,
        ]);
    }

    private function openEvent(Instance $instance, AlertRule $rule, array $overrides = []): AlertEvent
    {
        return AlertEvent::query()->create([
            'alert_rule_id' => $rule->id, 'instance_id' => $instance->id,
            'value' => 1, 'triggered_at' => now()->subMinutes(20), 'notified' => false,
            'last_evaluated_at' => now()->subMinute(),
            'notification_status' => ['triggered' => ['teams' => 'disabled', 'email' => 'disabled']],
            ...$overrides,
        ]);
    }

    private function evaluate(): void
    {
        $this->artisan('monitor:evaluate-alerts')->assertExitCode(0);
    }
}
