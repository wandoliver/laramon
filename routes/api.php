<?php

use App\Http\Controllers\Api\V1\HeartbeatController;
use App\Http\Controllers\Api\V1\IngestController;
use App\Http\Middleware\AuthenticateInstance;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware([AuthenticateInstance::class, 'throttle:ingest'])
    ->group(function () {
        Route::post('ingest', IngestController::class);
        Route::post('heartbeat', HeartbeatController::class);
    });
