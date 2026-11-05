@php
    $statusByKey = collect($statuses)->keyBy('key');
    $priorityByKey = collect($priorities)->keyBy('key');
    $isLate = fn ($issue) => $issue->isLateOn(now());
@endphp

<div class="table-wrap">
    @if ($issues->isEmpty())
        <div class="empty">
            <h3>{{ __('ui.board.empty') }}</h3>
            <p>{{ __('ui.board.empty_hint') }}</p>
            <button class="btn btn--primary" x-on:click="$dispatch('open-create')">+ {{ __('ui.board.create') }}</button>
        </div>
    @else
        <table class="grid">
            <thead>
            <tr>
                <th style="width:46px" class="num">{{ __('ui.list.no') }}</th>
                <th style="width:86px">{{ __('ui.list.key') }}</th>
                <th>{{ __('ui.list.summary') }}</th>
                <th style="width:120px">{{ __('ui.list.assignee') }}</th>
                <th style="width:88px">{{ __('ui.list.reporter') }}</th>
                <th style="width:92px">{{ __('ui.list.priority') }}</th>
                <th style="width:92px">{{ __('ui.list.status') }}</th>
                <th style="width:96px" class="num">{{ __('ui.list.percent') }}</th>
                <th style="width:88px" class="num">{{ __('ui.list.starts') }}</th>
                <th style="width:88px" class="num">{{ __('ui.list.due') }}</th>
                <th style="width:130px">{{ __('ui.list.note') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($issues as $i => $issue)
                <tr class="row row--{{ $issue->status_key }} {{ $isLate($issue) ? 'row--late' : '' }}"
                    data-issue-id="{{ $issue->id }}" data-status="{{ $issue->status_key }}"
                    x-on:click="$dispatch('open-issue', { url: '{{ route('issues.show', $issue) }}' })">
                    <td class="num muted tnum">{{ $i + 1 }}</td>
                    <td><span class="key">{{ $issue->issue_key }}</span></td>
                    <td class="summary-cell" title="{{ $issue->summary }}">{{ $issue->summary }}</td>
                    <td>
                        <span style="display:inline-flex;align-items:center;gap:5px">
                            @if ($issue->assignee)
                                <span class="avatar avatar--sm" style="background: {{ $issue->assignee->color }}">{{ $issue->assignee->initials() }}</span>
                            @endif
                            <span class="nowrap">{{ $issue->allAssigneeNames() ?: '—' }}</span>
                        </span>
                    </td>
                    <td class="nowrap">{{ $issue->reporter?->name ?? '—' }}</td>
                    <td>
                        <span class="chip chip--{{ $priorityByKey[$issue->priority_key]['tone'] ?? 'slate' }}">
                            {{ $priorityByKey[$issue->priority_key]['label'] ?? $issue->priority_key }}
                        </span>
                    </td>
                    <td>
                        <span class="chip chip--{{ $statusByKey[$issue->status_key]['tone'] ?? 'slate' }}">
                            {{ $statusByKey[$issue->status_key]['label'] ?? $issue->status_key }}
                        </span>
                    </td>
                    <td class="num tnum" style="font-weight:650">
                        {{ $issue->effectivePercent() }}%
                        <span class="row__bar" aria-hidden="true"><i style="width: {{ $issue->effectivePercent() }}%"></i></span>
                    </td>
                    <td class="num tnum muted">{{ $issue->starts_on?->format('m/d') ?? '—' }}</td>
                    <td class="num tnum {{ $isLate($issue) ? 'nowrap' : 'muted' }}"
                        style="{{ $isLate($issue) ? 'color:var(--red);font-weight:650' : '' }}">
                        {{ $issue->due_undecided ? __('ui.issue.due_undecided') : ($issue->due_on?->format('m/d') ?? '—') }}
                    </td>
                    <td class="muted" title="{{ $issue->note }}">{{ $issue->note }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
