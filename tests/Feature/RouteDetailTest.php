<?php

namespace Tests\Feature;

use App\Livewire\InstanceDetail;
use App\Models\Instance;
use App\Models\MetricBucket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RouteDetailTest extends TestCase
{
    use RefreshDatabase;

    private Instance $instance;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance = Instance::factory()->create();
        $this->user = User::factory()->create();
    }

    private function seedRoute(string $key, int $count, float $avg, float $max, string $type = 'request'): void
    {
        $start = now()->getTimestamp() - 600;
        $start -= $start % 300;

        MetricBucket::query()->create([
            'instance_id' => $this->instance->id,
            'type' => $type,
            'key' => $key,
            'key_hash' => MetricBucket::hashKey($key),
            'bucket_start' => $start,
            'bucket_seconds' => 300,
            'count' => $count,
            'sum' => $avg * $count,
            'min' => $avg * 0.5,
            'max' => $max,
        ]);
    }

    public function test_top_routes_sort_by_avg_and_max(): void
    {
        $this->seedRoute('/busy', count: 100, avg: 50, max: 200);
        $this->seedRoute('/slow', count: 5, avg: 900, max: 4000);

        Livewire::actingAs($this->user)
            ->test(InstanceDetail::class, ['instance' => $this->instance])
            ->assertSeeInOrder(['/busy', '/slow'])      // default: count
            ->set('routesSort', 'avg')
            ->assertSeeInOrder(['/slow', '/busy'])
            ->set('routesSort', 'max')
            ->assertSeeInOrder(['/slow', '/busy']);
    }

    public function test_route_detail_renders_with_related_slow_requests(): void
    {
        $this->seedRoute('/management/appointments', count: 50, avg: 120, max: 2400);
        $this->seedRoute('GET /management/appointments SomeHandler', count: 3, avg: 1500, max: 2400, type: 'slow_request');
        $this->seedRoute('GET /other/route OtherHandler', count: 2, avg: 1200, max: 1300, type: 'slow_request');

        $this->actingAs($this->user)
            ->get('/instances/'.$this->instance->slug.'/routes/'.md5('/management/appointments'))
            ->assertOk()
            ->assertSee('/management/appointments')
            ->assertSee('2400 ms')
            ->assertSee('SomeHandler')
            ->assertDontSee('OtherHandler');
    }

    public function test_route_detail_shows_per_route_p95(): void
    {
        $this->seedRoute('/management/appointments', count: 10, avg: 120, max: 500);
        // 10 samples in (200, 400]: p95 = 200 + 200 * 0.95 = 390.
        $this->seedRoute('/management/appointments|le_400', count: 10, avg: 0, max: 0, type: 'request_hist');

        $this->actingAs($this->user)
            ->get('/instances/'.$this->instance->slug.'/routes/'.md5('/management/appointments'))
            ->assertOk()
            ->assertSee('p95')
            ->assertSee('390 ms');
    }

    public function test_top_routes_sort_by_per_route_p95(): void
    {
        // /busy: more traffic but fast p95; /slow: low traffic, terrible p95.
        $this->seedRoute('/busy', count: 100, avg: 50, max: 200);
        $this->seedRoute('/busy|le_50', count: 100, avg: 0, max: 0, type: 'request_hist');
        $this->seedRoute('/slow', count: 5, avg: 300, max: 4000);
        $this->seedRoute('/slow|le_3200', count: 5, avg: 0, max: 0, type: 'request_hist');

        Livewire::actingAs($this->user)
            ->test(InstanceDetail::class, ['instance' => $this->instance])
            ->assertSeeInOrder(['/busy', '/slow'])
            ->set('routesSort', 'p95')
            ->assertSeeInOrder(['/slow', '/busy']);
    }

    public function test_route_detail_404s_for_unknown_hash(): void
    {
        $this->actingAs($this->user)
            ->get('/instances/'.$this->instance->slug.'/routes/'.md5('nope'))
            ->assertNotFound();
    }
}
