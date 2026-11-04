# The identity seam (SSO integration point)

## Current state

There is **no login**. This is intentional: a separate SSO/OIDC application is being
built and will be integrated later. Everything that needs to know "who is acting"
asks one contract, so the integration is a one-class change.

```php
namespace App\Contracts;

interface ActorResolver
{
    public function current(): ?Member;
    public function require(): Member;
}
```

Bound once, in `App\Providers\AppServiceProvider::register()`:

```php
$this->app->singleton(ActorResolver::class, fn () => new SessionActorResolver);
```

### The current implementation

`SessionActorResolver` resolves the actor in this order:

1. `session('wr.actor_id')` — written by the member switcher in the top bar
   (`POST /report/actor`, `MemberSwitchController`).
2. `config('workreport.current_member')` — the `WR_CURRENT_MEMBER` env value.
3. The first active member.

The switcher is labelled in the UI as temporary (`ui.actor.hint`: "SSO 연동 전까지
사용하는 임시 전환입니다").

## What is already in place for SSO

The schema was designed for it from the start:

| Table | Column | SSO meaning |
|---|---|---|
| `members` | `key` (unique) | stable handle; becomes the OIDC `sub` link |
| `members` | `email` (unique) | matches the OIDC `email` claim |
| `issue_activities` | `actor_id` | every mutation is already attributed |
| `issues` | `reporter_id`, `assignee_id` | already `members` foreign keys |
| `issue_comments` | `author_id` | already `members` |

Nothing is stored as a free-text name, so no data migration is needed.

## Integration checklist

1. **Migration** — add `oidc_subject`, `oidc_issuer` to `members`
   (nullable, unique together). Members keep existing rows and keys.
2. **Resolver** — implement `OidcActorResolver`:

   ```php
   final class OidcActorResolver implements ActorResolver
   {
       public function current(): ?Member
       {
           $claims = session('oidc.claims');
           if (! $claims) return null;

           return Member::firstOrCreate(
               ['oidc_issuer' => $claims['iss'], 'oidc_subject' => $claims['sub']],
               ['key' => Str::slug($claims['email']), 'name' => $claims['name'],
                'email' => $claims['email'], 'department' => config('workreport.department')],
           );
       }

       public function require(): Member
       {
           return $this->current() ?? throw new AuthenticationException;
       }
   }
   ```

3. **Binding** — replace the singleton in `AppServiceProvider`. That is the whole
   change; no controller, view, policy or test references the old class.
4. **Routes** — add the OIDC login/callback routes, and wrap the route group in
   `bootstrap/app.php` with `->middleware('auth')` once login is mandatory. The
   `/report/healthz` route must stay public for the container health check.
5. **Remove the switcher** — delete the `actor.switch` route, the
   `partials/actor.blade.php` switcher and `SessionActorResolver`. `WR_CURRENT_MEMBER`
   stops being read.
6. **Cookie scope** — nothing to do: `SESSION_PATH=/report` is already correct for
   the proxy mount point.

## Constraint to preserve

`ActorResolver` must stay the **only** way the application learns who is acting.
If a controller ever calls `Auth::user()` directly, the integration point is broken.
`grep -rn 'Auth::' app/` should stay empty.
