@extends('layouts.app')

@section('title', $document->title)

@section('content')
    @php
        $spec = [
            'revision' => $document->revision,
            'editable' => $editable,
            'stateUrl' => route('docs.state', $document),
            'saveUrl' => route('docs.update', $document),
            'labelSaved' => __('ui.docs.saved'),
            'labelConflict' => __('ui.docs.conflict'),
            'labelError' => __('ui.docs.save_failed'),
            'readers' => $readers->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'color' => $m->color])->values(),
        ];
    @endphp

    <div x-data="documentEditor({{ $document->id }}, {{ \Illuminate\Support\Js::from($spec) }})">
        <div class="toolbar">
            <span class="toolbar__title">■ {{ __('ui.docs.title') }}</span>
            <a class="btn btn--ghost btn--sm" href="{{ route('docs.index') }}">← {{ __('ui.docs.back') }}</a>
            <span class="chip chip--{{ $document->isPrivate() ? 'amber' : 'blue' }}">
                {{ $document->isPrivate() ? __('ui.docs.visibility_private') : __('ui.docs.visibility_team') }}
            </span>
            <span class="tiny muted" x-text="status" style="font-variant-numeric:tabular-nums"></span>
            <div style="flex:1"></div>
            <template x-for="reader in readers" :key="reader.id">
                <span class="avatar avatar--sm" :style="'background: ' + reader.color" :title="reader.name" x-text="reader.name.slice(0, 1)"></span>
            </template>
        </div>

        {{-- Someone else saved while this browser had unsaved text: nothing is
             overwritten, the newest version is offered instead. --}}
        <div id="doc-conflict" class="flash" role="alert"
             style="display:none;align-items:center;gap:10px;background:var(--amber-bg);color:var(--amber)">
            <strong>{{ __('ui.docs.conflict_title') }}</strong>
            <span class="tiny" x-text="status"></span>
            <button class="btn btn--sm" type="button" x-on:click="reload()">{{ __('ui.docs.reload') }}</button>
        </div>

        <div style="display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:16px;align-items:start">
            <div class="card" style="padding:14px">
                @if ($read)
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                        <h1 class="h2" style="margin:0">{{ $document->title }}</h1>
                        @if ($editable)
                            <a class="btn btn--ghost btn--sm" style="margin-left:auto" href="{{ route('docs.show', $document) }}">
                                {{ __('ui.docs.edit') }}
                            </a>
                        @endif
                    </div>
                    <article class="markdown" id="doc-read">{!! \App\Support\Markdown::toHtml($document->body) ?: '<p class="muted">'.e(__('ui.docs.no_body')).'</p>' !!}</article>
                @else
                    <input class="input" id="doc-title" value="{{ $document->title }}" maxlength="160"
                           style="font-size:16px;font-weight:700;margin-bottom:10px"
                           x-on:blur="save(true)">
                    <a class="btn btn--ghost btn--sm" style="float:right;margin-top:-38px"
                       href="{{ route('docs.show', $document) }}?read=1">{{ __('ui.docs.view') }}</a>
                    <textarea class="textarea" id="doc-body" rows="24" data-markdown data-field="body"
                              data-document-id="{{ $document->id }}"
                              placeholder="{{ __('ui.docs.body_placeholder') }}">{{ $document->body }}</textarea>
                    <div class="tiny muted" style="margin-top:8px">{{ __('ui.docs.editor_hint') }}</div>
                @endif
            </div>

            <aside style="display:flex;flex-direction:column;gap:12px">
                <div class="card" style="padding:12px">
                    <div class="tiny muted">{{ __('ui.docs.revisions') }}</div>
                    <ul style="list-style:none;margin:6px 0 0;padding:0;display:flex;flex-direction:column;gap:6px;max-height:240px;overflow:auto">
                        @foreach ($revisions as $revision)
                            <li class="tiny" style="display:flex;align-items:baseline;gap:6px">
                                <span class="tnum" style="font-weight:650">r{{ $revision->revision }}</span>
                                <span class="muted">{{ $revision->editor?->name ?? '—' }}</span>
                                <span class="muted" style="margin-left:auto">{{ $revision->created_at?->diffForHumans(short: true) }}</span>
                                @if ($editable && $revision->revision !== $document->revision)
                                    <form method="post" action="{{ route('docs.restore', [$document, $revision->revision]) }}">
                                        @csrf
                                        <button class="btn btn--sm btn--ghost" type="submit">{{ __('ui.docs.restore') }}</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>

                @if ($document->owner_id === $me->id)
                    <div class="card" style="padding:12px">
                        <div class="tiny muted">{{ __('ui.docs.sharing') }}</div>
                        <ul style="list-style:none;margin:6px 0;padding:0;display:flex;flex-direction:column;gap:4px">
                            @forelse ($document->shares as $share)
                                <li class="tiny" style="display:flex;align-items:center;gap:6px">
                                    <span class="avatar avatar--sm" style="background: {{ $share->member?->color }}">{{ $share->member?->initials() }}</span>
                                    {{ $share->member?->name }}
                                    <span class="muted">{{ $share->role }}</span>
                                    <form method="post" action="{{ route('docs.unshare', [$document, $share->member_id]) }}" style="margin-left:auto">
                                        @csrf @method('DELETE')
                                        <button class="btn btn--sm btn--ghost" type="submit">✕</button>
                                    </form>
                                </li>
                            @empty
                                <li class="tiny muted">{{ __('ui.docs.no_shares') }}</li>
                            @endforelse
                        </ul>
                        <form method="post" action="{{ route('docs.share', $document) }}" style="display:flex;gap:6px">
                            @csrf
                            <select class="select" name="member_id" style="flex:1;height:28px;font-size:12px">
                                @foreach ($members->whereNotIn('id', array_merge($document->shares->pluck('member_id')->all(), [$document->owner_id])) as $member)
                                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                                @endforeach
                            </select>
                            <select class="select" name="role" style="width:82px;height:28px;font-size:12px">
                                <option value="editor">{{ __('ui.docs.role_editor') }}</option>
                                <option value="viewer">{{ __('ui.docs.role_viewer') }}</option>
                            </select>
                            <button class="btn btn--sm" type="submit">+</button>
                        </form>
                    </div>
                @endif
            </aside>
        </div>
    </div>
