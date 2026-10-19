<?php

namespace LaraMon\Agent\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LaraMon\Agent\Collectors\CollectorRegistry;
use LaraMon\Agent\Contracts\BusinessMetricCollector;
use LaraMon\Agent\Export\Coverage;
use LaraMon\Agent\Export\PulseExporter;
use LaraMon\Agent\Export\Watermark;
use LaraMon\Agent\MonitorAgent;
use LaraMon\Agent\Recorders\Requests;
use LaraMon\Agent\Recorders\ScheduledTasks;
use Laravel\Pulse\Recorders\Exceptions;

class ExportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['pulse.enabled' => true, 'pulse.recorders' => [
            Requests::class => [],
            Exceptions::class => [],
            ScheduledTasks::class => [],
        ]]);
    }

    private function closedBucketStart(): int
    {
        // A 300s window safely inside [watermark horizon, settled cutoff].
        return (int) (floor((now()->getTimestamp() - 900) / 300) * 300);
    }

    public function test_exports_rebucketed_aggregates_and_advances_watermark(): void
    {
        Http::fake(['hub.test/*' => Http::response(['status' => 'ok'])]);

        $start = $this->closedBucketStart();

        // Two 60s buckets in the same 300s window: counts add, avg
        // reconstructs the sum, min/max combine.
        $this->seedAggregate($start, 'request', 'home', 'count', 10);
        $this->seedAggregate($start, 'request', 'home', 'avg', 100, 10);
        $this->seedAggregate($start, 'request', 'home', 'min', 40);
        $this->seedAggregate($start, 'request', 'home', 'max', 300);
        $this->seedAggregate($start + 60, 'request', 'home', 'count', 20);
        $this->seedAggregate($start + 60, 'request', 'home', 'avg', 200, 20);
        $this->seedAggregate($start + 60, 'request', 'home', 'min', 20);
        $this->seedAggregate($start + 60, 'request', 'home', 'max', 900);

        $this->artisan('monitor-agent:export')->assertExitCode(0);

        Http::assertSent(function ($request) use ($start) {
            $bucket = collect($request['buckets'])->firstWhere('type', 'request');

            return $request->url() === 'https://hub.test/api/v1/ingest'
                && $bucket !== null
                && $bucket['key'] === 'home'
                && $bucket['bucket_start'] === $start
                && $bucket['count'] === 30
                && (float) $bucket['sum'] === 5000.0
                && (float) $bucket['min'] === 20.0
                && (float) $bucket['max'] === 900.0;
        });

        $this->assertNotNull(app(Watermark::class)->get());
        $this->assertGreaterThan($start, app(Watermark::class)->get());
    }

    public function test_latency_histogram_bins_pass_through(): void
    {
        Http::fake(['hub.test/*' => Http::response(['status' => 'ok'])]);

        $start = $this->closedBucketStart();
        $this->seedAggregate($start, 'request_hist', 'le_100', 'count', 12);
        $this->seedAggregate($start, 'request_hist', 'le_400', 'count', 3);

        $this->artisan('monitor-agent:export')->assertExitCode(0);

        Http::assertSent(function ($request) {
            $bins = collect($request['buckets'])->where('type', 'request_hist');

            return $bins->firstWhere('key', 'le_100')['count'] === 12
                && $bins->firstWhere('key', 'le_400')['count'] === 3;
        });
    }

    public function test_scheduler_runtime_counts_only_timed_successes_and_preserves_existing_metric(): void
    {
        Http::fake(['hub.test/*' => Http::response(['status' => 'ok'])]);

        $start = $this->closedBucketStart();
        $key = json_encode(['maintenance', '--tenant=pre']);

        // Two timed foreground successes and one background success without
        // a runtime share this task and bucket. The average remains 300 ms.
        $this->seedAggregate($start, 'scheduled_task', $key, 'count', 3);
        $this->seedAggregate($start, 'scheduled_task', $key, 'avg', 300, 2);
        $this->seedAggregate($start, 'scheduled_task', $key, 'max', 400);

        $this->artisan('monitor-agent:export')->assertExitCode(0);

        Http::assertSent(function ($request) use ($start) {
            $buckets = collect($request['buckets']);
            $outcome = $buckets->firstWhere('type', 'scheduled_task');
            $runtime = $buckets->firstWhere('type', 'scheduled_task:runtime');

            return $outcome !== null
                && $outcome['key'] === 'maintenance --tenant=pre'
                && $outcome['bucket_start'] === $start
                && $outcome['bucket_seconds'] === 300
                && $outcome['count'] === 3
                && (float) $outcome['sum'] === 600.0
                && $outcome['min'] === null
                && (float) $outcome['max'] === 400.0
                && $runtime !== null
                && $runtime['key'] === $outcome['key']
                && $runtime['bucket_start'] === $start
                && $runtime['bucket_seconds'] === 300
                && $runtime['count'] === 2
                && (float) $runtime['sum'] === 600.0
                && $runtime['min'] === null
                && $runtime['max'] === null;
        });
    }

    public function test_scheduler_runtime_rebuckets_timed_samples_and_ignores_unknown_sample_counts(): void
    {
        Http::fake(['hub.test/*' => Http::response(['status' => 'ok'])]);

        $start = $this->closedBucketStart();

        $this->seedAggregate($start, 'scheduled_task', 'timed', 'count', 3);
        $this->seedAggregate($start, 'scheduled_task', 'timed', 'avg', 100, 2);
        $this->seedAggregate($start + 60, 'scheduled_task', 'timed', 'count', 2);
        $this->seedAggregate($start + 60, 'scheduled_task', 'timed', 'avg', 400, 1);
        $this->seedAggregate($start, 'scheduled_task', 'unknown', 'avg', 500);
        $this->seedAggregate($start, 'scheduled_task', 'empty', 'avg', 500, 0);
        $this->seedAggregate($start, 'scheduled_task', 'untimed', 'count', 1);

        $this->artisan('monitor-agent:export')->assertExitCode(0);

        Http::assertSent(function ($request) use ($start) {
            $runtime = collect($request['buckets'])->where('type', 'scheduled_task:runtime')->values();

            return $runtime->count() === 1
                && $runtime[0]['key'] === 'timed'
                && $runtime[0]['bucket_start'] === $start
                && $runtime[0]['count'] === 3
                && (float) $runtime[0]['sum'] === 600.0
                && $runtime[0]['min'] === null
                && $runtime[0]['max'] === null;
        });
    }

    public function test_exact_scheduler_completion_timestamps_export_without_an_outcome_count(): void
    {
        Http::fake(['hub.test/*' => Http::response(['status' => 'ok'])]);

        $start = $this->closedBucketStart();
        $this->seedAggregate($start, 'scheduled_task_last_success', 'maintenance', 'max', $start + 5);
        $this->seedAggregate($start + 60, 'scheduled_task_last_success', 'maintenance', 'max', $start + 79);
        $this->seedAggregate($start, 'scheduled_task_last_failure', 'maintenance', 'max', $start + 9);

        $this->artisan('monitor-agent:export')->assertExitCode(0);

        Http::assertSent(function ($request) use ($start) {
            $buckets = collect($request['buckets']);
            $success = $buckets->firstWhere('type', 'scheduled_task:last_success');
            $failure = $buckets->firstWhere('type', 'scheduled_task:last_failure');

            return $success !== null
                && $success['key'] === 'maintenance'
                && $success['count'] === 0
                && $success['sum'] === null
                && $success['min'] === null
                && (int) $success['max'] === $start + 79
                && $failure !== null
                && $failure['key'] === 'maintenance'
                && $failure['count'] === 0
                && $failure['sum'] === null
                && $failure['min'] === null
                && (int) $failure['max'] === $start + 9;
        });
    }

    public function test_exception_aggregates_become_fingerprints_with_metadata(): void
    {
        Http::fake(['hub.test/*' => Http::response(['status' => 'ok'])]);

        $start = $this->closedBucketStart();
        $key = json_encode(['RuntimeException', 'app/Foo.php:10']);

        $this->seedAggregate($start, 'exception', $key, 'count', 4);
        $this->seedAggregate($start, 'exception', $key, 'max', $start + 30);

        $this->artisan('monitor-agent:export')->assertExitCode(0);

        Http::assertSent(function ($request) {
            $exception = $request['exceptions'][0] ?? null;
            $bucket = collect($request['buckets'])->firstWhere('type', 'exception');

            return $exception !== null
                && $exception['class'] === 'RuntimeException'
                && $exception['location'] === 'app/Foo.php:10'
                && $exception['count'] === 4
                && $bucket['key'] === md5('RuntimeException|app/Foo.php:10');
        });
    }

    public function test_failed_ingest_keeps_the_watermark(): void
    {
        Http::fake(['hub.test/*' => Http::response([], 500)]);

        $this->seedAggregate($this->closedBucketStart(), 'request', 'home', 'count', 1);

        $this->artisan('monitor-agent:export')->assertExitCode(1);

        $this->assertNull(app(Watermark::class)->get());
    }

    public function test_retried_chunks_reuse_batch_uuids(): void
    {
        config(['monitor-agent.max_buckets_per_batch' => 1]);

        $failSlowQuery = true;

        Http::fake(function ($request) use (&$failSlowQuery) {
            $bucket = $request['buckets'][0] ?? null;

            return Http::response(
                ['status' => 'ok'],
                $failSlowQuery && ($bucket['type'] ?? null) === 'slow_query' ? 500 : 200,
            );
        });

        $start = $this->closedBucketStart();

        $this->seedAggregate($start, 'request', 'home', 'count', 1);
        $this->seedAggregate($start, 'slow_query', 'select 1', 'count', 1);

        $this->artisan('monitor-agent:export')->assertExitCode(1);
        $this->assertNull(app(Watermark::class)->get());

        $failSlowQuery = false;

        $this->artisan('monitor-agent:export')->assertExitCode(0);
        $this->assertNotNull(app(Watermark::class)->get());

        $requests = Http::recorded()->map(fn (array $pair) => $pair[0]);
        $homeRequests = $requests->filter(fn ($request) => ($request['buckets'][0]['type'] ?? null) === 'request')->values();
        $slowQueryRequests = $requests->filter(fn ($request) => ($request['buckets'][0]['type'] ?? null) === 'slow_query')->values();

        $this->assertCount(2, $homeRequests);
        $this->assertGreaterThanOrEqual(2, $slowQueryRequests->count());
        $this->assertSame($homeRequests->first()['batch_uuid'], $homeRequests->last()['batch_uuid']);
        $this->assertSame($slowQueryRequests->first()['batch_uuid'], $slowQueryRequests->last()['batch_uuid']);
        $this->assertNotSame($homeRequests->first()['batch_uuid'], $slowQueryRequests->first()['batch_uuid']);
    }

    public function test_disabled_agent_sends_nothing(): void
    {
        config(['monitor-agent.enabled' => false]);
        Http::fake();

        $this->artisan('monitor-agent:export')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_disabled_agent_before_install_remains_a_noop(): void
    {
        config(['monitor-agent.enabled' => false]);
        Schema::drop('monitor_agent_state');
        Http::fake();

        $this->artisan('monitor-agent:export')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_disabled_export_invalidates_existing_capture_baseline(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(10, 0));
        app(Coverage::class)->build(now()->getTimestamp() - 600);
        config(['monitor-agent.enabled' => false]);
        Http::fake();

        $this->artisan('monitor-agent:export')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(0, $this->app['db']->table('monitor_agent_state')->where('key', 'like', 'coverage_%')->count());
    }

    public function test_business_metrics_ride_along_and_failing_collectors_are_skipped(): void
    {
        Http::fake(['hub.test/*' => Http::response(['status' => 'ok'])]);

        app(CollectorRegistry::class)->collector(FailingCollector::class);
        MonitorAgent::gauge('active_users', fn () => 42);
        MonitorAgent::counter('appointments_booked', fn () => 7);

        $this->artisan('monitor-agent:export')->assertExitCode(0);

        Http::assertSent(function ($request) {
            $gauge = collect($request['buckets'])->firstWhere('type', 'gauge:active_users');
            $counter = collect($request['buckets'])->firstWhere('type', 'counter:appointments_booked');

            return $gauge !== null
                && (float) $gauge['max'] === 42.0
                && $counter !== null
                && $counter['count'] === 7;
        });
    }

    public function test_open_windows_are_not_shipped(): void
    {
        Http::fake(['hub.test/*' => Http::response(['status' => 'ok'])]);

        $openWindow = (int) (floor(now()->getTimestamp() / 300) * 300);
        $this->seedAggregate($openWindow, 'request', 'home', 'count', 5);

        // Pin the watermark so only the open window is in range.
        app(Watermark::class)->set($openWindow);

        $this->artisan('monitor-agent:export')->assertExitCode(0);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'ingest'));
    }

    public function test_quiet_windows_send_an_empty_confirmation_only_after_capture_baseline(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(10, 0));
        Http::fake(['hub.test/*' => Http::response(['status' => 'ok'])]);

        $this->artisan('monitor-agent:export')->assertExitCode(0);
        Http::assertNothingSent();

        $this->travel(5)->minutes();
        $this->artisan('monitor-agent:export')->assertExitCode(0);
        $this->travel(6)->minutes();
        $this->artisan('monitor-agent:export')->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['buckets'] === []
            && $request['exceptions'] === [] && $request['samples'] === []
            && $request['coverage']['version'] === 1
            && $request['coverage']['from'] === now()->setTime(10, 5)->getTimestamp()
            && $request['coverage']['through'] === now()->setTime(10, 10)->getTimestamp()
            && in_array('exception', $request['coverage']['types'], true));
        $this->assertSame(now()->setTime(10, 10)->getTimestamp(), app(Watermark::class)->get());
    }

    public function test_data_chunks_must_all_land_before_confirmation_is_sent(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(10, 0));
        app(Coverage::class)->build(now()->getTimestamp() - 600);
        $this->travel(5)->minutes();
        app(Coverage::class)->build(now()->getTimestamp() - 600);
        $this->travel(6)->minutes();
        config(['monitor-agent.max_buckets_per_batch' => 1]);

        $start = now()->setTime(10, 5)->getTimestamp();
        app(Watermark::class)->set($start);
        $this->seedAggregate($start, 'request', 'home', 'count', 1);
        $this->seedAggregate($start, 'exception', json_encode(['Exception', 'app/Test.php:1']), 'count', 1);

        $fail = true;
        Http::fake(function ($request) use (&$fail) {
            return Http::response([], $fail && ($request['buckets'][0]['type'] ?? null) === 'exception' ? 500 : 200);
        });

        $this->artisan('monitor-agent:export')->assertExitCode(1);
        Http::assertNotSent(fn ($request) => isset($request['coverage']));
        $this->assertSame($start, app(Watermark::class)->get());

        $fail = false;
        $this->artisan('monitor-agent:export')->assertExitCode(0);

        $requests = Http::recorded()->map(fn (array $pair) => $pair[0]);
        $this->assertNotNull($requests->last()['coverage']);
        $this->assertSame([], $requests->last()['buckets']);
        $this->assertCount(1, $requests->filter(fn ($request) => isset($request['coverage'])));
    }

    public function test_confirmation_failure_keeps_watermark_samples_and_stable_retry_uuid(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(10, 0));
        app(Coverage::class)->build(now()->getTimestamp() - 600);
        $this->travel(5)->minutes();
        app(Coverage::class)->build(now()->getTimestamp() - 600);
        $this->travel(6)->minutes();
        $start = now()->setTime(10, 5)->getTimestamp();
        app(Watermark::class)->set($start);

        $sample = [
            'kind' => 'exception', 'fingerprint' => md5('Exception'),
            'occurred_at' => $start, 'payload' => json_encode(['class' => 'Exception']),
        ];
        $this->app['db']->table('monitor_agent_samples')->insert($sample);

        $fail = true;
        Http::fake(function ($request) use (&$fail) {
            return Http::response([], $fail && isset($request['coverage']) ? 500 : 200);
        });
        $this->artisan('monitor-agent:export')->assertExitCode(1);
        $this->assertSame($start, app(Watermark::class)->get());
        $this->assertSame(1, $this->app['db']->table('monitor_agent_samples')->count());

        $failedConfirmation = Http::recorded()->last()[0];
        $fail = false;
        $this->artisan('monitor-agent:export')->assertExitCode(0);
        $this->assertSame(0, $this->app['db']->table('monitor_agent_samples')->count());

        $confirmations = Http::recorded()->map(fn (array $pair) => $pair[0])
            ->filter(fn ($request) => isset($request['coverage']))->values();
        $this->assertGreaterThan(1, $confirmations->count());
        $this->assertSame($failedConfirmation['batch_uuid'], $confirmations->last()['batch_uuid']);
        $this->assertSame($confirmations->last()['batch_uuid'], $confirmations->last()->header('Idempotency-Key')[0]);
        $firstUuid = $confirmations->first()['batch_uuid'];

        $this->travel(5)->minutes();
        $this->artisan('monitor-agent:export')->assertExitCode(0);
        $next = Http::recorded()->last()[0];
        $this->assertNotSame($firstUuid, $next['batch_uuid']);
        $this->assertSame([], $next['buckets']);
    }

    public function test_exporter_exposes_actual_read_lower_bound_after_trim_gap(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(10, 11, 42));
        app(Watermark::class)->set(now()->subHours(2)->getTimestamp());

        $export = app(PulseExporter::class)->build();

        $this->assertSame(now()->getTimestamp() - PulseExporter::TRIM_HORIZON_SECONDS, $export['read_from']);
        $this->assertSame(now()->setTime(10, 10)->getTimestamp(), $export['watermark']);
    }
}

class FailingCollector implements BusinessMetricCollector
{
    public function collect(): array
    {
        throw new \RuntimeException('boom');
    }
}
