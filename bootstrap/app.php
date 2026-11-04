<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$basePath = trim((string) (getenv('WR_BASE_PATH') ?: '/report'), '/');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Routes are registered below instead of through `web:` so the mount point
        // (/report behind the reverse proxy, empty in a local dev server) is applied
        // exactly once. Registering twice would make route() ambiguous.
        then: function () use ($basePath) {
            // Health lives at the root regardless of the mount point: the container
            // health check, the proxy and monitoring all expect a fixed path.
            Route::get('/healthz', fn () => response('ok', 200)
                ->header('Content-Type', 'text/plain'))
                ->withoutMiddleware('web');

            $register = function () {
                require __DIR__.'/../routes/web.php';
            };

            if ($basePath === '') {
                Route::middleware('web')->group($register);

                return;
            }

            Route::middleware('web')->prefix($basePath)->group($register);
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // tailscale serve terminates TLS and forwards X-Forwarded-* from loopback.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
