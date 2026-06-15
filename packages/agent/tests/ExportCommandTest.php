<?php

namespace LaraMon\Agent\Tests;

use Illuminate\Support\Facades\Http;
use LaraMon\Agent\Collectors\CollectorRegistry;
use LaraMon\Agent\Contracts\BusinessMetricCollector;
use LaraMon\Agent\Export\Watermark;
use LaraMon\Agent\MonitorAgent;

class ExportCommandTest extends TestCase
{
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

        $this->artisan('monitor-agent:export')->assertExitCode(0);

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

        $this->artisan('monitor-agent:export')->assertExitCode(0);
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
}

class FailingCollector implements BusinessMetricCollector
{
    public function collect(): array
    {
        throw new \RuntimeException('boom');
    }
}
