<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <title>@yield('title', __('ui.app.name')) · {{ __('ui.app.name') }}</title>
    {{-- Shared design tokens (docs/design-system.md); the same file is vendored into
         the SSO console, so both apps draw from one palette. --}}
    <link rel="stylesheet" href="{{ wr_asset('assets/theme.css') }}?v={{ substr(md5_file(public_path('assets/theme.css')), 0, 8) }}">
    <script>
        // Apply the stored theme before first paint (auto / light / dark).
        (function () {
            try {
                var stored = localStorage.getItem('wr-theme');
                if (stored === 'light' || stored === 'dark') {
                    document.documentElement.dataset.theme = stored;
                }
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="{{ wr_asset('assets/vendor/fontawesome/fontawesome.min.css') }}">
    <link rel="stylesheet" href="{{ wr_asset('assets/vendor/fontawesome/solid.min.css') }}">
    <link rel="stylesheet" href="{{ wr_asset('assets/vendor/easymde/easymde.min.css') }}?v={{ substr(md5_file(public_path('assets/vendor/easymde/easymde.min.css')), 0, 8) }}">
    <link rel="stylesheet" href="{{ wr_asset('assets/app.css') }}?v={{ substr(md5_file(public_path('assets/app.css')), 0, 8) }}">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%232f5597'/><text x='16' y='22' font-size='17' font-family='sans-serif' font-weight='700' fill='white' text-anchor='middle'>업</text></svg>">
    @stack('head')
    <script src="{{ wr_asset('assets/vendor/easymde/easymde.min.js') }}?v={{ substr(md5_file(public_path('assets/vendor/easymde/easymde.min.js')), 0, 8) }}" defer></script>
    <script src="{{ wr_asset('assets/vendor/alpine.min.js') }}?v={{ substr(md5_file(public_path('assets/vendor/alpine.min.js')), 0, 8) }}" defer></script>
</head>
<body>
<div class="app"
     x-data="app()"
     x-on:toast.window="toast($event.detail.message)"
     x-on:open-issue.window="openIssue($event.detail.url)"
     x-on:open-create.window="openCreate()"
     data-base="{{ $basePath }}">

    <a class="brand" href="{{ route('board.index') }}">
        <span class="brand__mark" aria-hidden="true">업</span>
        <span>
            {{ __('ui.app.name') }}
            <small>{{ __('ui.app.tagline') }}</small>
        </span>
    </a>

    <header class="topbar">
        <form class="search-wrap" method="get" action="{{ route('board.index') }}" style="flex:0 1 340px">
            @foreach (request()->except(['q', 'page']) as $name => $value)
                @if (is_scalar($value))
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endif
            @endforeach
            <input class="input input--search" type="search" name="q" value="{{ request('q') }}"
                   placeholder="{{ __('ui.board.search_placeholder') }}"
                   aria-label="{{ __('ui.board.search') }}"
                   x-ref="search"
                   x-on:keydown.window.slash.prevent="$refs.search.focus()">
        </form>

        <div class="topbar__spacer"></div>

        <button class="btn btn--ghost btn--icon theme-toggle" type="button" data-theme-toggle
                title="{{ __('ui.common.theme') }}" aria-label="{{ __('ui.common.theme') }}">◐</button>

        @include('partials.identity')

        <a class="btn btn--primary" href="{{ route('board.index') }}" x-on:click.prevent="openCreate()">
            + {{ __('ui.board.create') }}
        </a>
    </header>

    @include('partials.nav')

    <main class="main">
        @if (session('status'))
            <div class="flash" role="status">{{ session('status') }}</div>
        @endif

        @yield('content')
    </main>

    {{-- Issue drawer: filled over the wire, one component for every entry point. --}}
    <div class="drawer__scrim" x-show="drawer.open" x-on:click="closeDrawer()" x-cloak
         x-transition.opacity></div>
    <aside class="drawer" :class="'drawer--' + drawer.mode" x-show="drawer.open" x-cloak
           x-transition:enter="dr-enter" x-transition:enter-start="dr-enter-start"
           x-transition:enter-end="dr-enter-to" x-transition:leave="dr-leave"
           aria-label="{{ __('ui.issue.open') }}">
        <div class="drawer__head">
            <span class="key" x-text="drawer.key"></span>
            <span class="muted tiny" x-text="drawer.busy ? '{{ __('ui.common.loading') }}' : ''"></span>
            <div style="flex:1"></div>

            {{-- Three shapes for the same panel: beside the board, centred over it
                 (the default), or on a page of its own. The choice is remembered. --}}
            <span class="seg seg--panel" role="group" aria-label="{{ __('ui.issue.mode') }}">
                <button type="button" title="{{ __('ui.issue.mode_side') }}"
                        :class="{ 'is-active': drawer.mode === 'side' }"
                        x-on:click="setPanelMode('side')" aria-label="{{ __('ui.issue.mode_side') }}">◧</button>
                <button type="button" title="{{ __('ui.issue.mode_modal') }}"
                        :class="{ 'is-active': drawer.mode === 'modal' }"
                        x-on:click="setPanelMode('modal')" aria-label="{{ __('ui.issue.mode_modal') }}">▣</button>
                <button type="button" title="{{ __('ui.issue.mode_page') }}"
                        x-on:click="panelPage()" aria-label="{{ __('ui.issue.mode_page') }}">⤢</button>
            </span>

            <button class="btn btn--ghost btn--icon" x-on:click="closeDrawer()" aria-label="{{ __('ui.common.close') }}">✕</button>
        </div>
        <div class="drawer__body" x-html="drawer.html"></div>
    </aside>

    {{-- New issue composer --}}
    <template x-if="create.open">
        <div class="drawer__scrim is-open" x-on:click="closeCreate()"></div>
    </template>
    <template x-if="create.open">
        <aside class="drawer is-open" aria-label="{{ __('ui.issue.new') }}" style="z-index:62">
            <div class="drawer__head">
                <strong>{{ __('ui.issue.new') }}</strong>
                <div style="flex:1"></div>
                <button class="btn btn--ghost btn--icon" x-on:click="closeCreate()" aria-label="{{ __('ui.common.close') }}">✕</button>
            </div>
            <div class="drawer__body">
                @include('issues.composer')
            </div>
        </aside>
    </template>

    <div class="toast" x-ref="toast" role="status" aria-live="polite"></div>
</div>

<script>
// The team's names, for @mentions inside any Markdown editor on the page.
window.WR_MENTIONS = {{ \Illuminate\Support\Js::from(\App\Models\Member::active()->internal()->orderBy('position')->pluck('name')) }};

function app() {
    return {
        drawer: { open: false, html: '', key: '', busy: false, url: '', mode: 'modal' },
        create: { open: false },
        toastTimer: null,

        init() {
            const base = this.$el.dataset.base || '';
            this.base = base;

            // Textareas served with the page (the document editor) are adopted here.
            // The drawer and the composer are fetched later and call this themselves.
            this.$nextTick(() => window.initMarkdownEditors(this.$el));

            // Panel shape: side / modal (default) / page. Remembered per browser.
            try {
                const stored = localStorage.getItem('wr-panel');
                if (stored === 'side' || stored === 'modal') this.drawer.mode = stored;
            } catch (e) {}

            // Follow the browser history so the back button closes the drawer.
            window.addEventListener('popstate', () => this.closeDrawer(true));

            window.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') { this.closeDrawer(); this.closeCreate(); }
                // "c" opens the composer unless the user is typing.
                if (e.key === 'c' && !this.isTyping(e)) { e.preventDefault(); this.openCreate(); }
                // "g b" / "g l" / "g d" navigation, Jira's muscle memory.
                if (e.key === 'g') {
                    this.pending = true;
                    setTimeout(() => { this.pending = false; }, 700);
                    return;
                }
                if (this.pending) {
                    const map = { b: '{{ route('board.index') }}', l: '{{ route('board.list') }}', d: '{{ route('daily') }}' };
                    if (map[e.key]) { window.location = map[e.key]; }
                    this.pending = false;
                }
            });
        },

        setPanelMode(mode) {
            this.drawer.mode = mode;
            try { localStorage.setItem('wr-panel', mode); } catch (e) {}
        },

        /** Full screen means a page of its own — a real URL, not a bigger box. */
        panelPage() {
            if (this.drawer.url) {
                window.location = this.drawer.url;
            } else if (this.drawer.open) {
                // The key is only known from the fragment; fall back to the board.
                window.location.reload();
            }
        },

        isTyping(e) {
            const t = e.target;
            return t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable);
        },

        async openIssue(url) {
            this.drawer.open = true;
            this.drawer.busy = true;
            this.drawer.html = '';
            this.drawer.key = '';
            this.drawer.url = url;

            try {
                const res = await fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!res.ok) throw new Error(res.status);
                this.drawer.html = await res.text();
                const keyEl = document.querySelector('.drawer .key');
                this.drawer.key = keyEl ? keyEl.textContent.trim() : '';
                // The panel arrives as markup, so Alpine has to adopt the subtree,
                // and the Markdown editors have to be built on the newly inserted
                // textareas (scripts inside fetched HTML never run — which is why
                // the panel's component lives in this file, not in the fragment).
                this.$nextTick(() => {
                    const body = this.$el.querySelector('.drawer__body');
                    window.Alpine && window.Alpine.initTree(body);
                    window.initMarkdownEditors(body);
                });
            } catch (e) {
                this.drawer.html = '<p class="muted">이슈를 열지 못했습니다.</p>';
            } finally {
                this.drawer.busy = false;
            }
        },

        closeDrawer() {
            if (!this.drawer.open) return;
            this.drawer.open = false;
            this.drawer.html = '';
        },

        openCreate() {
            this.create.open = true;
            this.$nextTick(() => {
                const f = this.$el.querySelector('#new-issue-summary');
                if (f) f.focus();
                window.initMarkdownEditors(this.$el);
            });
        },
        closeCreate() { this.create.open = false; },

        toast(message) {
            const el = this.$refs.toast;
            el.textContent = message;
            el.classList.add('is-shown');
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => el.classList.remove('is-shown'), 2400);
        },
    };
}

