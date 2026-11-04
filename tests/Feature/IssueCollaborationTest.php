<?php

namespace Tests\Feature;

use App\Contracts\ActorResolver;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\Member;
use App\Models\Project;
use Database\Seeders\IssueSeeder;
use Database\Seeders\MemberSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The collaborative surface: create, move, comment, and the timeline that renders
 * the result. The timeline bug this suite guards against ("Array to string
 * conversion") only appeared when a *status change* existed to render.
 */
class IssueCollaborationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MemberSeeder::class, IssueSeeder::class]);
    }

    public function test_creating_an_issue_allocates_the_next_key(): void
    {
        $project = Project::where('abbr', 'GOAL')->firstOrFail();
        $before = Issue::where('project_id', $project->id)->max('issue_no');

        $response = $this->postJson('/issues', [
            'project_id' => $project->id,
            'summary' => '새 이슈',
            'priority_key' => 'high',
        ]);

        $response->assertCreated();
        $this->assertSame($before + 1, $response->json('issue.key') === null ? null : $before + 1);
        $this->assertSame(
            'GOAL-'.str_pad((string) ($before + 1), 2, '0', STR_PAD_LEFT),
            $response->json('issue.key')
        );

        $this->assertDatabaseHas('issue_activities', ['verb' => 'created']);
    }

    public function test_moving_an_issue_records_the_status_change(): void
    {
        $issue = Issue::where('status_key', 'planned')->firstOrFail();

        $this->postJson("/issues/{$issue->id}/move", ['status_key' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('issue.status_key', 'in_progress')
            ->assertJsonPath('status_label', '진행 중');

        $activity = IssueActivity::where('issue_id', $issue->id)
            ->where('verb', 'status_changed')->firstOrFail();

        $this->assertSame('planned', $activity->changes['from']);
        $this->assertSame('in_progress', $activity->changes['to']);
    }

    public function test_moving_to_done_forces_one_hundred_percent(): void
    {
        $issue = Issue::where('status_key', 'in_progress')->firstOrFail();

        $this->postJson("/issues/{$issue->id}/move", ['status_key' => 'done'])->assertOk();

        $issue->refresh();
        $this->assertSame(100, $issue->percent);
        $this->assertNotNull($issue->completed_at);
    }

    public function test_the_drawer_renders_a_status_change_without_an_error(): void
    {
        $issue = Issue::where('status_key', 'planned')->firstOrFail();
        $this->postJson("/issues/{$issue->id}/move", ['status_key' => 'on_hold'])->assertOk();

        // This is the regression: the timeline printed the whole status config entry
        // (an array) instead of its label and raised "Array to string conversion".
        $response = $this->get("/issues/{$issue->id}");

        $response->assertOk();
        $response->assertSee('보류', escape: false);
        $response->assertDontSee('Array to string conversion', escape: false);
    }

    public function test_comments_are_rendered_with_mentions_and_emphasis(): void
    {
        $issue = Issue::firstOrFail();

        $response = $this->postJson("/issues/{$issue->id}/comments", [
            'body' => '@이성민 확인 부탁드립니다 **중요**',
        ]);

        $response->assertCreated();
        $html = $response->json('comment.html');

        $this->assertStringContainsString('class="mention"', $html);
        $this->assertStringContainsString('<strong>중요</strong>', $html);
        $this->assertDatabaseHas('issue_activities', [
            'issue_id' => $issue->id,
            'verb' => 'commented',
        ]);
    }

    public function test_comment_html_escapes_scripts_and_rejects_javascript_urls(): void
    {
        $issue = Issue::firstOrFail();

        $html = $this->postJson("/issues/{$issue->id}/comments", [
            'body' => '<script>alert(1)</script> [x](javascript:alert(1)) `code`',
        ])->assertCreated()->json('comment.html');

        $this->assertStringNotContainsString('<script>', $html);
        // The text may still contain the literal string; what matters is that it was
        // never turned into a link.
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
    }

    public function test_a_member_switcher_changes_the_actor(): void
    {
        $member = Member::where('name', '정태훈')->firstOrFail();

        $this->post('/actor', ['member_id' => $member->id])->assertRedirect();

        $this->assertSame($member->id, session('wr.actor_id'));
        $this->assertSame($member->name, app(ActorResolver::class)->require()->name);
    }

    public function test_validation_rejects_an_unknown_status(): void
    {
        $issue = Issue::firstOrFail();

        $this->postJson("/issues/{$issue->id}/move", ['status_key' => 'archived'])
            ->assertStatus(422);
    }
}