@endsection

@push('scripts')
<script>
/*
 * One document's editor state.
 *
 * Saving is optimistic concurrency: the request carries the revision this browser
 * started from, and a 409 means somebody saved first. Nothing is overwritten in
 * that case — the newest text is written into the banner and offered instead.
 *
 * The same poll is the presence heartbeat: it renews this member's row and returns
 * who else has the document open, which is what the avatars in the toolbar show.
 * When the Yjs service lands, the body stops travelling through this poll (the
 * CRDT carries keystrokes); the revision history and the presence list stay here.
 */
function documentEditor(id, spec) {
    return {
        revision: spec.revision,
        editable: spec.editable,
        status: 'r' + spec.revision,
        dirty: false,
        readers: spec.readers,
        timer: null,

        init() {
            setInterval(() => this.poll(), 2500);

            document.addEventListener('mde-change', (e) => {
                if (e.target.id !== 'doc-body') return;
                this.dirty = true;
                this.queueSave();
            });
        },

        editor() {
            const el = document.getElementById('doc-body');
            return el && el._mde ? el._mde : null;
        },

        body() {
            const editor = this.editor();
            return editor ? editor.value() : (document.getElementById('doc-body')?.value ?? '');
        },

        title() {
            return document.getElementById('doc-title')?.value ?? '';
        },

        setBody(text) {
            const editor = this.editor();
            if (editor) { editor.value(text); } else { document.getElementById('doc-body').value = text; }
        },

        queueSave() {
            if (!this.editable) return;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.save(false), 1200);
        },

        async save(explicit) {
            if (!this.editable) return;

            const res = await fetch(spec.saveUrl, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ title: this.title(), body: this.body(), revision: this.revision }),
            });

            if (res.status === 409) {
                const data = await res.json();
                this.status = spec.labelConflict + (data.edited_by ? ' — ' + data.edited_by : '');
                this.offerReload(data);
                return;
            }

            if (!res.ok) {
                this.status = spec.labelError;
                return;
            }

            const data = await res.json();
            if (!data.unchanged) this.revision = data.revision;
            this.dirty = false;
            this.status = explicit ? spec.labelSaved + ' r' + this.revision : 'r' + this.revision;
            setTimeout(() => { if (this.status.startsWith(spec.labelSaved)) this.status = 'r' + this.revision; }, 1800);
        },

        offerReload(data) {
            const banner = document.getElementById('doc-conflict');
            if (!banner) return;
            banner.style.display = 'flex';
            banner.dataset.body = data.body ?? '';
            banner.dataset.title = data.title ?? '';
            banner.dataset.revision = data.revision ?? this.revision;
        },

        reload() {
            const banner = document.getElementById('doc-conflict');
            if (!banner) return;
            const title = document.getElementById('doc-title');
            if (title) title.value = banner.dataset.title;
            this.setBody(banner.dataset.body);
            this.revision = Number(banner.dataset.revision);
            this.dirty = false;
            banner.style.display = 'none';
            this.status = 'r' + this.revision;
        },

        async poll() {
            const res = await fetch(spec.stateUrl, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) return;

            const data = await res.json();
            this.readers = data.readers || [];

            if (data.revision <= this.revision) return;

            if (this.dirty) {
                // Keep what is being typed; offer the newer text instead of applying it.
                this.status = spec.labelConflict;
                this.offerReload(data);
                return;
            }

            this.setBody(data.body ?? '');
            const title = document.getElementById('doc-title');
            if (title && document.activeElement !== title) title.value = data.title ?? title.value;
            this.revision = data.revision;
            this.status = 'r' + this.revision + ' ← ' + (data.edited_by || '');
        },
    };
}
</script>
@endpush