/*
 * The issue drawer's component.
 *
 * It is defined here, on the page, and not inside the fetched panel: markup
 * inserted with x-html never executes the <script> it carries, so a factory
 * defined there is undefined at Alpine's evaluation time and every x-on:change in
 * the drawer silently does nothing.
 */
function issuePanel(issueId, initial) {
    return {
        percent: initial.percent,
        statusLabel: initial.statusLabel,
        commentCount: initial.commentCount,

        /** True when the reply came from anywhere but this application's API. */
        sessionLost(res) {
            const type = res.headers.get('content-type') || '';
            return res.redirected || res.status === 419 || res.status === 401 || !type.includes('application/json');
        },

        /** Keeps the rendered description (tasks blocks, tables, mentions) current. */
        async previewDescription(text) {
            const pane = document.getElementById('issue-description-preview');
            const wrap = document.getElementById('issue-description-preview-wrap');
            if (!pane) return;

            if (!text.trim()) {
                if (wrap) wrap.hidden = true;
                return;
            }

            try {
                const res = await fetch('{{ route('markdown.preview') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ text }),
                });
                if (!res.ok) return;
                const data = await res.json();
                pane.innerHTML = data.html || '';
                if (wrap) wrap.hidden = false;
            } catch (e) {
                /* the pane keeps the previous rendering */
            }
        },

        async save(field, value) {
            const res = await fetch(`{{ url('issues') }}/${issueId}`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ [field]: value === '' ? null : value }),
            });

            if (this.sessionLost(res)) {
                window.location.reload();
                return;
            }

            if (!res.ok) {
                const problem = await res.json().catch(() => ({}));
                this.$dispatch('toast', { message: problem.message || '저장하지 못했습니다' });
                return;
            }

            const data = await res.json();
            if (data.activity) {
                const timeline = document.getElementById('timeline');
                if (timeline) timeline.innerHTML = data.activity;
            }
            if (field === 'status_key') {
                const selected = document.querySelector('#p-status option:checked');
                if (selected) this.statusLabel = selected.textContent;
            }

            // The description has no footprint on the board; everything else does.
            if (field !== 'description') {
                window.refreshLiveRegion(issueId);
            }

            this.$dispatch('toast', { message: '{{ __('ui.issue.saved') }}' });
        },

        async submitComment(form) {
            const body = form.body.value.trim();
            if (!body) return;

            const res = await fetch(`{{ url('issues') }}/${issueId}/comments`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ body }),
            });

            if (this.sessionLost(res)) {
                window.location.reload();
                return;
            }

            if (!res.ok) {
                this.$dispatch('toast', { message: '댓글을 남기지 못했습니다' });
                return;
            }

            const data = await res.json();
            const holder = document.getElementById('comments');
            if (holder && data.comment) holder.insertAdjacentHTML('afterbegin', data.comment.html);

            const editor = form.body._mde;
            if (editor) { editor.value(''); } else { form.body.value = ''; }
            this.commentCount += 1;
            this.$dispatch('toast', { message: '{{ __('ui.issue.comment_added') }}' });
        },
    };
}

