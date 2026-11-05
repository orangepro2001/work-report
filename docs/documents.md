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

Two layers, and both stay:

1. **CRDT editing (Yjs, shipped).** Two browsers in a document's room merge
   keystrokes character by character instead of overwriting each other. The room is
   the document slug; `y-websocket` carries the updates.
2. **Persistence and presence (this application).** The autosave still writes the
   merged text to `documents.body` and appends a revision, and the 2.5 s poll still
   renews presence and reports who is reading. With the room live, the poll no
   longer applies the server's body — the CRDT is the truth — and a 409 is answered
   by adopting the newer revision and saving once more, because the browser already
   holds the other person's keystrokes.

### The service

| Piece | Where |
|---|---|
| Sync service | `deploy/collab/` — Node 22, `y-websocket` + a 60-line server, its own container `work-report-collab` on `127.0.0.1:1234` |
| Proxy | the application's nginx, `location /collab/` → `127.0.0.1:1234` with upgrade headers (no new public port) |
| Authorization | `POST /docs/{slug}/collab` decides once, with the editor's own rules, and returns a signed token |
| Token | `App\Support\CollabToken` — `base64url({room, sub, exp})` + HMAC-SHA256 with `COLLAB_SECRET`, 15 minutes |
| Check | `deploy/collab/auth.cjs` verifies the signature and that the token's room is the room being joined |

A viewer (share role `viewer`) gets `{editable: false}` and no token, so "can read
the page" never becomes "can type into it". A forged or expired token closes the
socket with 4001.

The service keeps nothing on disk: the text lives in Postgres through the autosave,
so a restart costs at most the keystrokes of a document nobody has saved yet.

### Building the client

`resources/js/collab.js` is the only source that needs a bundler (`yjs`,
`y-websocket`, `y-codemirror` — a module graph, and hand-vendoring it is exactly the
fragility AGENTS.md warns about). The `assets` stage of `deploy/Containerfile`
builds it with npm + esbuild on Debian (esbuild's binary is glibc), and the runtime
image just receives `public/assets/collab.js`. A checkout without the bundle simply
keeps the editor in polling mode.

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
