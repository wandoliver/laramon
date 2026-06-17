<?php

namespace Tests\Feature;

use App\Livewire\ExceptionDetail;
use App\Models\ExceptionGroup;
use App\Models\Instance;
use App\Models\MetricBucket;
use App\Models\Sample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SampleTest extends TestCase
{
    use RefreshDatabase;

    private Instance $instance;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance = Instance::factory()->create();
        $this->token = $this->instance->rotateToken();
    }

    private function ingestSamples(array $samples)
    {
        return $this->withToken($this->token)->postJson('/api/v1/ingest', [
            'schema_version' => 1,
            'agent_version' => '0.1.0',
            'batch_uuid' => (string) Str::uuid(),
            'sent_at' => now()->toIso8601String(),
            'buckets' => [],
            'samples' => $samples,
        ]);
    }

    public function test_samples_are_stored(): void
    {
        $fingerprint = md5('RuntimeException|app/Foo.php:42');

        $this->ingestSamples([[
            'kind' => 'exception',
            'fingerprint' => $fingerprint,
            'occurred_at' => now()->toIso8601String(),
            'payload' => ['class' => 'RuntimeException', 'message' => 'boom', 'trace' => '#0 app/Foo.php(42)'],
        ]])->assertOk();

        $sample = Sample::query()->sole();
        $this->assertSame('exception', $sample->kind);
        $this->assertSame('boom', $sample->payload['message']);
    }

    public function test_samples_are_capped_per_fingerprint(): void
    {
        $fingerprint = md5('RuntimeException|app/Foo.php:42');

        for ($i = 0; $i < Sample::KEEP_PER_FINGERPRINT + 5; $i++) {
            $this->ingestSamples([[
                'kind' => 'exception',
                'fingerprint' => $fingerprint,
                'occurred_at' => now()->subMinutes(100 - $i)->toIso8601String(),
                'payload' => ['message' => 'occurrence '.$i],
            ]]);
        }

        $this->assertSame(Sample::KEEP_PER_FINGERPRINT, Sample::query()->count());

        // The newest survive.
        $this->assertSame(
            'occurrence '.(Sample::KEEP_PER_FINGERPRINT + 4),
            Sample::query()->orderByDesc('occurred_at')->first()->payload['message'],
        );
    }

    public function test_invalid_sample_kind_is_rejected(): void
    {
        $this->ingestSamples([[
            'kind' => 'weird',
            'fingerprint' => md5('x'),
            'occurred_at' => now()->toIso8601String(),
            'payload' => [],
        ]])->assertStatus(422);
    }

    public function test_exception_detail_page_renders_with_samples(): void
    {
        $user = User::factory()->create();
        $fingerprint = md5('RuntimeException|app/Foo.php:42');

        ExceptionGroup::query()->create([
            'instance_id' => $this->instance->id,
            'fingerprint' => $fingerprint,
            'class' => 'RuntimeException',
            'location' => 'app/Foo.php:42',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
            'total_count' => 7,
        ]);

        Sample::query()->create([
            'instance_id' => $this->instance->id,
            'kind' => 'exception',
            'fingerprint' => $fingerprint,
            'payload' => ['message' => 'It exploded spectacularly', 'trace' => '#0 app/Foo.php(42): boom()'],
            'occurred_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/instances/'.$this->instance->slug.'/exceptions/'.$fingerprint)
            ->assertOk()
            ->assertSee('RuntimeException')
            ->assertSee('It exploded spectacularly')
            ->assertSee('app/Foo.php(42)');
    }

    public function test_exception_groups_can_be_resolved_with_a_comment(): void
    {
        $user = User::factory()->create();
        $fingerprint = md5('RuntimeException|app/Foo.php:42');

        $group = ExceptionGroup::query()->create([
            'instance_id' => $this->instance->id,
            'fingerprint' => $fingerprint,
            'class' => 'RuntimeException',
            'location' => 'app/Foo.php:42',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
            'total_count' => 7,
        ]);

        Livewire::actingAs($user)
            ->test(ExceptionDetail::class, ['instance' => $this->instance, 'fingerprint' => $fingerprint])
            ->call('startResolving')
            ->set('resolutionComment', 'Fixed in production and verified no new occurrences.')
            ->call('resolve')
            ->assertHasNoErrors()
            ->assertSet('showResolutionForm', false)
            ->assertSee('resolved');

        $group->refresh();

        $this->assertNotNull($group->resolved_at);
        $this->assertSame($user->id, $group->resolved_by_user_id);
        $this->assertSame('Fixed in production and verified no new occurrences.', $group->resolved_comment);
    }

    public function test_query_detail_page_renders_with_samples(): void
    {
        $user = User::factory()->create();
        $key = 'select * from `appointments` app/Services/CalendarService.php:51';
        $hash = md5($key);

        MetricBucket::query()->create([
            'instance_id' => $this->instance->id,
            'type' => 'slow_query',
            'key' => $key,
            'key_hash' => MetricBucket::hashKey($key),
            'bucket_start' => now()->getTimestamp() - 600,
            'bucket_seconds' => 300,
            'count' => 3,
            'sum' => null,
            'min' => null,
            'max' => 2400,
        ]);

        Sample::query()->create([
            'instance_id' => $this->instance->id,
            'kind' => 'slow_query',
            'fingerprint' => $hash,
            'payload' => ['sql' => 'select * from `appointments`', 'location' => 'app/Services/CalendarService.php:51', 'duration_ms' => 2400, 'connection' => 'mysql'],
            'occurred_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/instances/'.$this->instance->slug.'/queries/'.$hash)
            ->assertOk()
            ->assertSee('select * from `appointments`')
            ->assertSee('CalendarService.php:51')
            ->assertSee('2400 ms');
    }

    public function test_request_detail_page_renders_with_samples(): void
    {
        $user = User::factory()->create();
        $key = 'GET /management/appointments App\Livewire\Pages\Management\Appointments\Index';
        $hash = md5($key);

        MetricBucket::query()->create([
            'instance_id' => $this->instance->id,
            'type' => 'slow_request',
            'key' => $key,
            'key_hash' => MetricBucket::hashKey($key),
            'bucket_start' => now()->getTimestamp() - 600,
            'bucket_seconds' => 300,
            'count' => 2,
            'sum' => null,
            'min' => null,
            'max' => 1800,
        ]);

        Sample::query()->create([
            'instance_id' => $this->instance->id,
            'kind' => 'slow_request',
            'fingerprint' => $hash,
            'payload' => ['method' => 'GET', 'path' => '/management/appointments', 'status' => 200, 'duration_ms' => 1800],
            'occurred_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/instances/'.$this->instance->slug.'/requests/'.$hash)
            ->assertOk()
            ->assertSee('Slow request')
            ->assertSee('/management/appointments')
            ->assertSee('1800 ms');
    }

    public function test_query_detail_404s_for_unknown_hash(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/instances/'.$this->instance->slug.'/queries/'.md5('nope'))
            ->assertNotFound();
    }
}
