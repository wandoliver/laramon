<?php

namespace LaraMon\Agent\Support;

/**
 * A monotonic increment since the previous export, e.g. appointments booked.
 */
final readonly class Counter
{
    public function __construct(
        public string $key,
        public int $delta,
    ) {}
}
