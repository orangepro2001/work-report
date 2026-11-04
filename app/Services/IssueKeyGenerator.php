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
 * transaction that inserts the issue:
 *
 *   SELECT max(issue_no) FROM issues WHERE project_id = ? FOR UPDATE
 *
 * A partial unique index (project_id, issue_no) makes a collision impossible even
 * if two requests race past the lock.
 */
class IssueKeyGenerator
{
    public function nextFor(Project $project): int
    {
        $max = DB::table('issues')
            ->where('project_id', $project->getKey())
            ->lockForUpdate()
            ->max('issue_no');

        return ((int) $max) + 1;
    }
}
