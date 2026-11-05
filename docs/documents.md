# Documents — the internal assets shelf

A place for the documents that would otherwise live in someone's drive: procedures,
meeting notes, references. Same editor and same renderer as the board, plus revisions
and a presence list.

Everything here is Markdown (`app/Support/Markdown.php`), so a document gets tables,
checklists, `@mentions`, task blocks and images for free.

## Visibility

| Mode | Who can read | Who can write |
|---|---|---|
| `team` (default) | every signed-in member | every signed-in member |
| `private` | owner + shares | owner + shares with role `editor` |

A share is one row in `document_shares` (`editor` or `viewer`), managed from the
right-hand panel by the owner. There is no per-project scoping: this is a team shelf,
not a filing system.

## Saving: last writer never wins

`documents.revision` is the concurrency token. The editor sends the revision it
started from; the controller compares it with the stored one.

- equal → the save lands, `revision + 1`, one row in `document_revisions`;
- different → **409** with the newer title/body and who wrote it. Nothing is
  overwritten; the browser shows the conflict bar and offers "최신 내용 불러오기".

Every save writes a revision, including a restore, so undoing an undo is possible.
`restore` copies the old text into a *new* revision rather than rewinding history.

## Live collaboration

Two layers, on purpose:

1. **Presence and change detection (shipped).** The editor polls
   `GET /docs/{slug}/state` every 2.5 s. The same call renews this member's
   `document_presences` row (45 s TTL, so a closed tab ages out by itself) and
   returns who else is reading. If the revision moved and this browser has no
   unsaved text, the newer body is pulled in silently; if there *is* unsaved text,
   the conflict bar appears instead.
2. **CRDT editing (next).** Yjs over a `y-websocket` service gives character-level
   merging. The client is the one place a JavaScript build is justified: `yjs`,
   `y-websocket` and the CodeMirror-5 binding only ship as a module graph, so they
   are bundled by esbuild in a `node` stage of `deploy/Containerfile`
   (AGENTS.md no longer forbids this — the constraint was a repo note, and the owner
   does not require it). The body then stops travelling through the poll above; the
   revision history, the conflict bar and the presence list stay as they are.

## Images: local, and never public

Markdown images follow one rule: **they must live on this host**.

- Upload: `POST /uploads` (multipart, ≤ 12 MB, real image MIME types only) writes to
  the `local` disk under `storage/app/uploads/YYYY/MM/`, records an `attachments` row
  and answers with a relative URL.
- Serving: `GET /uploads/{id}` — a controller, not a public directory, because an
  image inside a private document must not be readable by guessing its URL. Rights
  come from the attachment's owner: a document (its visibility), an issue (the team),
  or nobody yet (the uploader alone).
- Rendering: `App\Support\Markdown\ImageGuardProcessor` refuses any image whose URL
  is not `uploads/{id}` (optionally with the mount prefix) and turns it into
  `[alt: 외부 이미지 차단]`. Absolute URLs are refused even when they point back here:
  a rule that has to recognise "our own host" inside rendered Markdown is one proxy
  header away from being wrong, and the uploader only ever writes relative paths.

`storage/app` is a **named volume** (`work-report-storage`), not tmpfs: uploaded
files have to survive a restart, a rebuild and an upgrade.

## Task blocks

````markdown
```tasks
3D-03
Write the SOP | high | 40 | in_progress
```
````

A line that is just an issue key is rendered **from the issue** — title, priority,
progress and status stay current after the board moves, and the row opens the issue
drawer. A free-standing line is `title | priority | percent | status`. The header
carries a completion meter over the whole block (`app/Support/Markdown/Tasks`).

## Where the code lives

| Piece | Path |
|---|---|
| Model, visibility rules | `app/Models/Document.php` |
| Controller (list, save, state, restore, share) | `app/Http/Controllers/DocumentsController.php` |
| Uploads | `app/Http/Controllers/UploadController.php` |
| Views | `resources/views/docs/{index,editor}.blade.php` |
| Tests | `tests/Feature/DocumentLibraryTest.php`, `ImageUploadTest.php`, `tests/Unit/Markdown*Test.php` |
