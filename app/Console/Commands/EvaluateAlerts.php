<?php

namespace App\Console\Commands;

use App\Services\AlertEvaluator;
use Illuminate\Console\Command;

class EvaluateAlerts extends Command
{
    protected $signature = 'monitor:evaluate-alerts';

    protected $description = 'Evaluate alert rules and send Teams notifications for breaches and recoveries';

    public function handle(AlertEvaluator $evaluator): int
    {
        $evaluator->evaluate();

        return self::SUCCESS;
    }
}
