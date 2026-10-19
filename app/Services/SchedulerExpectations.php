<?php

namespace App\Services;

use App\Models\Instance;
use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class SchedulerExpectations
{
    /**
     * Current expectations are independent of the dashboard's historical
     * range. Only explicitly configured, named critical tasks appear here.
     *
     * @return Collection<int, object>
     */
    public function current(Instance $instance, ?int $now = null): Collection
    {
        $now ??= now()->getTimestamp();
        $meta = $instance->meta ?? [];
        $manifest = $meta['scheduler'] ?? null;

        if (! is_array($manifest) || ! is_array($manifest['tasks'] ?? null)) {
            return collect();
        }

        $tasks = collect(array_slice($manifest['tasks'], 0, 100))
            ->filter(fn ($task) => is_array($task) && is_string($task['name'] ?? null)
                && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,119}$/D', $task['name']) === 1)
            ->unique('name')->values();

        if ($tasks->isEmpty()) {
            return collect();
        }

        $completions = $this->completions($instance->id, $tasks->pluck('name')->all(), $now);
        $lastGapEnd = DB::table('metric_buckets')
            ->where('instance_id', $instance->id)
            ->where('type', 'counter:agent.export_gap_minutes')
            ->where('count', '>', 0)
            ->whereIn('bucket_seconds', [300, 3600])
            ->where('bucket_start', '<=', $now)
            ->selectRaw('max(bucket_start + bucket_seconds) as last_gap_end')
            ->first()?->last_gap_end;
        $flow = app(DataFlowStatus::class)->snapshot($instance, $now);
        $coverage = $flow['coverage'];
        $fresh = $flow['state'] === 'healthy'
            && ($manifest['version'] ?? null) === 1
            && ($manifest['available'] ?? null) === true
            && $this->fresh($manifest['reported_at'] ?? null, $now, 120)
            && $this->fresh($instance->last_heartbeat_at?->getTimestamp(), $now, 120)
            && $this->fresh($instance->last_ingest_at?->getTimestamp(), $now, 900)
            && $this->clockTrusted($meta['clock_skew_seconds'] ?? 0)
            && $this->clockTrusted($manifest['clock_skew_seconds'] ?? 0);

        return $tasks->map(function (array $task) use ($now, $fresh, $completions, $coverage, $lastGapEnd) {
            $row = (object) [
                'key' => $task['name'],
                'status' => 'unknown',
                'reason' => 'Current monitoring data or schedule information is unavailable.',
                'due' => null,
                'deadline' => null,
                'next_deadline' => null,
                'last_success' => $completions[$task['name']]['success'] ?? null,
                'last_failure' => $completions[$task['name']]['failure'] ?? null,
            ];

            try {
                $expression = $task['expression'] ?? null;
                $timezone = $task['timezone'] ?? null;
                $grace = $task['grace_seconds'] ?? null;
                $since = $task['observed_since'] ?? null;

                if (! is_string($expression) || strlen($expression) > 120
                    || count(preg_split('/\s+/', trim($expression))) !== 5
                    || ! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)
                    || ! is_int($grace) || $grace < 900 || $grace > 604800
                    || ! is_int($since) || $since <= 0 || $since > $now) {
                    return $row;
                }

                $cron = (new CronExpression($expression))->setMaxIterationCount(1000);
                $cutoff = $now - $grace;
                $due = $cron->getPreviousRunDate(new DateTimeImmutable('@'.$cutoff), 0, true, $timezone)->getTimestamp();
                $next = $cron->getNextRunDate(new DateTimeImmutable('@'.max($cutoff, $since)), 0, true, $timezone)->getTimestamp();

                if ($next < $since || $next + $grace <= $now) {
                    $next = $cron->getNextRunDate(new DateTimeImmutable('@'.$next), 0, false, $timezone)->getTimestamp();
                }

                $row->next_deadline = $next + $grace;

                // A recorded failure is useful evidence even if newer data is
                // unavailable. Success needs both outcome streams confirmed.
                if ($due >= $since && ($row->last_failure ?? 0) >= $due
                    && ($row->last_failure ?? 0) >= ($row->last_success ?? 0)) {
                    $row->due = $due;
                    $row->deadline = $due + $grace;
                    $row->status = 'failed';
                    $row->reason = 'A failure after the expected run was recorded; missing data cannot confirm recovery.';

                    return $row;
                }

                if (! $fresh) {
                    return $row;
                }

                if ($due < $since) {
                    $row->status = 'awaiting_first_run';
                    $row->reason = 'No expected run has reached its deadline since monitoring began.';

                    return $row;
                }

                $row->due = $due;
                $row->deadline = $due + $grace;

                if ($lastGapEnd !== null && (int) $lastGapEnd > $due) {
                    $row->status = 'unknown';
                    $row->reason = 'Monitoring data since the expected run was lost; no completion can be confirmed.';
                } elseif (! in_array('scheduled_task:last_success', $coverage['types'], true)
                    || ! in_array('scheduled_task:last_failure', $coverage['types'], true)
                    || $coverage['from'] > intdiv($due, 300) * 300
                    || $coverage['through'] < max($row->deadline, intdiv($now - 60, 300) * 300)
                    || ($row->last_success ?? 0) >= $coverage['through']) {
                    $row->status = 'awaiting_data';
                    $row->reason = 'Waiting for complete, confirmed success and failure data since the expected run.';
                } elseif (($row->last_success ?? 0) >= $due) {
                    $row->status = 'succeeded';
                    $row->reason = 'An exact success was recorded and both outcome streams are complete through the latest settled interval.';
                } else {
                    $row->status = 'overdue';
                    $row->reason = 'No completion was recorded after the expected run by its deadline.';
                }
            } catch (Throwable) {
                // Invalid legacy metadata or an impossible cron must not
                // break the dashboard or turn missing evidence into success.
            }

            return $row;
        })->sortBy(fn (object $row) => match ($row->status) {
            'failed', 'overdue' => 0,
            'unknown' => 1,
            'awaiting_data', 'awaiting_first_run' => 2,
            default => 3,
        })->values();
    }

    /** @param list<string> $names */
    private function completions(int $instanceId, array $names, int $now): array
    {
        $latest = [];
        $rows = DB::table('metric_buckets')
            ->where('instance_id', $instanceId)
            ->whereIn('type', ['scheduled_task:last_success', 'scheduled_task:last_failure'])
            ->whereIn('key_hash', array_map(fn (string $name) => md5($name, true), $names))
            ->whereIn('bucket_seconds', [300, 3600])
            ->where('bucket_start', '<=', $now)
            ->select(['id', 'key', 'type', 'bucket_start', 'bucket_seconds', 'max'])
            ->lazyById(500);

        foreach ($rows as $row) {
            if (! in_array($row->key, $names, true) || ! is_numeric($row->max)) {
                continue;
            }

            $timestamp = (float) $row->max;
            $start = (int) $row->bucket_start;

            if (! is_finite($timestamp) || floor($timestamp) !== $timestamp || $timestamp <= 0
                || $timestamp > $now || $timestamp < $start || $timestamp >= $start + (int) $row->bucket_seconds) {
                continue;
            }

            $outcome = $row->type === 'scheduled_task:last_success' ? 'success' : 'failure';
            $latest[$row->key][$outcome] = max($latest[$row->key][$outcome] ?? 0, (int) $timestamp);
        }

        return $latest;
    }

    private function fresh(mixed $timestamp, int $now, int $seconds): bool
    {
        return is_int($timestamp) && $timestamp > 0 && $timestamp <= $now && $now - $timestamp <= $seconds;
    }

    private function clockTrusted(mixed $skew): bool
    {
        return is_numeric($skew) && is_finite((float) $skew) && abs((float) $skew) <= 60;
    }
}
