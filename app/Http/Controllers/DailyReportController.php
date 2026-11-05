<?php

namespace App\Http\Controllers;

use App\Contracts\ActorResolver;
use App\Models\DailyLog;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * 일보 view: the workbook's grid, generated from the tracker.
 *
 * The grid is fed by the same query the exporter uses, so what a person sees on
 * screen is what lands in the .xlsx — the two cannot drift.
 */
class DailyReportController extends Controller
{
    public function __construct(private readonly ActorResolver $actors) {}

    public function __invoke(Request $request)
    {
        $date = CarbonImmutable::parse(
            $request->query('date', 'now'),
            config('workreport.timezone'),
        );

        $member = $this->actors->current();
        $selectedMember = $member?->getKey();

        $issues = Issue::query()
            ->forBoard()
            ->with(['reporter:id,name'])
            ->whereNotNull('summary')
            ->where('summary', '<>', '')
            ->when($selectedMember, fn ($q, $id) => $q->forMember($id))
            ->orderBy(Project::select('position')->whereColumn('projects.id', 'issues.project_id'))
            ->orderBy('issue_no')
            ->get();

        $log = $selectedMember
            ? DailyLog::where('log_date', $date->toDateString())->where('member_id', $selectedMember)->first()
            : null;

        $view = view('report.daily', [
            'date' => $date,
            'member' => $member,
            'members' => Member::active()->internal()->get(['id', 'name', 'color', 'picture_url']),
            'projects' => Project::visible()->get(['id', 'name', 'abbr', 'color']),
            'issues' => $issues,
            'log' => $log,
            'stats' => $this->stats($issues, $date),
            'buckets' => config('workreport.summary_buckets'),
        ]);

        return $request->header('X-Live-Region') === 'daily' ? $view->fragment('daily') : $view;
    }

    /**
     * The numbers the summary block of the worksheet shows, computed in PHP so the
     * screen matches the sheet without a round trip through Excel.
     *
     * @param  Collection<int,Issue>  $issues
     * @return array<string,mixed>
     */
    private function stats($issues, CarbonImmutable $date): array
    {
        $byBucket = [];

        foreach (config('workreport.summary_buckets') as $bucket) {
            $rows = $issues->filter(fn (Issue $i) => $i->project?->name === $bucket['label']);

            $byBucket[] = [
                'label' => $bucket['label'],
                'total' => $rows->count(),
                'planned' => $rows->where('status_key', 'planned')->count(),
                'in_progress' => $rows->where('status_key', 'in_progress')->count(),
                'done' => $rows->where('status_key', 'done')->count(),
                'on_hold' => $rows->where('status_key', 'on_hold')->count(),
                'late' => $rows->filter(fn (Issue $i) => $i->isLateOn($date))->count(),
                'high_open' => $rows->filter(fn (Issue $i) => $i->priority_key === 'high' && $i->status_key !== 'done')->count(),
                'avg_percent' => (int) round($rows->avg(fn (Issue $i) => $i->effectivePercent()) ?? 0),
            ];
        }

        return [
            'buckets' => $byBucket,
            'total' => $issues->count(),
            'done' => $issues->where('status_key', 'done')->count(),
            'late' => $issues->filter(fn (Issue $i) => $i->isLateOn($date))->count(),
        ];
    }
}
