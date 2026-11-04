@php
    $statusByKey = collect(config('workreport.statuses'))->keyBy('key');
    $priorityByKey = collect(config('workreport.priorities'))->keyBy('key');
@endphp

@if ($issue->activities->isEmpty())
    <p class="tiny muted">{{ __('ui.issue.no_activity') }}</p>
@else
    <ul class="timeline">
        @foreach ($issue->activities as $activity)
            @php
                $changes = $activity->changes ?? [];
                $verb = $activity->verb;

                // Translate the stored keys into the labels a person reads.
                $params = [];
                if ($verb === 'status_changed') {
                    $params = [
                        'from' => $statusByKey[$changes['from'] ?? ''] ?? ($changes['from'] ?? '—'),
                        'to' => $statusByKey[$changes['to'] ?? ''] ?? ($changes['to'] ?? '—'),
                    ];
                } elseif ($verb === 'percent_changed') {
                    $params = ['to' => $changes['to'] ?? '—'];
                }
            @endphp
            <li>
                <span class="avatar avatar--sm" style="background: {{ $activity->actor?->color ?? '#c9d2de' }}"
                      title="{{ $activity->actor?->name }}">{{ $activity->actor?->initials() ?? '·' }}</span>
                <span>
                    <strong>{{ $activity->actor?->name ?? '—' }}</strong>
                    <span class="muted">{{ __("activity.{$verb}", array_map('strval', $params)) }}</span>
                    <time class="tiny muted nowrap" datetime="{{ $activity->created_at->toIso8601String() }}"
                          style="float:right" title="{{ $activity->created_at->format('Y-m-d H:i') }}">
                        {{ $activity->created_at->diffForHumans(short: true) }}
                    </time>
                </span>
            </li>
        @endforeach
    </ul>
@endif
