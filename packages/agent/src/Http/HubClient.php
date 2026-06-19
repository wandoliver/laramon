<?php

namespace LaraMon\Agent\Http;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Thin HTTP client for the hub. Never throws — a dead hub must never take
 * the host application down with it.
 */
class HubClient
{
    public const SCHEMA_VERSION = 1;

    public const AGENT_VERSION = '0.3.1';

    /**
     * @param  list<array<string, mixed>>  $buckets
     * @param  list<array<string, mixed>>  $exceptions
     * @param  list<array<string, mixed>>  $samples
     */
    public function ingest(array $buckets, array $exceptions, array $samples = [], ?string $batchUuid = null): bool
    {
        $batchUuid ??= (string) Str::uuid();

        try {
            $response = $this->request((int) config('monitor-agent.timeout', 5))
                ->retry(3, 500, throw: false)
                ->withHeader('Idempotency-Key', $batchUuid)
                ->post('/api/v1/ingest', [
                    'schema_version' => self::SCHEMA_VERSION,
                    'agent_version' => self::AGENT_VERSION,
                    'batch_uuid' => $batchUuid,
                    'sent_at' => now()->toIso8601String(),
                    'buckets' => $buckets,
                    'exceptions' => $exceptions,
                    'samples' => $samples,
                ]);

            if (! $response->successful()) {
                Log::warning('Monitor agent ingest failed', ['status' => $response->status()]);
            }

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning("Monitor agent ingest failed: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function heartbeat(array $payload): bool
    {
        try {
            return $this->request((int) config('monitor-agent.heartbeat_timeout', 2))
                ->post('/api/v1/heartbeat', array_merge([
                    'schema_version' => self::SCHEMA_VERSION,
                    'agent_version' => self::AGENT_VERSION,
                    'sent_at' => now()->toIso8601String(),
                ], $payload))
                ->successful();
        } catch (\Throwable $e) {
            Log::warning("Monitor agent heartbeat failed: {$e->getMessage()}");

            return false;
        }
    }

    protected function request(int $timeout): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('monitor-agent.hub_url'), '/'))
            ->withToken((string) config('monitor-agent.token'))
            ->acceptJson()
            ->timeout($timeout);
    }
}
