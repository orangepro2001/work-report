<?php

namespace App\Contracts;

use App\Models\Member;

/**
 * Who is acting right now.
 *
 * The product has no login yet — the user is building an SSO application
 * separately and will integrate it later (docs/auth-seam.md). Every controller
 * asks this contract instead of `Auth::user()`, so switching to OIDC means
 * binding one different implementation in AppServiceProvider; no controller,
 * view or policy has to change.
 */
interface ActorResolver
{
    public function current(): ?Member;

    /**
     * @throws \RuntimeException when nothing identifies the actor
     */
    public function require(): Member;
}
