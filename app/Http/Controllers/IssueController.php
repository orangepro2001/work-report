<?php

namespace App\Http\Controllers;

use App\Contracts\ActorResolver;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueComment;
use App\Models\Member;
use App\Models\Project;
use App\Services\IssueKeyGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Issue CRUD plus the two collaborative verbs: move (drag & drop) and comment.
 *
 * Every mutation writes an issue_activities row, which is what the timeline panel
 * renders; nobody has to reconstruct history from diffs.
 */
class IssueController extends Controller
{
    public function __construct(
        private readonly ActorResolver $actors,
        private readonly IssueKeyGenerator $keys,
    ) {}

    /**
     * The panel, in whichever shape the caller asked for.
     *
     * The board fetches it as a fragment for the drawer/modal; opening the same URL
     * directly (a bookmark, a new tab, "전체 화면") renders it inside the layout, so
     * there is one panel markup and three ways to look at it.
     */
    public function show(Request $request, Issue $issue)
    {
        $issue->load([
            'project:id,name,abbr,color',
            'assignee:id,name,color',
            'reporter:id,name,color',
            'coAssignees:id,name,color',
            'comments.author:id,name,color',
            'activities.actor:id,name,color',
            'todos.assignee:id,name',
            'sprint:id,name,state',
        ]);

        $todos = $issue->todos;
        $doneTodos = $todos->where('done', true)->count();

        $view = view('issues.panel', [
            'issue' => $issue,
            'todos' => [
                'items' => $todos->map(fn ($todo) => $todo->payload())->values(),
                'total' => $todos->count(),
                'done' => $doneTodos,
                'percent' => $todos->count() === 0 ? 0 : (int) round($doneTodos / $todos->count() * 100),
            ],
            'statuses' => config('workreport.statuses'),
            'priorities' => config('workreport.priorities'),
            'members' => Member::active()->internal()->orderBy('position')->get(['id', 'name', 'color']),
            // Reporters may be people without an account, so the field is free text
            // with these names offered as suggestions.
            'reporters' => Member::active()->orderBy('name')->get(['id', 'name', 'is_external']),
            'notes' => config('workreport.note_presets'),
        ]);

        return $request->ajax()
            ? $view
            : view('issues.page', ['issue' => $issue, 'panel' => $view->render()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'summary' => ['required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:20000'],
            'priority_key' => ['nullable', Rule::in(array_column(config('workreport.priorities'), 'key'))],
            'status_key' => ['nullable', Rule::in(array_column(config('workreport.statuses'), 'key'))],
            'percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'assignee_id' => ['nullable', 'integer', 'exists:members,id'],
            'reporter_id' => ['nullable', 'integer', 'exists:members,id'],
            'starts_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date'],
            'due_undecided' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:300'],
            'co_assignees' => ['array'],
            'co_assignees.*' => ['integer', 'exists:members,id'],
        ]);

        $actor = $this->actors->current();
        $project = Project::findOrFail($data['project_id']);

