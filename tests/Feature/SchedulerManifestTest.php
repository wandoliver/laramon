<?php

namespace Tests\Feature;

use App\Models\Instance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchedulerManifestTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $tasks): array
    {
        return [
            'schema_version' => 1,
            'agent_version' => '0.3.1',
            'sent_at' => now()->toIso8601String(),
            'scheduler' => ['version' => 1, 'tasks' => $tasks, 'exported_through' => now()->subMinutes(10)->getTimestamp()],
        ];
    }

    private static function task(): array
    {
        return ['name' => 'office365-subscriptions', 'expression' => '17 * * * *', 'timezone' => 'Europe/Rome', 'grace_seconds' => 900];
    }

    public function test_heartbeat_preserves_activation_until_schedule_changes_and_can_disable_expectations(): void
    {
        $this->travelTo(now()->startOfMinute());
        $instance = Instance::factory()->create(['meta' => ['keep' => 'existing']]);
        $token = $instance->rotateToken();
        $this->withToken($token)->postJson('/api/v1/heartbeat', $this->payload([self::task()]))->assertOk();
        $firstSeen = $instance->fresh()->meta['scheduler']['tasks'][0]['observed_since'];
        $this->travel(2)->minutes();
        $this->withToken($token)->postJson('/api/v1/heartbeat', $this->payload([self::task()]))->assertOk();
        $meta = $instance->fresh()->meta;
        $this->assertSame($firstSeen, $meta['scheduler']['tasks'][0]['observed_since']);
        $this->assertSame(now()->getTimestamp(), $meta['scheduler']['reported_at']);
        $this->assertSame('existing', $meta['keep']);
        $this->assertTrue($meta['scheduler']['available']);
        $this->assertSame(0, $meta['scheduler']['clock_skew_seconds']);
        $this->assertSame(now()->subMinutes(10)->getTimestamp(), $meta['scheduler']['exported_through']);

        $changed = array_replace(self::task(), ['expression' => '30 * * * *']);
        $this->withToken($token)->postJson('/api/v1/heartbeat', $this->payload([$changed]))->assertOk();
        $this->assertSame(now()->getTimestamp(), $instance->fresh()->meta['scheduler']['tasks'][0]['observed_since']);

        $this->withToken($token)->postJson('/api/v1/heartbeat', $this->payload([]))->assertOk();
        $this->assertSame([], $instance->fresh()->meta['scheduler']['tasks']);
    }

    public function test_older_agent_does_not_claim_existing_expectations_are_current(): void
    {
        $instance = Instance::factory()->create();
        $token = $instance->rotateToken();
        $this->withToken($token)->postJson('/api/v1/heartbeat', $this->payload([self::task()]))->assertOk();
        $payload = $this->payload([]);
        unset($payload['scheduler']);
        $this->withToken($token)->postJson('/api/v1/heartbeat', $payload)->assertOk();
        $this->assertFalse($instance->fresh()->meta['scheduler']['available']);
        $this->assertCount(1, $instance->fresh()->meta['scheduler']['tasks']);
    }

    public static function invalidManifests(): array
    {
        $task = self::task();

        return [
            'duplicate names' => [[$task, $task]],
            'raw command name' => [[array_replace($task, ['name' => 'command --password=secret'])]],
            'unknown field' => [[$task + ['command' => 'private']]],
            'invalid cron' => [[array_replace($task, ['expression' => 'not a valid cron'])]],
            'invalid timezone' => [[array_replace($task, ['timezone' => 'Not/AZone'])]],
            'invalid grace' => [[array_replace($task, ['grace_seconds' => 30])]],
            'oversize grace' => [[array_replace($task, ['grace_seconds' => 604801])]],
            'client activation time' => [[$task + ['observed_since' => 0]]],
            'not a list' => [['unexpected' => $task]],
            'too many tasks' => [array_fill(0, 101, $task)],
        ];
    }

    #[DataProvider('invalidManifests')]
    public function test_invalid_manifest_does_not_mutate_any_instance(array $tasks): void
    {
        $instance = Instance::factory()->create(['last_heartbeat_at' => null, 'meta' => ['unchanged' => true]]);
        $token = $instance->rotateToken();
        $this->withToken($token)->postJson('/api/v1/heartbeat', $this->payload($tasks))->assertUnprocessable();
        $this->assertSame(['unchanged' => true], $instance->fresh()->meta);
        $this->assertNull($instance->fresh()->last_heartbeat_at);
    }

    public function test_manifest_is_authenticated_and_tenant_scoped(): void
    {
        $one = Instance::factory()->create(['meta' => []]);
        $two = Instance::factory()->create(['meta' => []]);
        $this->postJson('/api/v1/heartbeat', $this->payload([self::task()]))->assertUnauthorized();
        $this->assertSame([], $one->fresh()->meta);
        $this->withToken($one->rotateToken())->postJson('/api/v1/heartbeat', $this->payload([self::task()]))->assertOk();
        $this->assertArrayHasKey('scheduler', $one->fresh()->meta);
        $this->assertSame([], $two->fresh()->meta);
    }

    public function test_corrupt_legacy_manifest_is_replaced_without_trusting_its_baseline(): void
    {
        foreach (['broken', ['tasks' => 'broken'], ['tasks' => [['name' => self::task()['name']]]]] as $legacy) {
            $instance = Instance::factory()->create(['meta' => ['scheduler' => $legacy]]);
            $token = $instance->rotateToken();
            $this->withToken($token)->postJson('/api/v1/heartbeat', $this->payload([self::task()]))->assertOk();
            $this->assertSame(now()->getTimestamp(), $instance->fresh()->meta['scheduler']['tasks'][0]['observed_since']);
        }
    }

    public function test_legacy_heartbeat_with_corrupt_optional_metadata_still_works(): void
    {
        $instance = Instance::factory()->create(['meta' => ['scheduler' => 'broken']]);
        $payload = $this->payload([]);
        unset($payload['scheduler']);
        $this->withToken($instance->rotateToken())->postJson('/api/v1/heartbeat', $payload)->assertOk();
        $this->assertSame('broken', $instance->fresh()->meta['scheduler']);
    }
}
