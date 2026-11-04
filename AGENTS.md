# AGENTS.md — work-report

Working notes for anyone (human or agent) changing this repository.

## What this is

A Jira-shaped work tracker whose **output is a specific Excel workbook**
(`docs/source-rev02.xlsx`). The document is the contract; the board is how data
gets into it. Read these before touching anything:

| File | Why |
|---|---|
| `docs/excel-schema.md` | The workbook, extracted cell by cell. The exporter must satisfy it. |
| `docs/architecture.md` | Why the exporter is four classes and where the layers are. |
| `docs/auth-seam.md` | The one place identity is decided. |
| `docs/deploy.md` | What actually runs in production. |
| `docs/perf.md` | Measured numbers and the known network bottleneck. |

## Non-negotiables

1. **`ActorResolver` is the only way to learn who is acting.**
   `grep -rn 'Auth::' app/` must stay empty. There is no login yet, on purpose.
2. **Never renumber an issue key.** Numbers are allocated once
   (`App\Services\IssueKeyGenerator`) and survive deletions. The workbook's
   `COUNTIFS` formula renumbers; that is exactly what we do not do.
3. **Formulas stay formulas in the export.** `B`, `C`, `O` and the whole summary
   block are written as formula strings so Excel recalculates after an edit.
4. **Do not add a build step.** No Vite, no Tailwind, no npm. The CSS is
   hand-written in `public/assets/app.css`, Alpine is vendored. This is why the
   image is small and the deploy is a copy.
5. **Palette literals live in `ReportSheetWriter`.** If a colour is needed
   somewhere else, reference the constant, do not retype the hex.

## The Excel exporter, in one paragraph

`ReportExporter` picks the rows → `WorkbookWriter` assembles three sheets in order
(업무일보, 담당자 요약, 목록) and defines the seven named ranges → `ReportSheetWriter`
writes sheet 업무일보 (geometry, header, summary, detail, conditional formatting,
validations, print setup) → `XlsxPackager` fixes two things PhpSpreadsheet gets
wrong for this file (`_xlfn.AVERAGEIFS` prefix, `bgColor` on solid fills) by
rewriting the produced archive. Every step has a test.

## Commands

```sh
# everything runs in containers; the host has no PHP
podman build -f /tmp/dev.Dockerfile -t localhost/wr-dev .        # once
podman build --format docker -f deploy/Containerfile -t localhost/work-report:current .

composer test        # 28 tests, needs a LOCAL PostgreSQL (see README)
composer lint        # pint --test
python3 tools/inspect_xlsx.py docs/source-rev02.xlsx > /tmp/dump.json
```

Deploy: `bash deploy/scripts/upgrade.sh` (builds, health-checks, rolls back on
failure). Production: <https://egg.tail9f7f1f.ts.net/report>. Two Quadlet units run
on that host: `work-report` (the app, 0.0.0.0:3560) and `work-report-db`
(PostgreSQL, 127.0.0.1:5433). The database's standby copy is one dump file mirrored
to the `AI` host every night (`deploy/scripts/backup-offsite.sh`, timer
`work-report-backup-offsite`); the connection is push-only, from here.

## Gotchas that cost time already

- **The offsite mirror replaces, it does not accumulate.** `work-report.sql.gz` on
  the AI host is overwritten daily; if you need a point two weeks back, that lives
  in `~/backups/work-report/` here via `backup.sh`. The mirror refuses to overwrite
  unless `gzip -t` passes on both ends and the sizes match — a red
  `work-report-backup-offsite` unit means the old copy is still good.

- **`tailscale serve --set-path /report` strips the prefix.** Routes are
  registered at the root; `ForceMountPath` puts the prefix back into generated
  URLs. nginx rewrites `/report/*` away for direct access. Do not "fix" this by
  prefixing the route group.
- **Tests need their own local database** (`wr-pg-test` on 5442). An interrupted
  run used to leave a transaction holding table locks and wedge the next one;
  `idle_in_transaction_session_timeout` is now set on every connection.
- **Never run two suites at once.** Killing the *shell* leaves the test container
  alive, and two suites colliding on `migrate:fresh` deadlock. `podman ps` first.
- **`SET` takes no bind parameters** in PostgreSQL, and a unit form like `20s`
  must be a string literal.
- **Persistent PDO connections and `RefreshDatabase` do not mix** — the test
  environment sets `DB_PERSISTENT=false`.
- **Migration order is by filename.** A table-altering migration numbered before
  the one that creates the table will fail on a fresh database.

## Style

- Korean is the source language for UI copy (`lang/ko`); translate from meaning.
- Comments explain *why*, and reference the document or measurement that
  justifies the choice. No comment that restates the code.
- `./vendor/bin/pint` before committing.
