@php
    $statusByKey = collect($statuses)->keyBy('key');
    $priorityByKey = collect($priorities)->keyBy('key');
    $isLate = $issue->isLateOn(now());
@endphp

<div x-data="issuePanel({{ $issue->id }})" data-issue="{{ $issue->id }}">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px">
        <span class="key">{{ $issue->issue_key }}</span>
        <span class="chip chip--{{ $statusByKey[$issue->status_key]['tone'] ?? 'slate' }}" x-ref="statusChip"
              x-text="statusLabel">{{ $statusByKey[$issue->status_key]['label'] ?? $issue->status_key }}</span>
        @if ($isLate)
            <span class="chip chip--red">{{ __('ui.daily.late') }}</span>
        @endif
        <span class="tiny muted" style="margin-left:auto">{{ $issue->project?->name }}</span>
    </div>

    {{-- Editable summary: saves on blur, so the board and the sheet follow instantly. --}}
    <textarea class="issue-title" rows="2" name="summary"
              x-on:blur="save('summary', $el.value)"
              x-on:keydown.meta.enter="save('summary', $el.value)"
              x-on:keydown.ctrl.enter="save('summary', $el.value)"
    >{{ $issue->summary }}</textarea>

    <div class="field__row" style="margin:14px 0 16px">
        <div class="field" style="margin:0">
            <label for="p-status">{{ __('ui.issue.status') }}</label>
            <select class="select" id="p-status" x-on:change="save('status_key', $el.value)">
                @foreach ($statuses as $status)
                    <option value="{{ $status['key'] }}" @selected($issue->status_key === $status['key'])>{{ $status['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0">
            <label for="p-priority">{{ __('ui.issue.priority') }}</label>
            <select class="select" id="p-priority" x-on:change="save('priority_key', $el.value)">
                @foreach ($priorities as $priority)
                    <option value="{{ $priority['key'] }}" @selected($issue->priority_key === $priority['key'])>{{ $priority['label'] }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="field">
        <label for="p-percent">
            {{ __('ui.issue.percent') }}
            <span class="tnum" x-text="(percent ?? {{ $issue->percent }}) + '%'"></span>
        </label>
        <input class="input" id="p-percent" type="range" min="0" max="100" step="5"
               value="{{ $issue->percent }}" style="padding:0;height:22px"
               x-on:input="percent = $el.value"
               x-on:change="save('percent', $el.value)">
    </div>

    <dl class="meta-grid">
        <dt>{{ __('ui.issue.assignee') }}</dt>
        <dd>
            <select class="select" style="height:26px;font-size:12.5px" x-on:change="save('assignee_id', $el.value || null)">
                <option value="">—</option>
                @foreach ($members as $member)
                    <option value="{{ $member->id }}" @selected($issue->assignee_id === $member->id)>{{ $member->name }}</option>
                @endforeach
            </select>
        </dd>

        <dt>{{ __('ui.issue.co_assignees') }}</dt>
        <dd class="tiny muted">{{ $issue->coAssignees->pluck('name')->implode(', ') ?: '—' }}</dd>

        <dt>{{ __('ui.issue.reporter') }}</dt>
        <dd class="tiny">{{ $issue->reporter?->name ?? '—' }}</dd>

        <dt>{{ __('ui.issue.starts') }}</dt>
        <dd><input class="input" style="height:26px;font-size:12.5px" type="date"
                   value="{{ $issue->starts_on?->toDateString() }}"
                   x-on:change="save('starts_on', $el.value || null)"></dd>

        <dt>{{ __('ui.issue.due') }}</dt>
        <dd style="display:flex;gap:6px;align-items:center">
            <input class="input" style="height:26px;font-size:12.5px" type="date"
                   value="{{ $issue->due_on?->toDateString() }}"
                   x-on:change="save('due_on', $el.value || null)">
            <label class="tiny nowrap muted" style="display:flex;gap:4px;align-items:center">
                <input type="checkbox" @checked($issue->due_undecided)
                       x-on:change="save('due_undecided', $el.checked)">
                {{ __('ui.issue.due_undecided') }}
            </label>
        </dd>

        <dt>{{ __('ui.issue.note') }}</dt>
        <dd><input class="input" style="height:26px;font-size:12.5px" list="note-presets"
                   value="{{ $issue->note }}" x-on:change="save('note', $el.value)">
            <datalist id="note-presets">
                @foreach ($notes as $preset)
                    <option value="{{ $preset }}"></option>
                @endforeach
            </datalist>
        </dd>
    </dl>

    <div class="field" style="margin-top:16px">
        <label>{{ __('ui.issue.description') }}</label>
        <textarea class="textarea" rows="4" placeholder="{{ __('ui.issue.description_placeholder') }}"
                  x-on:blur="save('description', $el.value)">{{ $issue->description }}</textarea>
    </div>

    <hr style="border:none;border-top:1px solid var(--hair);margin:18px 0 12px">

    {{-- Comments --}}
    <h3 style="font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);margin:0 0 4px">
        {{ __('ui.issue.comments') }}
        <span class="column__count" x-text="commentCount">{{ $issue->comments->count() }}</span>
    </h3>

    <div id="comments">
        @forelse ($issue->comments as $comment)
            @include('issues.partials.comment', ['comment' => $comment])
        @empty
            <p class="tiny muted">{{ __('ui.issue.no_comments') }}</p>
        @endforelse
    </div>

    <form x-on:submit.prevent="submitComment($el)" style="margin-top:10px">
        <textarea class="textarea" name="body" rows="3" required
                  placeholder="{{ __('ui.issue.comment_placeholder') }}"></textarea>
        <div style="display:flex;justify-content:flex-end;gap:6px;margin-top:6px">
            <button class="btn btn--primary btn--sm" type="submit">{{ __('ui.issue.comment_submit') }}</button>
        </div>
    </form>

    <hr style="border:none;border-top:1px solid var(--hair);margin:16px 0 10px">

    {{-- Activity timeline --}}
    <h3 style="font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);margin:0 0 6px">
        {{ __('ui.issue.activity') }}
    </h3>
    <div id="timeline">
        @include('issues.partials.timeline', ['issue' => $issue])
    </div>

    <details style="margin-top:18px">
        <summary class="tiny muted" style="cursor:pointer">{{ __('ui.issue.delete') }}</summary>
        <form method="post" action="{{ route('issues.destroy', $issue) }}" style="margin-top:8px"
              onsubmit="return confirm('{{ __('ui.issue.delete') }}?')">
            @csrf
            @method('DELETE')
            <button class="btn btn--sm" type="submit" style="color:var(--red)">{{ __('ui.issue.delete') }} {{ $issue->issue_key }}</button>
        </form>
    </details>
</div>

<script>
function issuePanel(issueId) {
    return {
        percent: {{ $issue->percent }},
        statusLabel: @js($statusByKey[$issue->status_key]['label'] ?? $issue->status_key),
        commentCount: {{ $issue->comments->count() }},

        async save(field, value) {
            const res = await fetch(`{{ url('issues') }}/${issueId}`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ [field]: value === '' ? null : value }),
            });

            if (!res.ok) {
                this.$dispatch('toast', { message: '저장하지 못했습니다' });
                return;
            }

            const data = await res.json();
            if (data.activity) {
                document.getElementById('timeline').innerHTML = data.activity;
            }
            if (field === 'status_key') {
                const selected = document.querySelector('#p-status option:checked');
                if (selected) this.statusLabel = selected.textContent;
            }
            this.$dispatch('toast', { message: '{{ __('ui.issue.saved') }}' });
        },

        async submitComment(form) {
            const body = form.body.value.trim();
            if (!body) return;

            const res = await fetch(`{{ url('issues') }}/${issueId}/comments`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ body }),
            });

            if (!res.ok) return;

            const data = await res.json();
            const holder = document.getElementById('comments');
            const empty = holder.querySelector('p.muted');
            if (empty) empty.remove();
            holder.insertAdjacentHTML('afterbegin', data.comment.html);
            form.reset();
            this.commentCount++;
        },
    };
}
</script>
