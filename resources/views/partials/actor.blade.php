@php
    /** @var \App\Models\Member|null $actor */
    $actor = app(\App\Contracts\ActorResolver::class)->current();
    $allMembers = \App\Models\Member::active()->get(['id', 'name', 'color']);
@endphp

<div class="menu" x-data="{ open: false }" x-on:click.outside="open = false">
    <button class="btn btn--ghost" x-on:click="open = !open" title="{{ __('ui.actor.hint') }}">
        @if ($actor)
            <span class="avatar" style="background: {{ $actor->color }}">{{ $actor->initials() }}</span>
            {{ $actor->name }}
        @else
            {{ __('ui.actor.label') }}
        @endif
        <span style="color:var(--faint);font-size:10px">▾</span>
    </button>

    <div class="menu__panel" x-show="open" x-cloak x-transition.opacity>
        <div class="tiny muted" style="padding:4px 8px 6px">{{ __('ui.actor.hint') }}</div>
        @foreach ($allMembers as $member)
            <form method="post" action="{{ route('actor.switch') }}">
                @csrf
                <input type="hidden" name="member_id" value="{{ $member->id }}">
                <button class="menu__item" type="submit">
                    <span class="avatar avatar--sm" style="background: {{ $member->color }}">{{ $member->initials() }}</span>
                    {{ $member->name }}
                    @if ($actor && $actor->id === $member->id)
                        <span style="margin-left:auto;color:var(--blue)">✓</span>
                    @endif
                </button>
            </form>
        @endforeach
    </div>
</div>
