<?php

namespace LaraMon\Agent;

use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Console\Scheduling\Schedule;
use LaraMon\Agent\Recorders\ScheduledTasks;

/** Read explicitly opted-in task definitions without invoking their callbacks or filters. */
class ScheduledTaskExpectations
{
    public function manifest(Schedule $schedule): array
    {
        // Older published configs can override the complete type map. Only
        // declare expectations when exact completion evidence can be exported.
        $recorders = (array) config('pulse.recorders', []);
        $recorder = $recorders[ScheduledTasks::class] ?? [];
        if (! config('pulse.enabled', true)
            || $recorder === false || (is_array($recorder) && ! ($recorder['enabled'] ?? true))
            || config('monitor-agent.pulse_types.scheduled_task_last_success') !== 'scheduled_task:last_success'
            || config('monitor-agent.pulse_types.scheduled_task_last_failure') !== 'scheduled_task:last_failure') {
            return ['version' => 1, 'tasks' => []];
        }

        $configured = (array) config('monitor-agent.expected_scheduled_tasks', []);
        $tasks = [];
        $duplicates = [];

        foreach ($schedule->events() as $event) {
            $name = $event->description;
            if (! is_string($name) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,119}\z/', $name)
                || ! array_key_exists($name, $configured)) {
                continue;
            }

            if (isset($tasks[$name]) || isset($duplicates[$name])) {
                unset($tasks[$name]);
                $duplicates[$name] = true;

                continue;
            }

            $grace = $configured[$name];
            $timezone = $event->timezone instanceof DateTimeZone
                ? $event->timezone->getName()
                : ($event->timezone ?: config('app.timezone', 'UTC'));
            $expression = $event->expression;

            if (! is_int($grace) || $grace < 900 || $grace > 604800
                || ! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)
                || ! is_string($expression) || strlen($expression) > 100
                || count(preg_split('/\s+/', trim($expression))) !== 5
                || ! CronExpression::isValidExpression($expression)) {
                continue;
            }

            $tasks[$name] = [
                'name' => $name,
                'expression' => $expression,
                'timezone' => $timezone,
                'grace_seconds' => $grace,
            ];
        }

        // A heartbeat must remain small, even if an application is misconfigured.
        return ['version' => 1, 'tasks' => array_slice(array_values($tasks), 0, 100)];
    }
}
