<?php

use App\Http\Middleware\EnsureCurrentTeam;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Deploy\StaleCacheGuard;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

// A new release zip was extracted: drop caches built for the old code before Laravel loads them.
StaleCacheGuard::clearIfNewRelease(dirname(__DIR__));

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The cron URL and payment webhooks run without the web middleware (no session, cookies or CSRF).
        then: function (): void {
            Route::group([], base_path('routes/cron.php'));
            Route::group([], base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'team' => EnsureCurrentTeam::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
