<?php

namespace LaraMon\Agent\Tests;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LaraMon\Agent\Sampler;

class SamplerTest extends TestCase
{
    public function test_exceptions_are_sampled_with_pulse_compatible_fingerprints(): void
    {
        $exception = new \RuntimeException('Something broke');

        app(Sampler::class)->exception($exception);

        $row = DB::table('monitor_agent_samples')->where('kind', 'exception')->first();

        $this->assertNotNull($row);

        $payload = json_decode($row->payload, true);
        $this->assertSame(\RuntimeException::class, $payload['class']);
        $this->assertSame('Something broke', $payload['message']);
        $this->assertNotEmpty($payload['trace']);
        $this->assertSame(md5($payload['class'].'|'.$payload['location']), $row->fingerprint);
    }

    public function test_repeated_exceptions_keep_one_latest_sample(): void
    {
        $sampler = app(Sampler::class);

        // Same construction site → same fingerprint → a single, latest row.
        $make = fn (string $message) => new \RuntimeException($message);

        $sampler->exception($make('first'));
        $sampler->exception($make('second'));

        $rows = DB::table('monitor_agent_samples')->get();

        $this->assertCount(1, $rows);
        $this->assertSame('second', json_decode($rows[0]->payload, true)['message']);
    }

    public function test_slow_queries_are_sampled_and_fast_ones_ignored(): void
    {
        $sampler = app(Sampler::class);
        $connection = DB::connection();

        $sampler->query(new QueryExecuted('select * from `users`', [], 1500.0, $connection));
        $sampler->query(new QueryExecuted('select * from `clients`', [], 20.0, $connection));

        $rows = DB::table('monitor_agent_samples')->where('kind', 'slow_query')->get();

        $this->assertCount(1, $rows);
        $this->assertSame('select * from `users`', json_decode($rows[0]->payload, true)['sql']);
    }

    public function test_slower_occurrence_replaces_faster_sample(): void
    {
        $sampler = app(Sampler::class);
        $connection = DB::connection();

        $sampler->query(new QueryExecuted('select * from `users`', [], 1500.0, $connection));
        $sampler->query(new QueryExecuted('select * from `users`', [], 4000.0, $connection));
        $sampler->query(new QueryExecuted('select * from `users`', [], 2000.0, $connection));

        $rows = DB::table('monitor_agent_samples')->where('kind', 'slow_query')->get();

        $this->assertCount(1, $rows);
        $this->assertSame(4000, json_decode($rows[0]->payload, true)['duration_ms']);
    }

    public function test_slow_requests_are_sampled_with_pulse_compatible_fingerprints(): void
    {
        \Illuminate\Support\Facades\Route::get('/appointments', fn () => 'ok')->name('appointments');

        $request = \Illuminate\Http\Request::create('/appointments', 'GET');
        $request->server->set('REQUEST_TIME_FLOAT', microtime(true) - 2.5);

        $route = app('router')->getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);

        app(Sampler::class)->request(new \Illuminate\Foundation\Http\Events\RequestHandled(
            $request,
            new \Illuminate\Http\Response('ok', 200),
        ));

        $row = DB::table('monitor_agent_samples')->where('kind', 'slow_request')->first();

        $this->assertNotNull($row);

        $payload = json_decode($row->payload, true);
        $this->assertSame('GET', $payload['method']);
        $this->assertSame('/appointments', $payload['path']);
        $this->assertSame(200, $payload['status']);
        $this->assertGreaterThanOrEqual(2000, $payload['duration_ms']);

        // Fingerprint matches the exporter's flattening of Pulse's
        // [method, path, via] key.
        $expectedKey = implode(' ', array_filter(['GET', $payload['path'], $payload['via'] ?? null]));
        $this->assertSame(md5($expectedKey), $row->fingerprint);
    }

    public function test_fast_requests_are_not_sampled(): void
    {
        \Illuminate\Support\Facades\Route::get('/quick', fn () => 'ok');

        $request = \Illuminate\Http\Request::create('/quick', 'GET');
        $request->server->set('REQUEST_TIME_FLOAT', microtime(true) - 0.05);

        $route = app('router')->getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);

        app(Sampler::class)->request(new \Illuminate\Foundation\Http\Events\RequestHandled(
            $request,
            new \Illuminate\Http\Response('ok', 200),
        ));

        $this->assertSame(0, DB::table('monitor_agent_samples')->where('kind', 'slow_request')->count());
    }

    public function test_export_ships_samples_and_deletes_them_on_success(): void
    {
        Http::fake(['hub.test/*' => Http::response(['status' => 'ok'])]);

        app(Sampler::class)->exception(new \RuntimeException('ship me'));

        $this->artisan('monitor-agent:export')->assertExitCode(0);

        Http::assertSent(function ($request) {
            $sample = $request['samples'][0] ?? null;

            return $sample !== null
                && $sample['kind'] === 'exception'
                && $sample['payload']['message'] === 'ship me';
        });

        $this->assertSame(0, DB::table('monitor_agent_samples')->count());
    }

    public function test_export_keeps_samples_when_the_hub_is_down(): void
    {
        Http::fake(['hub.test/*' => Http::response([], 500)]);

        app(Sampler::class)->exception(new \RuntimeException('keep me'));

        $this->artisan('monitor-agent:export')->assertExitCode(0);

        $this->assertSame(1, DB::table('monitor_agent_samples')->count());
    }
}
