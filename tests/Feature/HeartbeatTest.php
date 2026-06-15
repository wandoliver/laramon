<?php

namespace Tests\Feature;

use App\Models\Instance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_updates_instance(): void
    {
        $instance = Instance::factory()->create(['last_heartbeat_at' => null]);
        $token = $instance->rotateToken();

        $this->withToken($token)->postJson('/api/v1/heartbeat', [
            'schema_version' => 1,
            'agent_version' => '0.1.0',
            'app_version' => '2.4.0',
            'php_version' => '8.4.21',
            'laravel_version' => '13.15.0',
            'sent_at' => now()->toIso8601String(),
            'queue' => ['pending' => 14, 'oldest_pending_seconds' => 95, 'failed' => 2],
        ])->assertOk();

        $instance->refresh();

        $this->assertNotNull($instance->last_heartbeat_at);
        $this->assertSame('2.4.0', $instance->meta['app_version']);
        $this->assertSame(14, $instance->meta['queue']['pending']);
        $this->assertSame('healthy', $instance->health());
    }

    public function test_heartbeat_requires_token(): void
    {
        $this->postJson('/api/v1/heartbeat', ['schema_version' => 1])->assertStatus(401);
    }

    public function test_heartbeat_rejects_unsupported_schema_version(): void
    {
        $instance = Instance::factory()->create();
        $token = $instance->rotateToken();

        $this->withToken($token)->postJson('/api/v1/heartbeat', [
            'schema_version' => 999,
            'agent_version' => '0.1.0',
            'sent_at' => now()->toIso8601String(),
        ])->assertStatus(422);
    }
}
