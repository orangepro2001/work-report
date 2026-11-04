@php
    $currentRoute = request()->routeIs('board.list') ? 'board.list'
        : (request()->routeIs('board.backlog') ? 'board.backlog' : 'board.index');
    $activeFilters = array_filter($filters ?? [], fn ($v) => $v !== null && $v !== '');
@endphp

<div class="toolbar">
    <span class="toolbar__title">{{ __('ui.board.title') }}</span>

    <div class="seg" role="tablist">
        <a href="{{ route('board.index', array_filter(['project' => $filters['project'] ?? null])) }}"
           class="{{ $lens === 'board' ? 'is-active' : '' }}" role="tab">{{ __('ui.nav.board') }}</a>
        <a href="{{ route('board.list', array_filter(['project' => $filters['project'] ?? null])) }}"
           class="{{ $lens === 'list' ? 'is-active' : '' }}" role="tab">{{ __('ui.nav.list') }}</a>
        <a href="{{ route('board.backlog', array_filter(['project' => $filters['project'] ?? null])) }}"
           class="{{ $lens === 'backlog' ? 'is-active' : '' }}" role="tab">{{ __('ui.nav.backlog') }}</a>
    </div>

    <form method="get" action="{{ route($currentRoute) }}" style="display:flex;gap:6px;align-items:center">
        <select class="select" name="assignee" style="width:auto" onchange="this.form.submit()"
                aria-label="{{ __('ui.board.filter_assignee') }}">
            <option value="">{{ __('ui.board.filter_assignee') }}: {{ __('ui.common.all') }}</option>
            @foreach ($members as $member)
                <option value="{{ $member->id }}" @selected(($filters['assignee'] ?? null) === $member->id)>{{ $member->name }}</option>
            @endforeach
        </select>

        <select class="select" name="priority" style="width:auto" onchange="this.form.submit()"
                aria-label="{{ __('ui.board.filter_priority') }}">
            <option value="">{{ __('ui.board.filter_priority') }}: {{ __('ui.common.all') }}</option>
            @foreach ($priorities as $priority)
                <option value="{{ $priority['key'] }}" @selected(($filters['priority'] ?? null) === $priority['key'])>{{ $priority['label'] }}</option>
            @endforeach
        </select>

        @if ($filters['project'] ?? null)
            <input type="hidden" name="project" value="{{ $filters['project'] }}">
        @endif
        @if ($filters['q'] ?? null)
            <input type="hidden" name="q" value="{{ $filters['q'] }}">
        @endif
    </form>

    <div class="topbar__spacer"></div>

    <span class="muted tiny tnum">{{ __('ui.board.count', ['count' => $issues->count()]) }}</span>

    @if ($activeFilters !== [])
        <a class="btn btn--ghost btn--sm" href="{{ route($currentRoute) }}">{{ __('ui.board.clear_filters') }}</a>
    @endif

    <a class="btn btn--sm" href="{{ route('export', array_filter(['date' => request('date')])) }}">
        ⤓ {{ __('ui.nav.export') }}
    </a>
</div>
