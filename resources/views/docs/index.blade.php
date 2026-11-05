@extends('layouts.app')

@section('title', __('ui.docs.title'))

@section('content')
    <div class="toolbar">
        <span class="toolbar__title">■ {{ __('ui.docs.title') }}</span>
        <span class="chip chip--slate tnum">{{ $documents->count() }}</span>
        <span class="tiny muted">{{ __('ui.docs.subtitle') }}</span>
    </div>

    <form class="card" method="post" action="{{ route('docs.store') }}" style="display:flex;gap:8px;align-items:flex-end;margin-bottom:16px;flex-wrap:wrap">
        @csrf
        <label style="flex:1 1 320px">
            <span class="tiny muted">{{ __('ui.docs.new_title') }}</span>
            <input class="input" name="title" required maxlength="160" placeholder="{{ __('ui.docs.new_placeholder') }}">
        </label>
        <label>
            <span class="tiny muted">{{ __('ui.docs.visibility') }}</span>
            <select class="select" name="visibility">
                <option value="team">{{ __('ui.docs.visibility_team') }}</option>
                <option value="private">{{ __('ui.docs.visibility_private') }}</option>
            </select>
        </label>
        <button class="btn btn--primary" type="submit">+ {{ __('ui.docs.create') }}</button>
    </form>

    @if ($documents->isEmpty())
        <div class="empty">
            <h3>{{ __('ui.docs.empty') }}</h3>
            <p>{{ __('ui.docs.empty_hint') }}</p>
        </div>
    @else
        <div class="table-wrap">
            <table class="grid">
                <thead>
                <tr>
                    <th>{{ __('ui.docs.col_title') }}</th>
                    <th style="width:150px">{{ __('ui.docs.col_owner') }}</th>
                    <th style="width:110px">{{ __('ui.docs.col_visibility') }}</th>
                    <th style="width:96px" class="num">{{ __('ui.docs.col_revision') }}</th>
                    <th style="width:150px">{{ __('ui.docs.col_updated') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($documents as $document)
                    <tr x-on:click="window.location = '{{ route('docs.show', $document) }}'" style="cursor:pointer">
                        <td>
                            <span style="font-weight:650">{{ $document->title }}</span>
                            <div class="tiny muted">{{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::of($document->body)->replaceMatches('/[#*`>\-|]/', ' ')->squish(), 110) ?: __('ui.docs.no_body') }}</div>
                        </td>
                        <td>
                            <span style="display:inline-flex;align-items:center;gap:5px">
                                <x-avatar :member="$document->owner" size="sm" />
                                {{ $document->owner?->name ?? '—' }}
                            </span>
                        </td>
                        <td>
                            <span class="chip chip--{{ $document->isPrivate() ? 'amber' : 'blue' }}">
                                {{ $document->isPrivate() ? __('ui.docs.visibility_private') : __('ui.docs.visibility_team') }}
                            </span>
                        </td>
                        <td class="num tnum">r{{ $document->revision }}</td>
                        <td class="tiny muted">{{ $document->updated_at?->diffForHumans(short: true) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