/*
 * Re-renders the page's live region (the board, the list, or the 일보 grid).
 *
 * Not a client-side patch: the drawer changes one issue, and everything the change
 * touches — the card's column, three counters, a summary row, a table row — is
 * produced by re-running the same Blade the first render used. One request per
 * save, no second renderer to keep in sync.
 */
window.refreshLiveRegion = async function (changedIssueId) {
    const region = document.querySelector('[data-live]');
    if (!region) return;

    const res = await fetch(window.location.href, {
        headers: {
            'X-Live-Region': region.dataset.live,
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'text/html',
        },
        credentials: 'same-origin',
    });

    if (!res.ok) return;

    region.innerHTML = await res.text();
    window.Alpine && window.Alpine.initTree(region);

    // Show *what* moved: the changed item gets a short highlight, and the board is
    // scrolled to it when the change moved it to another column.
    const target = region.querySelector('[data-issue-id="' + changedIssueId + '"]');
    if (target) {
        target.classList.add('is-flash');
        target.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        setTimeout(() => target.classList.remove('is-flash'), 1600);
    }
};

/*
 * The issue checklist (app/Http/Controllers/IssueTodoController).
 *
 * Each action answers with the whole list and its progress, so the panel never has to
 * guess: it replaces its state with the server's. That is also why the Markdown `tasks`
 * block is not used here — a checklist is data, and data has a shape.
 */
