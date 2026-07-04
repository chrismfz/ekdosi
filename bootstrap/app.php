<?php

use App\Support\ErrorAlerts\ExceptionNotifier;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // PR #31 (WHMCS Stage B-1): register the inbound webhook
        // routes under /webhooks/* with the `api` middleware group
        // (stateless, no session, no CSRF). Each controller verifies
        // its own HMAC signature before doing anything.
        then: function (): void {
            Route::middleware('api')
                ->prefix('webhooks')
                ->name('webhooks.')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // OPS-3: email the ops recipients when the app reports an unhandled
        // exception (best-effort, deduped/throttled — see ExceptionNotifier).
        // The closure returns void so the normal laravel.log line is kept.
        $exceptions->report(function (Throwable $e): void {
            app(ExceptionNotifier::class)->reportFromHandler($e);
        });
    })->create();
