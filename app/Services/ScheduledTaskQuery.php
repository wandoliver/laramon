<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ScheduledTaskQuery
{
    public function __construct(private BucketQuery $buckets) {}

    /**
     * Combine both outcomes before ordering or pagination so failure-only
     * tasks remain visible regardless of the number of successful tasks.
     */
    public function query(int $instanceId, int $from, int $to): Builder
    {
        $grammar = DB::query()->getGrammar();
        $key = $grammar->wrap('key');
        $count = $grammar->wrap('count');
        $sum = $grammar->wrap('sum');
        $max = $grammar->wrap('max');
        $durationCount = $grammar->wrap('duration_count');

        // The optional timing count excludes background completions without
        // runtime. Old agents retain their existing per-bucket interpretation.
        $outcomes = DB::table('metric_buckets as outcomes')
            ->leftJoin('metric_buckets as runtime', function (JoinClause $join) {
                $join->on('runtime.instance_id', '=', 'outcomes.instance_id')
                    ->on('runtime.key_hash', '=', 'outcomes.key_hash')
                    ->on('runtime.bucket_start', '=', 'outcomes.bucket_start')
                    ->on('runtime.bucket_seconds', '=', 'outcomes.bucket_seconds')
                    ->where('runtime.type', 'scheduled_task:runtime');
            })
            ->select('outcomes.*')
            ->selectRaw('coalesce('.$grammar->wrap('runtime.count').', '.$grammar->wrap('outcomes.count').') as duration_count')
            ->where('outcomes.instance_id', $instanceId)
            ->whereIn('outcomes.type', ['scheduled_task', 'scheduled_task:failed'])
            ->where('outcomes.bucket_seconds', $this->buckets->resolution($from, $to))
            ->whereBetween('outcomes.bucket_start', [$from, $to]);

        $totals = DB::query()->fromSub($outcomes, 'task_outcomes')
            ->selectRaw($key)
            ->selectRaw("sum(case when type = ? then {$count} else 0 end) as successes", ['scheduled_task'])
            ->selectRaw("sum(case when type = ? then {$count} else 0 end) as failures", ['scheduled_task:failed'])
            ->selectRaw("1.0 * sum(case when type = ? then {$sum} end) / nullif(sum(case when type = ? and {$sum} is not null then {$durationCount} else 0 end), 0) as avg", ['scheduled_task', 'scheduled_task'])
            ->selectRaw("max(case when type = ? then {$max} end) as max", ['scheduled_task'])
            ->selectRaw('max(case when type = ? and '.$count.' > 0 then bucket_start end) as last_success', ['scheduled_task'])
            ->selectRaw('max(case when type = ? and '.$count.' > 0 then bucket_start end) as last_failure', ['scheduled_task:failed'])
            ->groupBy('key')
            ->havingRaw("sum({$count}) > 0");

        return DB::query()->fromSub($totals, 'scheduled_tasks')
            ->orderByRaw('case when last_failure is not null and (last_success is null or last_failure >= last_success) then 0 when failures > 0 then 1 else 2 end')
            ->orderByDesc('failures')
            ->orderBy('key');
    }

    public function paginate(int $instanceId, int $from, int $to, int $perPage = 25, ?int $page = null): LengthAwarePaginator
    {
        return $this->query($instanceId, $from, $to)
            ->paginate($perPage, ['*'], 'tasksPage', $page)
            ->through(function (object $task) use ($from, $to) {
                $task->successes = (int) $task->successes;
                $task->failures = (int) $task->failures;
                $task->avg = $task->avg !== null ? (float) $task->avg : null;
                $task->max = $task->max !== null ? (float) $task->max : null;
                $task->last_success = $task->last_success !== null ? (int) $task->last_success : null;
                $task->last_failure = $task->last_failure !== null ? (int) $task->last_failure : null;
                $task->resolution = $this->buckets->resolution($from, $to);
                $task->status = match (true) {
                    $task->last_failure !== null && $task->last_failure === $task->last_success => 'failure_recorded',
                    $task->last_failure !== null && ($task->last_success === null || $task->last_failure > $task->last_success) => 'failed',
                    default => 'succeeded',
                };

                return $task;
            });
    }
}
