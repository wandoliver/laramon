<?php

namespace LaraMon\Agent\Contracts;

use LaraMon\Agent\Support\Counter;
use LaraMon\Agent\Support\Gauge;

interface BusinessMetricCollector
{
    /**
     * @return list<Gauge|Counter>
     */
    public function collect(): array;
}
