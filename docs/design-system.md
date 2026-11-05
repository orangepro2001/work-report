# The visual paradigm (`wr-theme`)

One grammar for every internal app: the board (work-report) and the authorization
center (SSO) draw from the same tokens, so a person moving between them recognises
the same buttons, the same status colours, the same density.

## The rule

**`public/assets/theme.css` is canonical, in the work-report repository.** Other
applications copy the file verbatim, with a header line saying so, and then style
themselves using the token names only. If a colour has to change, it changes there
and the copy is refreshed — a second palette is how two apps stop looking like one
product.

```
work-report/public/assets/theme.css   ← the source
            │ copy (never fork)
            └─→ SSO/public/assets/theme.css
```

Both applications load it **before** their own stylesheet:

```html
<link rel="stylesheet" href="/assets/theme.css">
<link rel="stylesheet" href="/assets/app.css">
```

Nothing outside `theme.css` may contain a colour literal. Layout measurements that
belong to one application (a sidebar width, a header height) are the exception, and
they are declared in that application's `:root`.

## Tokens

| Group | Tokens | Meaning |
|---|---|---|
| Brand | `--navy`, `--blue`, `--blue-600`, `--blue-050` | one navy, one blue, one 5% wash of it for surfaces |
| Ink | `--ink`, `--ink-2`, `--muted`, `--faint` | four steps, no more: body, secondary, muted, decoration |
| Surface | `--bg`, `--surface`, `--zebra`, `--hair`, `--hair-strong` | page, card, alternating row, two border weights |
| Semantics | `--green/-bg`, `--amber/-bg`, `--red/-bg`, `--slate-bg` | a colour means **one** thing: done, warning, danger, neutral |
| Geometry | `--radius`, `--radius-sm`, `--radius-pill` | three radii, applied by element size |
| Depth | `--shadow-1/2/3` | resting card, hover, overlay |
| Type | `--font`, `--mono`, `--text-xs…xl` | one sans stack, one mono, a five-step scale |
| Rhythm | `--gap-1…6` | a 4px grid, named by use |
| Focus | `--focus-ring` | one focus treatment, everywhere |

## Components

- **Card**: `--surface`, 1px `--hair`, `--radius`, `--shadow-1`, padding `--gap-4/5`.
- **Table**: header in `--text-xs` upper-case `--muted`; rows separated by `--hair`;
  even rows `--zebra`; hover `--blue-050`.
- **Button**: default is surface + `--hair-strong` border; `primary` is `--blue` on
  white; `danger` is `--red` text on `--red-bg` hover. Radius `--radius-sm`.
- **Input**: `--hair-strong` border, focus = `--blue` border + `--focus-ring`.
- **Badge / chip**: `--radius-pill`, semantic background + semantic text, `--text-xs`.
- **Flash**: semantic background and border, dark text of the same family.

## Light and dark

Dark is the same grammar with the lights off, not an inverted palette: surfaces stay
navy-tinted, the blue is lifted for contrast, semantic colours are washed onto the
dark background. There is one dark block in `theme.css` and applications do not
write their own.

Theme choice is **auto by default** (follow the OS) with an explicit override:

| | |
|---|---|
| Stored value | `localStorage['wr-theme']` = `light` \| `dark` (absent = auto) |
| Applied as | `document.documentElement.dataset.theme` |
| Before paint | an inline `<script>` in `<head>`, so a dark preference never flashes white |
| Toggle | `<button data-theme-toggle>` cycling auto → light → dark → auto |

Both apps ship the same 20-line toggle script; it is the only duplicated JavaScript
in the system, and deliberately so — a shared package for twenty lines would be a
worse trade.

## Adopting it in a new application

1. Copy `theme.css` next to the app's stylesheet, with a provenance header.
2. Link it before the app stylesheet, add the no-flash snippet to `<head>`.
3. Rewrite the app stylesheet to use tokens; delete every colour literal.
4. Add the toggle button and its script.
5. Check three states: light, dark, and 1180px-wide (both apps collapse their
   multi-column layouts there).
