<?php

namespace Tests\Feature;

use App\Livewire\Alerts;
use App\Models\AlertRule;
use App\Models\Instance;
use App\Models\MetricBucket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AlertMetricCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_builtin_metrics_are_selectable_before_any_data_arrives(): void
    {
        $metrics = [
            'active_user', 'cache:hit', 'cache:miss', 'counter:agent.export_gap_minutes',
            'exception', 'gauge:queue.failed', 'gauge:queue.oldest_pending_seconds',
            'gauge:queue.pending', 'job:failed', 'job:processed', 'job:queued',
            'job:released', 'request', 'request_hist', 'scheduled_task',
            'scheduled_task:failed', 'scheduled_task:last_failure',
            'scheduled_task:last_success', 'scheduled_task:runtime', 'slow_job',
            'slow_outgoing_request', 'slow_query', 'slow_request',
        ];

        $component = Livewire::actingAs(User::factory()->create())->test(Alerts::class);

        $component->assertViewHas('metricTypes', fn ($types) => $types->all() === [
            AlertRule::TYPE_HEARTBEAT, AlertRule::TYPE_DATA_FLOW, ...$metrics,
        ]);

        foreach ($metrics as $metric) {
            $component->assertSeeHtml('<option value="'.$metric.'">'.$metric.'</option>');
        }

        $this->assertDatabaseCount('metric_buckets', 0);
        $this->assertDatabaseCount('alert_events', 0);
    }

    #[DataProvider('failureTypes')]
    public function test_failure_rules_can_be_created_and_edited_before_the_first_bucket(string $type): void
    {
        $instance = Instance::factory()->create();
        $component = Livewire::actingAs(User::factory()->create())
            ->test(Alerts::class)
            ->set('name', 'Failure alert')
            ->set('instance_id', $instance->id)
            ->set('metric_type', $type)
            ->set('aggregate', 'count')
            ->set('operator', '>')
            ->set('threshold', '0')
            ->set('window_minutes', 5)
            ->set('email_recipients', 'ops@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $rule = AlertRule::query()->sole();
        $this->assertSame($type, $rule->metric_type);
        $this->assertSame($instance->id, $rule->instance_id);
        $this->assertSame(['ops@example.com'], $rule->email_recipients);
        $this->assertNull($rule->webhook_url);

        $component->call('edit', $rule->id)
            ->assertSet('metric_type', $type)
            ->assertSet('instance_id', $instance->id)
            ->assertSet('email_recipients', 'ops@example.com')
            ->assertSet('webhook_url', '')
            ->set('name', 'Renamed failure alert')
            ->call('save')
            ->assertHasNoErrors();

        $rule->refresh();
        $this->assertSame('Renamed failure alert', $rule->name);
        $this->assertSame($type, $rule->metric_type);
        $this->assertSame(['ops@example.com'], $rule->email_recipients);
        $this->assertNull($rule->webhook_url);
        $this->assertDatabaseCount('metric_buckets', 0);
        $this->assertDatabaseCount('alert_events', 0);
    }

    public static function failureTypes(): array
    {
        return [
            'queue failure' => ['job:failed'],
            'scheduler failure' => ['scheduled_task:failed'],
        ];
    }

    public function test_observed_custom_metrics_remain_selectable_without_duplicate_options(): void
    {
        $instance = Instance::factory()->create();

        foreach (['gauge:custom.pending', 'exception', 'job:failed', 'heartbeat'] as $type) {
            MetricBucket::query()->create([
                'instance_id' => $instance->id,
                'type' => $type,
                'key' => 'example',
                'key_hash' => MetricBucket::hashKey('example'),
                'bucket_start' => intdiv(now()->getTimestamp(), 300) * 300 - 300,
                'bucket_seconds' => 300,
                'count' => 1,
            ]);
        }

        Livewire::actingAs(User::factory()->create())
            ->test(Alerts::class)
            ->assertSeeHtml('<option value="gauge:custom.pending">gauge:custom.pending</option>')
            ->assertViewHas('metricTypes', function ($types) {
                $values = $types->all();

                return array_slice($values, 0, 2) === [AlertRule::TYPE_HEARTBEAT, AlertRule::TYPE_DATA_FLOW]
                    && count($values) === count(array_unique($values))
                    && in_array('gauge:custom.pending', $values, true);
            });

        $this->assertDatabaseCount('metric_buckets', 4);
    }
}
