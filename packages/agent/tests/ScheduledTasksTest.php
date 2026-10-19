<?php

namespace LaraMon\Agent\Tests;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use LaraMon\Agent\Recorders\ScheduledTasks;
use Laravel\Pulse\Contracts\Ingest;
use Laravel\Pulse\Contracts\Storage;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Pulse;
use Mockery;
use RuntimeException;

class ScheduledTasksTest extends TestCase
{
    private Pulse $pulse;

    private ScheduledTasksInMemoryIngest $ingest;

    private ScheduledTasksSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['pulse.ingest.trim.lottery' => [0, 1]]);

        // The agent test harness does not boot Pulse's dashboard provider. Use
        // the real Pulse buffer and recorder registration without its UI stack.
        $this->pulse = new Pulse($this->app);
        $this->app->instance(Pulse::class, $this->pulse);
        $this->pulse->register([ScheduledTasks::class => []]);
        $this->ingest = new ScheduledTasksInMemoryIngest;
        $this->app->instance(Ingest::class, $this->ingest);

        // This isolated schedule contains only the in-process test tasks. Agent
        // exports, heartbeat commands, shell processes and external jobs never run.
        $this->schedule = new ScheduledTasksSchedule;
        $this->app->instance(Schedule::class, $this->schedule);

        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(12, 0));
    }

    public function test_foreground_success_preserves_label_runtime_and_bucket_aggregations(): void
    {
        $task = $this->commandTask();
        $task->exitCode = 0;

        app('events')->dispatch(new ScheduledTaskFinished($task, 1.234));

        $entries = $this->outcomes();
        $this->assertCount(1, $entries);
        $this->assertSame('scheduled_task', $entries[0]->type);
        $this->assertSame($task->getSummaryForDisplay(), $entries[0]->key);
        $this->assertSame(1234, $entries[0]->value);
        $this->assertEqualsCanonicalizing(['count', 'avg', 'max'], $entries[0]->aggregations());
        $this->assertTrue($entries[0]->isOnlyBuckets());
        $this->assertCompletionTimestamp('scheduled_task_last_success', $task);
    }

    public function test_real_scheduler_callback_success_records_one_success(): void
    {
        $calls = 0;
        $task = $this->schedule->call(function () use (&$calls) {
            $calls++;
        })->name('successful-callback');

        $this->artisan('schedule:run')->assertExitCode(0);

        $this->assertSame(1, $calls);
        $this->assertSame(0, $task->exitCode);
        $this->assertOutcomeTypes(['scheduled_task']);
        $this->assertCompletionTimestamp('scheduled_task_last_success', $task);
    }

    public function test_real_scheduler_false_callback_is_one_failure_without_false_success(): void
    {
        $task = $this->schedule->call(fn () => false)->name('failed-callback');
        $events = $this->observeForegroundEvents();

        $this->artisan('schedule:run')->assertExitCode(0);

        $this->assertSame([ScheduledTaskFinished::class, ScheduledTaskFailed::class], $events->all());
        $this->assertSame(1, $task->exitCode);
        $this->assertOutcomeTypes(['scheduled_task_failed']);
        $this->assertCompletionTimestamp('scheduled_task_last_failure', $task);
    }

    public function test_real_scheduler_throwing_callback_records_one_failure(): void
    {
        $task = $this->schedule->call(fn () => throw new RuntimeException('callback failed'))
            ->name('throwing-callback');
        $events = $this->observeForegroundEvents();

        $this->artisan('schedule:run')->assertExitCode(0);

        $this->assertSame([ScheduledTaskFailed::class], $events->all());
        $this->assertOutcomeTypes(['scheduled_task_failed']);
        $this->assertCompletionTimestamp('scheduled_task_last_failure', $task);
    }

    public function test_real_scheduler_nonzero_command_records_each_run_once(): void
    {
        $task = $this->commandTask();
        $task->simulatedExitCode = 7;
        $this->schedule->add($task);

        $this->artisan('schedule:run')->assertExitCode(0);
        $this->artisan('schedule:run')->assertExitCode(0);

        $this->assertSame(2, $task->executions);
        $this->assertOutcomeTypes(['scheduled_task_failed', 'scheduled_task_failed']);
    }

    public function test_background_launch_is_not_recorded_until_real_successful_completion(): void
    {
        $task = $this->commandTask()->runInBackground();
        $this->schedule->add($task);

        $this->artisan('schedule:run')->assertExitCode(0);

        $this->assertSame(1, $task->executions);
        $this->assertNull($task->exitCode);
        $this->assertCount(0, $this->schedulerEntries());

        $this->artisan('schedule:finish', ['id' => $task->mutexName(), 'code' => 0])->assertExitCode(0);

        $entries = $this->outcomes();
        $this->assertCount(1, $entries);
        $this->assertSame('scheduled_task', $entries[0]->type);
        $this->assertSame($task->getSummaryForDisplay(), $entries[0]->key);
        $this->assertNull($entries[0]->value);
        $this->assertSame(['count'], $entries[0]->aggregations());
        $this->assertTrue($entries[0]->isOnlyBuckets());
        $this->assertCompletionTimestamp('scheduled_task_last_success', $task);
    }

    public function test_background_failure_in_a_fresh_scheduler_process_records_one_failure(): void
    {
        // schedule:finish rebuilds the schedule in a separate process. Capturing
        // this outcome must not depend on observing its earlier launch event.
        $task = $this->commandTask()->runInBackground();
        $this->schedule->add($task);

        $this->artisan('schedule:finish', ['id' => $task->mutexName(), 'code' => 9])->assertExitCode(0);

        $this->assertSame(0, $task->executions);
        $this->assertOutcomeTypes(['scheduled_task_failed']);
        $this->assertCompletionTimestamp('scheduled_task_last_failure', $task);
    }

    public function test_unknown_completion_exit_codes_do_not_become_successes(): void
    {
        $task = $this->commandTask();

        app('events')->dispatch(new ScheduledTaskFinished($task, 1));
        app('events')->dispatch(new ScheduledBackgroundTaskFinished($task));

        $this->assertCount(0, $this->schedulerEntries());
    }

    public function test_background_launch_exception_records_a_failure(): void
    {
        $task = $this->commandTask()->runInBackground();

        app('events')->dispatch(new ScheduledTaskFailed($task, new RuntimeException('launch failed')));

        $this->assertOutcomeTypes(['scheduled_task_failed']);
        $this->assertCompletionTimestamp('scheduled_task_last_failure', $task);
    }

    public function test_overlapping_task_does_not_record_a_run_using_an_earlier_exit_code(): void
    {
        if (! property_exists(Event::class, 'skippedBecauseOverlapping')) {
            $this->markTestSkipped('This Laravel version does not expose overlap skips in completion events.');
        }

        $task = $this->commandTask()->withoutOverlapping();
        $task->exitCode = 7;
        $task->mutex = Mockery::mock(EventMutex::class);
        // Another scheduler obtains the lock between filtersPass and run.
        $task->mutex->shouldReceive('exists')->once()->with($task)->andReturn(false);
        $task->mutex->shouldReceive('create')->once()->with($task)->andReturn(false);
        $this->schedule->add($task);

        $this->artisan('schedule:run')->assertExitCode(0);

        $this->assertSame(0, $task->executions);
        $this->assertTrue($task->skippedBecauseOverlapping);
        $this->assertCount(0, $this->schedulerEntries());
    }

    public function test_recorder_listens_for_background_completion_events(): void
    {
        $this->assertContains(ScheduledBackgroundTaskFinished::class, app(ScheduledTasks::class)->listen);
    }

    private function commandTask(): ScheduledTasksCommandEvent
    {
        return (new ScheduledTasksCommandEvent(app(CacheEventMutex::class), 'scheduler-test-command'))
            ->name('command-task');
    }

    private function observeForegroundEvents(): Collection
    {
        $events = collect();

        app('events')->listen([ScheduledTaskFinished::class, ScheduledTaskFailed::class], function ($event) use ($events) {
            $events->push($event::class);
        });

        return $events;
    }

    private function schedulerEntries(): Collection
    {
        $this->pulse->ingest();

        return collect($this->ingest->entries)
            ->filter(fn ($entry) => $entry instanceof Entry && str_starts_with($entry->type, 'scheduled_task'))
            ->values();
    }

    private function outcomes(): Collection
    {
        return $this->schedulerEntries()
            ->filter(fn (Entry $entry) => in_array($entry->type, ['scheduled_task', 'scheduled_task_failed'], true))
            ->values();
    }

    private function assertOutcomeTypes(array $types): void
    {
        $entries = $this->outcomes();
        $this->assertSame($types, $entries->pluck('type')->all());

        foreach ($entries as $entry) {
            $this->assertTrue($entry->isCount());
            $this->assertTrue($entry->isOnlyBuckets());
        }
    }

    private function assertCompletionTimestamp(string $type, Event $task): void
    {
        $entries = $this->schedulerEntries()
            ->filter(fn (Entry $entry) => str_starts_with($entry->type, 'scheduled_task_last_'))
            ->values();

        $this->assertCount(1, $entries);
        $this->assertSame($type, $entries[0]->type);
        $this->assertSame($task->getSummaryForDisplay(), $entries[0]->key);
        $this->assertSame(now()->getTimestamp(), $entries[0]->value);
        $this->assertSame($entries[0]->value, $entries[0]->timestamp);
        $this->assertSame(['max'], $entries[0]->aggregations());
        $this->assertTrue($entries[0]->isOnlyBuckets());
    }
}

class ScheduledTasksSchedule extends Schedule
{
    public function add(Event $event): void
    {
        $this->events[] = $event;
    }
}

class ScheduledTasksCommandEvent extends Event
{
    public int $simulatedExitCode = 0;

    public int $executions = 0;

    protected function execute($container)
    {
        $this->executions++;

        return $this->simulatedExitCode;
    }
}

class ScheduledTasksInMemoryIngest implements Ingest
{
    public array $entries = [];

    public function ingest(Collection $items): void
    {
        array_push($this->entries, ...$items->all());
    }

    public function digest(Storage $storage): int
    {
        return 0;
    }

    public function trim(): void {}
}
