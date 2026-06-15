<?php

namespace LaraMon\Agent\Collectors;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use LaraMon\Agent\Contracts\BusinessMetricCollector;
use LaraMon\Agent\Support\Counter;
use LaraMon\Agent\Support\Gauge;

class CollectorRegistry
{
    /** @var list<class-string<BusinessMetricCollector>> */
    protected array $collectors = [];

    /** @var array<string, Closure(): (int|float)> */
    protected array $gauges = [];

    /** @var array<string, Closure(): int> */
    protected array $counters = [];

    /** @var (Closure(list<int|string>): array<int|string, string>)|null */
    protected ?Closure $userResolver = null;

    public function __construct(protected Container $container) {}

    /**
     * Register how user ids are turned into display labels for the hub.
     * The host app controls exactly what leaves the instance — e.g. staff
     * by name, clients pseudonymized.
     *
     * @param  Closure(list<int|string>): array<int|string, string>  $resolver
     */
    public function resolveUsersUsing(Closure $resolver): void
    {
        $this->userResolver = $resolver;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int|string, string>
     */
    public function resolveUsers(array $ids): array
    {
        $labels = [];

        if ($this->userResolver !== null) {
            try {
                $labels = ($this->userResolver)($ids);
            } catch (\Throwable $e) {
                Log::warning("Monitor agent user resolver failed: {$e->getMessage()}");
            }
        }

        foreach ($ids as $id) {
            $labels[$id] ??= 'User #'.$id;
        }

        return $labels;
    }

    /**
     * @param  class-string<BusinessMetricCollector>  $class
     */
    public function collector(string $class): void
    {
        $this->collectors[] = $class;
    }

    /**
     * @param  Closure(): (int|float)  $resolver
     */
    public function gauge(string $key, Closure $resolver): void
    {
        $this->gauges[$key] = $resolver;
    }

    /**
     * @param  Closure(): int  $resolver
     */
    public function counter(string $key, Closure $resolver): void
    {
        $this->counters[$key] = $resolver;
    }

    /**
     * Run every registered collector. A failing collector is logged and
     * skipped — business metrics must never abort an export.
     *
     * @return list<Gauge|Counter>
     */
    public function collect(): array
    {
        $metrics = [];

        foreach ($this->collectors as $class) {
            try {
                $collector = $this->container->make($class);

                foreach ($collector->collect() as $metric) {
                    $metrics[] = $metric;
                }
            } catch (\Throwable $e) {
                Log::warning("Monitor agent collector [{$class}] failed: {$e->getMessage()}");
            }
        }

        foreach ($this->gauges as $key => $resolver) {
            try {
                $metrics[] = new Gauge($key, (float) $resolver());
            } catch (\Throwable $e) {
                Log::warning("Monitor agent gauge [{$key}] failed: {$e->getMessage()}");
            }
        }

        foreach ($this->counters as $key => $resolver) {
            try {
                $metrics[] = new Counter($key, (int) $resolver());
            } catch (\Throwable $e) {
                Log::warning("Monitor agent counter [{$key}] failed: {$e->getMessage()}");
            }
        }

        return $metrics;
    }
}
