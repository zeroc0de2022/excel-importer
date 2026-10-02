<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Uploads are the expensive endpoint; keep a public demo from being flooded
        RateLimiter::for('uploads', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(30)->by($request->ip()),
        ]);
    }
}
