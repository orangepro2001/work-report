<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Sprint;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The board: three lenses over one query set (docs/ui.md).
 *
 * Filters are plain query parameters so a view is linkable and bookmarkable:
 *   ?project=2&assignee=1&q=AFVI&priority=high&mode=board
 */
class BoardController extends Controller
{
    public function index(Request $request)
    {
        return $this->render($request, 'board');
    }

    public function list(Request $request)
    {
        return $this->render($request, 'list');
    }

    public function backlog(Request $request)
    {
        return $this->render($request, 'backlog');
    }

    private function render(Request $request, string $lens)
    {
        $filters = $this->filters($request);

        $projects = Project::visible()->get(['id', 'name', 'abbr', 'color', 'position']);
        $members = Member::active()->internal()->get(['id', 'name', 'color', 'position']);

        // Reporter is only rendered in the list lens, so the board and backlog skip
        // that join: one fewer round trip over the tailnet (docs/perf.md).
        $relations = $lens === 'list'
            ? ['project:id,abbr,name,color', 'assignee:id,name,color', 'coAssignees:id,name,color', 'reporter:id,name']
            : ['project:id,abbr,name,color', 'assignee:id,name,color', 'coAssignees:id,name,color'];

        $query = Issue::query()
            ->with($relations)
            // Matches issues_board_order_idx (status_key, board_rank).
            ->orderBy('status_key')
            ->orderBy('board_rank')
            ->orderBy('id');

        $this->applyFilters($query, $filters);

        $issues = $query->get();

        $view = view('board.index', [
            'lens' => $lens,
            'filters' => $filters,
            'projects' => $projects,
            'members' => $members,
            'statuses' => config('workreport.statuses'),
            'priorities' => config('workreport.priorities'),
            'columns' => $this->columns($issues, $projects, $filters),
            'issues' => $issues,
            'sprints' => Sprint::where('state', '!=', 'closed')->orderByDesc('id')->get(['id', 'name', 'state', 'project_id']),
        ]);

        // The drawer asks for this region after every save, so the card can move
        // column, the counters can change and the list row can restyle without a
        // page reload — and still be rendered by Blade, not by JavaScript.
        return $request->header('X-Live-Region') === 'board' ? $view->fragment('board') : $view;
    }

    /** @return array<string,mixed> */
    private function filters(Request $request): array
    {
        return [
            'project' => $request->integer('project') ?: null,
            'assignee' => $request->integer('assignee') ?: null,
            'priority' => $request->string('priority')->toString() ?: null,
            'q' => $request->string('q')->toString() ?: null,
            'sprint' => $request->integer('sprint') ?: null,
            'flag' => $request->string('flag')->toString() ?: null,
        ];
    }

    private function applyFilters($query, array $filters): void
    {
        $query
            ->when($filters['project'], fn ($q, $id) => $q->where('project_id', $id))
            ->when($filters['assignee'], fn ($q, $id) => $q->forMember((int) $id))
            ->when($filters['priority'], fn ($q, $p) => $q->where('priority_key', $p))
            ->when($filters['sprint'], fn ($q, $s) => $q->where('sprint_id', $s))
            ->when($filters['flag'] === 'late', fn ($q) => $q
                ->where('status_key', '!=', 'done')
                ->whereNotNull('due_on')
                ->where('due_undecided', false)
                ->whereDate('due_on', '<', now()->toDateString()))
            ->when($filters['q'], fn ($q, $term) => $q->where(function ($w) use ($term) {
                // JQL-lite: a bare term matches summary text or the numeric key part.
                $w->where('summary', 'ilike', "%{$term}%")
                    ->orWhere('note', 'ilike', "%{$term}%")
                    ->orWhere('description', 'ilike', "%{$term}%");
            }));
    }

    /**
     * Groups issues into workflow columns for the kanban lens.
     *
     * @param  Collection<int,Issue>  $issues
     * @return array<int,array<string,mixed>>
     */
    private function columns($issues, $projects, array $filters): array
    {
        $projectId = $filters['project'];
        $activeProject = $projectId ? $projects->firstWhere('id', $projectId) : null;

        $columns = [];

        foreach (config('workreport.statuses') as $status) {
            $rows = $issues->where('status_key', $status['key'])->values();

            $columns[] = [
                'key' => $status['key'],
                'label' => $status['label'],
                'tone' => $status['tone'],
                'count' => $rows->count(),
                'percent' => (int) round($rows->avg(fn (Issue $i) => $i->effectivePercent()) ?? 0),
                'issues' => $rows,
            ];
        }

        return $columns;
    }
}
