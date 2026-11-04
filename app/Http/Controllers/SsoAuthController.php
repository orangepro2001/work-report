<?php

namespace App\Http\Controllers;

use App\Services\OidcActorResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Authorization-code login against the SSO at `521985.xyz/sso`.
 *
 * Confidential client: the secret never leaves the server, so no PKCE is needed.
 * The code is exchanged, the identity document is read from `userinfo`, and only
 * the claims are kept — the app has no use for a token it would have to refresh.
 * Everything after that goes through `OidcActorResolver`, which is the single
 * place identity is decided (docs/auth-seam.md).
 */
final class SsoAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('oidc.state', $state);

        return redirect()->away($this->authorizeUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = (string) $request->session()->pull('oidc.state', '');

        // The state is the only thing tying this reply to the browser that started
        // the flow. A mismatch is not an error the person can act on: another tab
        // overwrote the state, the callback was reloaded, or the session cookie was
        // dropped between the two legs. Start the flow again instead of showing a
        // 403 wall (the guard against a loop lives in restart()).
        if ($state === '' || ! hash_equals($state, (string) $request->query('state'))) {
            return $this->restart($request, $state !== '');
        }

        // A person who cancels at the SSO comes back with ?error=… and no code.
        if ($request->query('error') !== null) {
            return redirect()->route('signed-out')->with('status', __('ui.identity.denied'));
        }

        try {
            $claims = $this->claimsFor((string) $request->query('code'));
        } catch (ConnectionException|RequestException $e) {
            // A bare 500 tells the person nothing. The SSO being unreachable is an
            // infrastructure fault, so it gets a page that says so, and the address
            // that failed lands in the log.
            Log::error('SSO is unreachable', [
                'base' => config('workreport.sso.internal_base'),
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('signed-out')->with('status', __('ui.identity.unreachable'));
        }

        $request->session()->forget('oidc.restarts');
        $request->session()->put(OidcActorResolver::SESSION_KEY, $claims);

        return redirect()->intended(route('board'));
    }

    /**
     * Send the browser back through the authorization endpoint.
     *
     * A browser that refuses the session cookie would otherwise bounce between the
     * two legs forever, so the third consecutive failure becomes a page that says
     * what to check.
     */
    private function restart(Request $request, bool $sessionHadState): RedirectResponse
    {
        $attempts = (int) $request->session()->get('oidc.restarts', 0) + 1;

        Log::warning('SSO callback did not match the session; restarting the flow', [
            'session_had_state' => $sessionHadState,
            'attempt' => $attempts,
        ]);

        if ($attempts > 2) {
            $request->session()->forget('oidc.restarts');

            abort(403, __('ui.identity.state_lost'));
        }

        $request->session()->put('oidc.restarts', $attempts);

        return redirect()->route('sso.redirect');
    }

    /**
     * Signing out locally only. The SSO keeps its own session and exposes a
     * POST-only logout, so a browser that comes back is authenticated silently —
     * the signed-out page says so instead of pretending otherwise.
     */
    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(OidcActorResolver::SESSION_KEY);
        $request->session()->regenerate();

        return redirect()->route('signed-out');
    }

    private function authorizeUrl(string $state): string
    {
        return config('workreport.sso.issuer').'/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('workreport.sso.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'scope' => config('workreport.sso.scope'),
            'state' => $state,
        ]);
    }

    /**
     * The SSO signs users in by username (its `users` table has no e-mail column
     * since 17c1222), so the identity document carries `preferred_username` and
     * `name`. Both are kept: the username is a stable handle, the name is what a
     * person is shown as.
     *
     * @return array{iss: string, sub: string, name: string, username: string}
     */
    private function claimsFor(string $code): array
    {
        // Server-to-server calls go to internal_base, never to the public issuer:
        // the SSO is on this host and the public address is NATed without hairpin
        // (docs/auth-seam.md). The browser keeps using the issuer.
        $base = (string) config('workreport.sso.internal_base');

        // Failures bubble up to callback(), which turns them into a page a person
        // can read instead of a 500.
        $token = Http::asForm()->acceptJson()->timeout(15)
            ->post($base.'/oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
                'client_id' => config('workreport.sso.client_id'),
                'client_secret' => config('workreport.sso.client_secret'),
            ])
            ->throw()
            ->json();

        $info = Http::withToken((string) ($token['access_token'] ?? ''))->acceptJson()->timeout(15)
            ->get($base.'/oauth/userinfo')
            ->throw()
            ->json();

        abort_unless(is_string($token['access_token'] ?? null), 502, 'The SSO returned no access token.');

        abort_unless(is_string($info['sub'] ?? null) && $info['sub'] !== '', 502, 'The SSO returned no subject.');

        return [
            'iss' => (string) ($info['iss'] ?? config('workreport.sso.issuer')),
            'sub' => (string) $info['sub'],
            'name' => (string) ($info['name'] ?? ''),
            'username' => (string) ($info['preferred_username'] ?? ''),
        ];
    }

    private function redirectUri(): string
    {
        return (string) (config('workreport.sso.redirect_uri') ?: url('/auth/sso/callback'));
    }
}
