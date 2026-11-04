<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Allocates the per-project issue number that forms the printable key.
 *
 * The workbook derives its key with a formula over the visible rows
 * (`=COUNTIFS($D$16:$D16,$D16,$E$16:$E16,"<>")`), which renumbers whenever a row
 * is inserted. A real tracker cannot do that — other people quote the key in
 * comments — so the number is allocated once, atomically, inside the same
 * transaction that inserts the issue.
 *
 * PostgreSQL cannot combine an aggregate with FOR UPDATE ("FOR UPDATE is not
 * allowed with aggregate functions"), and locking every row of a project just to
 * learn its maximum would serialise unrelated inserts anyway. The counter is
 * therefore kept in a dedicated row: the project row itself is locked
 * (SELECT … FOR UPDATE), its `next_issue_no` column is incremented, and the
 * transaction that reserved the number is the one that inserts the issue. The
 * unique index (project_id, issue_no) remains the last line of defence.
 */
class IssueKeyGenerator
{
    public function nextFor(Project $project): int
    {
        $locked = DB::table('projects')
            ->where('id', $project->getKey())
            ->lockForUpdate()
            ->first(['id', 'next_issue_no', 'issue_high_water']);

        if ($locked === null) {
            throw new \RuntimeException("Project {$project->getKey()} does not exist.");
        }

        // The high-water mark survives deletions, which is what makes the key
        // stable: deleting 3D-02 must not let the next issue become 3D-02 again.
        $next = max((int) $locked->next_issue_no, (int) $locked->issue_high_water + 1, 1);

        DB::table('projects')->where('id', $locked->id)->update([
            'next_issue_no' => $next + 1,
            'issue_high_water' => $next,
        ]);

        return $next;
    }
}
