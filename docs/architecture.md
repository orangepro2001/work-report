# Architecture

## Shape

One Laravel application, one container, one PostgreSQL database. No queue worker,
no Redis, no asset build. The whole thing is a handful of PHP files, Blade
templates, hand-written CSS and a vendored Alpine.js — deliberately small, because
the domain is small and the constraint that matters is *fidelity to the workbook*.

```
browser ── tailscale serve (TLS, /report) ──▶ nginx :3560 ──▶ php-fpm ──▶ PostgreSQL (tailnet)
```

## Layers

| Layer | Location | Rule |
|---|---|---|
| Domain vocabulary | `config/workreport.php` | Statuses, priorities, buckets, note presets, Excel geometry. Single source for UI, exporter and seeder. |
| Persistence | `app/Models`, `database/migrations` | Jira semantics: `issues` with per-project numbering, `issue_assignees` for 공동 담당, append-only `issue_activities`. |
| Identity | `app/Contracts/ActorResolver` | Nothing else may ask "who is this"; see auth-seam.md. |
| Services | `app/Services` | Key generation, Excel writing. Stateless, testable, no HTTP knowledge. |
| HTTP | `app/Http/Controllers` | Thin: validate, call a service, render or stream. |
| View | `resources/views` | Blade + Alpine. Interaction lives in the template that owns it, not in a bundle. |

## Why the exporter is four classes

The requirement is "reproduce this document exactly", and an exact document has
four independent concerns:

1. **Geometry** — column widths, row heights, merges, print area, repeating title row.
2. **Content** — which database column lands in which cell.
3. **Presentation** — fonts, fills, borders, number formats, conditional rules.
4. **Package** — the two XML details PhpSpreadsheet gets subtly wrong for this file.

`ReportSheetWriter` owns 1-3 for sheet 업무일보, `WorkbookWriter` owns sheet order
and the seven defined names, `ReportExporter` owns filtering and the filename,
`XlsxPackager` owns the archive rewrite. The golden test can then fail on one of
them specifically instead of "the export is wrong".

## Data flow of a daily report

```
기간/구분/담당자 필터
        │
        ▼
Issue::forBoard()->orderBy(project.position)->orderBy(issue_no)   ← one query + 3 eager loads
        │
        ├── 일보 뷰      : same collection → Blade grid → on-screen sheet
        └── ExportExporter: same collection → PhpSpreadsheet → .xlsx
```

Both paths run the *same* query, so the screen and the file cannot disagree. The
screen additionally computes the summary block in PHP (`DailyReportController::stats`)
because a browser cannot evaluate Excel formulas; the exported file keeps the
`COUNTIFS`/`AVERAGEIFS` formulas live so a human editing a cell sees the numbers move.

## Performance choices

- Board: `issues(project_id, status_key, board_rank)` covers the column query
  exactly; `with()` eager loads assignee, co-assignees and project — three queries
  for the whole board regardless of card count.
- Dropdown validations and conditional formatting are written once per *range*, not
  per cell.
- OPcache with `validate_timestamps=0` and tracing JIT (the exporter is call-heavy).
- Session and cache in PostgreSQL (same database) so the rootfs stays read-only.
- Static assets carry a content hash in the query string and a 30-day cache header.
- No pagination on the board (a team board is tens of cards); the list and backlog
  views are equally bounded, and the export caps at the workbook's 61 slots.

## Deliberate omissions

| Not done | Why |
|---|---|
| Login / registration | The user is building SSO separately; the seam exists, nothing else does. See auth-seam.md. |
| Real-time websockets | A board with four users does not need them; drag & drop is optimistic and reconciles from the response. |
| Attachments UI | `attachments` table exists; the monthly report format has no place for files, so the UI waits for a real need. |
| Multi-tenancy | One department, one database. |
| Queues | Export is ~20 ms for a daily report; a job would add latency, not remove it. |
