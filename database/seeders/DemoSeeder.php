<?php

namespace Database\Seeders;

use App\Models\ExceptionGroup;
use App\Models\Instance;
use App\Models\MetricBucket;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Development-only: seeds a small fleet with a week of plausible-looking
 * telemetry so the dashboard can be designed and reviewed without a live
 * agent. Run with: php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    private array $rows = [];

    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'demo@laramon.test'],
            ['name' => 'Demo Admin', 'password' => Hash::make('password')],
        );

        $fleet = [
            ['name' => 'Beratungsstelle Nord', 'heartbeat' => now(), 'traffic' => 1.0],
            ['name' => 'Beratungsstelle Süd', 'heartbeat' => now()->subMinutes(6), 'traffic' => 0.6],
            ['name' => 'Demo Staging', 'heartbeat' => now()->subHours(3), 'traffic' => 0.15],
        ];

        foreach ($fleet as $config) {
            $instance = Instance::factory()->create([
                'name' => $config['name'],
                'slug' => \Illuminate\Support\Str::slug($config['name']),
                'environment' => str_contains($config['name'], 'Staging') ? 'staging' : 'production',
                'last_heartbeat_at' => $config['heartbeat'],
                'last_ingest_at' => $config['heartbeat'],
                'meta' => [
                    'agent_version' => '0.3.0',
                    'app_version' => '2.4.'.random_int(0, 3),
                    'php_version' => '8.4.21',
                    'laravel_version' => '13.15.0',
                    'queue' => ['pending' => random_int(0, 25), 'oldest_pending_seconds' => random_int(0, 120), 'failed' => random_int(0, 3)],
                ],
            ]);

            $this->seedInstance($instance, $config['traffic']);
        }

        $this->flush();

        $this->seedAlerts();

        Artisan::call('monitor:rollup');
    }

    private function seedAlerts(): void
    {
        $rule = \App\Models\AlertRule::query()->create([
            'name' => 'High error rate',
            'instance_id' => null,
            'metric_type' => 'exception',
            'aggregate' => 'count',
            'operator' => '>',
            'threshold' => 10,
            'window_minutes' => 15,
            'cooldown_minutes' => 30,
            'webhook_url' => 'https://example.com/teams-webhook',
            'enabled' => false, // demo only — enable with a real webhook
        ]);

        $instances = Instance::query()->orderBy('id')->limit(2)->get();

        if ($instances->count() === 2) {
            \App\Models\AlertEvent::query()->create([
                'alert_rule_id' => $rule->id,
                'instance_id' => $instances[1]->id,
                'value' => 42,
                'triggered_at' => now()->subHours(2),
                'notified' => true,
            ]);

            \App\Models\AlertEvent::query()->create([
                'alert_rule_id' => $rule->id,
                'instance_id' => $instances[0]->id,
                'value' => 23,
                'triggered_at' => now()->subDay(),
                'resolved_at' => now()->subDay()->addHours(1),
                'notified' => true,
            ]);
        }
    }

    private function seedInstance(Instance $instance, float $traffic): void
    {
        $start = now()->subDays(7)->getTimestamp();
        $start -= $start % 300;
        $end = now()->getTimestamp();

        $routes = ['dashboard', 'appointments.index', 'clients.show', 'cases.index', 'login'];
        $users = ['12|Anna Schneider', '17|Jonas Weber', '23|Client #23', '31|Maike Lorenz', '44|Client #44', '52|Thomas Brandt'];
        $exceptionClass = ['RuntimeException', 'app/Services/CalendarSync.php:88'];
        $spikeClass = ['Illuminate\\Database\\QueryException', 'app/Livewire/CaseList.php:142'];

        $spikeStart = now()->subDay()->subHours(3)->getTimestamp();
        $spikeEnd = $spikeStart + 7200;

        // The two busiest demo users get a guaranteed bucket here (after the
        // loop) so they show online dots — skip them inside the loop.
        $lastClosed = now()->getTimestamp();
        $lastClosed -= $lastClosed % 300;

        for ($t = $start; $t < $end; $t += 300) {
            $hour = (int) date('G', $t);
            $business = $hour >= 8 && $hour <= 18 ? 1.0 : 0.15;
            $load = $business * $traffic;

            foreach ($routes as $i => $route) {
                $count = (int) round(max(0, ($load * (60 - $i * 10)) * (0.7 + mt_rand() / mt_getrandmax() * 0.6)));

                if ($count === 0) {
                    continue;
                }

                $avg = 60 + $i * 25 + mt_rand(0, 40);

                $this->bucket($instance->id, 'request', $route, $t, $count, $count * $avg, $avg * 0.4, $avg * (2 + mt_rand(0, 8)));

                // Per-route latency histogram correlated with the avg above.
                $bulk = max(1, (int) round($count * 0.8));
                $this->bucket($instance->id, 'request_hist', $route.'|'.\App\Support\Histogram::binFor($avg), $t, $bulk);

                if ($count - $bulk > 0) {
                    $this->bucket($instance->id, 'request_hist', $route.'|'.\App\Support\Histogram::binFor($avg * 3), $t, $count - $bulk);
                }
            }

            if ($load > 0 && mt_rand(0, 5) === 0) {
                $duration = mt_rand(1100, 4800);
                $this->bucket($instance->id, 'slow_query', 'select * from `appointments` where `starttime` between ? and ? app/Services/CalendarService.php:51', $t, mt_rand(1, 4), null, null, $duration);
            }

            if ($load > 0 && mt_rand(0, 8) === 0) {
                $this->bucket($instance->id, 'slow_request', 'GET /management/appointments App\Livewire\Pages\Management\Appointments\Index', $t, mt_rand(1, 3), null, null, mt_rand(1100, 3500));
            }

            $jobs = (int) round($load * mt_rand(5, 20));
            if ($jobs > 0) {
                $this->bucket($instance->id, 'job:queued', 'database:default', $t, $jobs);
                $this->bucket($instance->id, 'job:processed', 'database:default', $t, $jobs);
                if (mt_rand(0, 20) === 0) {
                    $this->bucket($instance->id, 'job:failed', 'database:default', $t, 1);
                }
            }

            // Steady low-rate exception + a two-hour spike yesterday.
            if (mt_rand(0, 30) === 0) {
                $this->bucket($instance->id, 'exception', md5($exceptionClass[0].'|'.$exceptionClass[1]), $t, 1);
            }
            if ($t >= $spikeStart && $t < $spikeEnd && $traffic >= 0.5) {
                $this->bucket($instance->id, 'exception', md5($spikeClass[0].'|'.$spikeClass[1]), $t, mt_rand(2, 9));
            }


            foreach ($users as $i => $user) {
                if ($t === $lastClosed && $i < 2) {
                    continue;
                }

                if ($load > 0.2 && mt_rand(0, 2 + $i) === 0) {
                    $this->bucket($instance->id, 'active_user', $user, $t, mt_rand(1, 15));
                }
            }

            $this->bucket($instance->id, 'cache:hit', 'flat', $t, (int) round($load * mt_rand(200, 400)));
            $this->bucket($instance->id, 'cache:miss', 'flat', $t, (int) round($load * mt_rand(10, 40)));

            // Gauges & counters.
            $this->bucket($instance->id, 'gauge:queue.pending', 'queue.pending', $t, 1, null, null, max(0, (int) round($load * 20 + mt_rand(-5, 15))));
            $this->bucket($instance->id, 'gauge:active_users.client', 'active_users.client', $t, 1, null, null, (int) round($load * mt_rand(30, 80)));
            $this->bucket($instance->id, 'gauge:active_users.employee', 'active_users.employee', $t, 1, null, null, (int) round($load * mt_rand(5, 15)));
            $this->bucket($instance->id, 'gauge:consulting_cases.active', 'consulting_cases.active', $t, 1, null, null, 140 + (int) ($traffic * 60) + mt_rand(-3, 3));

            $booked = (int) round($load * mt_rand(0, 4));
            if ($booked > 0) {
                $this->bucket($instance->id, 'counter:appointments_booked', 'appointments_booked', $t, $booked);
            }

            // Hourly scheduled tasks on the hour.
            if ($t % 3600 === 0) {
                $this->bucket($instance->id, 'scheduled_task', 'app:send-notifications', $t, 1, mt_rand(800, 4000), null, mt_rand(4000, 9000));
            }
            if ($t % 86400 === 7200) {
                $this->bucket($instance->id, 'scheduled_task', 'app:backup', $t, 1, mt_rand(30000, 90000), null, mt_rand(90000, 200000));
                if (mt_rand(0, 6) === 0) {
                    $this->bucket($instance->id, 'scheduled_task:failed', 'app:backup', $t, 1);
                }
            }
        }

        foreach (array_slice($users, 0, 2) as $user) {
            $this->bucket($instance->id, 'active_user', $user, $lastClosed, mt_rand(2, 8));
        }

        ExceptionGroup::query()->create([
            'instance_id' => $instance->id,
            'fingerprint' => md5($exceptionClass[0].'|'.$exceptionClass[1]),
            'class' => $exceptionClass[0],
            'location' => $exceptionClass[1],
            'first_seen_at' => now()->subDays(30),
            'last_seen_at' => now()->subHours(mt_rand(1, 8)),
            'total_count' => mt_rand(40, 300),
        ]);

        foreach (range(1, 4) as $i) {
            DB::table('samples')->insert([
                'instance_id' => $instance->id,
                'kind' => 'exception',
                'fingerprint' => md5($exceptionClass[0].'|'.$exceptionClass[1]),
                'payload' => json_encode([
                    'class' => $exceptionClass[0],
                    'location' => $exceptionClass[1],
                    'message' => 'Failed to sync calendar: upstream returned HTTP 502',
                    'trace' => "#0 app/Services/CalendarSync.php(88): App\\Services\\CalendarSync->push()\n#1 app/Jobs/SyncCalendarJob.php(31): App\\Services\\CalendarSync->sync()\n#2 vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php(126): App\\Jobs\\SyncCalendarJob->handle()\n#3 {main}",
                    'url' => 'https://'.$instance->slug.'.example.com/management/appointments',
                    'method' => 'POST',
                ]),
                'occurred_at' => now()->subHours($i * 3),
            ]);
        }

        DB::table('samples')->insert([
            'instance_id' => $instance->id,
            'kind' => 'slow_request',
            'fingerprint' => md5('GET /management/appointments App\Livewire\Pages\Management\Appointments\Index'),
            'payload' => json_encode([
                'method' => 'GET',
                'path' => '/management/appointments',
                'via' => 'App\Livewire\Pages\Management\Appointments\Index',
                'status' => 200,
                'duration_ms' => mt_rand(1200, 3200),
            ]),
            'occurred_at' => now()->subMinutes(mt_rand(5, 90)),
        ]);

        DB::table('samples')->insert([
            'instance_id' => $instance->id,
            'kind' => 'slow_query',
            'fingerprint' => md5('select * from `appointments` where `starttime` between ? and ? app/Services/CalendarService.php:51'),
            'payload' => json_encode([
                'sql' => 'select * from `appointments` where `starttime` between ? and ?',
                'location' => 'app/Services/CalendarService.php:51',
                'duration_ms' => mt_rand(1500, 4500),
                'connection' => 'mysql',
            ]),
            'occurred_at' => now()->subMinutes(mt_rand(10, 120)),
        ]);

        if ($traffic >= 0.5) {
            ExceptionGroup::query()->create([
                'instance_id' => $instance->id,
                'fingerprint' => md5($spikeClass[0].'|'.$spikeClass[1]),
                'class' => $spikeClass[0],
                'location' => $spikeClass[1],
                'first_seen_at' => \Illuminate\Support\Carbon::createFromTimestamp($spikeStart),
                'last_seen_at' => \Illuminate\Support\Carbon::createFromTimestamp($spikeEnd),
                'total_count' => mt_rand(150, 600),
            ]);
        }
    }

    private function bucket(int $instanceId, string $type, string $key, int $start, int $count, ?float $sum = null, ?float $min = null, ?float $max = null): void
    {
        $this->rows[] = [
            'instance_id' => $instanceId,
            'type' => $type,
            'key' => $key,
            'key_hash' => MetricBucket::hashKey($key),
            'bucket_start' => $start,
            'bucket_seconds' => 300,
            'count' => $count,
            'sum' => $sum,
            'min' => $min,
            'max' => $max,
        ];

        if (count($this->rows) >= 1000) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if ($this->rows !== []) {
            DB::table('metric_buckets')->insert($this->rows);
            $this->rows = [];
        }
    }
}
