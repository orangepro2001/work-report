<?php

namespace App\Http\Middleware;

use App\Contracts\ActorResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The board has no anonymous visitors.
 *
 * Asks the seam instead of a guard, so a future provider only changes the binding
 * in AppServiceProvider. The intended URL is remembered here rather than in the
 * matcher: the board is reached from links that carry filters and a date.
 */
final class RequireActor
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(ActorResolver::class)->current() !== null) {
            return $next($request);
        }

        // Built through the URL generator on purpose: the reverse proxy strips the
        // /report mount prefix before PHP runs, so $request->fullUrl() would send
        // the person to /board — the *portal's* path — after signing in. url()
        // puts the prefix back (ForceMountPath, docs/deploy.md).
        $query = $request->getQueryString();
        $intended = url($request->path()).($query !== null && $query !== '' ? '?'.$query : '');

        // Drop identity that no longer resolves, but nothing else: invalidating the
        // whole session here destroyed the `oidc.state` of a round trip that is
        // still in flight (another tab, a reload, a prefetch), which made the SSO's
        // reply fail the state check — the "cookie conflict" this fixed itself into.
        // A session that has run out of retries is dealt with in the callback.
        $request->session()->forget(['oidc.claims', 'wr.actor_id']);
        $request->session()->put('url.intended', $intended);

        return redirect()->route('sso.redirect');
    }
}
