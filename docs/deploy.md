# Deployment

Podman + Quadlet, one container, `/report` behind `tailscale serve`. Everything
here is what actually runs on the AI host.

## Topology

```
internet / tailnet
      │  https://tony-ai-pc-1.tail9f7f1f.ts.net/report
      ▼
tailscale serve  ── strips nothing, forwards Host + X-Forwarded-*
      │  http://127.0.0.1:3560
      ▼
work-report container (host network, rootfs read-only, user nobody)
  nginx :3560 → php-fpm (unix socket) → PostgreSQL 100.101.146.117:5432 (TLS)
```

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

## Database

Dedicated role and database on the existing PostgreSQL 18 instance
(`pxspec-pg`, host networking, `listen_addresses='*'`, TLS on).

```sql
CREATE ROLE work_report LOGIN PASSWORD '…';
CREATE DATABASE work_report OWNER work_report ENCODING 'UTF8' TEMPLATE template0;
\c work_report
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
```

`pg_hba.conf` already contains `hostssl all all 100.64.0.0/10 scram-sha-256`
(tailnet) and other scoped rules, so nothing had to be changed. The application
connects with `sslmode=require` and no superuser.

A second database, `work_report_test`, is used by the test suite so development
data is never touched.

## First install

```sh
git clone https://github.com/orangepro2001/work-report && cd work-report
mkdir -p ~/.config/work-report && chmod 700 ~/.config/work-report
cp deploy/env/work-report.env.example ~/.config/work-report/env
chmod 600 ~/.config/work-report/env
# fill in APP_KEY (php artisan key:generate --show) and DB_PASSWORD
deploy/scripts/install.sh
```

`install.sh` builds the image, tags it `:current`, renders the Quadlet unit from
`deploy/quadlet/work-report.container`, starts the service and waits for
`/healthz`.

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
| Health | `curl -fsS http://127.0.0.1:3560/healthz` |
| Backup | `deploy/scripts/backup.sh [keep]` → `~/backups/work-report/*.sql.gz` |
| Restart | `systemctl --user restart work-report` |
| Remove | `deploy/scripts/uninstall.sh` |
| Second instance | `WRP_NAME=work-report-test WRP_PORT=3561 deploy/scripts/install.sh` |

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
