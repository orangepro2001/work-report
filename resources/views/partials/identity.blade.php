@php
    /** @var \App\Models\Member|null $actor */
    $actor = app(\App\Contracts\ActorResolver::class)->current();
@endphp

<div class="menu" x-data="{ open: false }" x-on:click.outside="open = false">
    <button class="btn btn--ghost" x-on:click="open = !open" title="{{ $actor?->email }}">
        <span class="avatar" style="background: {{ $actor?->color }}">{{ $actor?->initials() }}</span>
        {{ $actor?->name ?? __('ui.identity.label') }}
        <span style="color:var(--faint);font-size:10px">▾</span>
    </button>

    <div class="menu__panel" x-show="open" x-cloak x-transition.opacity>
        <div class="tiny muted" style="padding:4px 8px 6px">{{ __('ui.identity.hint') }}</div>
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button class="menu__item" type="submit">{{ __('ui.identity.logout') }}</button>
        </form>
    </div>
</div>
