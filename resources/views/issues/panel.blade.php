@php
    $statusByKey = collect($statuses)->keyBy('key');
    $priorityByKey = collect($priorities)->keyBy('key');
    $isLate = $issue->isLateOn(now());
@endphp

@php($owned = $issue->isOwnedBy(app(\App\Contracts\ActorResolver::class)->current()))
<div x-data="issuePanel({{ $issue->id }}, {{ Js::from([
    'owned' => $owned,
    'percent' => $issue->percent,
    'statusLabel' => $statusByKey[$issue->status_key]['label'] ?? $issue->status_key,
    'commentCount' => $issue->comments->count(),
]) }})" data-issue="{{ $issue->id }}" data-owned="{{ $owned ? '1' : '0' }}">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px">
        <span class="key">{{ $issue->issue_key }}</span>
        <span class="chip chip--{{ $statusByKey[$issue->status_key]['tone'] ?? 'slate' }}" x-ref="statusChip"
              x-text="statusLabel">{{ $statusByKey[$issue->status_key]['label'] ?? $issue->status_key }}</span>
        @unless ($owned)
            {{-- Strict isolation: somebody else's issue is readable, commentable and
                 otherwise frozen. The server refuses the writes as well (403). --}}
            <span class="chip chip--slate" title="{{ __('ui.issue.not_yours') }}">{{ __('ui.issue.read_only') }}</span>
        @endunless
        @if ($isLate)
            <span class="chip chip--red">{{ __('ui.daily.late') }}</span>
        @endif
        <span class="tiny muted" style="margin-left:auto">{{ $issue->project?->name }}</span>
    </div>

    {{-- Editable summary: saves on blur, so the board and the sheet follow instantly. --}}
    <textarea class="issue-title" rows="2" name="summary" @disabled(! $owned)
              x-on:blur="save('summary', $el.value)"
              x-on:keydown.meta.enter="save('summary', $el.value)"
              x-on:keydown.ctrl.enter="save('summary', $el.value)"
    >{{ $issue->summary }}</textarea>

    <div class="field__row" style="margin:14px 0 16px">
        <div class="field" style="margin:0">
            <label for="p-status">{{ __('ui.issue.status') }}</label>
            <select class="select" id="p-status" @disabled(! $owned) x-on:change="save('status_key', $el.value)">
                @foreach ($statuses as $status)
                    <option value="{{ $status['key'] }}" @selected($issue->status_key === $status['key'])>{{ $status['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0">
            <label for="p-priority">{{ __('ui.issue.priority') }}</label>
            <select class="select" id="p-priority" @disabled(! $owned) x-on:change="save('priority_key', $el.value)">
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
        <input class="input" id="p-percent" type="range" min="0" max="100" step="5" @disabled(! $owned)
               value="{{ $issue->percent }}" style="padding:0;height:22px"
               x-on:input="percent = $el.value"
               x-on:change="save('percent', $el.value)">
    </div>

    <dl class="meta-grid">
        <dt>{{ __('ui.issue.assignee') }}</dt>
        <dd>
            <select class="select" style="height:26px;font-size:12.5px" @disabled(! $owned) x-on:change="save('assignee_id', $el.value || null)">
                <option value="">—</option>
                @foreach ($members as $member)
                    <option value="{{ $member->id }}" @selected($issue->assignee_id === $member->id)>{{ $member->name }}</option>
                @endforeach
            </select>
        </dd>

        <dt>{{ __('ui.issue.co_assignees') }}</dt>
        <dd class="tiny muted">{{ $issue->coAssignees->pluck('name')->implode(', ') ?: '—' }}</dd>

        <dt>{{ __('ui.issue.reporter') }}</dt>
        <dd>
            {{-- Free text: the team is three people, but a report is often addressed by
                 somebody outside it. Known names are offered, unknown ones become
                 name-only contacts (Member::forName). --}}
            <input class="input" style="height:26px;font-size:12.5px" @disabled(! $owned) list="reporter-options"
                   value="{{ $issue->reporter?->name }}" maxlength="120"
                   placeholder="{{ __('ui.issue.reporter_placeholder') }}"
                   x-on:change="save('reporter_name', $el.value)">
            <datalist id="reporter-options">
                @foreach ($reporters as $reporter)
                    <option value="{{ $reporter->name }}">{{ $reporter->is_external ? __('ui.issue.external') : '' }}</option>
                @endforeach
            </datalist>
        </dd>

        <dt>{{ __('ui.issue.starts') }}</dt>
        <dd><input class="input" style="height:26px;font-size:12.5px" type="date"
                   value="{{ $issue->starts_on?->toDateString() }}"
                   x-on:change="save('starts_on', $el.value || null)"></dd>

        <dt>{{ __('ui.issue.due') }}</dt>
        <dd style="display:flex;gap:6px;align-items:center">
            <input class="input" style="height:26px;font-size:12.5px" @disabled(! $owned) type="date"
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
        {{-- The checklist: rows in issue_todos, not a fenced block in the description.
             Ticking one is a single request; the header shows done/total and a meter. --}}
        <div class="todo" x-data="issueTodos({{ $issue->id }}, {{ \Illuminate\Support\Js::from($todos) }}, {{ $owned ? 'true' : 'false' }})">
            <div class="todo__head">
                <strong>{{ __('ui.issue.todos') }}</strong>
                <span class="todo__count" x-text="`${done} / ${total}`"></span>
                <span class="todo__meter" aria-hidden="true"><i :style="`width: ${percent}%`"></i></span>
                <span class="todo__percent" x-text="`${percent}%`"></span>
            </div>

            <template x-for="todo in todos" :key="todo.id">
                <div class="todo__row" :class="{ 'is-done': todo.done }">
                    <input type="checkbox" class="todo__box" :checked="todo.done" :disabled="!owned"
                           x-on:change="toggle(todo, $event.target.checked)">
                    <input class="todo__title" :value="todo.title" maxlength="300" :disabled="!owned"
                           x-on:change="rename(todo, $event.target.value)"
                           x-on:keydown.enter.prevent="$event.target.blur()">
                    <span class="todo__assignee tiny muted" x-text="todo.assignee_name || ''"></span>
                    <span class="todo__move" x-show="owned">
                        <button type="button" x-on:click="move(todo, 'up')" aria-label="{{ __('ui.issue.todo_up') }}">↑</button>
                        <button type="button" x-on:click="move(todo, 'down')" aria-label="{{ __('ui.issue.todo_down') }}">↓</button>
                    </span>
                    <button type="button" class="todo__delete" x-show="owned" x-on:click="remove(todo)"
                            aria-label="{{ __('ui.common.delete') }}">✕</button>
                </div>
            </template>

            <div class="todo__add" x-show="owned">
                <input class="input" type="text" maxlength="300" x-ref="newTodo"
                       placeholder="{{ __('ui.issue.todo_placeholder') }}"
                       x-on:keydown.enter.prevent="add()">
                <button class="btn btn--sm" type="button" x-on:click="add()">+ {{ __('ui.issue.todo_add') }}</button>
            </div>
        </div>

        <label>{{ __('ui.issue.description') }}</label>
        <textarea class="textarea" rows="4" data-markdown data-field="description" @disabled(! $owned)
                  placeholder="{{ __('ui.issue.description_placeholder') }}"
                  x-on:mde-change.debounce.900ms="save('description', $event.detail.value); previewDescription($event.detail.value)">{{ $issue->description }}</textarea>

        {{-- The description as readers see it: a `tasks` block, a table or a checklist
             typed in the editor used to be invisible here, because this panel rendered
             the textarea and nothing else. Kept in sync by the same debounce as the
             save (POST /markdown renders it with the application's one renderer). --}}
        <div id="issue-description-preview-wrap" class="card" style="margin-top:10px;padding:12px 14px"
             @if (! trim((string) $issue->description)) hidden @endif>
            <div class="tiny muted" style="margin-bottom:8px">{{ __('ui.issue.description_preview') }}</div>
            <article id="issue-description-preview" class="markdown">{!! \App\Support\Markdown::toHtml($issue->description) !!}</article>
        </div>
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
        <textarea class="textarea" name="body" rows="3" required data-markdown data-field="comment"
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
