<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the app behave as if it were mounted at WR_BASE_PATH.
 *
 * `tailscale serve --set-path /report` *strips* the mount prefix before forwarding:
 * a browser asking for /report/board reaches nginx as /board. Routes are therefore
 * registered at the root, and this middleware tells the URL generator what the
 * outside world sees, so every link, form action and redirect comes back carrying
 * /report.
 *
 * Direct access (curl http://host:3560/report/board on the tailnet) is handled by
 * nginx, which rewrites the prefix away before PHP ever runs.
 */
final class ForceMountPath
{
    public function handle(Request $request, Closure $next): Response
    {
        $prefix = trim((string) config('workreport.base_path'), '/');

        if ($prefix !== '') {
            // Prefer the forwarded scheme and host when a proxy supplied them, so a
            // TLS terminator in front of the container produces https URLs.
            $scheme = $request->header('X-Forwarded-Proto') ?: $request->getScheme();
            $host = $request->header('X-Forwarded-Host') ?: $request->getHost();

            URL::forceRootUrl(sprintf('%s://%s/%s', $scheme, $host, $prefix));
        }

        return $next($request);
    }
}
