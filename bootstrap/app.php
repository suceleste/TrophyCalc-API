<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: base_path('routes/web.php'),
        api: base_path('routes/api.php'), // On active les routes API
        commands: base_path('routes/console.php'),
        health: '/up',
        // On configure les permissions pour les routes API
        apiPrefix: 'api',
        then: function () {
            // On s'assure que les routes API demandent une authentification (pour plus tard)
            Broadcast::routes();
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        // On définit les alias de middleware si nécessaire (ne pas toucher)
        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        ]);

        // On définit les règles CORS globales
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->validateCsrfTokens(except: [
            'stripe/*',
        ]);
        $middleware->validateSignatures(except: [
            'stripe/*',
        ]);

        // C'EST LA PARTIE LA PLUS IMPORTANTE
        // On autorise notre frontend à communiquer avec l'API
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
