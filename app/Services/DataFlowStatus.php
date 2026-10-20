<?php

namespace App\Services;

use App\Models\Instance;
use App\Models\MetricBucket;

class DataFlowStatus
{
    public const BUCKET_SECONDS = 300;

    public const MAX_AGE_SECONDS = 900;

    public const TRANSFER_TIMEOUT_SECONDS = 300;

    private const REASONS = [
        'healthy' => 'Recent data flow is confirmed.',
        'heartbeat_missing' => 'No heartbeat has been received.',
        'heartbeat_stale' => 'Heartbeat is stale.',
        'heartbeat_unverified' => 'Heartbeat freshness is unverified.',
        'export_missing' => 'No accepted export is available.',
        'export_stale' => 'Accepted exports are stale.',
        'export_unverified' => 'Export freshness or clock alignment is unverified.',
        'coverage_stale' => 'Confirmed data coverage is stale.',
        'coverage_warming_up' => 'Waiting for a complete confirmed interval.',
        'coverage_unverified' => 'Data completeness is unverified.',
        'coverage_clock_invalid' => 'Coverage timestamps or clock alignment are invalid.',
        'export_in_progress' => 'An export is in progress; waiting for its final confirmation.',
        'export_overdue' => 'The export has not received its final confirmation within five minutes.',
        'export_unconfirmed' => 'The latest export is not fully confirmed.',
    ];

    public static function reasonText(?string $code): string
    {
        return self::REASONS[$code ?? ''] ?? 'Reason was not recorded.';
    }

    public const TYPES = [
        'request', 'request_hist', 'exception', 'slow_request', 'slow_query',
        'slow_job', 'slow_outgoing_request', 'cache:hit', 'cache:miss', 'active_user',
        'job:queued', 'job:processed', 'job:released', 'job:failed', 'scheduled_task',
        'scheduled_task:failed', 'scheduled_task:last_success', 'scheduled_task:last_failure',
        'scheduled_task:runtime',
    ];

    /** Server receipt times establish freshness; a heartbeat alone never proves completeness. */
    public function snapshot(Instance $instance, ?int $now = null): array
    {
        $now ??= now()->getTimestamp();
        $heartbeat = $this->receipt($instance->last_heartbeat_at?->getTimestamp(), $now, 120);
        $export = $this->receipt($instance->last_ingest_at?->getTimestamp(), $now, self::MAX_AGE_SECONDS);
        $meta = is_array($instance->meta) ? $instance->meta : [];
        $proof = $this->proof($meta['data_flow'] ?? null);
        $coverageClockInvalid = false;
        $coverage = [
            'state' => 'unknown', 'from' => null, 'through' => null,
            'confirmed_at' => null, 'age_seconds' => null, 'types' => [],
        ];

        if ($proof !== null) {
            $age = $now - $proof['covered_through'];
            $confirmationAge = $now - $proof['confirmed_at'];
            $coverageClockInvalid = $age < 60 || $confirmationAge < 0 || abs($proof['clock_skew_seconds']) > 60;
            $coverage = [
                'state' => 'unknown', 'from' => $proof['covered_from'],
                'through' => $proof['covered_through'], 'confirmed_at' => $proof['confirmed_at'],
                'age_seconds' => $age >= 0 ? $age : null, 'types' => $proof['types'],
            ];

            if ($age >= 60 && $confirmationAge >= 0 && abs($proof['clock_skew_seconds']) <= 60) {
                $coverage['state'] = $age > self::MAX_AGE_SECONDS || $confirmationAge > self::MAX_AGE_SECONDS
                    ? 'stale'
                    : ($proof['covered_through'] - $proof['covered_from'] >= self::BUCKET_SECONDS ? 'healthy' : 'warming_up');
            }
        }

        $heartbeatClock = $meta['heartbeat_clock_skew_seconds'] ?? 0;
        if (! is_int($heartbeatClock) || abs($heartbeatClock) > 60) {
            $heartbeat['state'] = 'unknown';
        }
        $exportClock = $meta['clock_skew_seconds'] ?? 0;
        if (! is_int($exportClock) || abs($exportClock) > 60) {
            $export['state'] = 'unknown';
        }
        $pending = ($meta['data_flow_pending'] ?? false) !== false;
        $startedAt = $meta['data_flow_pending_since'] ?? null;
        $validStart = ($meta['data_flow_pending'] ?? false) === true
            && is_int($startedAt) && $startedAt >= 0 && $startedAt <= $now;
        $transferAge = $pending && $validStart ? $now - $startedAt : null;
        $transfer = [
            'state' => ! $pending ? 'idle' : ($transferAge === null ? 'unverified'
                : ($transferAge < self::TRANSFER_TIMEOUT_SECONDS ? 'transferring' : 'overdue')),
            'started_at' => $pending && $validStart ? $startedAt : null,
            'age_seconds' => $transferAge,
            'deadline_at' => $pending && $validStart ? $startedAt + self::TRANSFER_TIMEOUT_SECONDS : null,
        ];

        // In-flight data never proves health. A bounded neutral transfer needs
        // an existing fresh proof and every independent freshness check first.
        [$state, $reasonCode] = match (true) {
            $heartbeat['age_seconds'] !== null && $heartbeat['age_seconds'] >= 600 => ['down', 'heartbeat_stale'],
            $heartbeat['timestamp'] === null => ['unknown', 'heartbeat_missing'],
            $heartbeat['state'] === 'unknown' => ['unknown', 'heartbeat_unverified'],
            $heartbeat['state'] !== 'healthy' => ['degraded', 'heartbeat_stale'],
            $export['timestamp'] === null => ['unknown', 'export_missing'],
            $export['state'] === 'unknown' => ['unknown', 'export_unverified'],
            $export['state'] !== 'healthy' => ['degraded', 'export_stale'],
            $coverageClockInvalid => ['unknown', 'coverage_clock_invalid'],
            $coverage['state'] === 'stale' => ['degraded', 'coverage_stale'],
            $coverage['state'] === 'warming_up' => ['unknown', 'coverage_warming_up'],
            $coverage['state'] !== 'healthy' => ['unknown', 'coverage_unverified'],
            $pending && $transfer['state'] === 'transferring' => ['transferring', 'export_in_progress'],
            $pending && $transfer['state'] === 'overdue' => ['degraded', 'export_overdue'],
            $pending => ['unknown', 'export_unconfirmed'],
            default => ['healthy', 'healthy'],
        };
        if ($pending) {
            $coverage['state'] = $state === 'transferring' ? 'transferring' : 'unknown';
        }
        $reason = self::reasonText($reasonCode);

        return ['reason_code' => $reasonCode, ...compact('state', 'reason', 'heartbeat', 'export', 'coverage', 'transfer')];
    }

