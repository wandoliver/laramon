<?php

namespace LaraMon\Agent;

use Illuminate\Support\Facades\Facade;
use LaraMon\Agent\Collectors\CollectorRegistry;

/**
 * @method static void collector(string $class)
 * @method static void gauge(string $key, \Closure $resolver)
 * @method static void counter(string $key, \Closure $resolver)
 * @method static void resolveUsersUsing(\Closure $resolver)
 *
 * @see CollectorRegistry
 */
class MonitorAgent extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CollectorRegistry::class;
    }
}
