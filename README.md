# 업무일보 — work-report

A Jira-shaped collaborative work tracker that exports **the team's existing Excel
daily report, cell for cell**. The workbook everyone already uses stays the output
format; the input becomes a board, a list, a backlog and an issue drawer that
several people can work in at the same time.

- **Korean by default.** The UI copy is written the way the team speaks, not
  translated from English. `en` and `ja` are available.
- **The spreadsheet is a contract, not an export.** Column widths, merged ranges,
  hairline borders, number formats, conditional formatting, dropdown validations
  and the summary formulas are all reproduced and asserted by
  `tests/Feature/ExcelExportGoldenTest.php`.
- **Laravel 13 + PostgreSQL 18 + PhpSpreadsheet 5**, one container, no build step.

```
업무일보 보드   →  일보 그리드  →  업무일보_YYYYMMDD.xlsx
 (칸반 · 목록)      (양식 그대로)      (rev02 서식 1:1)
```

## Quick start

```sh
cp .env.example .env
php artisan key:generate
# fill in DB_* (PostgreSQL), then:
php artisan migrate --seed
php artisan serve
```

Open <http://127.0.0.1:8000/report/board>.

No PHP on the host? Run everything in the dev image:

```sh
podman build -f deploy/Containerfile -t localhost/work-report:current .
deploy/scripts/install.sh
```

Full deployment (Podman + Quadlet + `tailscale serve /report`) is in
[docs/deploy.md](docs/deploy.md).

## What is in the box

| Surface | Route | Purpose |
|---|---|---|
| Board | `/report/board` | Kanban with drag & drop across 예정 / 진행 중 / 완료 / 보류 |
| List | `/report/list` | The workbook's columns as a dense sortable table |
| Backlog | `/report/backlog` | Everything grouped by 구분 |
| Issue drawer | `/report/issues/{id}` | Inline edit, comments, `@mentions`, activity timeline |
| 일보 | `/report/daily` | The printed sheet, generated from live data |
| Export | `/report/export.xlsx` | The real thing: rev02 layout, `업무일보_YYYYMMDD.xlsx` |

Keyboard: `c` new issue · `/` search · `g b` board · `g l` list · `g d` daily · `Esc` close.

## Architecture

```
app/
  Contracts/ActorResolver.php        ← the only seam for identity (SSO-ready)
  Services/SessionActorResolver.php  ← current implementation: member switcher
  Services/IssueKeyGenerator.php     ← atomic per-project numbering (3D-01)
  Services/Excel/
    ReportSheetWriter.php            ← sheet 업무일보, every colour and formula
    WorkbookWriter.php               ← three sheets, defined names, order
    ReportExporter.php               ← filters → workbook + filename
    XlsxPackager.php                 ← post-write XML repairs (see below)
  Http/Controllers/                  ← Board, Issue, DailyReport, Export
resources/views/                     ← Blade + Alpine, hand-written CSS
lang/ko/{ui,activity}.php            ← Korean is the source language
docs/excel-schema.md                 ← the golden spec, extracted from the file
tools/inspect_xlsx.py                ← regenerates that spec from any workbook
```

### Two details worth knowing

**`XlsxPackager` exists for a reason.** PhpSpreadsheet writes `_xlfn.AVERAGEIFS`
(the function-name prefix Excel uses for post-2007 functions), but the reference
workbook contains plain `AVERAGEIFS` — and LibreOffice rejects the prefixed form.
It also writes only `fgColor` for solid fills, which renders black in readers that
honour `bgColor`. Both are repaired by rewriting the produced archive, so
everything else stays exactly as the writer emitted it.

**Issue keys are allocated, never derived.** The workbook computes `업무 키` with
`=COUNTIFS($D$16:$D16,$D16,$E$16:$E16,"<>")`, which renumbers when a row is
inserted. People quote keys in comments, so the number is reserved once inside the
insert transaction (`IssueKeyGenerator`) and kept in a project counter that survives
deletions.

## Development

```sh
composer test            # phpunit, PostgreSQL (jsonb is real)
composer lint            # pint --test
composer fix             # pint
python3 tools/inspect_xlsx.py docs/source-rev02.xlsx   # re-extract the spec
```

The test suite asserts the exporter against `docs/source-rev02.xlsx` itself:
sheet order, column widths, merged ranges, every written cell, the hidden
`집계용 진행률` helper column, the six dropdown ranges, the conditional-format
blocks and the print setup.

## Identity and SSO

There is no login yet, by design. `App\Contracts\ActorResolver` is the single
place that decides who is acting; today `SessionActorResolver` reads a session
value set by the member switcher in the top bar. When the separate SSO/OIDC
application is ready, bind a different implementation in `AppServiceProvider` —
controllers, views, policies and the schema stay untouched. See
[docs/auth-seam.md](docs/auth-seam.md).

## Docs

- [docs/excel-schema.md](docs/excel-schema.md) — the workbook, extracted and specified
- [docs/architecture.md](docs/architecture.md) — how the pieces fit
- [docs/deploy.md](docs/deploy.md) — Podman, Quadlet, `/report` reverse proxy
- [docs/auth-seam.md](docs/auth-seam.md) — the OIDC integration point
- [docs/ui.md](docs/ui.md) — interaction model and design tokens

## License

MIT
