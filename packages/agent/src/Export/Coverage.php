<?php

namespace LaraMon\Agent\Export;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraMon\Agent\Recorders\ActiveUsers;
use LaraMon\Agent\Recorders\Requests;
use LaraMon\Agent\Recorders\ScheduledTasks;
use Laravel\Pulse\Recorders;

/**
 * Attests only fully captured, settled Pulse windows. A missing metric in
 * such a window may mean zero; without this attestation it means unknown.
 */
class Coverage
{
    private const SIGNATURE_KEY = 'coverage_capture_signature';

    private const BASELINE_KEY = 'coverage_capture_from';

    private const OBSERVED_KEY = 'coverage_last_observed_at';

    private const MAX_OBSERVATION_GAP_SECONDS = 600;

    private const MAX_TIMESTAMP = 4_102_444_800;

    /** @var array<class-string, array<string, string>> */
    private const RECORDER_TYPES = [
        Requests::class => ['request' => 'request', 'request_hist' => 'request_hist'],
        Recorders\Exceptions::class => ['exception' => 'exception'],
        Recorders\SlowRequests::class => ['slow_request' => 'slow_request'],
        Recorders\SlowQueries::class => ['slow_query' => 'slow_query'],
        Recorders\SlowJobs::class => ['slow_job' => 'slow_job'],
        Recorders\SlowOutgoingRequests::class => ['slow_outgoing_request' => 'slow_outgoing_request'],
        Recorders\CacheInteractions::class => ['cache_hit' => 'cache:hit', 'cache_miss' => 'cache:miss'],
        ActiveUsers::class => ['active_user' => 'active_user'],
        Recorders\Queues::class => ['queued' => 'job:queued', 'processed' => 'job:processed', 'released' => 'job:released', 'failed' => 'job:failed'],
        ScheduledTasks::class => [
            'scheduled_task' => 'scheduled_task',
            'scheduled_task_failed' => 'scheduled_task:failed',
            'scheduled_task_last_success' => 'scheduled_task:last_success',
            'scheduled_task_last_failure' => 'scheduled_task:last_failure',
        ],
    ];

