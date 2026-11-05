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
                    // The config entry is an array (key/label/tone); only the label is
                    // printable. Passing the whole entry used to raise "Array to string
                    // conversion" while rendering the timeline.
                    $params = [
                        'from' => $statusByKey[$changes['from'] ?? '']['label'] ?? ($changes['from'] ?? '—'),
                        'to' => $statusByKey[$changes['to'] ?? '']['label'] ?? ($changes['to'] ?? '—'),
                    ];
                } elseif ($verb === 'percent_changed') {
                    $params = ['to' => $changes['to'] ?? '—'];
                }
            @endphp
            <li>
                <x-avatar :member="$activity->actor" size="sm" />
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
