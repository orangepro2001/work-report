<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <title>@yield('title', __('ui.app.name')) · {{ __('ui.app.name') }}</title>
    <link rel="stylesheet" href="{{ wr_asset('assets/app.css') }}?v={{ substr(md5_file(public_path('assets/app.css')), 0, 8) }}">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%232f5597'/><text x='16' y='22' font-size='17' font-family='sans-serif' font-weight='700' fill='white' text-anchor='middle'>업</text></svg>">
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
                // The panel arrives as markup, so Alpine has to adopt the subtree.
                this.$nextTick(() => window.Alpine && window.Alpine.initTree(this.$el.querySelector('.drawer__body')));
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

        openCreate() { this.create.open = true; this.$nextTick(() => { const f = this.$el.querySelector('#new-issue-summary'); if (f) f.focus(); }); },
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
</script>
@stack('scripts')
</body>
</html>
