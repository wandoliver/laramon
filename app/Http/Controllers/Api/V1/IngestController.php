<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Instance;
use App\Services\ExceptionAggregator;
use App\Services\MetricWriter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IngestController extends Controller
{
    public const SUPPORTED_SCHEMA_VERSIONS = [1];

    public const TYPE_PATTERN = '/^[a-z][a-z0-9_:.]{0,59}$/';

    public function __invoke(
        Request $request,
        MetricWriter $writer,
        ExceptionAggregator $exceptions,
        \App\Services\SampleWriter $samples,
    ): JsonResponse {
        /** @var Instance $instance */
        $instance = $request->attributes->get('instance');

        $data = $request->validate([
            'schema_version' => ['required', 'integer', 'in:'.implode(',', self::SUPPORTED_SCHEMA_VERSIONS)],
            'agent_version' => ['required', 'string', 'max:32'],
            'batch_uuid' => ['required', 'uuid'],
            'sent_at' => ['required', 'date'],
            'buckets' => ['present', 'array', 'max:2000'],
            'buckets.*.type' => ['required', 'string', 'regex:'.self::TYPE_PATTERN],
            'buckets.*.key' => ['required', 'string', 'max:500'],
            'buckets.*.bucket_start' => ['required', 'integer', 'min:0'],
            'buckets.*.bucket_seconds' => ['required', 'integer', 'in:300'],
            'buckets.*.count' => ['required', 'integer', 'min:0'],
            'buckets.*.sum' => ['nullable', 'numeric'],
            'buckets.*.min' => ['nullable', 'numeric'],
            'buckets.*.max' => ['nullable', 'numeric'],
            'exceptions' => ['sometimes', 'array', 'max:500'],
            'exceptions.*.fingerprint' => ['required', 'string', 'size:32'],
            'exceptions.*.class' => ['required', 'string', 'max:255'],
            'exceptions.*.location' => ['nullable', 'string', 'max:500'],
            'exceptions.*.count' => ['required', 'integer', 'min:1'],
            'exceptions.*.last_seen_at' => ['required', 'date'],
            'samples' => ['sometimes', 'array', 'max:50'],
            'samples.*.kind' => ['required', 'string', 'in:'.implode(',', \App\Models\Sample::KINDS)],
            'samples.*.fingerprint' => ['required', 'string', 'size:32'],
            'samples.*.occurred_at' => ['required', 'date'],
            'samples.*.payload' => ['required', 'array'],
        ]);

        // Idempotency: a batch uuid is accepted exactly once per instance.
        try {
            DB::table('ingest_batches')->insert([
                'instance_id' => $instance->id,
                'batch_uuid' => $data['batch_uuid'],
                'bucket_count' => count($data['buckets']),
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['status' => 'duplicate']);
        }

        $buckets = $this->clampBuckets($data['buckets']);

        $accepted = $writer->write($instance, $buckets);
        $exceptions->record($instance, $data['exceptions'] ?? []);
        $samples->write($instance, $data['samples'] ?? []);

        $instance->forceFill([
            'last_ingest_at' => now(),
            'meta' => array_merge($instance->meta ?? [], [
                'agent_version' => $data['agent_version'],
                'clock_skew_seconds' => (int) now()->diffInSeconds(
                    \Illuminate\Support\Carbon::parse($data['sent_at']),
                ),
            ]),
        ])->save();

        return response()->json(['status' => 'ok', 'accepted' => $accepted]);
    }

    /**
     * Guard against wildly skewed client clocks: drop buckets older than 30
     * days or starting more than 2 minutes in the future.
     *
     * @param  list<array<string, mixed>>  $buckets
     * @return list<array<string, mixed>>
     */
    private function clampBuckets(array $buckets): array
    {
        $floor = now()->subDays(30)->getTimestamp();
        $ceiling = now()->addSeconds(120)->getTimestamp();

        return array_values(array_filter(
            $buckets,
            fn (array $bucket): bool => $bucket['bucket_start'] >= $floor
                && $bucket['bucket_start'] <= $ceiling,
        ));
    }
}