function issueTodos(issueId, initial) {
    return {
        todos: initial.items || [],
        total: initial.total || 0,
        done: initial.done || 0,
        percent: initial.percent || 0,

        apply(state) {
            this.todos = state.todos || [];
            this.total = state.total || 0;
            this.done = state.done || 0;
            this.percent = state.percent || 0;
        },

        async send(url, options = {}) {
            const res = await fetch(url, {
                ...options,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                    ...(options.headers || {}),
                },
            });

            if (!res.ok) {
                this.$dispatch('toast', { message: '저장하지 못했습니다' });
                return;
            }

            this.apply(await res.json());
        },

        add() {
            const field = this.$refs.newTodo;
            const title = field.value.trim();
            if (!title) return;

            field.value = '';
            this.send(`{{ url('issues') }}/${issueId}/todos`, {
                method: 'POST',
                body: JSON.stringify({ title }),
            });
        },

        toggle(todo, done) {
            todo.done = done;
            this.send(`{{ url('todos') }}/${todo.id}`, {
                method: 'PATCH',
                body: JSON.stringify({ done }),
            });
        },

        rename(todo, title) {
            if (title.trim() === '' || title === todo.title) return;
            this.send(`{{ url('todos') }}/${todo.id}`, {
                method: 'PATCH',
                body: JSON.stringify({ title }),
            });
        },

        remove(todo) {
            this.send(`{{ url('todos') }}/${todo.id}`, { method: 'DELETE' });
        },

        move(todo, direction) {
            this.send(`{{ url('todos') }}/${todo.id}/move`, {
                method: 'POST',
                body: JSON.stringify({ direction }),
            });
        },
    };
}

/*
 * Markdown live preview and editing ergonomics — the editor's final form.
 *
 * Everything here is *view-side* work on CodeMirror 5: the document text is never
 * rewritten by the decoration pass, so autosave, the Yjs binding and the revision
 * history see exactly what was typed. The one exception is the task checkbox, which
 * the person clicks on purpose (it rewrites `[ ]` ⇄ `[x]`, two characters).
 *
 * Behaviour:
 *   · tokens styled (headings, bold, italic, strike, links, quotes)
 *   · syntax markers collapsed on every line except the caret's
 *   · fenced code blocks read as blocks, images render inline
 *   · task list boxes are real, clickable checkboxes
 *   · Enter continues a list / task, and an empty item ends it
 *   · Tab / Shift-Tab indent and outdent list items
 *   · typing @ suggests the team
 */
