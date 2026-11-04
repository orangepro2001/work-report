# The identity seam — SSO (OIDC)

Sign-in is the authorization center at **<https://521985.xyz/sso>** (a separate
Laravel OAuth 2.0 / OIDC issuer, see its own `DESIGN.md`). There is no password in
this application: every page needs a session that carries OIDC claims, and the
claims are the only thing the application ever knows about the person.

## The flow

```
GET /report/board
      │  RequireActor: no claims in the session
      ▼
GET /report/auth/sso/redirect        → state (40 chars) into the session, then
      │                                302 to {issuer}/oauth/authorize
      ▼                               ?response_type=code&client_id=…
GET https://521985.xyz/sso/login      the SSO authenticates the person
      │                                 (its own session, its own forms)
      ▼
GET /report/auth/sso/callback?code=…&state=…
      │  state compared with hash_equals, then
      │  POST {internal_base}/oauth/token   (client_secret_post, no PKCE: confidential client)
      │  GET  {internal_base}/oauth/userinfo (bearer)
      ▼
session['oidc.claims'] = {iss, sub, name, username}   → 302 to the intended URL
```

Note the two bases: the **browser** is sent to the issuer, while the **server**
calls the SSO over the loopback (`SSO_INTERNAL_BASE`). This host's public address
is NATed without hairpin NAT, so `curl https://521985.xyz` fails *from the machine
that serves it*; a container that used the issuer for its own calls timed out and
the callback answered 500.

The SSO identifies people by **username** and keeps no e-mail address (its commit
`17c1222`), so `userinfo` returns `sub`, `name` and `preferred_username`. There is
also no self-registration anywhere in it: an administrator creates every account
with a display name, a username and a password, and people sign in with the last
two only.

`/report/auth/sso/redirect`, `/report/auth/sso/callback`, `/report/auth/signed-out`
and `/report/healthz` are the only public routes. Everything else sits inside
`Route::middleware(RequireActor::class)`.

## The resolver

`App\Contracts\ActorResolver` is still the only way to learn who is acting;
`OidcActorResolver` is the single implementation and `AppServiceProvider` is the
single binding. `grep -rn 'Auth::' app/` stays empty — the app does not use the
Laravel auth guards at all.

`OidcActorResolver::current()`:

1. reads `session('oidc.claims')`; no claims ⇒ `null` ⇒ the middleware redirects;
2. looks up `members` by `oidc_issuer` + `oidc_subject` (the pair the provider
   guarantees is stable — a display name is not);
3. on a first sign-in, enrols the person: the member `key` is derived once from
   `preferred_username` (the string they type to sign in), and a seeded row whose
   `key` already matches is **adopted** rather than duplicated, so their issue
   history follows the person.

`members.key` is never renumbered or recomputed: it is quoted in comments and the
workbook, exactly like issue keys.

## What is stored

| Table | Column | Meaning |
|---|---|---|
| `members` | `oidc_issuer`, `oidc_subject` | unique pair, `NULL` until the first sign-in |
| `members` | `key` | derived once from `preferred_username`; then never changes |
| `members` | `email` | optional: the SSO keeps none, so this stays empty unless filled by hand |
| `issue_activities` | `actor_id` | every mutation is attributed to the resolver's member |
| `issues`, `issue_comments` | `reporter_id`, `assignee_id`, `author_id` | already members |

No token is stored: the session keeps claims only. Access tokens live 7 days on the
SSO side, and the session (`SESSION_LIFETIME=720`, i.e. 12 h) dies long before one
would need refreshing — an expired session simply walks the flow again.

## Signing out

`POST /report/auth/logout` forgets the claims and regenerates the session. That is
**local only**: the SSO session stays open, and its logout is POST-only, so the
signed-out page says so instead of pretending the person is gone. A browser that
reopens the board is signed in silently.

## Configuration

| Key | Meaning |
|---|---|
| `SSO_ISSUER` | issuer + base URL (`https://521985.xyz/sso`); endpoints are `{issuer}/oauth/authorize`, `/token`, `/userinfo` |
| `SSO_CLIENT_ID` / `SSO_CLIENT_SECRET` | the confidential client registered on the SSO |
| `SSO_REDIRECT_URI` | leave **empty**: `url('/auth/sso/callback')` then answers with the hostname the request arrived on, which is what lets one deployment serve both `521985.xyz/report` and `egg.tail9f7f1f.ts.net/report` |
| `SSO_INTERNAL_BASE` | where the **server** reaches the SSO (`http://127.0.0.1:3550`). Split horizon: the browser uses `SSO_ISSUER`, the token exchange and `userinfo` use this. The public address is NATed without hairpin, so a container calling the issuer times out — that failure looked exactly like a 500 on the callback |

Both entries are registered on the SSO as exact redirect URIs — the SSO never
redirects to a wildcard. Adding a third hostname means registering it there first.

## Linking a member before the first sign-in

Enrolment is automatic, but pre-linking is better when the person already exists in
the board (the four seeded members carry the issue history). The link is the pair
the SSO sends — `iss` is its issuer and `sub` is the numeric user id as a string:

```sql
update members set oidc_issuer = 'https://521985.xyz/sso', oidc_subject = '4'
 where key = 'chae-gilyong';   -- SSO username yzy
```

Two short-cuts follow from the same rule:

- **Name the SSO username after the member key** (`lee-sungmin`, `jung-ungyo`) and
the resolver adopts that seeded row on first sign-in without any SQL.
- **Nothing is created twice.** A member whose `oidc_subject` is already set is
found, never rewritten; a person with neither hint gets a fresh row whose `key` is
their username.

## Constraint to preserve

`ActorResolver` must stay the **only** way the application learns who is acting. If
a controller ever calls `Auth::user()` or reads `session('oidc.claims')` directly,
the seam is broken. Tests sign in through the seam too (`Tests\TestCase::signInAs`),
never by stubbing a guard.
