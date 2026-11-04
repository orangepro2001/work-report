<?php

namespace App\Services;

use App\Contracts\ActorResolver;
use App\Models\Member;

/**
 * Development / single-identity actor.
 *
 * The active member is the one remembered in the session (the member switcher in
 * the top bar writes it), falling back to WR_CURRENT_MEMBER from the environment
 * and finally to the first active member. This is deliberately the only class that
 * knows how identity is established today.
 */
class SessionActorResolver implements ActorResolver
{
    public function __construct(private readonly string $sessionKey = 'wr.actor_id') {}

    public function current(): ?Member
    {
        $id = session($this->sessionKey);

        if ($id && ($member = Member::find($id))) {
            return $member;
        }

        $fallback = trim((string) config('workreport.current_member'));

        if ($fallback !== '' && ($member = Member::where('name', $fallback)->first())) {
            return $member;
        }

        return Member::active()->first();
    }

    public function require(): Member
    {
        return $this->current()
            ?? throw new \RuntimeException('No member is available: seed the members table first.');
    }

    /** Used by the member switcher only. */
    public function switchTo(int $memberId): void
    {
        session([$this->sessionKey => $memberId]);
    }
}
