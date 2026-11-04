# Deployment

Podman + Quadlet, one container, `/report` behind `tailscale serve`. Everything
here is what actually runs on the AI host.

## Topology

```
internet / tailnet
      │  https://egg.tail9f7f1f.ts.net/report
      ▼
tailscale serve --set-path /report   ── *strips* the prefix, forwards Host + X-Forwarded-*
      │  http://127.0.0.1:3560
      ▼
work-report container (host network, rootfs read-only, user nobody)
  nginx :3560 → php-fpm (unix socket) → PostgreSQL 127.0.0.1:5433
```

Two Quadlet units, both on this host:

| Unit | Container | Port |
|---|---|---|
| `work-report-db` | `work-report-db` (postgres:18-alpine) | `127.0.0.1:5433`, named volume `work-report-db-data` |
| `work-report` | `work-report` (this image) | `0.0.0.0:3560` |

The database runs **locally** since 2026-11-05. It used to live on the AI host over
the tailnet; the move cut a board request from 0.45 s to 0.09 s. The tailnet path was
direct (not relayed) but cost ~250 ms per query round trip with occasional
multi-second stalls — measured, see docs/perf.md.

Port **3560**, not 3500: `daily-work-web` already owns 3500/`/report` on another
host, and 3560 keeps this service unambiguous in `ss -ltn` output.

## Why the app binds 0.0.0.0

The requirement is "listen on all interfaces, no IP restriction". `nginx.conf`
therefore has `listen 0.0.0.0:3560`. `tailscale serve` still points at
`127.0.0.1:3560`, so the tailnet is the ordinary path; the wildcard bind means the
service is also reachable directly, e.g. from another machine on the wired LAN.

