<?php

namespace Tests\Feature;

use App\Livewire\Alerts;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Instance;
use App\Models\MetricBucket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AlertTest extends TestCase
{
    use RefreshDatabase;

    private function seedExceptions(Instance $instance, int $count): void
    {
        $start = now()->getTimestamp() - 600;
        $start -= $start % 300;

        MetricBucket::query()->create([
            'instance_id' => $instance->id,
            'type' => 'exception',
            'key' => md5('RuntimeException|app/Foo.php:1'),
            'key_hash' => MetricBucket::hashKey(md5('RuntimeException|app/Foo.php:1')),
            'bucket_start' => $start,
            'bucket_seconds' => 300,
            'count' => $count,
            'sum' => null,
            'min' => null,
            'max' => null,
        ]);
    }

    private function makeRule(array $overrides = []): AlertRule
    {
        return AlertRule::query()->create(array_merge([
            'name' => 'High error rate',
            'instance_id' => null,
            'metric_type' => 'exception',
            'aggregate' => 'count',
            'operator' => '>',
            'threshold' => 10,
            'window_minutes' => 15,
            'cooldown_minutes' => 30,
            'webhook_url' => 'https://teams.test/webhook',
            'enabled' => true,
        ], $overrides));
    }

    public function test_breach_creates_event_and_notifies_once(): void
    {
        Http::fake(['teams.test/*' => Http::response('ok')]);

        $instance = Instance::factory()->create();
        $this->seedExceptions($instance, 20);
        $this->makeRule();

        $this->artisan('monitor:evaluate-alerts')->assertExitCode(0);
        $this->artisan('monitor:evaluate-alerts')->assertExitCode(0); // still breached → no repeat

        $this->assertSame(1, AlertEvent::query()->count());
        $this->assertTrue(AlertEvent::query()->sole()->notified);
        Http::assertSentCount(1);

        Http::assertSent(function ($request) use ($instance) {
            $card = $request['attachments'][0]['content'] ?? [];

            return str_contains($card['body'][0]['text'] ?? '', $instance->name)
                && str_contains($card['body'][0]['text'] ?? '', '🔴');
        });
    }

    public function test_recovery_resolves_and_sends_recovery_card(): void
    {
        Http::fake(['teams.test/*' => Http::response('ok')]);

        $instance = Instance::factory()->create();
        $this->seedExceptions($instance, 20);
        $this->makeRule();

        $this->artisan('monitor:evaluate-alerts');

        // Errors stop: drop the breach data out of the window.
        MetricBucket::query()->delete();

        $this->artisan('monitor:evaluate-alerts');

        $event = AlertEvent::query()->sole();
        $this->assertNotNull($event->resolved_at);
        Http::assertSentCount(2);
    }

    public function test_cooldown_suppresses_immediate_retrigger(): void
    {
        Http::fake(['teams.test/*' => Http::response('ok')]);

        $instance = Instance::factory()->create();
        $this->seedExceptions($instance, 20);
        $this->makeRule(['cooldown_minutes' => 30]);

        $this->artisan('monitor:evaluate-alerts');
        AlertEvent::query()->update(['resolved_at' => now()]); // flap: resolved but breach persists

        $this->artisan('monitor:evaluate-alerts');

        $this->assertSame(1, AlertEvent::query()->count());
        Http::assertSentCount(1);
    }

    public function test_heartbeat_rule_fires_for_silent_instance_only(): void
    {
        Http::fake(['teams.test/*' => Http::response('ok')]);

        $silent = Instance::factory()->create(['last_heartbeat_at' => now()->subMinutes(30)]);
        Instance::factory()->create(['last_heartbeat_at' => now()]);

        $this->makeRule([
            'name' => 'Instance down',
            'metric_type' => AlertRule::TYPE_HEARTBEAT,
            'threshold' => 10,
        ]);

        $this->artisan('monitor:evaluate-alerts');

        $events = AlertEvent::query()->get();
        $this->assertCount(1, $events);
        $this->assertSame($silent->id, $events[0]->instance_id);
    }

    public function test_fleet_wide_rule_fans_out_per_instance(): void
    {
        Http::fake(['teams.test/*' => Http::response('ok')]);

        $a = Instance::factory()->create();
        $b = Instance::factory()->create();
        $this->seedExceptions($a, 20);
        $this->seedExceptions($b, 20);
        $this->makeRule();

        $this->artisan('monitor:evaluate-alerts');

        $this->assertSame(2, AlertEvent::query()->count());
        Http::assertSentCount(2);
    }

    public function test_failed_webhook_still_records_the_event(): void
    {
        Http::fake(['teams.test/*' => Http::response([], 500)]);

        $instance = Instance::factory()->create();
        $this->seedExceptions($instance, 20);
        $this->makeRule();

        $this->artisan('monitor:evaluate-alerts');

        $event = AlertEvent::query()->sole();
        $this->assertFalse($event->notified);
    }

    public function test_alerts_page_renders_and_rules_can_be_created(): void
    {
        $user = User::factory()->create();
        Instance::factory()->create();

        $this->actingAs($user)->get('/alerts')->assertOk()->assertSee('Alerts');

        Livewire::actingAs($user)
            ->test(Alerts::class)
            ->set('name', 'Queue backlog')
            ->set('metric_type', 'gauge:queue.pending')
            ->set('aggregate', 'max')
            ->set('operator', '>')
            ->set('threshold', '100')
            ->set('window_minutes', 15)
            ->set('cooldown_minutes', 30)
            ->set('webhook_url', 'https://teams.test/webhook')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Queue backlog', AlertRule::query()->sole()->name);
    }

    public function test_open_alert_events_can_be_manually_resolved_with_a_comment(): void
    {
        $user = User::factory()->create();
        $instance = Instance::factory()->create();
        $rule = $this->makeRule(['instance_id' => $instance->id]);

        $event = AlertEvent::query()->create([
            'alert_rule_id' => $rule->id,
            'instance_id' => $instance->id,
            'value' => 25,
            'triggered_at' => now(),
            'notified' => true,
        ]);

        Livewire::actingAs($user)
            ->test(Alerts::class)
            ->call('startResolving', $event->id)
            ->set('resolutionComment', 'Deployed a fix and verified the queue drained.')
            ->call('resolveEvent')
            ->assertHasNoErrors()
            ->assertSet('resolvingEventId', null)
            ->assertSet('resolutionComment', '');

        $event->refresh();

        $this->assertNotNull($event->resolved_at);
        $this->assertSame($user->id, $event->resolved_by_user_id);
        $this->assertSame('Deployed a fix and verified the queue drained.', $event->resolved_comment);
    }
}