    /** @return array{version: int, from: int, through: int, types: list<string>}|null */
    public function build(int $readFrom, ?int $readThrough = null): ?array
    {
        $pulseConnection = config('pulse.storage.database.connection');

        if (! config('monitor-agent.enabled') || ! config('pulse.enabled', true)
            || config('monitor-agent.bucket_seconds', 300) !== 300
            // Buffered/Redis ingestion may still contain older events. Reading
            // an empty aggregate table cannot attest that those events are zero.
            || config('pulse.ingest.driver', 'storage') !== 'storage'
            || config('pulse.storage.driver', 'database') !== 'database'
            || ($pulseConnection !== null && (! is_string($pulseConnection) || $pulseConnection === ''))) {
            $this->reset();

            return null;
        }

        $now = now()->getTimestamp();

        if ($now < 60 || $now >= self::MAX_TIMESTAMP || $readFrom < 0 || $readFrom > $now
            || ($readThrough !== null && ($readThrough < 0 || $readThrough > $now || $readThrough % 300 !== 0))) {
            return null;
        }

        $recorders = (array) config('pulse.recorders', []);
        $typeMap = (array) config('monitor-agent.pulse_types', []);
        $types = [];
        $captureConfig = [];

        foreach (self::RECORDER_TYPES as $class => $sources) {
            $options = $recorders[$class] ?? false;
            $captureConfig[$class] = $this->safeConfig($options);

            if ($options !== true && ! is_array($options)) {
                continue;
            }

            if (is_array($options) && (! ($options['enabled'] ?? true)
                || ! is_numeric($options['sample_rate'] ?? 1)
                || (float) ($options['sample_rate'] ?? 1) !== 1.0
                || $this->hasUnsupportedConfig($options))) {
                continue;
            }

            if ($class === ActiveUsers::class && ! config('monitor-agent.track_users', true)) {
                continue;
            }

            foreach ($sources as $source => $type) {
                if (($typeMap[$source] ?? null) === $type) {
                    $types[] = $type;
                }
            }

            // Runtime is produced by the exporter from timed scheduler samples.
            if ($class === ScheduledTasks::class && ($typeMap['scheduled_task'] ?? null) === 'scheduled_task') {
                $types[] = 'scheduled_task:runtime';
            }
        }

        $types = array_values(array_unique($types));
        sort($types, SORT_STRING);

        // Persist only the digest, never capture configuration or its values.
        $signature = hash('sha256', json_encode($this->safeConfig([
            'recorders' => $captureConfig,
            'types' => $typeMap,
            'track_users' => config('monitor-agent.track_users', true),
            'storage' => config('pulse.storage'),
            'ingest' => config('pulse.ingest'),
        ]), JSON_THROW_ON_ERROR));

        $state = DB::table('monitor_agent_state')
            ->whereIn('key', [self::SIGNATURE_KEY, self::BASELINE_KEY, self::OBSERVED_KEY])
            ->pluck('value', 'key');
        $storedBaseline = $state[self::BASELINE_KEY] ?? null;
        $lastObserved = $state[self::OBSERVED_KEY] ?? null;

        if (($state[self::SIGNATURE_KEY] ?? null) !== $signature
            || ! is_string($storedBaseline) || ! ctype_digit($storedBaseline)
            || (float) $storedBaseline > self::MAX_TIMESTAMP
            || (int) $storedBaseline % 300 !== 0
            || ! is_string($lastObserved) || ! ctype_digit($lastObserved)
            || (float) $lastObserved > $now
            || $now - (int) $lastObserved > self::MAX_OBSERVATION_GAP_SECONDS) {
            // Begin at the next boundary: the first observed config cannot
            // establish what was recording earlier in the current window.
            $baseline = (intdiv($now, 300) + 1) * 300;
            DB::table('monitor_agent_state')->upsert([
                ['key' => self::SIGNATURE_KEY, 'value' => $signature],
                ['key' => self::BASELINE_KEY, 'value' => (string) $baseline],
                ['key' => self::OBSERVED_KEY, 'value' => (string) $now],
            ], ['key'], ['value']);
        } else {
            $baseline = (int) $storedBaseline;
            DB::table('monitor_agent_state')->upsert([
                ['key' => self::OBSERVED_KEY, 'value' => (string) $now],
            ], ['key'], ['value']);
        }

        $from = intdiv(max($readFrom, $baseline, $now - PulseExporter::TRIM_HORIZON_SECONDS) + 299, 300) * 300;
        $through = min($readThrough ?? PHP_INT_MAX, intdiv($now - 60, 300) * 300);

        return $types !== [] && $from < $through ? [
            'version' => 1,
            'from' => $from,
            'through' => $through,
            'types' => $types,
        ] : null;
    }

    public function reset(): void
    {
        // A disabled, not-yet-installed agent has no state to invalidate.
        if (! Schema::hasTable('monitor_agent_state')) {
            return;
        }

        DB::table('monitor_agent_state')
            ->whereIn('key', [self::SIGNATURE_KEY, self::BASELINE_KEY, self::OBSERVED_KEY])
            ->delete();
    }

    private function hasUnsupportedConfig(mixed $value, int $depth = 0): bool
    {
        if ($depth > 16 || (! is_array($value) && ! is_scalar($value) && $value !== null)) {
            return true;
        }

        foreach (is_array($value) ? $value : [] as $item) {
            if ($this->hasUnsupportedConfig($item, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /** Stable representation without executing configured callbacks or objects. */
    private function safeConfig(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 16 || (! is_array($value) && ! is_scalar($value) && $value !== null)) {
            return '[unsupported]';
        }

        if (! is_array($value)) {
            return is_float($value) && ! is_finite($value) ? '[nonfinite]' : $value;
        }

        ksort($value);

        return array_map(fn ($item) => $this->safeConfig($item, $depth + 1), $value);
    }
}
