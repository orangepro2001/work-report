<?php

use App\Http\Middleware\ForceMountPath;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Routes are registered at the root, always. The reverse proxy
        // (`tailscale serve --set-path /report`) strips the mount prefix before
        // forwarding, so the app genuinely receives bare paths; the prefix is put
        // back into every generated URL by ForceMountPath.
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
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

        // Prepended, not appended: URL generation must be correct before any
        // controller, middleware or error page builds a link.
        $middleware->prependToGroup('web', ForceMountPath::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
