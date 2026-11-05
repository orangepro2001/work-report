@extends('layouts.app')

@section('title', __('ui.daily.title'))

@section('content')
    {{-- Same live region contract as the board: the drawer asks for this fragment
         after a save, so the counters, the summary block and the rows all follow
         a status change without a reload. --}}
    <div data-live="daily">
        @fragment('daily')
        @php
            $statusByKey = collect(config('workreport.statuses'))->keyBy('key');
            $priorityByKey = collect(config('workreport.priorities'))->keyBy('key');
            $isLate = fn ($issue) => $issue->isLateOn($date);
            $prev = $date->subDay()->toDateString();
            $next = $date->addDay()->toDateString();
            $rows = $issues;
            // The workbook groups buckets in a fixed order and separates groups with a
            // heavier rule; reproduce that with a running index.
            $groupStarts = [];
            $seen = null;
            foreach ($rows as $idx => $issue) {
                $name = $issue->project?->name;
                if ($name !== $seen) { $groupStarts[$idx] = true; $seen = $name; }
            }
        @endphp

        <div class="toolbar">
            <span class="toolbar__title">■ {{ __('ui.daily.title') }}</span>
            <span class="chip chip--blue tnum">{{ $date->format('Y-m-d') }}</span>
            <span class="tiny muted">{{ $date->locale('ko')->isoFormat('ddd') }}</span>

            <div class="seg" style="margin-left:6px">
                <a href="{{ route('daily', ['date' => $prev]) }}" title="{{ __('ui.daily.prev_day') }}">‹</a>
                <a href="{{ route('daily', ['date' => now()->toDateString()]) }}" class="{{ $date->isToday() ? 'is-active' : '' }}">
                    {{ __('ui.daily.today') }}
                </a>
                <a href="{{ route('daily', ['date' => $next]) }}" title="{{ __('ui.daily.next_day') }}">›</a>
            </div>

            <form method="get" action="{{ route('daily') }}" style="display:flex;gap:6px">
                <input class="input tnum" type="date" name="date" value="{{ $date->toDateString() }}"
                       style="width:auto" onchange="this.form.submit()">
            </form>

            <div class="topbar__spacer"></div>

            <button class="btn btn--sm" type="button" onclick="window.print()">🖨 {{ __('ui.daily.print') }}</button>
            <a class="btn btn--sm btn--primary" href="{{ route('export', ['date' => $date->toDateString()]) }}">
                ⤓ {{ __('ui.daily.download') }}
            </a>
        </div>

        <div class="sheet">
            <div class="sheet__paper">
                <h1 class="sheet__title">
                    {{ config('workreport.title_prefix') }} / {{ $date->format('ymd') }}
                </h1>

                <div class="sheet__meta">
                    <span>
                        <span class="k">{{ __('ui.daily.report_date') }}</span>
                        <span class="v v--date">{{ $date->format('Y-m-d') }} ({{ $date->locale('ko')->isoFormat('ddd') }})</span>
                    </span>
                    <span>
                        <span class="k">{{ __('ui.daily.department') }}</span>
                        <span class="v">{{ config('workreport.department') }}</span>
                    </span>
                    <span>
                        <span class="k">{{ __('ui.daily.author') }}</span>
                        <span class="v">{{ $member?->name ?? '—' }}</span>
                    </span>
                </div>

                <div class="summary-strip">
                    <div class="stat">
                        <div class="stat__label">{{ __('ui.daily.total') }}</div>
                        <div class="stat__value">{{ $stats['total'] }}<small>{{ __('ui.board.count', ['count' => ''] ) ? '' : '' }}</small></div>
                    </div>
                    <div class="stat">
                        <div class="stat__label">{{ __('ui.daily.done') }}</div>
                        <div class="stat__value" style="color:var(--green)">{{ $stats['done'] }}</div>
                    </div>
                    <div class="stat">
                        <div class="stat__label">{{ __('ui.daily.late') }}</div>
                        <div class="stat__value" style="color:var(--red)">{{ $stats['late'] }}</div>
                    </div>
                    <div class="stat">
                        <div class="stat__label">{{ __('ui.daily.completion') }}</div>
                        <div class="stat__value">
                            {{ $stats['total'] > 0 ? (int) round($stats['done'] / $stats['total'] * 100) : 0 }}<small>%</small>
                        </div>
                    </div>
                </div>

                <h2>{{ __('ui.daily.summary_title') }}</h2>

                {{-- Same columns as workbook rows 6-11. --}}
                <table class="grid grid--sheet">
                    <thead>
                    <tr>
                        <th style="width:110px">{{ __('ui.daily.bucket') }}</th>
                        <th class="c" style="width:70px">{{ __('ui.daily.completion') }}</th>
                        <th class="c" style="width:56px">{{ __('ui.daily.total') }}</th>
                        <th class="c" style="width:56px">{{ __('ui.daily.planned') }}</th>
                        <th class="c" style="width:62px">{{ __('ui.daily.in_progress') }}</th>
                        <th class="c" style="width:56px">{{ __('ui.daily.done') }}</th>
                        <th class="c" style="width:70px">{{ __('ui.daily.avg_percent') }}</th>
                        <th class="c" style="width:56px">{{ __('ui.daily.on_hold') }}</th>
                        <th class="c" style="width:56px">{{ __('ui.daily.late') }}</th>
                        <th class="c" style="width:76px">{{ __('ui.daily.high_open') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($stats['buckets'] as $bucket)
                        <tr>
                            <td class="l" style="font-weight:650">{{ $bucket['label'] }}</td>
                            <td class="c tnum">{{ $bucket['total'] > 0 ? (int) round($bucket['done'] / $bucket['total'] * 100) : 0 }}%</td>
                            <td class="c tnum">{{ $bucket['total'] }}</td>
                            <td class="c tnum">{{ $bucket['planned'] ?: '-' }}</td>
                            <td class="c tnum">{{ $bucket['in_progress'] ?: '-' }}</td>
                            <td class="c tnum">{{ $bucket['done'] ?: '-' }}</td>
                            <td class="c tnum">{{ $bucket['avg_percent'] }}%</td>
                            <td class="c tnum">{{ $bucket['on_hold'] ?: '-' }}</td>
                            <td class="c tnum" style="{{ $bucket['late'] ? 'color:var(--red);font-weight:650' : '' }}">
                                {{ $bucket['late'] ?: '-' }}
                            </td>
                            <td class="c tnum">{{ $bucket['high_open'] ?: '-' }}</td>
                        </tr>
                    @endforeach
                    <tr style="background:var(--slate-bg);font-weight:700">
                        <td class="l">{{ __('ui.daily.sum') }}</td>
                        <td class="c tnum">{{ $stats['total'] > 0 ? (int) round($stats['done'] / $stats['total'] * 100) : 0 }}%</td>
                        <td class="c tnum">{{ $stats['total'] }}</td>
                        <td class="c tnum">{{ collect($stats['buckets'])->sum('planned') ?: '-' }}</td>
                        <td class="c tnum">{{ collect($stats['buckets'])->sum('in_progress') ?: '-' }}</td>
                        <td class="c tnum">{{ $stats['done'] ?: '-' }}</td>
                        <td class="c tnum">{{ (int) round($issues->avg(fn ($i) => $i->effectivePercent()) ?? 0) }}%</td>
                        <td class="c tnum">{{ collect($stats['buckets'])->sum('on_hold') ?: '-' }}</td>
                        <td class="c tnum" style="{{ $stats['late'] ? 'color:var(--red)' : '' }}">{{ $stats['late'] ?: '-' }}</td>
                        <td class="c tnum">{{ collect($stats['buckets'])->sum('high_open') ?: '-' }}</td>
                    </tr>
                    </tbody>
                </table>

                <h2>{{ __('ui.daily.list_title') }}</h2>

                @if ($rows->isEmpty())
                    <div class="empty">
                        <h3>{{ __('ui.daily.no_issues') }}</h3>
                        <p>{{ __('ui.daily.no_issues_hint') }}</p>
                        <button class="btn btn--primary" x-on:click="$dispatch('open-create')">+ {{ __('ui.board.create') }}</button>
                    </div>
                @else
                    <table class="grid grid--sheet">
                        <thead>
                        <tr>
                            <th class="c" style="width:44px">{{ __('ui.list.no') }}</th>
                            <th style="width:80px">{{ __('ui.list.key') }}</th>
                            <th style="width:96px">{{ __('ui.list.bucket') ?? __('ui.daily.bucket') }}</th>
                            <th class="w-summary">{{ __('ui.list.summary') }}</th>
                            <th style="width:118px">{{ __('ui.list.assignee') }}</th>
                            <th style="width:78px">{{ __('ui.list.reporter') }}</th>
                            <th class="c" style="width:80px">{{ __('ui.list.priority') }}</th>
                            <th class="c" style="width:78px">{{ __('ui.list.status') }}</th>
                            <th class="c" style="width:98px">{{ __('ui.list.percent') }}</th>
                            <th class="c" style="width:78px">{{ __('ui.list.starts') }}</th>
                            <th class="c" style="width:78px">{{ __('ui.list.due') }}</th>
                            <th class="w-note">{{ __('ui.list.note') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($rows as $idx => $issue)
                            <tr class="{{ isset($groupStarts[$idx]) && $idx > 0 ? 'group-start' : '' }} {{ $issue->status_key === 'done' ? 'is-done' : '' }} {{ $isLate($issue) ? 'is-late' : '' }}"
                                data-issue-id="{{ $issue->id }}" data-status="{{ $issue->status_key }}"
                                x-on:click="$dispatch('open-issue', { url: '{{ route('issues.show', $issue) }}' })" style="cursor:pointer">
                                <td class="c tnum muted">{{ $idx + 1 }}</td>
                                <td><span class="key">{{ $issue->issue_key }}</span></td>
                                <td>{{ $issue->project?->name }}</td>
                                <td class="l">{{ $issue->summary }}</td>
                                <td>{{ $issue->allAssigneeNames() ?: '—' }}</td>
                                <td>{{ $issue->reporter?->name ?? '—' }}</td>
                                <td class="c">
                                    <span class="chip chip--{{ $priorityByKey[$issue->priority_key]['tone'] ?? 'slate' }}">
                                        {{ $priorityByKey[$issue->priority_key]['label'] ?? $issue->priority_key }}
                                    </span>
                                </td>
                                <td class="c">
                                    <span class="chip chip--{{ $statusByKey[$issue->status_key]['tone'] ?? 'slate' }}">
                                        {{ $statusByKey[$issue->status_key]['label'] ?? $issue->status_key }}
                                    </span>
                                </td>
                                <td class="c">
                                    <span class="bar">
                                        <span class="bar__track"><i class="{{ $issue->status_key === 'done' ? 'done' : '' }}"
                                                 style="width:{{ $issue->effectivePercent() }}%"></i></span>
                                        <span class="bar__val tnum">{{ $issue->effectivePercent() }}%</span>
                                    </span>
                                </td>
                                <td class="c tnum">{{ $issue->starts_on?->format('m/d') ?? '—' }}</td>
                                <td class="c tnum {{ $issue->due_undecided ? 'undecided' : ($isLate($issue) ? 'late' : '') }}">
                                    {{ $issue->due_undecided ? __('ui.daily.undecided') : ($issue->due_on?->format('m/d') ?? '—') }}
                                </td>
                                <td class="w-note">{{ $issue->note }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
        @endfragment
    </div>
@endsection
