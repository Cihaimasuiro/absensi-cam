<?php

namespace App\Providers;

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
        $trustedProxies = config('app.trusted_proxies', '127.0.0.1');
        if ($trustedProxies === '*') {
            \Illuminate\Http\Middleware\TrustProxies::at('*');
        } else {
            \Illuminate\Http\Middleware\TrustProxies::at(explode(',', $trustedProxies));
        }
    }
}