function wrLivePreview(editor) {
    const cm = editor.codemirror;
    const suppress = { on: false };

    const MARKERS = [
        /\*\*|__|~~|`/g,               // emphasis and inline code
        /^#{1,6}\s/g,                  // heading
        /^>\s?/g,                      // blockquote
        /^[-*+]\s/g, /^\d+[.)]\s/g,    // list bullets and numbers
        /\[(?=[^\]]*\]\()/g, /\]\([^)]*\)/g,  // link syntax around its label
    ];

    const TASK = /^(\s*)[-*+]\s\[([ xX])\]\s/;

    let marks = [];
    let pending = null;
    // Image elements are reused per URL. Rebuilding them on every caret move made each
    // picture load again, the document height changed under the cursor, and CodeMirror
    // pulled the viewport back to the top — which is exactly what made writing below an
    // image impossible.
    const imageCache = new Map();
    // What the last pass saw: skip the work when neither the text nor the active line
    // moved, so a plain click inside a paragraph costs nothing.
    let lastActive = -1;
    let lastSignature = '';

    /** Clickable checkbox: replaces the `[ ]` run on a line that is not being edited. */
    function checkbox(line, from, checked) {
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.checked = checked;
        box.className = 'cm-md-task';
        box.setAttribute('aria-label', checked ? 'done' : 'todo');
        box.addEventListener('mousedown', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const text = cm.getLine(line) || '';
            const start = from - (checked ? 4 : 3);   // the bracket pair we marked
            const replacement = checked ? '[ ]' : '[x]';
            cm.replaceRange(replacement, { line, ch: start }, { line, ch: start + 3 });
            cm.focus();
            if (!text) return;
        });

        marks.push(cm.markText({ line, ch: from }, { line, ch: from + 3 }, {
            replacedWith: box, clearOnEnter: false, handleMouseEvents: true,
        }));
    }

    function decorate() {
        pending = null;
        if (suppress.on) return;

        const active = cm.getCursor().line;
        const viewport = cm.getViewport();
        const signature = `${active}|${cm.getValue().length}|${cm.getViewport().from}`;

        if (signature === lastSignature) return;   // nothing moved: leave the view alone
        lastSignature = signature;

        // Anything this pass does to the layout is undone afterwards: the reader keeps
        // the place they were reading.
        const scrollTop = cm.getScrollInfo().top;

        clear();

        let fence = null;   // {line, marker}

        for (let ln = viewport.from; ln <= viewport.to; ln++) {
            const text = cm.getLine(ln) || '';
            const trimmed = text.trimStart();

            // ── fenced code blocks: style the block, collapse the fence markers ──
            const fenceMatch = /^(\s*)(```|~~~)/.exec(text);
            if (fenceMatch) {
                if (ln !== active) {
                    marks.push(cm.markText(
                        { line: ln, ch: 0 },
                        { line: ln, ch: text.length },
                        { className: 'cm-md-fence' },
                    ));
                }
                fence = fence ? null : { indent: fenceMatch[1].length };
                continue;
            }

            if (fence) {
                marks.push(cm.markText(
                    { line: ln, ch: fence.indent },
                    { line: ln, ch: text.length },
                    { className: 'cm-md-code' },
                ));
                continue;
            }

            if (ln === active) continue;   // the caret's line keeps its source

            // ── task list: a real checkbox ──
            const task = TASK.exec(text);
            if (task) {
                const from = task[1].length + 2;   // after "  - "
                checkbox(ln, from + 1, task[2] !== ' ');   // mark the bracket pair
            }

            // ── syntax markers ──
            for (const pattern of MARKERS) {
                pattern.lastIndex = 0;
                let match;
                while ((match = pattern.exec(text)) !== null) {
                    if (match[0].length === 0) break;
                    if (task && match.index >= task[0].length - 3 && match.index <= task[0].length - 1) continue;
                    marks.push(cm.markText(
                        { line: ln, ch: match.index },
                        { line: ln, ch: match.index + match[0].length },
                        { className: 'cm-md-hidden' },
                    ));
                }
            }

            // ── images served by this application ──
            const image = /!\[([^\]]*)\]\(([^)\s]+)\)/.exec(text);
            if (image && /(?:^|\/)uploads\/\d+(?:\/[^/]+)?$/.test(image[2])) {
                let node = imageCache.get(image[2]);
                if (!node) {
                    node = document.createElement('img');
                    node.src = image[2];
                    node.className = 'cm-md-image';
                    node.loading = 'lazy';
                    // Reserve the space before the bitmap arrives: a late layout shift
                    // under the caret is the jump this whole cache exists to prevent.
                    node.style.minHeight = '40px';
                    imageCache.set(image[2], node);
                }
                node.alt = image[1] || '';
                marks.push(cm.markText(
                    { line: ln, ch: image.index },
                    { line: ln, ch: image.index + image[0].length },
                    { replacedWith: node, clearOnEnter: false },
                ));
            }
        }

        lastActive = active;

        if (Math.abs(cm.getScrollInfo().top - scrollTop) > 1) {
            cm.scrollTo(null, scrollTop);
        }
    }

    function schedule() {
        if (pending !== null) return;
        pending = window.requestAnimationFrame(decorate);
    }

    // ── editing ergonomics ────────────────────────────────────────────────────
    function lineInfo() {
        const line = cm.getCursor().line;
        const text = cm.getLine(line) || '';
        return {
            line,
            text,
            bullet: /^(\s*)([-*+])\s(\[[ xX]\]\s)?/.exec(text),
            ordered: /^(\s*)(\d+)([.)])\s/.exec(text),
            inFence: /^\s*(```|~~~)/.test(text),
        };
    }

    // The CodeMirror constructor is not a global in EasyMDE's bundle, so referencing
    // its `Pass` sentinel directly throws a ReferenceError the moment a key falls
    // through. Take it from the instance; if it is missing, skip the keymap.
    const PASS = (cm.constructor && cm.constructor.Pass) || null;

    function indent(direction) {
        const { line, text, inFence } = lineInfo();
        const cursor = cm.getCursor();

        if (inFence) return PASS;                                // keep literal code literal

        const unit = '  ';
        if (direction > 0) {
            cm.replaceRange(unit, { line, ch: 0 });
            cm.setCursor({ line, ch: cursor.ch + unit.length });
        } else if (text.startsWith(unit)) {
            cm.replaceRange('', { line, ch: 0 }, { line, ch: unit.length });
            cm.setCursor({ line, ch: Math.max(0, cursor.ch - unit.length) });
        }
        schedule();
        return null;   // handled
    }

    function enter() {
        const { line, text, bullet, ordered, inFence } = lineInfo();
        const cursor = cm.getCursor();
        if (inFence) return PASS;

        // Only continue a list when the caret is at the end of that item.
        if (cursor.ch < text.length) return PASS;

        if (bullet) {
            const [, ws, mark, task] = bullet;
            const empty = text.trim() === (mark + (task ? ' [ ]' : ''));
            if (empty) {                          // Enter on an empty item ends the list
                cm.replaceRange('', { line, ch: 0 }, { line, ch: text.length });
                return null;
            }
            const next = `\n${ws}${mark} ${task ? '[ ] ' : ''}`;
            cm.replaceSelection(next);
            schedule();
            return null;
        }

        if (ordered) {
            const [, ws, number, dot] = ordered;
            const empty = text.trim() === number + dot;
            if (empty) {
                cm.replaceRange('', { line, ch: 0 }, { line, ch: text.length });
                return null;
            }
            cm.replaceSelection(`\n${ws}${Number(number) + 1}${dot} `);
            schedule();
            return null;
        }

        return PASS;
    }

    const BOLD = /\*\*|__/g;

    function wrap(marker) {
        const selection = cm.getSelection();
        const from = cm.getCursor('from');
        const to = cm.getCursor('to');

        if (selection === '') {
            cm.replaceSelection(marker + marker);
            cm.setCursor({ line: from.line, ch: from.ch + marker.length });
        } else {
            cm.replaceRange(marker + selection + marker, from, to);
        }
        cm.focus();
        schedule();
    }

    if (PASS) {
        cm.addKeyMap({
            Enter: enter,
            Tab: () => indent(1),
            'Shift-Tab': () => indent(-1),
            'Ctrl-B': () => wrap('**'),
            'Ctrl-I': () => wrap('_'),
        });
    }

    // ── @mentions ─────────────────────────────────────────────────────────────
    const people = Array.isArray(window.WR_MENTIONS) ? window.WR_MENTIONS : [];
    let popup = null;
    let popupState = null;

    function closePopup() {
        if (popup) popup.remove();
        popup = null;
        popupState = null;
    }

    function openPopup(matches, coords) {
        if (popup) popup.remove();
        popup = document.createElement('div');
        popup.className = 'cm-mention-pop';
        matches.forEach((name, index) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.textContent = name;
            if (index === 0) item.classList.add('is-active');
            item.addEventListener('mousedown', (event) => {
                event.preventDefault();
                insertMention(name);
            });
            popup.appendChild(item);
        });
        document.body.appendChild(popup);
        popup.style.left = `${coords.left}px`;
        popup.style.top = `${coords.bottom + window.scrollY + 4}px`;
    }

    function insertMention(name) {
        if (!popupState) return;
        const { line, from } = popupState;
        cm.replaceRange(name, { line, ch: from }, { line, ch: cm.getCursor().ch });
        closePopup();
        cm.focus();
    }

    function suggest() {
        if (people.length === 0) return;
        const cursor = cm.getCursor();
        const before = cm.getLine(cursor.line).slice(0, cursor.ch);
        const match = /@([\p{L}\p{N}_.-]*)$/u.exec(before);

        if (!match || match[1].includes(' ')) { closePopup(); return; }

        const query = match[1].toLowerCase();
        const matches = people.filter((name) => name.toLowerCase().includes(query)).slice(0, 6);

        if (matches.length === 0) { closePopup(); return; }

        popupState = { line: cursor.line, from: cursor.ch - match[1].length };
        openPopup(matches, cm.cursorCoords(cursor, 'window'));
    }

    cm.on('cursorActivity', () => { suggest(); schedule(); });
    cm.on('change', schedule);
    cm.on('viewportChange', schedule);
    cm.on('blur', closePopup);
    schedule();

    // Word count, in an element of our own under the editor: EasyMDE's status bar is
    // optional machinery we do not need.
    const counter = document.createElement('div');
    counter.className = 'md-count tiny muted';
    (editor.codemirror.getWrapperElement().parentNode || editor.codemirror.getWrapperElement())
        .appendChild(counter);

    function count() {
        const text = cm.getValue();
        const words = (text.match(/[\p{L}\p{N}]+/gu) || []).length;
        counter.textContent = `${words} 단어 · ${text.length} 자`;
    }

    cm.on('change', count);
    count();
}

/*
 * Builds a Markdown editor on every textarea[data-markdown] inside root.
 *
 * EasyMDE and Font Awesome are vendored under public/assets/vendor — no build
 * step, no CDN and no network dependency (AGENTS.md). The preview is rendered by
 * the server through POST /markdown, so what the editor shows is exactly what the
 * saved comment will look like.
 */
window.initMarkdownEditors = function (root) {
    if (!root || typeof window.EasyMDE !== 'function') return;

    root.querySelectorAll('textarea[data-markdown]').forEach((el) => {
        if (el.dataset.mdeReady === '1') return;
        el.dataset.mdeReady = '1';

        // A textarea inside a drawer that is still animating in has no measurable size,
        // and CodeMirror built at that moment renders empty (or half-built: a toolbar
        // with the original textarea still visible). Wait for a layout box.
        if (el.offsetParent === null || el.getBoundingClientRect().width === 0) {
            let tries = 0;
            const waitForLayout = () => {
                if (el.offsetParent !== null && el.getBoundingClientRect().width > 0) {
                    el.dataset.mdeReady = '0';
                    window.initMarkdownEditors(el.parentNode || document);

                    return;
                }
                if (tries++ < 30) requestAnimationFrame(waitForLayout);
            };
            requestAnimationFrame(waitForLayout);

            return;
        }

        // Images never leave this host: EasyMDE hands the file to our own endpoint,
        // which answers with the URL of a file served through /uploads/{id}. The
        // renderer refuses anything else (app/Support/Markdown/ImageGuardProcessor.php).
        const uploadImage = (file, onSuccess, onError) => {
            const data = new FormData();
            data.append('file', file);
            if (el.dataset.documentId) data.append('document', el.dataset.documentId);
            if (el.dataset.issueId) data.append('issue', el.dataset.issueId);

            fetch('{{ route('uploads.store') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
                body: data,
            })
                .then((res) => (res.ok ? res.json() : Promise.reject(new Error(res.status))))
                .then((json) => onSuccess(json.url))
                .catch(() => onError('이미지를 올리지 못했습니다'));
        };

        let editor;
        try {
            editor = new window.EasyMDE({
                element: el,
                autoDownloadFontAwesome: false,
                spellChecker: false,
                minHeight: '110px',
                // EasyMDE's own status bar is left alone: an unknown key there is one more
                // thing its constructor can trip over, and the count belongs to us anyway.
                status: false,
                autofocus: false,
                uploadImage: true,
                imageAccept: 'image/png,image/jpeg,image/webp,image/gif,image/avif',
                imageMaxSize: 12 * 1024 * 1024,
                imageUploadFunction: uploadImage,
                imageTexts: {
                    sbInit: '이미지를 끌어다 놓거나 붙여넣으세요 (이 서버에 저장됩니다)',
                    sbOnDragEnter: '놓으면 업로드합니다',
                    sbOnDrop: '업로드 중…',
                    sbProgress: '업로드 중… (#file_progress#)',
                    sbOnUploaded: '업로드 완료',
                    sizeUnits: ' KB, MB',
                },
                placeholder: el.getAttribute('placeholder') || '',
                toolbar: [
                    'bold', 'italic', 'strikethrough', 'heading', '|',
                    'quote', 'unordered-list', 'ordered-list', '|',
                    'link', 'image', 'code', 'table', '|',
                    {
                        // The tasks block (app/Support/Markdown/Tasks) is this project's
                        // own syntax, so it gets its own button instead of a hidden rule.
                        name: 'tasks',
                        action: (editor) => {
                            editor.codemirror.replaceSelection('```tasks\n\n```');
                            editor.codemirror.setCursor({ line: editor.codemirror.getCursor().line - 2, ch: 0 });
                            editor.codemirror.focus();
                        },
                        className: 'fa fa-list-check',
                        title: '할 일 목록 (issue 키 또는 "제목 | 우선순위 | 진행률 | 상태")',
                    },
                    '|', 'preview', 'fullscreen', 'guide',
                ],
                previewRender: (plainText) => {
                    // EasyMDE wants a string back synchronously, so the server's answer
                    // replaces the pane once it lands. The debounce keeps side-by-side
                    // mode from firing a request per keystroke; the preview is still the
                    // saved output's renderer, never a second client-side parser.
                    const pane = () => el.closest('.EasyMDEContainer')?.querySelector('.editor-preview-side, .editor-preview');
                    const seed = pane()?.innerHTML || '<p class="muted">{{ __('ui.common.loading') }}</p>';

                    clearTimeout(el._previewTimer);
                    el._previewTimer = setTimeout(() => {
                        fetch('{{ route('markdown.preview') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({ text: plainText }),
                        })
                            .then((res) => (res.ok ? res.json() : { html: '' }))
                            .then((data) => {
                                const target = pane();
                                if (target) {
                                    // The markdown classes are what give the preview the same
                                    // typography as the saved document.
                                    target.classList.add('markdown');
                                    target.innerHTML = data.html || '';
                                }
                            })
                            .catch(() => {});
                    }, 250);

                    return seed;
                },
            });
        } catch (error) {
            // A half-built editor (toolbar rendered, textarea still raw) is worse than a
            // plain textarea: say so loudly and leave the element usable.
            console.error('markdown editor failed to initialise', error);
            el.dataset.mdeReady = '0';
            el.dataset.mdeFailed = '1';

            return;
        }

        // Live preview: styled tokens, markers only on the caret's line.
        wrLivePreview(editor);

        // The textarea is hidden by EasyMDE; the field name lives on it, so the
        // panel keeps its own saving logic and Alpine's debounce does the throttling.
        el._mde = editor;
        el._syncMde = () => { el.value = editor.value(); };
        editor.codemirror.on('change', () => {
            el.value = editor.value();
            el.dispatchEvent(new CustomEvent('mde-change', { detail: { value: el.value, field: el.dataset.field }, bubbles: true }));
        });
    });
};

</script>
<script>
/* Theme toggle contract (docs/design-system.md): auto → light → dark → auto. */
(function () {
    var button = document.querySelector('[data-theme-toggle]');
    if (!button) return;

    var order = ['auto', 'light', 'dark'];
    var label = { auto: '◐', light: '☀', dark: '☾' };

    function current() {
        try { return localStorage.getItem('wr-theme') || 'auto'; } catch (e) { return 'auto'; }
    }

    function apply(theme) {
        if (theme === 'auto') {
            delete document.documentElement.dataset.theme;
            try { localStorage.removeItem('wr-theme'); } catch (e) {}
        } else {
            document.documentElement.dataset.theme = theme;
            try { localStorage.setItem('wr-theme', theme); } catch (e) {}
        }
        button.textContent = label[theme];
    }

    button.textContent = label[current()];
    button.addEventListener('click', function () {
        apply(order[(order.indexOf(current()) + 1) % order.length]);
    });
})();
</script>
@stack('scripts')
</body>
</html>
