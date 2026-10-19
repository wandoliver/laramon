<?php

namespace Tests\Feature;

use App\Models\Instance;
use App\Services\DataFlowStatus;
use App\Services\ExceptionAggregator;
use App\Support\InstanceToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DataFlowIngestTest extends TestCase
{
    use RefreshDatabase;

    private Instance $instance;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDateTime(2026, 10, 19, 12, 0, 30));
        $this->instance = Instance::factory()->create();
        $this->token = $this->instance->rotateToken();
    }

    private function payload(?array $coverage = null): array
    {
        $payload = [
            'schema_version' => 1, 'agent_version' => '0.1.0', 'batch_uuid' => (string) Str::uuid(),
            'sent_at' => now()->toIso8601String(), 'buckets' => [], 'exceptions' => [], 'samples' => [],
        ];
        if ($coverage !== null) {
            $payload['coverage'] = $coverage;
        }

        return $payload;
    }

    private function coverage(int $minutes = 10): array
    {
        $through = intdiv(now()->getTimestamp() - 60, 300) * 300;

        return ['version' => 1, 'from' => $through - $minutes * 60, 'through' => $through, 'types' => ['exception', 'request']];
    }

    private function ingest(array $payload)
    {
        return $this->withToken($this->token)->postJson('/api/v1/ingest', $payload);
    }

    public function test_legacy_exports_and_fresh_heartbeats_do_not_imply_completeness(): void
    {
        $this->ingest($this->payload())->assertOk();
        $status = $this->instance->fresh()->dataFlow();
        $this->assertSame('healthy', $status['heartbeat']['state']);
        $this->assertSame('healthy', $status['export']['state']);
        $this->assertSame('unknown', $status['coverage']['state']);
        $this->assertSame('unknown', $status['state']);
    }

    public function test_confirmation_bootstraps_and_requires_a_whole_observed_interval(): void
    {
        $this->ingest($this->payload($this->coverage()))->assertOk();
        $initial = $this->instance->fresh()->dataFlow();
        $baseline = (intdiv(now()->getTimestamp(), 300) + 1) * 300;
        $this->assertSame($baseline, $initial['coverage']['from']);
        $this->assertSame('warming_up', $initial['coverage']['state']);
        $this->assertNull(app(DataFlowStatus::class)->window($this->instance->fresh(), 5, 'exception'));

        $this->travel(16)->minutes();
        $this->instance->update(['last_heartbeat_at' => now()]);
        $this->ingest($this->payload($this->coverage(25)))->assertOk();
        $instance = $this->instance->fresh();
        $this->assertSame('healthy', $instance->health());
        $window = app(DataFlowStatus::class)->window($instance, 5, 'exception');
        $this->assertSame($window['through'] - 1, $window['to']);
        $this->assertSame(300, $window['through'] - $window['from']);
        $this->assertNull(app(DataFlowStatus::class)->window($instance, 15, 'exception'));
        $this->assertNull(app(DataFlowStatus::class)->window($instance, 5, 'job:failed'));
        $this->assertNull(app(DataFlowStatus::class)->window($instance, 7, 'exception'));
    }

    public function test_duplicate_confirmation_and_old_interval_with_fresh_uuid_cannot_refresh_proof(): void
    {
        $payload = $this->payload($this->coverage());
        $this->ingest($payload)->assertOk();
        $old = $this->instance->fresh();
        $this->travel(5)->minutes();
        $this->ingest($payload)->assertJson(['status' => 'duplicate']);
        $this->assertTrue($old->last_ingest_at->eq($this->instance->fresh()->last_ingest_at));
        $payload['batch_uuid'] = (string) Str::uuid();
        $payload['sent_at'] = now()->toIso8601String();
        $this->ingest($payload)->assertOk();
        $this->assertSame($old->meta['data_flow'], $this->instance->fresh()->meta['data_flow']);
    }

    public function test_changed_stream_catalog_or_discontinuous_proof_restarts_observation(): void
    {
        $this->ingest($this->payload($this->coverage()))->assertOk();
        $this->travel(10)->minutes();
        $proof = $this->coverage(5);
        $proof['types'] = ['request'];
        $this->ingest($this->payload($proof))->assertOk();
        $this->assertSame((intdiv(now()->getTimestamp(), 300) + 1) * 300, $this->instance->fresh()->meta['data_flow']['covered_from']);
        $this->travel(20)->minutes();
        $proof = $this->coverage(5);
        $proof['types'] = ['request'];
        $this->ingest($this->payload($proof))->assertOk();
        $this->assertSame((intdiv(now()->getTimestamp(), 300) + 1) * 300, $this->instance->fresh()->meta['data_flow']['covered_from']);
    }

    public function test_confirmation_is_scoped_to_authenticated_instance(): void
    {
        $other = Instance::factory()->create();
        $other->forceFill(['token_hash' => InstanceToken::hash('other')])->save();
        $this->ingest($this->payload($this->coverage()))->assertOk();
        $this->assertArrayHasKey('data_flow', $this->instance->fresh()->meta);
        $this->assertNull($other->fresh()->meta['data_flow'] ?? null);
    }

    public function test_invalid_or_non_final_coverage_is_rejected_without_writes(): void
    {
        $proof = $this->coverage();
        $invalid = [
            array_replace($proof, ['version' => 2]),
            array_replace($proof, ['from' => $proof['from'] + 1]),
            array_replace($proof, ['through' => $proof['from']]),
            array_replace($proof, ['through' => (intdiv(now()->getTimestamp(), 300) + 1) * 300]),
            array_replace($proof, ['from' => $proof['through'] - 7200]),
            array_replace($proof, ['types' => ['counter:payments']]),
            array_replace($proof, ['types' => ['exception', 'exception']]),
            array_replace($proof, ['types' => []]),
            array_replace($proof, ['types' => ['arbitrary' => 'exception']]),
            array_replace($proof, ['secret' => 'ignored?']),
            array_replace($proof, ['through' => 9223372036854775807]),
        ];
        foreach ($invalid as $coverage) {
            $this->ingest($this->payload($coverage))->assertUnprocessable();
        }
        $payload = $this->payload($proof);
        $payload['buckets'][] = ['type' => 'exception', 'key' => 'x', 'bucket_start' => $proof['from'], 'bucket_seconds' => 300, 'count' => 1];
        $this->ingest($payload)->assertUnprocessable();
        $this->assertDatabaseCount('ingest_batches', 0);
        $this->assertDatabaseCount('metric_buckets', 0);
        $this->assertNull($this->instance->fresh()->last_ingest_at);
    }

    public function test_partial_ingest_failure_rolls_back_receipt_and_metrics_so_retry_is_not_lost(): void
    {
        $payload = $this->payload();
        $payload['buckets'][] = ['type' => 'exception', 'key' => 'x', 'bucket_start' => $this->coverage()['from'], 'bucket_seconds' => 300, 'count' => 1];
        $this->mock(ExceptionAggregator::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('simulated failure'));
        $this->ingest($payload)->assertServerError();
        $this->assertDatabaseCount('ingest_batches', 0);
        $this->assertDatabaseCount('metric_buckets', 0);
        $this->assertDatabaseCount('exception_groups', 0);
        $this->assertNull($this->instance->fresh()->last_ingest_at);
        $this->app->forgetInstance(ExceptionAggregator::class);
        $this->ingest($payload)->assertOk()->assertJson(['status' => 'ok', 'accepted' => 1]);
        $this->assertDatabaseCount('metric_buckets', 1);
    }

    public function test_heartbeat_preserves_confirmation_and_does_not_renew_its_freshness(): void
    {
        $this->ingest($this->payload($this->coverage()))->assertOk();
        $proof = $this->instance->fresh()->meta['data_flow'];
        $this->travel(20)->minutes();
        $this->withToken($this->token)->postJson('/api/v1/heartbeat', [
            'schema_version' => 1, 'agent_version' => '0.1.0', 'sent_at' => now()->toIso8601String(),
        ])->assertOk();
        $this->assertSame($proof, $this->instance->fresh()->meta['data_flow']);
        $this->assertSame('degraded', $this->instance->fresh()->health());
    }

    public function test_untrusted_clock_future_receipts_or_stale_proof_never_become_healthy(): void
    {
        $through = $this->coverage()['through'];
        $proof = ['version' => 1, 'covered_from' => $through - 1800, 'covered_through' => $through,
            'confirmed_at' => now()->getTimestamp(), 'clock_skew_seconds' => 0, 'types' => ['exception']];
        $this->instance->update(['last_ingest_at' => now(), 'meta' => ['data_flow' => $proof]]);
        $this->assertSame('healthy', $this->instance->fresh()->health());
        foreach ([['clock_skew_seconds' => 61], ['confirmed_at' => now()->addMinute()->getTimestamp()], ['covered_through' => $through + 600], ['confirmed_at' => now()->subMinutes(16)->getTimestamp()]] as $change) {
            $this->instance->update(['meta' => ['data_flow' => array_replace($proof, $change)]]);
            $this->assertNotSame('healthy', $this->instance->fresh()->health());
        }
        $this->instance->update(['meta' => ['data_flow' => $proof], 'last_heartbeat_at' => now()->addMinute()]);
        $this->assertSame('unknown', $this->instance->fresh()->health());
        $this->assertNull($this->instance->fresh()->heartbeatAgeSeconds());
        $this->instance->update(['meta' => ['data_flow' => $proof, 'clock_skew_seconds' => 61], 'last_heartbeat_at' => now()]);
        $this->assertSame('unknown', $this->instance->fresh()->health());
    }

    public function test_unconfirmed_chunks_revoke_old_coverage_even_in_the_same_second(): void
    {
        $through = $this->coverage()['through'];
        $proof = ['version' => 1, 'covered_from' => $through - 1800, 'covered_through' => $through,
            'confirmed_at' => now()->getTimestamp(), 'clock_skew_seconds' => 0, 'types' => ['exception', 'request']];
        $this->instance->update(['last_ingest_at' => now(), 'meta' => ['data_flow' => $proof]]);
        $this->assertSame('healthy', $this->instance->fresh()->health());
        $payload = $this->payload();
        $payload['buckets'][] = ['type' => 'exception', 'key' => 'new-error', 'bucket_start' => $through, 'bucket_seconds' => 300, 'count' => 20];
        $this->ingest($payload)->assertOk();
        $this->assertSame('unknown', $this->instance->fresh()->health());
        $this->assertNull(app(DataFlowStatus::class)->window($this->instance->fresh(), 5, 'exception'));

        // A fresh UUID with an old interval must not acknowledge newer chunks.
        $this->ingest($this->payload($this->coverage()))->assertOk();
        $this->assertSame('unknown', $this->instance->fresh()->health());
        $this->travel(5)->minutes();
        $this->instance->update(['last_heartbeat_at' => now()]);
        $this->ingest($this->payload($this->coverage()))->assertOk();
        $this->assertSame('healthy', $this->instance->fresh()->health());
    }

    public function test_missing_queue_data_in_a_fresh_heartbeat_is_not_presented_as_current(): void
    {
        $heartbeat = ['schema_version' => 1, 'agent_version' => '0.1.0', 'sent_at' => now()->toIso8601String()];
        $this->withToken($this->token)->postJson('/api/v1/heartbeat', $heartbeat + ['queue' => ['pending' => 0, 'failed' => 0]])->assertOk();
        $this->assertTrue($this->instance->fresh()->meta['queue_available']);
        $this->assertSame(now()->getTimestamp(), $this->instance->fresh()->meta['queue_reported_at']);
        $this->travel(1)->minutes();
        $heartbeat['sent_at'] = now()->toIso8601String();
        $this->withToken($this->token)->postJson('/api/v1/heartbeat', $heartbeat)->assertOk();
        $this->assertFalse($this->instance->fresh()->meta['queue_available']);
    }
}
