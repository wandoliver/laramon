<?php

namespace LaraMon\Agent;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use LaraMon\Agent\Collectors\CollectorRegistry;
use LaraMon\Agent\Commands\ExportCommand;
use LaraMon\Agent\Commands\HeartbeatCommand;
use LaraMon\Agent\Commands\TestConnectionCommand;
use LaraMon\Agent\Export\Coverage;
use LaraMon\Agent\Recorders\Requests;
use LaraMon\Agent\Recorders\ScheduledTasks;

class MonitorAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/monitor-agent.php', 'monitor-agent');

        $this->app->singleton(CollectorRegistry::class, function (Application $app) {
            $registry = new CollectorRegistry($app);

            foreach ((array) config('monitor-agent.collectors', []) as $collector) {
                $registry->collector($collector);
            }

            return $registry;
        });

        // Pulse reads its recorder config in boot(), so appending here is
        // safe regardless of provider order.
        if (config('monitor-agent.enabled')) {
            $recorders = [Requests::class => [], ScheduledTasks::class => []];

            if (config('monitor-agent.track_users')) {
                $recorders[Recorders\ActiveUsers::class] = [];
            }

            config([
                'pulse.recorders' => array_merge($recorders, (array) config('pulse.recorders', [])),
            ]);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/monitor-agent.php' => config_path('monitor-agent.php'),
            ], 'monitor-agent-config');

            $this->commands([
                ExportCommand::class,
                HeartbeatCommand::class,
                TestConnectionCommand::class,
            ]);
        }

        if (config('monitor-agent.enabled')) {
            $this->registerSamplers();

            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command('monitor-agent:export')
                    ->everyFiveMinutes()
                    ->withoutOverlapping()
                    ->runInBackground();

                $schedule->command('monitor-agent:heartbeat')
                    ->everyMinute()
                    ->runInBackground();
            });
        } else {
            // Disabled agents do not schedule exports, so invalidate capture
            // proof here as well. Monitoring failures must not break the app.
            try {
                $this->app->make(Coverage::class)->reset();
            } catch (\Throwable) {
                try {
                    Log::warning('Monitor agent capture state invalidation failed.');
                } catch (\Throwable) {
                    // A broken logger must not turn disabled monitoring into
                    // an application startup failure either.
                }
            }
        }
    }

    /**
     * Capture occurrence details (exception traces, slow query occurrences)
     * for the hub's drill-down pages.
     */
    protected function registerSamplers(): void
    {
        $this->app->singleton(Sampler::class);

        $this->callAfterResolving(
            ExceptionHandler::class,
            fn (ExceptionHandler $handler) => $handler->reportable(
                fn (\Throwable $e) => $this->app->make(Sampler::class)->exception($e),
            ),
        );

        Event::listen(QueryExecuted::class, fn (QueryExecuted $event) => $this->app->make(Sampler::class)->query($event));

        Event::listen(
            RequestHandled::class,
            fn (RequestHandled $event) => $this->app->make(Sampler::class)->request($event),
        );
    }
}
