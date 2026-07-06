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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
