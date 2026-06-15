<?php

namespace App\Services;

use App\Models\Instance;
use App\Models\Sample;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SampleWriter
{
    /**
     * Store occurrence samples and cap retention per fingerprint so a noisy
     * exception can never flood the table.
     *
     * @param  list<array{kind: string, fingerprint: string, occurred_at: string, payload: array<string, mixed>}>  $samples
     */
    public function write(Instance $instance, array $samples): void
    {
        $touched = [];

        foreach ($samples as $sample) {
            $payload = json_encode($sample['payload']);

            if ($payload === false || strlen($payload) > 65535) {
                continue;
            }

            Sample::query()->create([
                'instance_id' => $instance->id,
                'kind' => $sample['kind'],
                'fingerprint' => $sample['fingerprint'],
                'payload' => $sample['payload'],
                'occurred_at' => Carbon::parse($sample['occurred_at']),
            ]);

            $touched[$sample['kind'].'|'.$sample['fingerprint']] = $sample;
        }

        foreach ($touched as $sample) {
            $this->cap($instance, $sample['kind'], $sample['fingerprint']);
        }
    }

    private function cap(Instance $instance, string $kind, string $fingerprint): void
    {
        $keepIds = Sample::query()
            ->where('instance_id', $instance->id)
            ->where('kind', $kind)
            ->where('fingerprint', $fingerprint)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(Sample::KEEP_PER_FINGERPRINT)
            ->pluck('id');

        DB::table('samples')
            ->where('instance_id', $instance->id)
            ->where('kind', $kind)
            ->where('fingerprint', $fingerprint)
            ->whereNotIn('id', $keepIds->all())
            ->delete();
    }
}
