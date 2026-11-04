<?php

namespace Tests;

use App\Models\Member;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The board search indexes need pg_trgm; CREATE EXTENSION is idempotent and
        // cheap, so ensure it here rather than documenting a manual step.
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (\Throwable) {
            // A role without the privilege still runs every test except the trigram
            // index creation, which the migration tolerates.
        }
    }

    /**
     * Tests talk to the application directly, bypassing nginx and the reverse proxy.
     *
     * In production `tailscale serve --set-path /report` strips the mount prefix
     * before forwarding, so PHP genuinely receives bare paths; ForceMountPath only
     * puts the prefix back into *generated* URLs. The default implementation builds
     * the request URL with url(), which would therefore carry /report and 404. Bare
     * paths are exactly what the application sees in production.
     */
    protected function prepareUrlForRequest($uri)
    {
        return '/'.ltrim($uri, '/');
    }

    /**
     * Sign a member in the way production does: OIDC claims in the session, matched
     * to the member by issuer+subject (docs/auth-seam.md). Nothing is stubbed, so
     * the tests exercise the resolver the app actually binds.
     */
    protected function signInAs(?Member $member = null): Member
    {
        $member ??= Member::query()->where('active', true)->orderBy('position')->firstOrFail();

        $member->forceFill(['oidc_issuer' => 'https://sso.test', 'oidc_subject' => 'sub-'.$member->id])->save();

        // Exactly the claims the SSO's userinfo returns: no e-mail, a username and
        // a display name (docs/auth-seam.md).
        $this->withSession(['oidc.claims' => [
            'iss' => 'https://sso.test',
            'sub' => 'sub-'.$member->id,
            'name' => $member->name,
            'username' => $member->key,
        ]]);

        return $member;
    }
}
