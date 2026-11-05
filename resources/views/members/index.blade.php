@extends('layouts.app')

@section('title', __('ui.members.title'))

@section('content')
    <div class="toolbar">
        <span class="toolbar__title">■ {{ __('ui.members.title') }}</span>
        <span class="chip chip--slate tnum">{{ $members->count() }}</span>
        <span class="tiny muted">{{ __('ui.members.subtitle') }}</span>
    </div>

    @if ($errors->any())
        <div class="flash" role="alert" style="background:var(--red-bg);color:var(--red)">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form class="card" method="post" action="{{ route('members.store') }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px">
        @csrf
        <label style="flex:1 1 200px">
            <span class="tiny muted">{{ __('ui.members.name') }}</span>
            <input class="input" name="name" required maxlength="120" placeholder="홍길동">
        </label>
        <label style="flex:0 1 180px">
            <span class="tiny muted">{{ __('ui.members.key') }}</span>
            <input class="input" name="key" maxlength="64" placeholder="hong-gildong">
        </label>
        <label style="flex:0 1 120px">
            <span class="tiny muted">{{ __('ui.members.title_field') }}</span>
            <input class="input" name="title" maxlength="60" placeholder="선임">
        </label>
        <label style="flex:0 1 150px">
            <span class="tiny muted">{{ __('ui.members.color') }}</span>
            <select class="select" name="color">
                @foreach ($colors as $color)
                    <option value="{{ $color }}" @selected($loop->first)>{{ $color }}</option>
                @endforeach
            </select>
        </label>
        <button class="btn btn--primary" type="submit">+ {{ __('ui.members.add') }}</button>
    </form>

    <div class="table-wrap">
        <table class="grid">
            <thead>
            <tr>
                <th>{{ __('ui.members.name') }}</th>
                <th style="width:130px">{{ __('ui.members.key') }}</th>
                <th style="width:90px">{{ __('ui.members.title_field') }}</th>
                <th style="width:120px">{{ __('ui.members.department') }}</th>
                <th style="width:74px" class="num">{{ __('ui.members.issues') }}</th>
                <th style="width:86px">{{ __('ui.members.sso') }}</th>
                <th style="width:86px">{{ __('ui.common.status') }}</th>
                <th style="width:200px"></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($members as $member)
                <tr class="{{ $member->active ? '' : 'is-muted' }}">
                    <td>
                        <span style="display:inline-flex;align-items:center;gap:7px">
                            <x-avatar :member="$member" size="sm" />
                            <span style="font-weight:650">{{ $member->name }}</span>
                            @if ($member->is_external)
                                <span class="chip chip--slate">{{ __('ui.issue.external') }}</span>
                            @endif
                            @if ($member->id === $me->id)
                                <span class="chip chip--blue">{{ __('ui.members.you') }}</span>
                            @endif
                        </span>
                    </td>
                    <td class="mono small muted">{{ $member->key }}</td>
                    <td class="small">{{ $member->title ?: '—' }}</td>
                    <td class="small muted">{{ $member->department ?: '—' }}</td>
                    <td class="num tnum">{{ $member->issueCount() }}</td>
                    <td class="small muted">{{ $member->oidc_subject ? __('ui.members.linked') : '—' }}</td>
                    <td>
                        <span class="chip {{ $member->active ? 'chip--green' : 'chip--slate' }}">
                            {{ $member->active ? __('ui.members.active') : __('ui.members.inactive') }}
                        </span>
                    </td>
                    <td>
                        @php($history = $member->referenceCount())
                        <div style="display:flex;gap:6px;justify-content:flex-end;align-items:center">
                            <form method="post" action="{{ route('members.update', $member) }}">
                                @csrf @method('PATCH')
                                <input type="hidden" name="active" value="{{ $member->active ? 0 : 1 }}">
                                <button class="btn btn--sm" type="submit">
                                    {{ $member->active ? __('ui.members.deactivate') : __('ui.members.activate') }}
                                </button>
                            </form>

                            <form method="post" action="{{ route('members.destroy', $member) }}"
                                  x-on:submit.prevent="if (window.confirm(@js(__('ui.members.delete_confirm', ['name' => $member->name])))) $el.submit()">
                                @csrf @method('DELETE')
                                <button class="btn btn--sm" type="submit"
                                        style="color:var(--red)"
                                        @disabled($history > 0 || $member->id === $me->id)
                                        title="{{ $history > 0 ? __('ui.members.has_history_short') : __('ui.members.delete') }}">
                                    {{ __('ui.members.delete') }}
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <p class="tiny muted" style="margin-top:12px">{{ __('ui.members.hint') }}</p>
@endsection
