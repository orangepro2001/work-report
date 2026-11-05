<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <title>@yield('title', __('ui.app.name')) · {{ __('ui.app.name') }}</title>
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
    <aside class="drawer" x-show="drawer.open" x-cloak
           x-transition:enter="dr-enter" x-transition:enter-start="dr-enter-start"
           x-transition:enter-end="dr-enter-to" x-transition:leave="dr-leave"
           aria-label="{{ __('ui.issue.open') }}">
        <div class="drawer__head">
            <span class="key" x-text="drawer.key"></span>
            <span class="muted tiny" x-text="drawer.busy ? '{{ __('ui.common.loading') }}' : ''"></span>
            <div style="flex:1"></div>
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
function app() {
    return {
        drawer: { open: false, html: '', key: '', busy: false },
        create: { open: false },
        toastTimer: null,

        init() {
            const base = this.$el.dataset.base || '';
            this.base = base;

            // Textareas served with the page (the document editor) are adopted here.
            // The drawer and the composer are fetched later and call this themselves.
            this.$nextTick(() => window.initMarkdownEditors(this.$el));

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

        const editor = new window.EasyMDE({
            element: el,
            autoDownloadFontAwesome: false,
            spellChecker: false,
            status: false,
            minHeight: '110px',
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
                '|', 'preview', 'side-by-side', 'guide',
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

        // A document is written to be read: side-by-side puts the rendered result
        // next to the source and keeps it there while typing.
        if (el.dataset.markdownSplit !== undefined) {
            editor.toggleSideBySide();
            editor.codemirror.getWrapperElement()?.classList.add('wr-split');
        }

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
@stack('scripts')
</body>
</html>
