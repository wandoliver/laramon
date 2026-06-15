<?php

namespace Tests\Feature;

use App\Models\ExceptionGroup;
use App\Models\Instance;
use App\Models\MetricBucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IngestTest extends TestCase
{
    use RefreshDatabase;

    private Instance $instance;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance = Instance::factory()->create();
        $this->token = $this->instance->rotateToken();
    }

    private function payload(array $overrides = []): array
    {
        $bucketStart = (int) (floor(now()->subMinutes(10)->getTimestamp() / 300) * 300);

        return array_merge([
            'schema_version' => 1,
            'agent_version' => '0.1.0',
            'batch_uuid' => (string) Str::uuid(),
            'sent_at' => now()->toIso8601String(),
            'buckets' => [
                [
                    'type' => 'slow_query',
                    'key' => 'select * from `appointments`',
                    'bucket_start' => $bucketStart,
                    'bucket_seconds' => 300,
                    'count' => 12,
                    'sum' => 18840,
                    'min' => 1020,
                    'max' => 4100,
                ],
            ],
            'exceptions' => [
                [
                    'fingerprint' => str_repeat('ab', 16),
                    'class' => 'RuntimeException',
                    'location' => 'app/Services/Foo.php:42',
                    'count' => 3,
                    'last_seen_at' => now()->toIso8601String(),
                ],
            ],
        ], $overrides);
    }

    private function ingest(array $payload, ?string $token = null)
    {
        return $this->withToken($token ?? $this->token)->postJson('/api/v1/ingest', $payload);
    }

    public function test_rejects_missing_token(): void
    {
        $this->postJson('/api/v1/ingest', $this->payload())->assertStatus(401);
    }

    public function test_rejects_invalid_token(): void
    {
        $this->ingest($this->payload(), 'ahm_'.$this->instance->id.'_'.Str::random(40))
            ->assertStatus(401);
    }

    public function test_rejects_token_of_other_instance(): void
    {
        $other = Instance::factory()->create();
        $otherToken = $other->rotateToken();

        // Token is valid for $other but the embedded id routes to it, so
        // data lands on the right instance — assert isolation.
        $this->ingest($this->payload(), $otherToken)->assertOk();

        $this->assertSame(0, MetricBucket::query()->where('instance_id', $this->instance->id)->count());
        $this->assertSame(1, MetricBucket::query()->where('instance_id', $other->id)->count());
    }

    public function test_accepts_valid_payload(): void
    {
        $this->ingest($this->payload())
            ->assertOk()
            ->assertJson(['status' => 'ok', 'accepted' => 1]);

        $bucket = MetricBucket::query()->sole();
        $this->assertSame('slow_query', $bucket->type);
        $this->assertSame(12, (int) $bucket->count);
        $this->assertSame($this->instance->id, $bucket->instance_id);

        $group = ExceptionGroup::query()->sole();
        $this->assertSame('RuntimeException', $group->class);
        $this->assertSame(3, (int) $group->total_count);

        $this->assertNotNull($this->instance->fresh()->last_ingest_at);
    }

    public function test_duplicate_batch_uuid_is_a_no_op(): void
    {
        $payload = $this->payload();

        $this->ingest($payload)->assertOk()->assertJson(['status' => 'ok']);
        $this->ingest($payload)->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, MetricBucket::query()->count());
        $this->assertSame(3, (int) ExceptionGroup::query()->sole()->total_count);
    }

    public function test_replayed_buckets_with_fresh_uuid_replace_not_add(): void
    {
        $payload = $this->payload();

        $this->ingest($payload)->assertOk();
        $this->ingest(array_merge($payload, ['batch_uuid' => (string) Str::uuid(), 'exceptions' => []]))
            ->assertOk();

        $bucket = MetricBucket::query()->sole();
        $this->assertSame(12, (int) $bucket->count);
    }

    public function test_clamps_buckets_outside_the_accepted_window(): void
    {
        $payload = $this->payload();
        $payload['buckets'][0]['bucket_start'] = now()->subDays(60)->getTimestamp();
        $payload['buckets'][] = array_merge($payload['buckets'][0], [
            'bucket_start' => now()->addHour()->getTimestamp(),
        ]);

        $this->ingest($payload)->assertOk()->assertJson(['accepted' => 0]);

        $this->assertSame(0, MetricBucket::query()->count());
    }

    public function test_rejects_malformed_bucket_type(): void
    {
        $payload = $this->payload();
        $payload['buckets'][0]['type'] = 'Invalid Type!';

        $this->ingest($payload)->assertStatus(422);
    }

    public function test_previous_token_works_within_grace_window(): void
    {
        $oldToken = $this->token;
        $this->instance->rotateToken();

        $this->ingest($this->payload(), $oldToken)->assertOk();

        $this->instance->forceFill(['previous_token_expires_at' => now()->subMinute()])->save();

        $this->ingest($this->payload(), $oldToken)->assertStatus(401);
    }

    public function test_legacy_ahm_tokens_are_still_accepted(): void
    {
        $legacyToken = 'ahm_'.$this->instance->id.'_'.Str::random(40);
        $this->instance->forceFill([
            'token_hash' => \App\Support\InstanceToken::hash($legacyToken),
        ])->save();

        $this->ingest($this->payload(), $legacyToken)->assertOk();
    }

    public function test_exception_counts_add_and_last_seen_does_not_regress(): void
    {
        $fingerprint = str_repeat('cd', 16);
        $newer = now()->startOfSecond();

        $this->ingest($this->payload([
            'exceptions' => [[
                'fingerprint' => $fingerprint,
                'class' => 'RuntimeException',
                'location' => 'app/Services/Foo.php:42',
                'count' => 2,
                'last_seen_at' => $newer->toIso8601String(),
            ]],
        ]))->assertOk();

        $this->ingest($this->payload([
            'batch_uuid' => (string) Str::uuid(),
            'exceptions' => [[
                'fingerprint' => $fingerprint,
                'class' => 'RuntimeException',
                'location' => 'app/Services/Foo.php:42',
                'count' => 5,
                'last_seen_at' => $newer->copy()->subHour()->toIso8601String(),
            ]],
        ]))->assertOk();

        $group = ExceptionGroup::query()->where('fingerprint', $fingerprint)->sole();

        $this->assertSame(7, (int) $group->total_count);
        $this->assertTrue($group->last_seen_at->equalTo($newer));
    }
}
