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
        $middleware->alias([
            'admin'              => \App\Http\Middleware\AdminOnly::class,
            'admin.write'        => \App\Http\Middleware\AdminWriteOnly::class,
            'admin.create'       => \App\Http\Middleware\AdminCreateOnly::class,
            'admin.only.strict'  => \App\Http\Middleware\AdminOnlyStrict::class,
        ]);

        // Runs on every web request after the session middleware:
        // 1. Kills sessions of deactivated accounts before controllers run.
        // 2. Marks all responses no-store so logged-out users can never see
        //    protected pages via Back button, bookmark, or browser cache.
        $middleware->web(append: [
            \App\Http\Middleware\EnsureUserIsActive::class,
            \App\Http\Middleware\NoCache::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
