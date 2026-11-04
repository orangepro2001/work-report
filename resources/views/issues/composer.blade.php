@php
    $projects = \App\Models\Project::visible()->get(['id', 'name', 'abbr', 'color']);
    $members = \App\Models\Member::active()->get(['id', 'name', 'color']);
    $actor = app(\App\Contracts\ActorResolver::class)->current();
@endphp

{{-- Quick composer: only the summary is required, everything else can be filled in
     later from the drawer (docs/ui.md — "capture now, classify later"). --}}
<form method="post" action="{{ route('issues.store') }}" x-data="{ busy: false }"
      x-on:submit="busy = true">
    @csrf

    <div class="field">
        <label for="new-issue-summary">{{ __('ui.issue.summary') }}</label>
        <input class="input" id="new-issue-summary" name="summary" required maxlength="300"
               placeholder="{{ __('ui.issue.summary_placeholder') }}">
        <p class="tiny muted" style="margin:5px 0 0">{{ __('ui.issue.quick_create_hint') }}</p>
    </div>

    <div class="field__row">
        <div class="field">
            <label for="new-issue-project">{{ __('ui.issue.bucket') }}</label>
            <select class="select" id="new-issue-project" name="project_id" required>
                @foreach ($projects as $project)
                    <option value="{{ $project->id }}" @selected(request()->integer('project') === $project->id)>
                        {{ $project->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="new-issue-assignee">{{ __('ui.issue.assignee') }}</label>
            <select class="select" id="new-issue-assignee" name="assignee_id">
                @foreach ($members as $member)
                    <option value="{{ $member->id }}" @selected($actor && $actor->id === $member->id)>{{ $member->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="field__row">
        <div class="field">
            <label for="new-issue-priority">{{ __('ui.issue.priority') }}</label>
            <select class="select" id="new-issue-priority" name="priority_key">
                @foreach (config('workreport.priorities') as $priority)
                    <option value="{{ $priority['key'] }}" @selected($priority['key'] === 'medium')>{{ $priority['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="new-issue-status">{{ __('ui.issue.status') }}</label>
            <select class="select" id="new-issue-status" name="status_key">
                @foreach (config('workreport.statuses') as $status)
                    <option value="{{ $status['key'] }}">{{ $status['label'] }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="field__row">
        <div class="field">
            <label for="new-issue-start">{{ __('ui.issue.starts') }}</label>
            <input class="input" id="new-issue-start" type="date" name="starts_on">
        </div>
        <div class="field">
            <label for="new-issue-due">{{ __('ui.issue.due') }}</label>
            <input class="input" id="new-issue-due" type="date" name="due_on">
        </div>
    </div>

    <div class="field">
        <label for="new-issue-note">{{ __('ui.issue.note') }}</label>
        <input class="input" id="new-issue-note" name="note" list="note-presets-new" maxlength="300">
        <datalist id="note-presets-new">
            @foreach (config('workreport.note_presets') as $preset)
                <option value="{{ $preset }}"></option>
            @endforeach
        </datalist>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:6px;margin-top:16px">
        <button class="btn" type="button" x-on:click="$root.closeCreate()">{{ __('ui.common.cancel') }}</button>
        <button class="btn btn--primary" type="submit" x-bind:disabled="busy">
            {{ __('ui.board.create') }}
        </button>
    </div>
</form>
