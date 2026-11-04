@php
    $statusByKey = collect($statuses)->keyBy('key');
    $priorityByKey = collect($priorities)->keyBy('key');
    $grouped = $issues->groupBy(fn ($i) => $i->project?->name ?? '—');
    $order = collect($projects)->pluck('name')->all();
    $isLate = fn ($issue) => $issue->isLateOn(now());
@endphp

<div class="table-wrap">
    @if ($issues->isEmpty())
        <div class="empty">
            <h3>{{ __('ui.board.empty') }}</h3>
            <p>{{ __('ui.board.empty_hint') }}</p>
        </div>
    @else
        @foreach ($order as $projectName)
            @php $rows = $grouped[$projectName] ?? collect(); @endphp
            @continue($rows->isEmpty())

            <h2 style="font-size:13px;margin:18px 0 8px;color:var(--navy);display:flex;align-items:center;gap:8px">
                {{ $projectName }}
                <span class="column__count">{{ $rows->count() }}</span>
                <span class="tiny muted">
                    {{ __('ui.daily.avg_percent') }} {{ (int) round($rows->avg(fn ($i) => $i->effectivePercent()) ?? 0) }}%
                </span>
            </h2>

            <table class="grid" style="margin-bottom:6px">
                <tbody>
                @foreach ($rows as $issue)
                    <tr x-on:click="$dispatch('open-issue', { url: '{{ route('issues.show', $issue) }}' })">
                        <td style="width:86px"><span class="key">{{ $issue->issue_key }}</span></td>
                        <td style="width:96px">
                            <span class="chip chip--{{ $statusByKey[$issue->status_key]['tone'] ?? 'slate' }}">
                                {{ $statusByKey[$issue->status_key]['label'] ?? $issue->status_key }}
                            </span>
                        </td>
                        <td>{{ $issue->summary }}</td>
                        <td style="width:110px">{{ $issue->allAssigneeNames() ?: '—' }}</td>
                        <td style="width:80px">
                            <span class="chip chip--{{ $priorityByKey[$issue->priority_key]['tone'] ?? 'slate' }}">
                                {{ $priorityByKey[$issue->priority_key]['label'] ?? $issue->priority_key }}
                            </span>
                        </td>
                        <td style="width:70px" class="num tnum">{{ $issue->effectivePercent() }}%</td>
                        <td style="width:76px" class="num tnum {{ $isLate($issue) ? '' : 'muted' }}"
                            style="{{ $isLate($issue) ? 'color:var(--red);font-weight:650' : '' }}">
                            {{ $issue->due_undecided ? __('ui.issue.due_undecided') : ($issue->due_on?->format('m/d') ?? '—') }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endforeach
    @endif
</div>
