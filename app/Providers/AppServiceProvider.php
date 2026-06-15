<?php

namespace App\Providers;

use App\Models\Instance;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('ingest', function (Request $request) {
            $instance = $request->attributes->get('instance');

            return Limit::perMinute(30)->by(
                $instance instanceof Instance ? 'instance:'.$instance->id : $request->ip(),
            );
        });
    }
}