`/report/...` is accepted *and* `/...` is accepted: the reverse proxy strips the
prefix, but nginx also rewrites `/report/...` for direct access (`location ~*
^/report/(assets/.+)$` plus Laravel's own `WR_BASE_PATH` routing). This is verified
by requesting both forms.

## Public entry point: 521985.xyz/report

The tailnet URL is not the only door. The rootful Caddy on this host
(`/etc/daily-work-web/Caddyfile`, container `daily-work-web-caddy`, public
210.207.108.170 → NIC 172.22.0.29) terminates TLS for `521985.xyz` and exposes
`/report` next to `/sso` and `/teach`:

```caddy
import prefix report 127.0.0.1:3560
```

`(prefix)` strips the prefix before forwarding — the same contract `tailscale serve
--set-path /report` uses, and the reason one build serves both doors: the app emits
`/report/...` URLs (`WR_BASE_PATH=/report`) and never receives the prefix itself.
A direct request to the container with the prefix left on (`127.0.0.1:3560/report/board`)
is a 404, so any new proxy in front of it must strip too.

Two things to know:

- **The board is world-readable there.** There is no login (docs/auth-seam.md), so
  anyone who guesses `/report` can read and edit it. That is a decision, not an
  oversight; adding Basic auth to the vhost is a two-line change.
- The vhost is **not in this repository**, so nothing in CI can catch a regression.
  The `nginx` vhost of the same name (`/etc/nginx/sites-available/egg-521985.xyz.conf`)
  is dead: nginx is inactive and its `127.0.0.1:3000` upstream no longer exists.

## Database

PostgreSQL 18 runs on this host as the `work-report-db` Quadlet unit, published on
**127.0.0.1:5433** only, with its cluster in the named volume
`work-report-db-data`. Local sockets trust (`--auth-local=trust`, so the container's
own health check works); network connections require scram-sha-256. The application
connects over the loopback with `sslmode=prefer`.

The first boot initialises the cluster, creates the `work_report` database and gives
the role a password from `~/.config/work-report/db.env`. Extensions:

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;    -- JQL-lite search indexes
CREATE EXTENSION IF NOT EXISTS unaccent;
```

The entrypoint runs `CREATE EXTENSION IF NOT EXISTS pg_trgm` on every boot, so a
restored dump never has to remember it.

### Local is the only default

Every checked-in file points at `127.0.0.1:5433`: `.env.example`,
`deploy/env/work-report.env.example` and the live `~/.config/work-report/env`. The
AI-host cluster survives as the rollback point below and nothing else — it is never
picked up implicitly, and the commented block at the bottom of the env example is
the only place that mentions it.

### Offsite mirror (the standby copy)

`deploy/scripts/backup-offsite.sh` dumps this host's database and replaces
`~/backups/work-report/work-report.sql.gz` on the AI host. One file, overwritten
each run — the AI host is a standby, not an archive; generations stay here (see
`deploy/scripts/backup.sh`). Scheduled by `work-report-backup-offsite.timer`
(daily 03:40, `Persistent=true`, up to 10 min jitter), installed by `install.sh`.

The dump only replaces the previous one after `gzip -t` passes on both ends and
the byte counts match, so a half-written or truncated file never becomes "the
backup". The last successful dump also stays at
`~/.local/state/work-report/offsite/work-report.sql.gz` — an unreachable AI host
therefore costs freshness, never the copy. Restore:

```sh
zcat ~/.local/state/work-report/offsite/work-report.sql.gz \
  | podman exec -i work-report-db psql -U work_report -d work_report
```

### Rollback point

The previous instance (`pxspec-pg` on the AI host, database `work_report`) still
holds a copy of the data as of the migration, and the mirror above keeps a fresher
dump beside it (`/home/tony/backups/work-report/`). To go back to the migrated
state, point `DB_HOST`/`DB_PORT` in `~/.config/work-report/env` at
`100.101.146.117:5432`, set `DB_SSLMODE=require`, and restart the service. Nothing
in the schema differs. The mirror host is `AI` in `~/.ssh/config`; its dumps are
plain SQL (`pg_dump` without `-F`), so any of them loads with `psql` alone.

### Test database

`work_report_test` lives in a separate local container (`wr-pg-test`, port 5442) so
a test run can drop and recreate its schema without touching production. See the
README for the two commands that create it.

## First install

```sh
git clone https://github.com/orangepro2001/work-report && cd work-report
mkdir -p ~/.config/work-report && chmod 700 ~/.config/work-report

# application settings + database password (must match db.env below)
cp deploy/env/work-report.env.example ~/.config/work-report/env
cp deploy/env/work-report-db.env.example ~/.config/work-report/db.env
chmod 600 ~/.config/work-report/env ~/.config/work-report/db.env
# fill in APP_KEY (php artisan key:generate --show) in env
# fill in POSTGRES_PASSWORD in db.env and the same value in DB_PASSWORD

deploy/scripts/install.sh
```

`install.sh` creates the data volume, renders both Quadlet units (database and
application), builds the image, tags it `:current`, starts `work-report-db` then
`work-report`, waits for `/healthz`, and enables the daily offsite mirror.

Then expose it:

```sh
tailscale serve --bg --set-path /report http://127.0.0.1:3560
tailscale serve status
```

## Upgrade

```sh
git pull
deploy/scripts/upgrade.sh          # build → tag :previous → restart → health check → rollback on failure
```

`upgrade.sh` keeps `:previous` and rolls back automatically if `/healthz` does not
come up within 120 s. The entrypoint runs `migrate --force` on every boot, so a
schema change is applied before nginx starts taking traffic.

## Operations

| Task | Command |
|---|---|
| Logs | `journalctl --user -u work-report -f` |
| Sign-in failures | `journalctl --user -u work-report -n 100 \| grep -i sso` (then check the SSO's own log) |
| Database logs | `journalctl --user -u work-report-db -f` |
| Health | `curl -fsS http://127.0.0.1:3560/healthz` |
| Backup | `deploy/scripts/backup.sh [keep]` → `~/backups/work-report/*.sql.gz` |
| Offsite mirror | `deploy/scripts/backup-offsite.sh` → `AI:~/backups/work-report/work-report.sql.gz` |
| Mirror status | `systemctl --user list-timers work-report-backup-offsite.timer` / `journalctl --user -u work-report-backup-offsite -n 20` |
| Dump by hand | `podman exec work-report-db pg_dump -U work_report work_report \| gzip > dump.sql.gz` |
| Restart | `systemctl --user restart work-report` |
| Remove | `deploy/scripts/uninstall.sh` |
| Second instance | `WRP_NAME=work-report-test WRP_PORT=3561 deploy/scripts/install.sh` |

## Identity

Nobody reaches the board anonymously: every route except `healthz`, the three
`auth/sso` routes and `auth/signed-out` requires OIDC claims (docs/auth-seam.md).
Sign-in and the client registration both live on the SSO at
<https://521985.xyz/sso>; the app keeps no users, passwords or tokens, only a
`members` row per person with `oidc_issuer`/`oidc_subject` filled in on first
sign-in.

`SSO_CLIENT_ID`/`SSO_CLIENT_SECRET` come from the SSO's admin console
(`/sso/admin/clients`). Register **both** public hostnames as redirect URIs:

```
https://521985.xyz/report/auth/sso/callback
https://egg.tail9f7f1f.ts.net/report/auth/sso/callback
```

## Container hardening

| Setting | Value | Reason |
|---|---|---|
| `ReadOnly=true` | rootfs read-only | Laravel writes only to tmpfs mounts |
| `Tmpfs` | `/tmp`, storage subdirs, `bootstrap/cache` | writable where the framework needs it |
| `User` | `nobody` | no root in the container at all |
| `NoNewPrivileges` | true | blocks setuid escalation |
| `DropCapability=ALL` | all dropped | nothing needs a capability |
| `HealthOnFailure=kill` | kill | a broken container restarts instead of serving errors |

`php-fpm` listens on a **unix socket**, not `127.0.0.1:9000`: with host networking
two instances would otherwise fight over the loopback port.

## Verifying the exported file

```sh
curl -fsS -o /tmp/r.xlsx 'http://127.0.0.1:3560/report/export.xlsx?date=2026-11-04'
python3 tools/inspect_xlsx.py /tmp/r.xlsx > /tmp/r.json
python3 tools/inspect_xlsx.py docs/source-rev02.xlsx > /tmp/g.json
# compare sheets/geometry/print in the two JSON dumps, or simply run the test suite:
composer test -- --filter=ExcelExportGoldenTest
```
