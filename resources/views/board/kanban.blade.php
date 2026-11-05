@php
    $date = $date ?? null;
    $isLate = fn ($issue) => $issue->isLateOn(now());
@endphp

<div class="board" x-data="board()" x-cloak>
    @foreach ($columns as $column)
        <section class="column column--{{ $column['key'] }}" aria-label="{{ $column['label'] }}">
            <div class="column__head">
                <span class="chip chip--{{ $column['tone'] }}">{{ $column['label'] }}</span>
                <span class="column__count">{{ $column['count'] }}</span>
                <span class="column__meter" title="{{ __('ui.daily.avg_percent') }}">{{ $column['percent'] }}%</span>
            </div>

            <div class="column__body"
                 data-status="{{ $column['key'] }}"
                 x-on:dragover="dragOver($event, $el)"
                 x-on:dragleave="if (!$el.contains($event.relatedTarget)) $el.classList.remove('is-over')"
                 x-on:drop="drop($event, $el)">

                @foreach ($column['issues'] as $issue)
                    <a class="card {{ $issue->status_key === 'done' ? 'card--done' : '' }} {{ $isLate($issue) ? 'card--late' : '' }}"
                       href="{{ route('issues.show', $issue) }}"
                       data-issue-id="{{ $issue->id }}"
                       data-issue-key="{{ $issue->issue_key }}"
                       data-status="{{ $issue->status_key }}"
                       draggable="true"
                       x-on:dragstart="dragStart($event, $el)"
                       x-on:dragend="dragEnd($event, $el)"
                       x-on:click.prevent="$dispatch('open-issue', { url: $el.href })">

                        <div class="card__top">
                            <span class="key">{{ $issue->issue_key }}</span>
                            <span class="dot" style="width:6px;height:6px;border-radius:50%;background:{{ $issue->project?->color }}"
                                  title="{{ $issue->project?->name }}"></span>
                            @if ($issue->priority_key === 'high')
                                <span class="chip chip--red" style="margin-left:auto">High</span>
                            @elseif ($issue->priority_key === 'medium')
                                <span class="chip chip--amber" style="margin-left:auto">Medium</span>
                            @endif
                        </div>

                        <div class="card__summary">{{ $issue->summary }}</div>

                        <div class="card__progress" aria-hidden="true">
                            <i style="width: {{ $issue->effectivePercent() }}%" @if ($issue->status_key === 'done') data-done @endif></i>
                        </div>

                        <div class="card__meta">
                            @if ($issue->assignee)
                                <span class="avatar avatar--sm" style="background: {{ $issue->assignee->color }}"
                                      title="{{ $issue->allAssigneeNames() }}">{{ $issue->assignee->initials() }}</span>
                            @endif
                            @if ($issue->coAssignees->isNotEmpty())
                                <span class="tiny muted">+{{ $issue->coAssignees->count() }}</span>
                            @endif

                            <span class="tiny muted tnum">{{ $issue->effectivePercent() }}%</span>

                            @if ($issue->due_undecided)
                                <span class="tiny muted">{{ __('ui.issue.due_undecided') }}</span>
                            @elseif ($issue->due_on)
                                <span class="tiny {{ $isLate($issue) ? 'nowrap' : 'muted' }} {{ $isLate($issue) ? '' : 'tnum' }}"
                                      style="{{ $isLate($issue) ? 'color:var(--red);font-weight:650' : '' }}">
                                    {{ $issue->due_on->format('m/d') }}
                                </span>
                            @endif

                            @if ($issue->comments_count ?? false)
                                <span class="tiny muted">💬 {{ $issue->comments_count }}</span>
                            @endif
                        </div>

                        @if ($issue->note)
                            <div class="card__note">{{ $issue->note }}</div>
                        @endif
                    </a>
                @endforeach

                @if ($column['count'] === 0)
                    <p class="tiny muted" style="text-align:center;padding:18px 8px">{{ __('ui.board.empty_column') }}</p>
                @endif
            </div>
        </section>
    @endforeach
</div>
