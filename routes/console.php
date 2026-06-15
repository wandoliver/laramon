<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('monitor:rollup')->hourlyAt(5)->withoutOverlapping();
Schedule::command('monitor:prune')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('monitor:evaluate-alerts')->everyMinute()->withoutOverlapping();
