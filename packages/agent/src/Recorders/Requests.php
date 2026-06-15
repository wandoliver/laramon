<?php

namespace LaraMon\Agent\Recorders;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Laravel\Pulse\Concerns\ConfiguresAfterResolving;
use Laravel\Pulse\Pulse;
use Laravel\Pulse\Recorders\Concerns;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records every handled request (count + avg/min/max duration per route) —
 * Pulse's own SlowRequests recorder only captures requests over a threshold,
 * so throughput and typical latency would otherwise be invisible.
 */
class Requests
{
    use Concerns\Ignores,
        Concerns\LivewireRoutes,
        Concerns\Sampling,
        ConfiguresAfterResolving;

    /**
     * Latency histogram bin boundaries (ms) — must match the hub's
     * Histogram::BOUNDARIES so p95 interpolation lines up.
     */
    public const HISTOGRAM_BOUNDARIES = [25, 50, 100, 200, 400, 800, 1600, 3200, 6400, 12800];

    public function __construct(
        protected Pulse $pulse,
    ) {}

    public function register(callable $record, Application $app): void
    {
        $this->afterResolving(
            $app,
            Kernel::class,
            fn (Kernel $kernel) => $kernel->whenRequestLifecycleIsLongerThan(-1, $record),
        );
    }

    public function record(Carbon $startedAt, Request $request, Response $response): void
    {
        if (! $request->route() instanceof Route || ! $this->shouldSample()) {
            return;
        }

        [$path] = $this->resolveRoutePath($request);

        if ($this->shouldIgnore($path)) {
            return;
        }

        $duration = (int) $startedAt->diffInMilliseconds();

        $this->pulse->record(
            type: 'request',
            key: $path,
            value: $duration,
            timestamp: $startedAt,
        )->count()->avg()->min()->max()->onlyBuckets();

        // Per-route latency histogram (one count per route+bin) — the hub
        // derives true p95 from these, per route and instance-wide (by
        // summing bins across routes). Format: "{route}|le_{boundary}".
        $this->pulse->record(
            type: 'request_hist',
            key: $path.'|'.$this->bin($duration),
            timestamp: $startedAt,
        )->count()->onlyBuckets();
    }

    protected function bin(int $milliseconds): string
    {
        foreach (self::HISTOGRAM_BOUNDARIES as $boundary) {
            if ($milliseconds <= $boundary) {
                return 'le_'.$boundary;
            }
        }

        return 'le_inf';
    }
}