    /**
     * Inclusive query bounds for a complete, settled interval. Arbitrary shorter
     * slices cannot be reconstructed faithfully from five-minute aggregates.
     */
    public function window(Instance $instance, int $windowMinutes, ?string $type = null, ?int $now = null): ?array
    {
        if ($windowMinutes < 5 || $windowMinutes > 1440 || $windowMinutes % 5 !== 0) {
            return null;
        }

        $status = $this->snapshot($instance, $now);
        $coverage = $status['coverage'];
        if ($status['state'] !== 'healthy' || ($type !== null && ! in_array($type, $coverage['types'], true))) {
            return null;
        }

        $through = $coverage['through'];
        $from = $through - $windowMinutes * 60;
        if ($coverage['from'] > $from || $this->hasGap($instance, $from, $through)) {
            return null;
        }

        return ['from' => $from, 'to' => $through - 1, 'through' => $through];
    }

    /** Merge only validated final acknowledgements, never heartbeat watermarks. */
    public function confirm(?array $previous, array $coverage, int $receivedAt, int $clockSkew): array
    {
        $types = $coverage['types'];
        sort($types, SORT_STRING);
        $previous = $this->proof($previous);
        $through = (int) $coverage['through'];
        $from = (int) $coverage['from'];

        // An old or replayed interval cannot refresh a stalled stream.
        if ($previous !== null && $through <= $previous['covered_through']) {
            return $previous;
        }

        $continuous = $previous !== null && abs($previous['clock_skew_seconds']) <= 60 && abs($clockSkew) <= 60 && $previous['types'] === $types
            && $from <= $previous['covered_through'];

        return [
            'version' => 1,
            'covered_from' => $continuous ? $previous['covered_from'] : (intdiv($receivedAt, 300) + 1) * 300,
            'covered_through' => $through,
            'types' => $types,
            'confirmed_at' => $receivedAt,
            'clock_skew_seconds' => $clockSkew,
        ];
    }

    private function receipt(?int $timestamp, int $now, int $freshFor): array
    {
        $age = $timestamp === null ? null : $now - $timestamp;

        return [
            'state' => $age === null || $age < 0 ? 'unknown' : ($age < $freshFor ? 'healthy' : 'stale'),
            'timestamp' => $timestamp,
            'age_seconds' => $age !== null && $age >= 0 ? $age : null,
        ];
    }

    private function proof(mixed $proof): ?array
    {
        if (! is_array($proof) || ($proof['version'] ?? null) !== 1) {
            return null;
        }
        foreach (['covered_from', 'covered_through', 'confirmed_at', 'clock_skew_seconds'] as $key) {
            if (! is_int($proof[$key] ?? null)) {
                return null;
            }
        }
        foreach (['covered_from', 'covered_through'] as $key) {
            if ($proof[$key] < 0 || $proof[$key] > 4102444800 || $proof[$key] % 300 !== 0) {
                return null;
            }
        }
        $types = $proof['types'] ?? null;
        if (! is_array($types) || ! array_is_list($types) || $types === [] || count($types) > count(self::TYPES)) {
            return null;
        }
        foreach ($types as $type) {
            if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
                return null;
            }
        }
        if (count(array_unique($types)) !== count($types)) {
            return null;
        }
        sort($types, SORT_STRING);
        $proof['types'] = $types;

        return $proof;
    }

    private function hasGap(Instance $instance, int $from, int $through): bool
    {
        return MetricBucket::query()->where('instance_id', $instance->id)
            ->where('type', 'counter:agent.export_gap_minutes')->where('key_hash', MetricBucket::hashKey('agent.export_gap_minutes'))
            ->where('bucket_seconds', 300)->where('bucket_start', '>=', $from - 300)
            ->where('bucket_start', '<', $through)->whereRaw('bucket_start + bucket_seconds > ?', [$from])
            ->where(fn ($query) => $query->where('count', '>', 0)->orWhere('sum', '>', 0)->orWhere('max', '>', 0))
            ->exists();
    }
}
