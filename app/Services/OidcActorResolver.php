<?php

namespace App\Services;

use App\Contracts\ActorResolver;
use App\Models\Member;
use Illuminate\Support\Str;

/**
 * Who is acting, according to the authorization center (docs/auth-seam.md).
 *
 * The OIDC dance ends in a redirect, so the callback stores the claims in the
 * session and every later request reads them here. No controller, view or policy
 * ever sees a token or an `Auth::` call: this stays the only way to learn identity.
 */
final class OidcActorResolver implements ActorResolver
{
    public const SESSION_KEY = 'oidc.claims';

    public function current(): ?Member
    {
        $claims = session(self::SESSION_KEY);

        if (! is_array($claims) || blank($claims['sub'] ?? null) || blank($claims['iss'] ?? null)) {
            return null;
        }

        return Member::query()
            ->where('oidc_issuer', $claims['iss'])
            ->where('oidc_subject', $claims['sub'])
            ->first()
            ?? $this->enrol($claims);
    }

    public function require(): Member
    {
        return $this->current()
            ?? throw new \RuntimeException('Nobody is signed in: the SSO session is missing its claims.');
    }

    /**
     * First sign-in. A member row is created from the claims, keyed by the pair the
     * provider guarantees is stable rather than by a display name that can change.
     *
     * The SSO identifies people by username and keeps no e-mail address, so the
     * member key comes from `preferred_username` — the same string the person types
     * to sign in, which makes `이슈` history readable ("yzy", not "sub-4").
     *
     * @param  array<string, mixed>  $claims
     */
    private function enrol(array $claims): Member
    {
        $username = mb_strtolower(trim((string) ($claims['username'] ?? '')));
        $email = mb_strtolower(trim((string) ($claims['email'] ?? '')));
        $name = trim((string) ($claims['name'] ?? ''));
        $base = Str::slug($username !== '' ? $username : ($email !== '' ? Str::before($email, '@') : (string) $claims['sub']));
        $link = ['oidc_issuer' => $claims['iss'], 'oidc_subject' => $claims['sub']];

        // A row that already claims this handle (a seeded member whose e-mail or
        // username was filled in by hand) is adopted instead of duplicated, so the
        // person's history follows the person.
        $existing = $base !== '' ? Member::where('key', $base)->first() : null;
        $existing ??= $email !== '' ? Member::where('email', $email)->first() : null;

        if ($existing !== null && $existing->oidc_subject === null) {
            $existing->forceFill($link)->save();

            return $existing;
        }

        return Member::create($link + [
            'key' => $this->uniqueKey($base !== '' ? $base : 'member'),
            'name' => $name !== '' ? $name : ($username !== '' ? $username : $base),
            'email' => $email !== '' ? $email : null,
            'department' => config('workreport.department'),
            'color' => $this->nextColor(),
            'position' => (int) Member::max('position') + 1,
            'active' => true,
        ]);
    }

    /** `key` is quoted in comments and printed in the workbook: it never changes. */
    private function uniqueKey(string $base): string
    {
        $key = $base;

        for ($i = 2; Member::where('key', $key)->exists(); $i++) {
            $key = $base.'-'.$i;
        }

        return $key;
    }

    /** The palette the seeder established, cycled so avatars stay distinguishable. */
    private function nextColor(): string
    {
        $palette = ['#2F5597', '#C65911', '#375623', '#7030A0', '#7F7F7F', '#1F7A7A'];

        return $palette[Member::count() % count($palette)];
    }
}
