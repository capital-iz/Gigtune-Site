<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\EnforceGigTuneSiteMaintenance::class);

        $middleware->alias([
            'gigtune.auth' => \App\Http\Middleware\EnsureGigTuneAuthenticated::class,
            'gigtune.admin' => \App\Http\Middleware\EnsureGigTuneAdmin::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'wp-json/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
