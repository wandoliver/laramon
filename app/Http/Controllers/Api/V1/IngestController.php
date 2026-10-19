<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Instance;
use App\Models\Sample;
use App\Services\DataFlowStatus;
use App\Services\ExceptionAggregator;
use App\Services\MetricWriter;
use App\Services\SampleWriter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IngestController extends Controller
{
    public const SUPPORTED_SCHEMA_VERSIONS = [1];

    public const TYPE_PATTERN = '/^[a-z][a-z0-9_:.]{0,59}$/';

    public function __invoke(
        Request $request,
        MetricWriter $writer,
        ExceptionAggregator $exceptions,
        SampleWriter $samples,
        DataFlowStatus $flow,
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
            'samples.*.kind' => ['required', 'string', 'in:'.implode(',', Sample::KINDS)],
            'samples.*.fingerprint' => ['required', 'string', 'size:32'],
            'samples.*.occurred_at' => ['required', 'date'],
            'samples.*.payload' => ['required', 'array'],
            'coverage' => ['sometimes', 'array:version,from,through,types', 'required_array_keys:version,from,through,types'],
            'coverage.version' => ['required_with:coverage', 'integer', 'in:1'],
            'coverage.from' => ['required_with:coverage', 'integer', 'min:0', 'max:4102444800'],
            'coverage.through' => ['required_with:coverage', 'integer', 'min:0', 'max:4102444800'],
            'coverage.types' => ['required_with:coverage', 'array', 'list', 'min:1', 'max:'.count(DataFlowStatus::TYPES)],
            'coverage.types.*' => ['required', 'string', 'distinct:strict', Rule::in(DataFlowStatus::TYPES)],
        ]);

        if (isset($data['coverage'])) {
            $from = (int) $data['coverage']['from'];
            $through = (int) $data['coverage']['through'];
            $now = now()->getTimestamp();
            // Only a separate final acknowledgement can attest every preceding chunk.
            if ($data['buckets'] !== [] || ($data['exceptions'] ?? []) !== [] || ($data['samples'] ?? []) !== []
                || $from % 300 !== 0 || $through % 300 !== 0 || $from >= $through
                || $through - $from > 3600 || $from < $now - 3600
                || $through > intdiv($now - 60, 300) * 300) {
                throw ValidationException::withMessages(['coverage' => 'Coverage must confirm a recent, complete, settled interval in a separate empty batch.']);
            }
        }

        // Receipt, metrics, samples and completeness form one atomic acknowledgement.
        // Lock and reload before merging metadata shared with heartbeat requests.
        try {
            $result = DB::transaction(function () use ($instance, $data, $writer, $exceptions, $samples, $flow) {
                $instance = Instance::query()->whereKey($instance->id)->lockForUpdate()->firstOrFail();
                if (DB::table('ingest_batches')->where('instance_id', $instance->id)->where('batch_uuid', $data['batch_uuid'])->exists()) {
                    return ['status' => 'duplicate'];
                }

                $receivedAt = now();
                DB::table('ingest_batches')->insert([
                    'instance_id' => $instance->id,
                    'batch_uuid' => $data['batch_uuid'],
                    'bucket_count' => count($data['buckets']),
                    'received_at' => $receivedAt,
                ]);

                $accepted = $writer->write($instance, $this->clampBuckets($data['buckets']));
                $exceptions->record($instance, $data['exceptions'] ?? []);
                $samples->write($instance, $data['samples'] ?? []);
                $clockSkew = $receivedAt->getTimestamp() - Carbon::parse($data['sent_at'])->getTimestamp();
                $meta = is_array($instance->meta) ? $instance->meta : [];
                if (isset($data['coverage'])) {
                    $previous = is_array($meta['data_flow'] ?? null) ? $meta['data_flow'] : null;
                    $meta['data_flow'] = $flow->confirm(
                        $previous,
                        $data['coverage'], $receivedAt->getTimestamp(), $clockSkew,
                    );
                    if ($meta['data_flow']['covered_through'] > ($previous['covered_through'] ?? -1)) {
                        $meta['data_flow_pending'] = false;
                    }
                } else {
                    // Even same-second partial exports revoke the previous all-clear.
                    // A duplicate or old final acknowledgement cannot clear this flag.
                    $meta['data_flow_pending'] = true;
                }

                $instance->forceFill([
                    'last_ingest_at' => $receivedAt,
                    'meta' => array_merge($meta, [
                        'agent_version' => $data['agent_version'],
                        'clock_skew_seconds' => $clockSkew,
                    ]),
                ])->save();

                return ['status' => 'ok', 'accepted' => $accepted];
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! DB::table('ingest_batches')->where('instance_id', $instance->id)->where('batch_uuid', $data['batch_uuid'])->exists()) {
                throw $exception;
            }
            $result = ['status' => 'duplicate'];
        }

        return response()->json($result);
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
