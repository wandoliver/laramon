<?php

namespace LaraMon\Agent\Recorders;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Laravel\Pulse\Pulse;

/**
 * Records scheduled task outcomes — Pulse has no scheduler recorder.
 */
class ScheduledTasks
{
    /**
     * @var list<class-string>
     */
    public array $listen = [
        ScheduledTaskFinished::class,
        ScheduledTaskFailed::class,
        ScheduledBackgroundTaskFinished::class,
    ];

    public function __construct(
        protected Pulse $pulse,
    ) {}

    public function record(ScheduledTaskFinished|ScheduledTaskFailed|ScheduledBackgroundTaskFinished $event): void
    {
        // Recent framework versions expose overlapping runs that never executed.
        if ($event->task->skippedBecauseOverlapping ?? false) {
            return;
        }

        if ($event instanceof ScheduledTaskFinished) {
            // Background launches are not completed runs. For foreground failures,
            // Laravel dispatches Finished before Failed: count only the latter.
            if ($event->task->runInBackground || $event->task->exitCode !== 0) {
                return;
            }
        } elseif ($event instanceof ScheduledBackgroundTaskFinished && $event->task->exitCode === null) {
            return;
        }

        $key = $event->task->getSummaryForDisplay();
        $completedAt = now()->getTimestamp();

        if ($event instanceof ScheduledTaskFailed || $event->task->exitCode !== 0) {
            $this->pulse->record(
                type: 'scheduled_task_failed',
                key: $key,
                timestamp: $completedAt,
            )->count()->onlyBuckets();

            $this->pulse->record(
                type: 'scheduled_task_last_failure',
                key: $key,
                value: $completedAt,
                timestamp: $completedAt,
            )->max()->onlyBuckets();

            return;
        }

        $entry = $this->pulse->record(
            type: 'scheduled_task',
            key: $key,
            value: $event instanceof ScheduledTaskFinished ? (int) round($event->runtime * 1000) : null,
            timestamp: $completedAt,
        )->count()->onlyBuckets();

        // Background completion events contain no runtime. Preserve the outcome
        // count without introducing a fictitious zero into latency aggregates.
        if ($event instanceof ScheduledTaskFinished) {
            $entry->avg()->max();
        }

        $this->pulse->record(
            type: 'scheduled_task_last_success',
            key: $key,
            value: $completedAt,
            timestamp: $completedAt,
        )->max()->onlyBuckets();
    }
}
