<?php

namespace Tests\Feature;

use App\Models\Instance;
use App\Models\MetricBucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RollupPruneTest extends TestCase
{
    use RefreshDatabase;

    private function seedBucket(Instance $instance, int $start, int $seconds, int $count, float $sum, float $min, float $max, string $key = 'app.routes.home'): void
    {
        MetricBucket::query()->create([
            'instance_id' => $instance->id,
            'type' => 'request',
            'key' => $key,
            'key_hash' => MetricBucket::hashKey($key),
            'bucket_start' => $start,
            'bucket_seconds' => $seconds,
            'count' => $count,
            'sum' => $sum,
            'min' => $min,
            'max' => $max,
        ]);
    }

    public function test_rollup_aggregates_into_hourly_buckets_idempotently(): void
    {
        $instance = Instance::factory()->create();
        $hourStart = (int) (floor(now()->subDays(2)->getTimestamp() / 3600) * 3600);

        $this->seedBucket($instance, $hourStart, 300, 10, 1000, 50, 200);
        $this->seedBucket($instance, $hourStart + 300, 300, 20, 4000, 30, 700);

        $this->artisan('monitor:rollup')->assertExitCode(0);
        $this->artisan('monitor:rollup')->assertExitCode(0); // idempotent

        $hourly = MetricBucket::query()->where('bucket_seconds', 3600)->get();

        $this->assertCount(1, $hourly);
        $this->assertSame($hourStart, (int) $hourly[0]->bucket_start);
        $this->assertSame(30, (int) $hourly[0]->count);
        $this->assertSame(5000.0, (float) $hourly[0]->sum);
        $this->assertSame(30.0, (float) $hourly[0]->min);
        $this->assertSame(700.0, (float) $hourly[0]->max);
    }

    public function test_prune_removes_expired_rows(): void
    {
        $instance = Instance::factory()->create();

        $this->seedBucket($instance, now()->subDays(8)->getTimestamp(), 300, 1, 1, 1, 1, 'old.fine');
        $this->seedBucket($instance, now()->subDay()->getTimestamp(), 300, 1, 1, 1, 1, 'fresh.fine');
        $this->seedBucket($instance, now()->subDays(91)->getTimestamp(), 3600, 1, 1, 1, 1, 'old.hourly');

        DB::table('ingest_batches')->insert([
            ['instance_id' => $instance->id, 'batch_uuid' => '00000000-0000-0000-0000-000000000001', 'bucket_count' => 1, 'received_at' => now()->subDays(3)],
            ['instance_id' => $instance->id, 'batch_uuid' => '00000000-0000-0000-0000-000000000002', 'bucket_count' => 1, 'received_at' => now()],
        ]);

        $this->artisan('monitor:prune')->assertExitCode(0);

        $this->assertSame(['fresh.fine'], MetricBucket::query()->pluck('key')->all());
        $this->assertSame(1, DB::table('ingest_batches')->count());
    }
}
