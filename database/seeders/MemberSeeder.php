<?php

namespace Database\Seeders;

use App\Models\Member;
use App\Models\Project;
use Illuminate\Database\Seeder;

/**
 * People, projects and reporting lines taken from the reference workbook
 * (docs/source-rev02.xlsx, sheet 목록, rows 6-20).
 *
 * Idempotent: safe to run on every boot, which is what the container does.
 */
class MemberSeeder extends Seeder
{
    public function run(): void
    {
        $members = [
            ['name' => '이성민', 'key' => 'lee-sungmin', 'title' => '팀장', 'color' => '#2F5597', 'position' => 1],
            ['name' => '정태훈', 'key' => 'jung-taehun', 'title' => '선임', 'color' => '#C65911', 'position' => 2],
            ['name' => '채길용', 'key' => 'chae-gilyong', 'title' => '선임', 'color' => '#375623', 'position' => 3],
            ['name' => '정운교', 'key' => 'jung-ungyo', 'title' => '부서장', 'color' => '#7030A0', 'position' => 0],
        ];

        $created = [];

        foreach ($members as $row) {
            $created[$row['name']] = Member::updateOrCreate(
                ['key' => $row['key']],
                $row + [
                    'department' => config('workreport.department'),
                    'active' => true,
                ],
            );
        }

        // Reporting line: everyone reports to the department head, the lead reports to no one.
        $head = $created['정운교'];
        foreach ($created as $name => $member) {
            if ($name !== '정운교') {
                $member->forceFill(['reports_to_id' => $head->getKey()])->save();
            }
        }

        $projects = [
            ['name' => '3D AFVI', 'abbr' => '3D', 'color' => '#2F5597', 'position' => 1],
            ['name' => '2D AFVI', 'abbr' => '2D', 'color' => '#375623', 'position' => 2],
            ['name' => '기타', 'abbr' => 'ETC', 'color' => '#7F7F7F', 'position' => 3],
            ['name' => '목표', 'abbr' => 'GOAL', 'color' => '#C65911', 'position' => 4],
        ];

        foreach ($projects as $row) {
            $project = Project::updateOrCreate(['abbr' => $row['abbr']], $row);
            // Everyone is a member of every bucket until project roles are needed.
            $project->members()->syncWithoutDetaching(
                collect($created)->mapWithKeys(fn (Member $m) => [$m->getKey() => ['role' => 'member']])->all()
            );
        }
    }
}
