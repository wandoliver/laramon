<?php

namespace LaraMon\Agent;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\ViewException;
use Laravel\Pulse\Recorders\Concerns\LivewireRoutes;
use Laravel\Pulse\Recorders\SlowQueries;
use Laravel\Pulse\Recorders\SlowRequests;
use Throwable;

/**
 * Captures occurrence details (exception traces, slow query occurrences)
 * for the hub's drill-down pages. Fingerprints mirror Pulse's recorder key
 * derivation exactly so samples line up with the aggregate metrics.
 *
 * Holds at most one row per fingerprint between exports; never throws.
 */
class Sampler
{
    use LivewireRoutes;

    protected bool $recording = false;

    public function exception(Throwable $e): void
    {
        try {
            $class = $this->resolveClass($e);
            $location = $this->resolveExceptionLocation($e);

            $request = app()->bound('request') && ! app()->runningInConsole() ? request() : null;

            $this->store('exception', md5($class.'|'.$location), [
                'class' => $class,
                'location' => $location,
                'message' => Str::limit($e->getMessage(), 2000),
                'trace' => Str::limit($e->getTraceAsString(), 12000),
                'url' => $request?->fullUrl(),
                'method' => $request?->method(),
            ]);
        } catch (Throwable) {
            // Sampling must never interfere with exception handling.
        }
    }

    public function request(RequestHandled $event): void
    {
        try {
            if (! $event->request->route() instanceof Route) {
                return;
            }

            $startTime = $event->request->server('REQUEST_TIME_FLOAT');

            if (! $startTime) {
                return;
            }

            $duration = (int) round((microtime(true) - (float) $startTime) * 1000);

            $threshold = config('pulse.recorders.'.SlowRequests::class.'.threshold', 1000);

            if (is_array($threshold)) {
                $threshold = $threshold['default'] ?? 1000;
            }

            if ($duration < (int) $threshold) {
                return;
            }

            [$path, $via] = $this->resolveRoutePath($event->request);

            foreach ((array) config('pulse.recorders.'.SlowRequests::class.'.ignore', []) as $pattern) {
                if (preg_match($pattern, $path) === 1) {
                    return;
                }
            }

            // Mirror the exporter's key flattening of Pulse's
            // [method, path, via] json key.
            $key = mb_substr(implode(' ', array_filter(
                [$event->request->method(), $path, $via],
                fn ($part) => is_string($part) && $part !== '',
            )), 0, 500);

            $this->store('slow_request', md5($key), [
                'method' => $event->request->method(),
                'path' => $path,
                'via' => $via,
                'status' => $event->response->getStatusCode(),
                'duration_ms' => $duration,
            ], keepSlowest: true);
        } catch (Throwable) {
            // Never let sampling break the request that triggered it.
        }
    }

    public function query(QueryExecuted $event): void
    {
        if ($this->recording) {
            return;
        }

        try {
            $duration = (int) $event->time;

            if ($duration < (int) config('pulse.recorders.'.SlowQueries::class.'.threshold', 1000)) {
                return;
            }

            $sql = $event->sql;

            if (preg_match('/(["`])(pulse_|monitor_agent_)[\w]+?\1/', $sql) === 1) {
                return;
            }

            if ($maxLength = config('pulse.recorders.'.SlowQueries::class.'.max_query_length')) {
                $sql = Str::limit($sql, $maxLength);
            }

            $location = config('pulse.recorders.'.SlowQueries::class.'.location', true)
                ? $this->resolveQueryLocation()
                : '';

            // Mirror the exporter's key flattening so the fingerprint matches
            // the hub-side metric key hash.
            $key = mb_substr(implode(' ', array_filter([$sql, $location], fn ($part) => $part !== '')), 0, 500);

            $this->store('slow_query', md5($key), [
                'sql' => Str::limit($sql, 10000),
                'location' => $location,
                'duration_ms' => $duration,
                'connection' => $event->connectionName,
            ], keepSlowest: true);
        } catch (Throwable) {
            // Never let sampling break the query that triggered it.
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function store(string $kind, string $fingerprint, array $payload, bool $keepSlowest = false): void
    {
        $this->recording = true;

        try {
            if ($keepSlowest) {
                $existing = DB::table('monitor_agent_samples')
                    ->where('kind', $kind)
                    ->where('fingerprint', $fingerprint)
                    ->first(['payload']);

                if ($existing !== null
                    && (json_decode($existing->payload, true)['duration_ms'] ?? 0) >= ($payload['duration_ms'] ?? 0)) {
                    return;
                }
            }

            DB::table('monitor_agent_samples')->upsert(
                [[
                    'kind' => $kind,
                    'fingerprint' => $fingerprint,
                    'payload' => (string) json_encode(array_filter($payload, fn ($value) => $value !== null)),
                    'occurred_at' => now()->getTimestamp(),
                ]],
                ['kind', 'fingerprint'],
                ['payload', 'occurred_at'],
            );
        } finally {
            $this->recording = false;
        }
    }

    /**
     * Mirrors Pulse's Exceptions recorder class resolution.
     */
    protected function resolveClass(Throwable $e): string
    {
        if ($e instanceof ViewException && $e->getPrevious() !== null) {
            return $e->getPrevious()::class;
        }

        return $e::class;
    }

    /**
     * Mirrors Pulse's Exceptions recorder location resolution.
     */
    protected function resolveExceptionLocation(Throwable $e): string
    {
        if ($e instanceof ViewException) {
            preg_match('/\(View: (?P<path>.*?)\)/', $e->getMessage(), $matches);

            return $this->formatLocation($matches['path'] ?? 'unknown', null);
        }

        $firstNonVendorFrame = collect($e->getTrace())
            ->firstWhere(fn (array $frame) => isset($frame['file']) && ! $this->isInternalFile($frame['file'], vendor: true));

        if (! $this->isInternalFile($e->getFile(), vendor: true) || $firstNonVendorFrame === null) {
            return $this->formatLocation($e->getFile(), $e->getLine());
        }

        return $this->formatLocation($firstNonVendorFrame['file'] ?? 'unknown', $firstNonVendorFrame['line'] ?? null);
    }

    /**
     * Mirrors Pulse's SlowQueries recorder location resolution.
     */
    protected function resolveQueryLocation(): string
    {
        $backtrace = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))->skip(2);

        $frame = $backtrace->firstWhere(fn (array $frame) => isset($frame['file']) && ! $this->isInternalFile($frame['file']));

        if ($frame === null) {
            return '';
        }

        return $this->formatLocation($frame['file'] ?? 'unknown', $frame['line'] ?? null);
    }

    protected function isInternalFile(string $file, bool $vendor = false): bool
    {
        return ($vendor
                ? Str::startsWith($file, base_path('vendor'))
                : (Str::startsWith($file, base_path('vendor'.DIRECTORY_SEPARATOR.'laravel'.DIRECTORY_SEPARATOR.'pulse'))
            || Str::startsWith($file, base_path('vendor'.DIRECTORY_SEPARATOR.'laravel'.DIRECTORY_SEPARATOR.'framework'))))
            || Str::startsWith($file, base_path('vendor'.DIRECTORY_SEPARATOR.'laramon'))
            || str_contains($file, 'packages'.DIRECTORY_SEPARATOR.'agent'.DIRECTORY_SEPARATOR.'src')
            || $file === base_path('artisan')
            || $file === public_path('index.php');
    }

    protected function formatLocation(string $file, ?int $line): string
    {
        return Str::replaceFirst(base_path(DIRECTORY_SEPARATOR), '', $file).(is_int($line) ? (':'.$line) : '');
    }
}
