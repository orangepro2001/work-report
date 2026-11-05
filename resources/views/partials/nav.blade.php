@php
    $projects = $projects ?? collect();
    $routeLens = request()->routeIs('board.list') ? 'list' : (request()->routeIs('board.backlog') ? 'backlog' : 'board');
    $projectId = request()->integer('project');
@endphp

<nav class="nav" aria-label="{{ __('ui.nav.work') }}">
    <div class="nav__group">{{ __('ui.nav.work') }}</div>

    <a class="nav__item {{ $routeLens === 'board' ? 'is-active' : '' }}" href="{{ route('board.index', array_filter(['project' => $projectId])) }}">
        <span aria-hidden="true">▦</span> {{ __('ui.nav.board') }}
    </a>
    <a class="nav__item {{ $routeLens === 'list' ? 'is-active' : '' }}" href="{{ route('board.list', array_filter(['project' => $projectId])) }}">
        <span aria-hidden="true">☰</span> {{ __('ui.nav.list') }}
    </a>
    <a class="nav__item {{ $routeLens === 'backlog' ? 'is-active' : '' }}" href="{{ route('board.backlog', array_filter(['project' => $projectId])) }}">
        <span aria-hidden="true">▤</span> {{ __('ui.nav.backlog') }}
    </a>

    <div class="nav__group">{{ __('ui.nav.reports') }}</div>

    <a class="nav__item {{ request()->routeIs('daily') ? 'is-active' : '' }}" href="{{ route('daily') }}">
        <span aria-hidden="true">▣</span> {{ __('ui.nav.daily') }}
    </a>
    <a class="nav__item {{ request()->routeIs('docs.*') ? 'is-active' : '' }}" href="{{ route('docs.index') }}">
        <span aria-hidden="true">▤</span> {{ __('ui.nav.docs') }}
    </a>
    <a class="nav__item {{ request()->routeIs('members.*') ? 'is-active' : '' }}" href="{{ route('members.index') }}">
        <span aria-hidden="true">◕</span> {{ __('ui.nav.members') }}
    </a>
    <a class="nav__item" href="{{ route('export', array_filter(['date' => request('date')])) }}">
        <span aria-hidden="true">⤓</span> {{ __('ui.nav.export') }}
    </a>

    <div class="nav__group">{{ __('ui.nav.projects') }}</div>

    <a class="nav__item {{ ! $projectId ? 'is-active' : '' }}" href="{{ route(request()->routeIs('board.list') ? 'board.list' : 'board.index') }}">
        <span class="dot" style="background:var(--faint)"></span> {{ __('ui.nav.all_projects') }}
    </a>

    @foreach ($projects as $project)
        <a class="nav__item {{ $projectId === $project->id ? 'is-active' : '' }}"
           href="{{ route(request()->routeIs('board.list') ? 'board.list' : 'board.index', ['project' => $project->id]) }}">
            <span class="dot" style="background: {{ $project->color }}"></span>
            {{ $project->name }}
        </a>
    @endforeach
</nav>
