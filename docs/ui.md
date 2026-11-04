# Interaction model & design tokens

## Design language

The palette is taken from the workbook itself, so the app and the exported file
feel like the same document rather than two products.

| Token | Light | Origin in the workbook |
|---|---|---|
| `--navy` | `#1F3864` | title text, all table headers, the 합계 rule |
| `--blue` | `#2F5597` | header labels (`보고일`/`부서`/`작성자`), 업무 키 text |
| `--hair` | `#DFE4EC` | row separators (`BFC9D9` is the tighter `--hair-strong`) |
| `--zebra` | `#F7F9FC` | the alternating row fill (`F3F6FB` in the sheet) |
| `--green` / `--amber` / `--red` | `#2F7A4D` / `#A56A00` / `#B3261E` | 완료 / Medium·보류 / High·지연 |
| Cream `#FFF8E1` | | the `보고일` value cell (`.v--date`) |

Dark mode is a `prefers-color-scheme` override of the same token names — no
component knows which mode it is in.

## The three lenses

One query, three presentations, chosen with the segmented control:

| Lens | Answers | Rendering |
|---|---|---|
| **보드** | "what is moving?" | Columns per status, drag & drop, per-column average progress |
| **목록** | "the workbook's table" | Same column order as the sheet, dense, scannable |
| **백로그** | "what do we owe?" | Grouped by 구분 with a per-bucket average |

Filters (`project`, `assignee`, `priority`, `q`, `flag=late`) are query parameters,
so every view is a link someone can paste into a chat.

## Interactions

| Action | Behaviour | Why |
|---|---|---|
| Drag a card | Moves optimistically, reconciles from the response, reloads only on failure | Board latency is what people feel |
| Click a card / row | Opens the drawer **without** leaving the page | Context stays visible behind the panel |
| Edit any field in the drawer | Saves on `blur`/`change`, no save button | Fewer clicks than Jira, same safety |
| Comment | Prepends the rendered comment, updates the count | No reload, no lost scroll position |
| Status → 완료 | Forces `percent = 100` and stamps `completed_at` | The sheet's `O` column does exactly this |
| Delete | Soft delete; the number is never reused | Keys are quoted in comments |
| `Esc` | Closes the drawer or composer | |
| `c` | New issue composer | Jira muscle memory |
| `/` | Focus search | |
| `g b` / `g l` / `g d` | Board / list / daily | |

## Progressive detail

The composer asks only for 구분 + 요약. Everything else (assignee, dates, note,
priority) has a sensible default and can be filled in the drawer. Capturing a task
must not cost more than the task itself — the same principle as pressing Tab in
the spreadsheet it replaces.

## The 일보 screen is the document

`/report/daily` deliberately looks like paper: cream title rule, navy section
headers (`■ 업무 현황 요약`, `■ 업무 목록`), bucket-group separators, a progress bar
in the 진행률 column, red 지연 numbers. It reads the *same* query as the exporter,
and `Ctrl/Cmd+P` produces a page that can be filed as a PDF without touching Excel.

## Accessibility & i18n

- Semantic landmarks (`nav`, `main`, `aside`), `aria-label` on every icon button,
  focus-visible rings on all inputs (`box-shadow` on `:focus`).
- Status and priority are never colour-only: every chip carries its Korean label.
- `[x-cloak]` prevents the flash of unstyled Alpine blocks.
- All copy comes from `lang/{ko,en,ja}/ui.php`; Korean is the source language, so
  translations are written from meaning, not from English word order.