        $issue = DB::transaction(function () use ($data, $project, $actor) {
            $no = $this->keys->nextFor($project);

            $issue = Issue::create([
                'project_id' => $project->getKey(),
                'issue_no' => $no,
                'summary' => $data['summary'],
                'description' => $data['description'] ?? null,
                'priority_key' => $data['priority_key'] ?? 'medium',
                'status_key' => $data['status_key'] ?? 'planned',
                'percent' => $data['percent'] ?? 0,
                'assignee_id' => $data['assignee_id'] ?? $actor?->getKey(),
                'reporter_id' => $data['reporter_id'] ?? $actor?->getKey(),
                'starts_on' => $data['starts_on'] ?? null,
                'due_on' => $data['due_on'] ?? null,
                'due_undecided' => (bool) ($data['due_undecided'] ?? false),
                'note' => $data['note'] ?? null,
                'board_rank' => $this->nextRank($project, $data['status_key'] ?? 'planned'),
            ]);

            if (! empty($data['co_assignees'])) {
                $issue->coAssignees()->sync(
                    collect($data['co_assignees'])
                        ->reject(fn ($id) => $id == ($data['assignee_id'] ?? null))
                        ->values()
                        ->mapWithKeys(fn ($id, $i) => [$id => ['position' => $i]])
                        ->all()
                );
            }

            $this->log($issue, $actor, 'created', []);

            return $issue;
        });

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'issue' => $this->payload($issue->fresh(['project', 'assignee', 'coAssignees'])),
            ], 201);
        }

        return redirect()->route('board.index', ['project' => $project->getKey()])
            ->with('status', "{$issue->issue_key} 이슈를 만들었습니다.");
    }

    public function update(Request $request, Issue $issue)
    {
        $data = $request->validate([
            'summary' => ['sometimes', 'string', 'max:300'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'priority_key' => ['sometimes', Rule::in(array_column(config('workreport.priorities'), 'key'))],
            'status_key' => ['sometimes', Rule::in(array_column(config('workreport.statuses'), 'key'))],
            'percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'assignee_id' => ['sometimes', 'nullable', 'integer', 'exists:members,id'],
            'reporter_id' => ['sometimes', 'nullable', 'integer', 'exists:members,id'],
            // A reporter may be somebody outside the team: a name is enough. It is
            // stored as a name-only member row, so the workbook column and every
            // foreign key keep working (docs/documents.md → people).
            'reporter_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'due_on' => ['sometimes', 'nullable', 'date'],
            'due_undecided' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
            'flagged' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('reporter_name', $data)) {
            $name = trim((string) $data['reporter_name']);
            unset($data['reporter_name']);
            $data['reporter_id'] = $name === '' ? null : Member::forName($name)->id;
        }

        $actor = $this->actors->current();
        $changes = [];

        foreach ($data as $field => $value) {
            $before = $issue->getRawOriginal($field);
            if ((string) $before === (string) $value) {
                continue;
            }
            $changes[$field] = ['from' => $before, 'to' => $value];
        }

        if ($changes === []) {
            return $request->expectsJson()
                ? response()->json(['ok' => true, 'issue' => $this->payload($issue)])
                : back();
        }

        DB::transaction(function () use ($issue, $data, $changes, $actor) {
            // Keep the completion timestamp in sync with the workflow state: the
            // exporter and the 지연 rule both read it.
            if (isset($data['status_key'])) {
                $data['completed_at'] = $data['status_key'] === 'done' ? now() : null;
                if ($data['status_key'] === 'done') {
                    $data['percent'] = 100;
                }
            }

            $issue->update($data);
            $this->log($issue, $actor, $this->verbFor($changes), $changes);
        });

        $issue = $issue->fresh(['project', 'assignee', 'coAssignees', 'comments.author', 'activities.actor']);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'issue' => $this->payload($issue),
                'activity' => view('issues.partials.timeline', ['issue' => $issue])->render(),
            ]);
        }

        return back()->with('status', '이슈를 저장했습니다.');
    }

    /**
     * POST /issues/{issue}/move  {status_key, before_id?, after_id?}
     *
     * Returns the two affected columns so the client can re-render them without
     * reloading the board (optimistic move + authoritative reconciliation).
     */
    public function move(Request $request, Issue $issue)
    {
        $data = $request->validate([
            'status_key' => ['required', Rule::in(array_column(config('workreport.statuses'), 'key'))],
            'before_id' => ['nullable', 'integer'],
            'after_id' => ['nullable', 'integer'],
        ]);

        $actor = $this->actors->current();

        DB::transaction(function () use ($issue, $data, $actor) {
            $from = $issue->status_key;

            $rank = $this->rankBetween(
                $issue->project_id,
                $data['status_key'],
                $data['before_id'] ?? null,
                $data['after_id'] ?? null,
            );

            $issue->update([
                'status_key' => $data['status_key'],
                'board_rank' => $rank,
                'completed_at' => $data['status_key'] === 'done' ? now() : null,
                'percent' => $data['status_key'] === 'done' ? 100 : $issue->percent,
            ]);

            if ($from !== $data['status_key']) {
                $this->log($issue, $actor, 'status_changed', [
                    'field' => 'status_key',
                    'from' => $from,
                    'to' => $data['status_key'],
                ]);
            }
        });

        $issue = $issue->fresh(['project', 'assignee', 'coAssignees']);

        return response()->json([
            'ok' => true,
            'issue' => $this->payload($issue),
            'status_label' => $issue->statusLabel(),
        ]);
    }

    public function destroy(Issue $issue)
    {
        $key = $issue->issue_key;
        $issue->delete(); // soft delete: the key is never reused

        return redirect()->route('board.index')->with('status', "{$key} 이슈를 보관함으로 옮겼습니다.");
    }

    public function comment(Request $request, Issue $issue)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:20000'],
        ]);

        $actor = $this->actors->current();

        $comment = DB::transaction(function () use ($issue, $data, $actor) {
            $comment = IssueComment::create([
                'issue_id' => $issue->getKey(),
                'author_id' => $actor?->getKey(),
                'body' => $data['body'],
            ]);

            $this->log($issue, $actor, 'commented', ['excerpt' => mb_substr($data['body'], 0, 80)]);

            return $comment->load('author:id,name,color');
        });

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'comment' => [
                    'id' => $comment->getKey(),
                    'body' => $comment->body,
                    'html' => view('issues.partials.comment', ['comment' => $comment])->render(),
                ],
            ], 201);
        }

        return back()->with('status', '댓글을 남겼습니다.');
    }

    public function destroyComment(IssueComment $comment)
    {
        $comment->delete();

        return response()->json(['ok' => true]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Fractional ranking: pick a board_rank between the two neighbours.
     *
     * 1.0, 2.0, 3.0 … means a drop always finds a value in between; only repeated
     * drops into the same gap need a renormalisation, which happens lazily.
     */
    private function rankBetween(int $projectId, string $status, ?int $beforeId, ?int $afterId): float
    {
        $before = $beforeId ? Issue::whereKey($beforeId)->value('board_rank') : null;
        $after = $afterId ? Issue::whereKey($afterId)->value('board_rank') : null;

        if ($before !== null && $after !== null) {
            return ((float) $before + (float) $after) / 2;
        }

        if ($before !== null) {
            return (float) $before + 1.0;
        }

        if ($after !== null) {
            return max(0.5, (float) $after - 1.0);
        }

        return $this->nextRank(new Project(['id' => $projectId]), $status);
    }

    private function nextRank(Project $project, string $status): float
    {
        $max = Issue::where('project_id', $project->getKey())
            ->where('status_key', $status)
            ->max('board_rank');

        return ((float) $max) + 1.0;
    }

    private function verbFor(array $changes): string
    {
        if (isset($changes['status_key'])) {
            return 'status_changed';
        }
        if (isset($changes['percent'])) {
            return 'percent_changed';
        }
        if (isset($changes['assignee_id'])) {
            return 'assigned';
        }
        if (isset($changes['reporter_id'])) {
            return 'reported_by_changed';
        }

        return 'updated';
    }

    private function log(Issue $issue, ?Member $actor, string $verb, array $changes): void
    {
        IssueActivity::create([
            'issue_id' => $issue->getKey(),
            'actor_id' => $actor?->getKey(),
            'verb' => $verb,
            'changes' => $changes === [] ? null : $changes,
        ]);
    }

    /** The shape the board JavaScript expects. */
    private function payload(Issue $issue): array
    {
        return [
            'id' => $issue->getKey(),
            'key' => $issue->issue_key,
            'summary' => $issue->summary,
            'status_key' => $issue->status_key,
            'priority_key' => $issue->priority_key,
            'percent' => $issue->percent,
            'note' => $issue->note,
            'due_on' => $issue->due_on?->toDateString(),
            'due_undecided' => $issue->due_undecided,
            'project' => $issue->project?->only(['id', 'name', 'abbr', 'color']),
            'assignees' => $issue->allAssigneeNames(),
            'reporter_id' => $issue->reporter_id,
            'assignee_id' => $issue->assignee_id,
            'assignee_color' => $issue->assignee?->color,
            'board_rank' => (float) $issue->board_rank,
        ];
    }
}
