<?php

namespace Database\Seeders;

use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Loads the 25 tasks of the reference workbook so the first boot looks exactly
 * like the document the team already uses (database/seeders/issues.json is a
 * direct extraction of sheet 업무일보, rows 16-45).
 */
class IssueSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/issues.json');

        if (! is_file($path)) {
            return;
        }

        $rows = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $projects = Project::all()->keyBy('name');
        $members = Member::all()->keyBy('name');
        $statusByLabel = collect(config('workreport.statuses'))->keyBy('label');
        $priorityByLabel = collect(config('workreport.priorities'))->keyBy('label');

        DB::transaction(function () use ($rows, $projects, $members, $statusByLabel, $priorityByLabel) {
            $rank = [];

            foreach ($rows as $row) {
                $project = $projects[$row['bucket']] ?? null;
                if (! $project) {
                    continue;
                }

                // "3D-01" -> 1
                $no = (int) substr($row['key'], strrpos($row['key'], '-') + 1);
                $names = array_filter(array_map('trim', explode(',', $row['assignees'])));
                $primary = $members[$names[0] ?? ''] ?? null;

                $rank[$project->getKey()] = ($rank[$project->getKey()] ?? 0) + 1;

                $issue = Issue::updateOrCreate(
                    ['project_id' => $project->getKey(), 'issue_no' => $no],
                    [
                        'summary' => $row['summary'],
                        'priority_key' => $priorityByLabel[$row['priority']]['key'] ?? 'medium',
                        'status_key' => $statusByLabel[$row['status']]['key'] ?? 'planned',
                        'percent' => (int) $row['percent'],
                        'reporter_id' => ($members[$row['reporter']] ?? null)?->getKey(),
                        'assignee_id' => $primary?->getKey(),
                        'starts_on' => $row['starts_on'],
                        'due_on' => $row['due_on'],
                        'due_undecided' => (bool) $row['due_undecided'],
                        'note' => $row['note'],
                        'board_rank' => $rank[$project->getKey()],
                        'completed_at' => ($statusByLabel[$row['status']]['key'] ?? null) === 'done' ? now() : null,
                    ],
                );

                $issue->coAssignees()->sync(
                    collect($names)
                        ->skip(1)
                        ->map(fn (string $name, int $i) => [
                            'member_id' => $members[$name]->getKey(),
                            'position' => $i,
                        ])
                        ->values()
                        ->all()
                );
            }

            // Reserve the numbers the seed used, so the next manual issue continues
            // after them instead of colliding with a seeded key.
            foreach ($projects as $project) {
                $max = Issue::where('project_id', $project->getKey())->max('issue_no') ?? 0;
                $project->forceFill([
                    'issue_high_water' => (int) $max,
                    'next_issue_no' => (int) $max + 1,
                ])->save();
            }
        });
    }
}
