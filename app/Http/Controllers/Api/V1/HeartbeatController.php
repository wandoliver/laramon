<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Instance;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class HeartbeatController extends Controller
{
    public const SUPPORTED_SCHEMA_VERSIONS = [1];

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Instance $instance */
        $instance = $request->attributes->get('instance');

        $data = $request->validate([
            'schema_version' => ['required', 'integer', 'in:'.implode(',', self::SUPPORTED_SCHEMA_VERSIONS)],
            'agent_version' => ['required', 'string', 'max:32'],
            'app_version' => ['nullable', 'string', 'max:64'],
            'php_version' => ['nullable', 'string', 'max:32'],
            'laravel_version' => ['nullable', 'string', 'max:32'],
            'sent_at' => ['required', 'date'],
            'queue' => ['sometimes', 'array'],
            'queue.pending' => ['nullable', 'integer', 'min:0'],
            'queue.oldest_pending_seconds' => ['nullable', 'integer', 'min:0'],
            'queue.failed' => ['nullable', 'integer', 'min:0'],
            'scheduler_last_run_at' => ['nullable', 'date'],
            'scheduler' => ['sometimes', 'array:version,tasks,exported_through', 'required_array_keys:version,tasks'],
            'scheduler.version' => ['required_with:scheduler', 'integer', 'in:1'],
            'scheduler.tasks' => ['sometimes', 'array', 'list', 'max:100'],
            'scheduler.exported_through' => ['nullable', 'integer', 'min:0'],
            'scheduler.tasks.*' => ['array:name,expression,timezone,grace_seconds', 'required_array_keys:name,expression,timezone,grace_seconds'],
            'scheduler.tasks.*.name' => ['required', 'string', 'max:120', 'distinct', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,119}\z/'],
            'scheduler.tasks.*.expression' => ['required', 'string', 'max:100', function ($attribute, $value, $fail) {
                if (! is_string($value) || count(preg_split('/\s+/', trim($value))) !== 5 || ! CronExpression::isValidExpression($value)) {
                    $fail('The expression must be a valid five-field cron expression.');
                }
            }],
            'scheduler.tasks.*.timezone' => ['required', 'string', 'max:64', function ($attribute, $value, $fail) {
                if (! in_array($value, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
                    $fail('The timezone must be a valid timezone identifier.');
                }
            }],
            'scheduler.tasks.*.grace_seconds' => ['required', 'integer', 'min:900', 'max:604800'],
        ]);

        DB::transaction(function () use ($instance, $data) {
            $instance = Instance::query()->whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $meta = is_array($instance->meta) ? $instance->meta : [];
            if (array_key_exists('scheduler', $data)) {
                $oldManifest = is_array($meta['scheduler'] ?? null) ? $meta['scheduler'] : [];
                $previous = collect(is_array($oldManifest['tasks'] ?? null) ? $oldManifest['tasks'] : [])
                    ->filter(fn ($task) => is_array($task) && is_string($task['name'] ?? null))->keyBy('name');
                $tasks = [];
                foreach ($data['scheduler']['tasks'] as $task) {
                    $task['grace_seconds'] = (int) $task['grace_seconds'];
                    $old = $previous->get($task['name']);
                    $unchanged = is_array($old)
                        && ($old['expression'] ?? null) === $task['expression']
                        && ($old['timezone'] ?? null) === $task['timezone']
                        && ($old['grace_seconds'] ?? null) === $task['grace_seconds']
                        && is_int($old['observed_since'] ?? null)
                        && $old['observed_since'] > 0 && $old['observed_since'] <= now()->getTimestamp();
                    $task['observed_since'] = $unchanged ? $old['observed_since'] : now()->getTimestamp();
                    $tasks[] = $task;
                }
                $meta['scheduler'] = [
                    'version' => 1,
                    'available' => true,
                    'reported_at' => now()->getTimestamp(),
                    'clock_skew_seconds' => now()->getTimestamp() - Carbon::parse($data['sent_at'])->getTimestamp(),
                    'exported_through' => isset($data['scheduler']['exported_through']) ? (int) $data['scheduler']['exported_through'] : null,
                    'tasks' => $tasks,
                ];
            } elseif (is_array($meta['scheduler'] ?? null)) {
                // An older/downgraded agent cannot attest that expectations remain valid.
                $meta['scheduler']['available'] = false;
            }

            $instance->forceFill([
                'last_heartbeat_at' => now(),
                'meta' => array_merge($meta, array_filter([
                    'agent_version' => $data['agent_version'],
                    'heartbeat_clock_skew_seconds' => now()->getTimestamp() - Carbon::parse($data['sent_at'])->getTimestamp(),
                    'queue_available' => array_key_exists('queue', $data),
                    'queue_reported_at' => array_key_exists('queue', $data) ? now()->getTimestamp() : null,
                    'app_version' => $data['app_version'] ?? null,
                    'php_version' => $data['php_version'] ?? null,
                    'laravel_version' => $data['laravel_version'] ?? null,
                    'queue' => $data['queue'] ?? null,
                    'scheduler_last_run_at' => $data['scheduler_last_run_at'] ?? null,
                ], fn ($value) => $value !== null)),
            ])->save();
        });

        return response()->json(['status' => 'ok']);
    }
}
