<?php

namespace LaraMon\Agent\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LaraMon\Agent\Export\PulseExporter;
use LaraMon\Agent\Export\Watermark;
use LaraMon\Agent\Http\HubClient;

class ExportCommand extends Command
{
    protected $signature = 'monitor-agent:export';

    protected $description = 'Export Pulse aggregates and business metrics to the monitor hub';

    public function handle(PulseExporter $exporter, HubClient $client, Watermark $watermark): int
    {
        if (! config('monitor-agent.enabled')
            || ! config('monitor-agent.hub_url')
            || ! config('monitor-agent.token')) {
            return self::SUCCESS;
        }

        $export = $exporter->build();

        if ($export['buckets'] === [] && $export['exceptions'] === [] && $export['samples'] === []) {
            if ($export['watermark'] !== null) {
                $watermark->set($export['watermark']);
            }

            return self::SUCCESS;
        }

        $maxPerBatch = max(1, (int) config('monitor-agent.max_buckets_per_batch', 2000));
        $chunks = array_chunk($export['buckets'], $maxPerBatch) ?: [[]];

        $allOk = true;

        foreach ($chunks as $index => $chunk) {
            // Exception metadata and samples ride along with the first batch.
            $allOk = $client->ingest(
                $chunk,
                $index === 0 ? $export['exceptions'] : [],
                $index === 0 ? $export['samples'] : [],
                $this->batchUuid($chunk, $index === 0 ? $export['exceptions'] : [], $index === 0 ? $export['samples'] : [], $index),
            ) && $allOk;
        }

        // Advance only when every batch landed; idempotent upserts on the
        // hub make the resulting overlap on retry harmless.
        if ($allOk) {
            if ($export['watermark'] !== null) {
                $watermark->set($export['watermark']);
            }

            if ($export['sample_ids'] !== []) {
                DB::table('monitor_agent_samples')->whereIn('id', $export['sample_ids'])->delete();
            }
        }

        if (! $allOk) {
            $this->warn('One or more ingest batches failed; watermark not advanced.');
        }

        return self::SUCCESS;
    }

    /**
     * Stable per-payload UUID: if a later chunk fails, retried chunks keep the
     * same idempotency key so additive side effects on the hub are not replayed.
     *
     * @param  list<array<string, mixed>>  $buckets
     * @param  list<array<string, mixed>>  $exceptions
     * @param  list<array<string, mixed>>  $samples
     */
    private function batchUuid(array $buckets, array $exceptions, array $samples, int $index): string
    {
        $hash = hash('sha256', json_encode([
            'index' => $index,
            'buckets' => $buckets,
            'exceptions' => $exceptions,
            'samples' => $samples,
        ], JSON_THROW_ON_ERROR));

        return sprintf(
            '%s-%s-5%s-%s%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            dechex((hexdec($hash[16]) & 0x3) | 0x8),
            substr($hash, 17, 3),
            substr($hash, 20, 12),
        );
    }
}
