<?php

namespace LaraMon\Agent\Recorders;

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
    ];

    public function __construct(
        protected Pulse $pulse,
    ) {}

    public function record(ScheduledTaskFinished|ScheduledTaskFailed $event): void
    {
        $key = $event->task->getSummaryForDisplay();

        if ($event instanceof ScheduledTaskFinished) {
            $this->pulse->record(
                type: 'scheduled_task',
                key: $key,
                value: (int) round($event->runtime * 1000),
            )->count()->avg()->max()->onlyBuckets();

            return;
        }

        $this->pulse->record(
            type: 'scheduled_task_failed',
            key: $key,
        )->count()->onlyBuckets();
    }
}
