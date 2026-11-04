# Performance

Measured on the production host (egg), the container built from `deploy/Containerfile`.
The dates matter: the numbers in the last section were taken while PostgreSQL was on
the AI host and reached over the tailnet; the database now runs on this host, and the
difference is the whole story of this document.

## Method

```sh
for i in 1 2 3 4 5; do curl -s -o /dev/null -w '%{time_total}\n' http://127.0.0.1:3560/report/board; done
```

Request time as reported by `curl` against the container directly (nginx → php-fpm),
i.e. without the `tailscale serve` hop.

## Results

Current (database local, loopback):

| Endpoint | Steady state |
|---|---|
| `/report/board` (26 cards, 4 columns) | **0.09 – 0.12 s** |
| `/report/list` | 0.10 – 0.12 s |
| `/report/daily` | 0.09 – 0.11 s |
| `/report/export.xlsx` | **0.29 – 0.31 s** |

For comparison, with the database on the AI host over the tailnet the same endpoints
measured 0.44–0.50 s and 1.30–1.35 s, and before any optimisation 1.9 s / 0.6 s /
0.6 s / 1.4 s. Moving the cluster onto this host is the single largest improvement;
everything else below is a smaller multiplier on top of it.

## What the profile said

`DB::enableQueryLog()` inside the application, not the browser:

```
query+hydrate: 355 ms, rows=25, queries=5
  129 ms  select * from "issues" where deleted_at is null order by board_rank
   23 ms  select id, abbr, name, color from "projects" ...
  122 ms  select id, name, color from "members" ...
   21 ms  select ... from "members" inner join "issue_assignees" ...
   18 ms  select id, name from "members" ...
view render: 188 ms, 21224 bytes
```

Two findings:

1. **Connection setup dominated.** A fresh PDO connection to the AI host costs
   ~110 ms (TCP + TLS to the tailnet peer), and Laravel opened one per request.
   `PDO::ATTR_PERSISTENT` on php-fpm's long-lived workers removed that from every
   request after the first. This is safe here because php-fpm is `pm = static`
   with a fixed worker count, so persistent connections cannot pile up.
2. **Rendering, not SQL, was the second cost.** 188 ms for ~21 KB of HTML is
   Blade compiling 25 cards × 4 column templates. The view cache is pre-built at
   boot (`view:cache`), so this is pure PHP execution; the remaining lever would be
   fragment caching, which is not worth the invalidation complexity at this size.

## Changes and their effect

| Change | Effect |
|---|---|
| `PDO::ATTR_PERSISTENT` | −110 ms per request after the first |
| `issues(status_key, board_rank)` index | removes the sort of the whole table on the board |
| GIN `pg_trgm` on `summary`, `note` | turns search into an index scan |
| Drop the reporter join outside the list lens | −20 ms on board and daily |
| OPcache `validate_timestamps=0`, tracing JIT | no per-request stat calls; the exporter is call-heavy |
| `view:cache` / `route:cache` / `config:cache` at boot | no runtime compilation |
| Sessions and cache in PostgreSQL | rootfs stays read-only; one dependency fewer |
| Static assets with a content hash + 30-day cache | assets downloaded once per deploy |

## Export: where the time went

A 25-row export measured end to end, with the database already local:

```
queries + models:      44 ms
sheet writing:        965 ms   <- PhpSpreadsheet style calls
xlsx write + repair:   45 ms
```

PhpSpreadsheet's `getStyle()` copies a `Style` object for **every cell in the range**
it is handed. The detail block was styled cell by cell (61 rows × 13 columns, ~800
`getStyle()` calls), and `setConditionalStyles()` re-copied the whole 732-cell
`B16:M76` array once per rule. Both were rewritten to work per property:

| Before | After |
|---|---|
| per cell, inside a 61-row loop | one `getStyle()` per column range |
| `getStyle`/`setConditionalStyles` per rule | rules collected, applied once per range |

Sheet writing: **965 ms → 225 ms**. Full export: **1.05 s → 0.36 s** of work.

## Known bottleneck: packet loss on the tailnet path (resolved)

`ping 100.101.146.117` from the application host reports **~35 % packet loss** with
6–8 ms RTT, and `tailscale netcheck` shows the path is being relayed through the
Tokyo DERP rather than a direct connection. Every extra round trip therefore costs
far more than its nominal latency, which is why a page with five queries takes
0.45 s rather than 0.05 s.

This was a network property, not application time: a single `psql` round trip from
the same host measured 5–9 ms once established, but a page needs five of them.

**Resolved by taking option 2**: the cluster now runs on this host as the
`work-report-db` Quadlet unit and the application connects over the loopback. No
code changed — `DB_HOST`/`DB_PORT`/`DB_SSLMODE` in `~/.config/work-report/env`, and
the board went from 0.45 s to 0.09 s. The remote instance still holds a copy as a
rollback point (docs/deploy.md).

## Export cost

`ReportExporter::build()` + `XlsxPackager::save()` for a 25-row report:

```
build (queries + models): as measured above
write + XML repair:       167 ms   (PhpSpreadsheet serialisation of 3 sheets)
```

The packaging step is the expensive half and is dominated by PhpSpreadsheet
building the shared string table and styles; zipping 20 KB is negligible. Export is
a deliberate user action, so 1.3 s end to end is acceptable and needs no queue. If
the report ever grows past the workbook's 61 rows, the fix is to write with
`Xlsx` streaming rather than to background the job.
