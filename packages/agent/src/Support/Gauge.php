<?php

namespace LaraMon\Agent\Support;

/**
 * A point-in-time value, e.g. current queue backlog or active users.
 */
final readonly class Gauge
{
    public function __construct(
        public string $key,
        public float $value,
    ) {}
}
