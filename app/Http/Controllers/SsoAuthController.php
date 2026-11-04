<?php

namespace App\Http\Controllers;

use App\Services\OidcActorResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
        // the flow; anything else can be replayed by a third party.
        abort_unless(
            $state !== '' && hash_equals($state, (string) $request->query('state')),
            403,
            'The sign-in reply did not belong to this session.',
        );

        if ($request->query('error') !== null) {
            return redirect()->route('board')->with('status', __('ui.identity.denied'));
        }

        $claims = $this->claimsFor((string) $request->query('code'));

        $request->session()->put(OidcActorResolver::SESSION_KEY, $claims);

        return redirect()->intended(route('board'));
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
        $issuer = config('workreport.sso.issuer');

        $token = Http::asForm()->acceptJson()->timeout(15)
            ->post($issuer.'/oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
                'client_id' => config('workreport.sso.client_id'),
                'client_secret' => config('workreport.sso.client_secret'),
            ])
            ->throw()
            ->json();

        abort_unless(is_string($token['access_token'] ?? null), 502, 'The SSO returned no access token.');

        $info = Http::withToken($token['access_token'])->acceptJson()->timeout(15)
            ->get($issuer.'/oauth/userinfo')
            ->throw()
            ->json();

        abort_unless(is_string($info['sub'] ?? null) && $info['sub'] !== '', 502, 'The SSO returned no subject.');

        return [
            'iss' => (string) ($info['iss'] ?? $issuer),
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
