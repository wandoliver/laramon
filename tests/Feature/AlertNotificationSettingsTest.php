<?php

namespace Tests\Feature;

use App\Livewire\Alerts;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Instance;
use App\Models\User;
use App\Services\AlertNotificationSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AlertNotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function form()
    {
        return Livewire::actingAs(User::factory()->create())
            ->test(Alerts::class)
            ->set('name', 'Instance down')
            ->set('threshold', '10');
    }

    private function makeRule(array $overrides = []): AlertRule
    {
        return AlertRule::query()->create(array_merge([
            'name' => 'Instance down',
            'metric_type' => AlertRule::TYPE_HEARTBEAT,
            'aggregate' => 'count',
            'operator' => '>',
            'threshold' => 10,
            'window_minutes' => 15,
            'cooldown_minutes' => 30,
            'webhook_url' => null,
            'email_recipients' => [],
            'enabled' => true,
        ], $overrides));
    }

    public function test_rule_can_be_created_and_edited_without_external_channels(): void
    {
        $form = $this->form()
            ->set('webhook_url', '   ')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Notifications: Internal only');

        $rule = AlertRule::query()->sole();
        $this->assertNull($rule->webhook_url);
        $this->assertSame([], $rule->email_recipients);

        $form->call('edit', $rule->id)
            ->assertSet('webhook_url', '')
            ->assertSet('email_recipients', '')
            ->set('name', 'Renamed internal rule')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Renamed internal rule', $rule->fresh()->name);
        $this->assertNull($rule->fresh()->webhook_url);
    }

    public function test_email_only_rule_normalizes_and_deduplicates_recipients(): void
    {
        $this->form()
            ->set('email_recipients', " Ops@Example.com ; second@example.com\r\nops@example.com, third@example.com ")
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Notifications: Email');

        $rule = AlertRule::query()->sole();
        $this->assertNull($rule->webhook_url);
        $this->assertSame(['Ops@Example.com', 'second@example.com', 'third@example.com'], $rule->email_recipients);
    }

    public function test_teams_and_email_can_be_combined_and_removed(): void
    {
        $form = $this->form()
            ->set('webhook_url', ' https://teams.test/webhook ')
            ->set('email_recipients', 'ops@example.com')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Notifications: Teams + Email');

        $rule = AlertRule::query()->sole();
        $this->assertSame('https://teams.test/webhook', $rule->webhook_url);

        $form->call('edit', $rule->id)
            ->assertSet('email_recipients', 'ops@example.com')
            ->set('webhook_url', '')
            ->set('email_recipients', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Notifications: Internal only');

        $this->assertNull($rule->fresh()->webhook_url);
        $this->assertSame([], $rule->fresh()->email_recipients);
    }

    #[DataProvider('invalidRecipients')]
    public function test_unsafe_or_invalid_recipients_are_rejected(string $input): void
    {
        $this->form()
            ->set('email_recipients', $input)
            ->call('save')
            ->assertHasErrors('email_recipients');

        $this->assertDatabaseCount('alert_rules', 0);
    }

    public static function invalidRecipients(): array
    {
        return [
            'malformed address' => ['not-an-address'],
            'display name' => ['Operations <ops@example.com>'],
            'address comment unsupported by sender' => ['ops(comment)@example.com'],
            'unicode local part unsupported by sender' => ['tést@example.com'],
            'unicode domain unsupported by sender' => ['ops@bücher.de'],
            'header injection' => ["ops@example.com\r\nBcc: outsider@example.com"],
            'bare carriage return' => ["ops@example.com\rbcc@example.com"],
            'control byte' => ["ops@example.com\0"],
            'embedded tab' => ["ops\t@example.com"],
            'overlong address' => [str_repeat('a', 245).'@example.com'],
            'too many recipients' => [implode(', ', array_map(fn ($number) => "ops{$number}@example.com", range(1, 11)))],
            'oversized input' => [str_repeat('a', 3001)],
        ];
    }

    #[DataProvider('invalidWebhooks')]
    public function test_webhook_must_use_http_or_https_when_present(string $url): void
    {
        $this->form()->set('webhook_url', $url)->call('save')->assertHasErrors('webhook_url');

        $this->assertDatabaseCount('alert_rules', 0);
    }

    public static function invalidWebhooks(): array
    {
        return [
            ['ftp://teams.test/webhook'],
            ['file:///etc/passwd'],
            ['javascript:alert(1)'],
            ['not a URL'],
        ];
    }

    public function test_internal_only_test_sends_nothing_and_creates_no_event(): void
    {
        Http::fake();
        Mail::fake();
        $rule = $this->makeRule();

        $this->form()->call('sendTest', $rule->id)
            ->assertSee('uses internal alarms only')
            ->assertSee('no test notification was sent')
            ->assertDontSee('delivery failed');

        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        $this->assertDatabaseCount('alert_events', 0);
    }

    public function test_channel_test_reports_partial_delivery_without_exposing_addresses_or_webhook(): void
    {
        $rule = $this->makeRule([
            'webhook_url' => 'https://teams.test/private-token',
            'email_recipients' => ['private-recipient@example.com'],
        ]);

        $sender = Mockery::mock(AlertNotificationSender::class);
        $sender->shouldReceive('test')->once()->with(Mockery::on(fn ($argument) => $argument->is($rule)))
            ->andReturn(['teams' => 'sent', 'email' => 'failed']);
        $this->app->instance(AlertNotificationSender::class, $sender);

        $component = $this->form()->call('sendTest', $rule->id)
            ->assertSee('Teams: delivered; Email: delivery failed');

        $result = $component->get('testResult');
        $this->assertStringNotContainsString('private-token', $result);
        $this->assertStringNotContainsString('private-recipient', $result);
        $this->assertDatabaseCount('alert_events', 0);
    }

    public function test_event_delivery_uses_recorded_channel_statuses_and_preserves_legacy_rows(): void
    {
        $rule = $this->makeRule(['webhook_url' => 'https://teams.test/webhook']);
        $instance = Instance::factory()->create();

        foreach ([
            ['triggered' => ['teams' => 'disabled', 'email' => 'disabled']],
            ['triggered' => ['teams' => 'sent', 'email' => 'failed'], 'resolved' => ['teams' => 'failed', 'email' => 'sent']],
            ['triggered' => ['teams' => 'pending', 'email' => 'disabled']],
            null,
        ] as $statuses) {
            AlertEvent::query()->create([
                'alert_rule_id' => $rule->id,
                'instance_id' => $instance->id,
                'value' => 20,
                'triggered_at' => now(),
                'resolved_at' => isset($statuses['resolved']) ? now() : null,
                'notified' => false,
                'notification_status' => $statuses,
            ]);
        }

        $this->form()
            ->assertSee('Alarm delivery:')
            ->assertSee('Internal only')
            ->assertSee('Teams: delivered')
            ->assertSee('Email: delivery failed')
            ->assertSee('Recovery delivery:')
            ->assertSee('Teams: delivery failed')
            ->assertSee('Email: delivered')
            ->assertSee('Teams: pending')
            ->assertSee('Email: not configured')
            ->assertSee('not delivered');
    }

    public function test_new_internal_only_event_never_displays_nondelivery_after_rule_is_edited(): void
    {
        $rule = $this->makeRule(['webhook_url' => 'https://teams.test/new-webhook']);
        $instance = Instance::factory()->create();
        AlertEvent::query()->create([
            'alert_rule_id' => $rule->id,
            'instance_id' => $instance->id,
            'value' => 20,
            'triggered_at' => now(),
            'notified' => false,
            'notification_status' => ['triggered' => ['teams' => 'disabled', 'email' => 'disabled']],
        ]);

        $this->form()->assertSee('Internal only')->assertDontSee('not delivered');
    }

    public function test_email_settings_route_requires_authentication(): void
    {
        $this->get(route('alerts.email-templates'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())->get(route('alerts.email-templates'))->assertOk();
    }
}
