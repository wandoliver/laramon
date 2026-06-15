<?php

use App\Http\Controllers\Auth\LoginController;
use App\Livewire\ExceptionDetail;
use App\Livewire\FleetOverview;
use App\Livewire\InstanceDetail;
use App\Livewire\Instances;
use App\Livewire\QueryDetail;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/', FleetOverview::class)->name('fleet');
    Route::get('/instances/{instance:slug}', InstanceDetail::class)->name('instance');
    Route::get('/instances/{instance:slug}/exceptions/{fingerprint}', ExceptionDetail::class)
        ->where('fingerprint', '[a-f0-9]{32}')->name('instance.exception');
    Route::get('/instances/{instance:slug}/queries/{hash}', QueryDetail::class)
        ->where('hash', '[a-f0-9]{32}')->name('instance.query');
    Route::get('/instances/{instance:slug}/requests/{hash}', \App\Livewire\RequestDetail::class)
        ->where('hash', '[a-f0-9]{32}')->name('instance.request');
    Route::get('/instances/{instance:slug}/routes/{hash}', \App\Livewire\RouteDetail::class)
        ->where('hash', '[a-f0-9]{32}')->name('instance.route');
    Route::get('/alerts', \App\Livewire\Alerts::class)->name('alerts');
    Route::get('/settings/instances', Instances::class)->name('instances.settings');
});
