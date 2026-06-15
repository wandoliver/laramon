<?php

namespace Tests\Feature;

use App\Models\ExceptionGroup;
use App\Models\Instance;
use App\Models\MetricBucket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function seedMetrics(Instance $instance): void
    {
        $start = now()->getTimestamp() - 3600;
        $start -= $start % 300;

        foreach (['active_user' => '7|Maria Muster', 'request_hist' => 'le_100'] as $type => $key) {
            MetricBucket::query()->create([
                'instance_id' => $instance->id,
                'type' => $type,
                'key' => $key,
                'key_hash' => MetricBucket::hashKey($key),
                'bucket_start' => (int) (floor((now()->getTimestamp() - 400) / 300) * 300),
                'bucket_seconds' => 300,
                'count' => 10,
                'sum' => null,
                'min' => null,
                'max' => null,
            ]);
        }

        foreach (['request' => 'dashboard', 'slow_query' => 'select 1', 'exception' => md5('RuntimeException|app/Foo.php:1'), 'gauge:active_users.client' => 'active_users.client', 'counter:appointments_booked' => 'appointments_booked', 'active_user' => '42|Maria Muster'] as $type => $key) {
            MetricBucket::query()->create([
                'instance_id' => $instance->id,
                'type' => $type,
                'key' => $key,
                'key_hash' => MetricBucket::hashKey($key),
                'bucket_start' => $start,
                'bucket_seconds' => 300,
                'count' => 5,
                'sum' => 500,
                'min' => 50,
                'max' => 200,
            ]);
        }

        ExceptionGroup::query()->create([
            'instance_id' => $instance->id,
            'fingerprint' => md5('RuntimeException|app/Foo.php:1'),
            'class' => 'RuntimeException',
            'location' => 'app/Foo.php:1',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
            'total_count' => 12,
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_fleet_overview_renders(): void
    {
        $instance = Instance::factory()->create();
        $this->seedMetrics($instance);

        $this->actingAs($this->user)
            ->get('/')
            ->assertOk()
            ->assertSee($instance->name)
            ->assertSee('1 online');
    }

    public function test_instance_detail_renders_all_sections(): void
    {
        $instance = Instance::factory()->create();
        $this->seedMetrics($instance);

        $this->actingAs($this->user)
            ->get('/instances/'.$instance->slug)
            ->assertOk()
            ->assertSee('Requests')
            ->assertSee('Database')
            ->assertSee('Scheduler')
            ->assertSee('RuntimeException')
            ->assertSee('Business metrics')
            ->assertSee('appointments booked')
            ->assertSee('Active users')
            ->assertSee('Maria Muster')
            ->assertSee('p95')
            ->assertSee('98 ms'); // Histogram::percentile(['le_100' => 10], 0.95) = 97.5
    }

    public function test_instance_detail_renders_for_every_range(): void
    {
        $instance = Instance::factory()->create();
        $this->seedMetrics($instance);

        foreach (['1h', '24h', '7d', '30d'] as $range) {
            $this->actingAs($this->user)
                ->get('/instances/'.$instance->slug.'?range='.$range)
                ->assertOk();
        }
    }

    public function test_instances_admin_renders(): void
    {
        Instance::factory()->create(['name' => 'Visible Instance']);

        $this->actingAs($this->user)
            ->get('/settings/instances')
            ->assertOk()
            ->assertSee('Visible Instance');
    }

    public function test_login_flow(): void
    {
        $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($this->user);
    }
}
