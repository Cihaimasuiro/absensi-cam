<?php

namespace App\Providers;

use App\Domain\Student\Models\Student;
use App\Policies\StudentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Daftarkan semua layanan aplikasi.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap semua layanan aplikasi.
     */
    public function boot(): void
    {
        Gate::policy(Student::class, StudentPolicy::class);

        // Berikan secara implisit semua pemeriksaan permission kepada role 'super_admin'
        Gate::before(function ($user, $ability) {
            return method_exists($user, 'hasRole') && $user->hasRole('super_admin') ? true : null;
        });

        $trustedProxies = config('app.trusted_proxies', '127.0.0.1');
        if ($trustedProxies === '*') {
            \Illuminate\Http\Middleware\TrustProxies::at('*');
        } else {
            \Illuminate\Http\Middleware\TrustProxies::at(explode(',', $trustedProxies));
        }
    }
}
