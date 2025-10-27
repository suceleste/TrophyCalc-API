<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Broadcast;

return Application::configure(basePath: dirname(__DIR__))
    // Dans withRouting(...)
    ->withRouting(
        web: base_path('routes/web.php'),
        api: base_path('routes/api.php'),
        commands: base_path('routes/console.php'),
        health: '/up',
        apiPrefix: 'api',
        then: function () {
            Broadcast::routes();
        }
    )
    // Dans withMiddleware(...)
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            //'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        ]);

        //$middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
