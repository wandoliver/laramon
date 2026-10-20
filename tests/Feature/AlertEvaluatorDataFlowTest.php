<?php

namespace Tests\Feature;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Instance;
use App\Models\MetricBucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlertEvaluatorDataFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-19 12:22:00', 'UTC'));
        Http::preventStrayRequests();
        Http::fake(['teams.test/*' => Http::response('ok')]);
    }

    public function test_missing_data_and_unrelated_fresh_ingests_never_resolve_an_open_event(): void
    {
        $instance = Instance::factory()->create(['last_ingest_at' => now()]);
        $event = $this->openEvent($instance, $this->rule($instance));
        $this->bucket($instance, 'request', 'GET /', $this->through() - 300, 40);

        $this->evaluate();

        $this->assertNull($event->refresh()->resolved_at);
        Http::assertNothingSent();
    }

    public function test_missing_coverage_cannot_open_a_low_traffic_alert_from_an_empty_window(): void
    {
        $instance = Instance::factory()->create(['last_ingest_at' => now()]);
        $this->rule($instance, ['metric_type' => 'request', 'operator' => '<', 'threshold' => 1]);

        $this->evaluate();

        $this->assertSame(0, AlertEvent::query()->count());
        Http::assertNothingSent();
    }

    public function test_observed_exception_breaches_still_open_for_legacy_agents(): void
    {
        $instance = Instance::factory()->create();
        $this->rule($instance);
        $this->bucket($instance, 'exception', 'fingerprint', $this->through() - 300, 20);

        $this->evaluate();

        $this->assertSame(1, AlertEvent::query()->count());
        $this->assertNull(AlertEvent::query()->sole()->resolved_at);
        Http::assertSentCount(1);
    }

    public function test_fresh_complete_typed_coverage_allows_quiet_window_recovery(): void
    {
        $instance = $this->verifiedInstance();
        $event = $this->openEvent($instance, $this->rule($instance));

        $this->evaluate();

        $this->assertNotNull($event->refresh()->resolved_at);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['attachments'][0]['content']['body'][0]['text'] ?? '', 'Resolved:'));
    }

    public function test_typed_coverage_can_prove_zero_for_a_low_traffic_rule(): void
    {
        $instance = $this->verifiedInstance(['types' => ['request']]);
        $this->rule($instance, ['metric_type' => 'request', 'operator' => '<', 'threshold' => 1]);

        $this->evaluate();

        $this->assertSame(0.0, AlertEvent::query()->sole()->value);
        Http::assertSentCount(1);
    }

    public function test_stale_missing_partial_skewed_and_unrelated_coverage_never_clear_an_alert(): void
    {
        foreach (['heartbeat', 'export', 'confirmed', 'coverage', 'partial', 'types', 'skew', 'future', 'version', 'odd_window'] as $case) {
            $instance = $this->verifiedInstance();
            $meta = $instance->meta;
            $overrides = [];

            match ($case) {
                'heartbeat' => $instance->last_heartbeat_at = now()->subSeconds(121),
                'export' => $instance->last_ingest_at = now()->subSeconds(901),
                'confirmed' => $meta['data_flow']['confirmed_at'] = now()->subSeconds(901)->getTimestamp(),
                'coverage' => $meta['data_flow']['covered_through'] = $this->through() - 1800,
                'partial' => $meta['data_flow']['covered_from'] = $this->through() - 300,
                'types' => $meta['data_flow']['types'] = ['request'],
                'skew' => $meta['data_flow']['clock_skew_seconds'] = 61,
                'future' => $meta['data_flow']['confirmed_at'] = now()->addSecond()->getTimestamp(),
                'version' => $meta['data_flow']['version'] = 2,
                'odd_window' => $overrides = ['window_minutes' => 7],
            };

            $instance->meta = $meta;
            $instance->save();
            $event = $this->openEvent($instance, $this->rule($instance, $overrides));

            $this->evaluate();

            $this->assertNull($event->refresh()->resolved_at, $case);
        }

        Http::assertNothingSent();
    }

    public function test_export_gaps_prevent_recovery_even_with_current_typed_coverage(): void
    {
        $instance = $this->verifiedInstance();
        $event = $this->openEvent($instance, $this->rule($instance));
        $this->bucket($instance, 'counter:agent.export_gap_minutes', 'agent.export_gap_minutes', $this->through() - 300, 1, 5);

        $this->evaluate();

        $this->assertNull($event->refresh()->resolved_at);
        Http::assertNothingSent();
    }

    public function test_a_fresh_heartbeat_alone_cannot_resolve_a_heartbeat_alert(): void
    {
        $instance = Instance::factory()->create(['last_ingest_at' => now()]);
        $rule = $this->rule($instance, ['metric_type' => AlertRule::TYPE_HEARTBEAT]);
        $event = $this->openEvent($instance, $rule);

        $this->evaluate();
        $this->assertNull($event->refresh()->resolved_at);
        Http::assertNothingSent();

        $instance->forceFill($this->verifiedAttributes())->save();
        $this->evaluate();

        $this->assertNotNull($event->refresh()->resolved_at);
        Http::assertSentCount(1);
    }

    public function test_the_data_flow_rule_reports_unverified_state_and_only_recovers_with_proof(): void
    {
        $instance = Instance::factory()->create();
        $this->rule($instance, ['metric_type' => AlertRule::TYPE_DATA_FLOW, 'threshold' => 0]);

        $this->evaluate();
        $event = AlertEvent::query()->sole();
        $this->assertSame(1.0, $event->value);

        $instance->forceFill(['last_ingest_at' => now()])->save();
        $this->evaluate();
        $this->assertNull($event->refresh()->resolved_at);
        Http::assertSentCount(1);

        $this->travel(1)->seconds();
        $instance->forceFill($this->verifiedAttributes())->save();
        $this->evaluate();

        $this->assertNotNull($event->refresh()->resolved_at);
        Http::assertSentCount(2);
    }

    public function test_business_metrics_need_explicit_samples_for_every_bucket_and_an_exact_key(): void
    {
        $instance = $this->verifiedInstance();
        $rule = $this->rule($instance, ['metric_type' => 'gauge:queue.pending', 'key' => 'critical', 'aggregate' => 'max']);
        $event = $this->openEvent($instance, $rule);

        $this->evaluate();
        $this->assertNull($event->refresh()->resolved_at);

        $this->bucket($instance, $rule->metric_type, 'critical', $this->through() - 900, 1, 0);
        $this->bucket($instance, $rule->metric_type, 'critical', $this->through() - 300, 1, 0);
        $this->bucket($instance, $rule->metric_type, 'other-key', $this->through() - 600, 1, 0);
        $this->evaluate();
        $this->assertNull($event->refresh()->resolved_at);
        Http::assertNothingSent();

        $this->bucket($instance, $rule->metric_type, 'critical', $this->through() - 600, 1, 0);
        $this->evaluate();

        $this->assertNotNull($event->refresh()->resolved_at);
        Http::assertSentCount(1);
    }

    public function test_a_keyless_business_rule_cannot_infer_that_all_collectors_have_reported(): void
    {
        $instance = $this->verifiedInstance();
        $rule = $this->rule($instance, ['metric_type' => 'counter:critical.events']);
        $event = $this->openEvent($instance, $rule);

        foreach ([$this->through() - 900, $this->through() - 600, $this->through() - 300] as $start) {
            $this->bucket($instance, $rule->metric_type, 'default', $start, 0, 0);
        }

        $this->evaluate();

        $this->assertNull($event->refresh()->resolved_at);
        Http::assertNothingSent();
    }

    public function test_coverage_for_one_instance_does_not_resolve_another_instances_alert(): void
    {
        $this->verifiedInstance();
        $other = Instance::factory()->create();
        $event = $this->openEvent($other, $this->rule($other));

        $this->evaluate();

        $this->assertNull($event->refresh()->resolved_at);
        Http::assertNothingSent();
    }

    public function test_a_completed_window_before_an_observed_breach_cannot_clear_it(): void
    {
        $instance = Instance::factory()->create();
        $this->rule($instance);
        $this->bucket($instance, 'exception', 'new-breach', $this->through(), 20);
        $this->evaluate();
        $event = AlertEvent::query()->sole();

        // The proof ends at 12:20, while the breach arrived at 12:22.
        $instance->forceFill($this->verifiedAttributes())->save();
        $this->evaluate();

        $this->assertNull($event->refresh()->resolved_at);
        Http::assertSentCount(1);
    }

    public function test_a_data_flow_event_cannot_recover_from_a_confirmation_that_predates_it(): void
    {
        $instance = $this->verifiedInstance(['confirmed_at' => now()->subMinute()->getTimestamp()]);
        $rule = $this->rule($instance, ['metric_type' => AlertRule::TYPE_DATA_FLOW, 'threshold' => 0]);
        $event = $this->openEvent($instance, $rule);
        $event->forceFill(['triggered_at' => now()])->save();

        $this->evaluate();

        $this->assertNull($event->refresh()->resolved_at);
        Http::assertNothingSent();
    }

    public function test_a_half_open_window_ending_exactly_at_the_breach_cannot_clear_it(): void
    {
        $instance = $this->verifiedInstance();
        $event = $this->openEvent($instance, $this->rule($instance));
        $event->forceFill(['triggered_at' => Carbon::createFromTimestamp($this->through())])->save();

        $this->evaluate();

        $this->assertNull($event->refresh()->resolved_at);
        Http::assertNothingSent();
    }

    public function test_an_invalid_operator_cannot_be_treated_as_a_recovery(): void
    {
        $instance = $this->verifiedInstance();
        $event = $this->openEvent($instance, $this->rule($instance, ['operator' => '?']));

        $this->evaluate();

        $this->assertNull($event->refresh()->resolved_at);
        Http::assertNothingSent();
    }

    public function test_a_newer_unconfirmed_breach_keeps_an_older_alert_open_despite_a_clean_confirmed_window(): void
    {
        $this->travel(5)->minutes();
        $instance = $this->verifiedInstance();
        $event = $this->openEvent($instance, $this->rule($instance));
        // This newer, closed bucket arrived before its final acknowledgement.
        $this->bucket($instance, 'exception', 'new-breach', $this->through(), 20);

        $this->evaluate();

        $this->assertNull($event->refresh()->resolved_at);
        Http::assertNothingSent();
    }

    public function test_a_newer_observed_breach_can_open_an_alert_even_while_older_coverage_is_clean(): void
    {
        $this->travel(5)->minutes();
        $instance = $this->verifiedInstance();
        $this->rule($instance);
        $this->bucket($instance, 'exception', 'new-breach', $this->through(), 20);

        $this->evaluate();

        $this->assertSame(20.0, AlertEvent::query()->sole()->value);
        $this->assertNull(AlertEvent::query()->sole()->resolved_at);
        Http::assertSentCount(1);
    }

    public function test_newer_maximum_and_minimum_breaches_also_block_recovery(): void
    {
        $this->travel(5)->minutes();

        foreach ([['max', '>', 20], ['min', '<', 1]] as [$aggregate, $operator, $value]) {
            $instance = $this->verifiedInstance();
            $rule = $this->rule($instance, [
                'metric_type' => 'gauge:critical.value',
                'key' => 'critical',
                'aggregate' => $aggregate,
                'operator' => $operator,
            ]);
            $event = $this->openEvent($instance, $rule);
            foreach ([$this->through() - 900, $this->through() - 600, $this->through() - 300] as $start) {
                $this->bucket($instance, $rule->metric_type, 'critical', $start, 1, 10);
            }
            $this->bucket($instance, $rule->metric_type, 'critical', $this->through(), 1, $value);

            $this->evaluate();

            $this->assertNull($event->refresh()->resolved_at, $aggregate);
        }

        Http::assertNothingSent();
    }

    private function verifiedInstance(array $coverage = []): Instance
    {
        return Instance::factory()->create($this->verifiedAttributes($coverage));
    }

    private function verifiedAttributes(array $coverage = []): array
    {
        return [
            'last_heartbeat_at' => now(),
            'last_ingest_at' => now(),
            'meta' => ['clock_skew_seconds' => 0, 'data_flow' => array_merge([
                'version' => 1,
                'covered_from' => $this->through() - 1200,
                'covered_through' => $this->through(),
                'types' => ['exception'],
                'confirmed_at' => now()->getTimestamp(),
                'clock_skew_seconds' => 0,
            ], $coverage)],
        ];
    }

    private function through(): int
    {
        return Carbon::parse('2026-10-19 12:20:00', 'UTC')->getTimestamp();
    }

    private function rule(Instance $instance, array $overrides = []): AlertRule
    {
        return AlertRule::query()->create(array_merge([
            'name' => 'Verified rule',
            'instance_id' => $instance->id,
            'metric_type' => 'exception',
            'key' => null,
            'aggregate' => 'count',
            'operator' => '>',
            'threshold' => 10,
            'window_minutes' => 15,
            'cooldown_minutes' => 30,
            'webhook_url' => 'https://teams.test/webhook',
            'enabled' => true,
            'trigger_delay_minutes' => 0, 'recovery_delay_minutes' => 0,
        ], $overrides));
    }

    private function openEvent(Instance $instance, AlertRule $rule): AlertEvent
    {
        return AlertEvent::query()->create([
            'alert_rule_id' => $rule->id,
            'instance_id' => $instance->id,
            'value' => 20,
            'triggered_at' => now()->subMinutes(20),
            'notified' => true,
            'notification_attempted_at' => now()->subMinutes(20),
            'notification_status' => ['triggered' => ['teams' => 'sent', 'email' => 'disabled']],
            'notification_targets' => ['teams' => hash('sha256', $rule->webhook_url), 'email' => []],
        ]);
    }

    private function bucket(Instance $instance, string $type, string $key, int $start, int $count, ?float $value = null): void
    {
        MetricBucket::query()->create([
            'instance_id' => $instance->id,
            'type' => $type,
            'key' => $key,
            'key_hash' => MetricBucket::hashKey($key),
            'bucket_start' => $start,
            'bucket_seconds' => 300,
            'count' => $count,
            'sum' => $value,
            'min' => $value,
            'max' => $value,
        ]);
    }

    private function evaluate(): void
    {
        $this->artisan('monitor:evaluate-alerts')->assertExitCode(0);
    }
}
