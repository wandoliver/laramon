<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        ]);

        $instance->forceFill([
            'last_heartbeat_at' => now(),
            'meta' => array_merge($instance->meta ?? [], array_filter([
                'agent_version' => $data['agent_version'],
                'app_version' => $data['app_version'] ?? null,
                'php_version' => $data['php_version'] ?? null,
                'laravel_version' => $data['laravel_version'] ?? null,
                'queue' => $data['queue'] ?? null,
                'scheduler_last_run_at' => $data['scheduler_last_run_at'] ?? null,
            ], fn ($value) => $value !== null)),
        ])->save();

        return response()->json(['status' => 'ok']);
    }
}
