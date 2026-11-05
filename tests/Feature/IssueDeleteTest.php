<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Member;
use Database\Seeders\IssueSeeder;
use Database\Seeders\MemberSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleting an issue from its card (app/Http/Controllers/IssueController.php → destroy).
 *
 * Soft delete: the row stays, the key is never reused, and the board's own region is
 * re-rendered afterwards — which is why the endpoint answers with JSON for the card and
 * with a redirect for the panel's old form.
 */
class IssueDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Member $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MemberSeeder::class, IssueSeeder::class]);
        $this->actor = $this->signInAs(Member::where('name', '이성민')->firstOrFail());
    }

    public function test_the_delete_button_is_only_on_your_own_cards(): void
    {
        $mine = Issue::where('assignee_id', $this->actor->id)->firstOrFail();
        // Somebody else's card: neither the assignee nor the reporter is the actor.
        $other = Issue::where('assignee_id', '!=', $this->actor->id)
            ->where('reporter_id', '!=', $this->actor->id)
            ->firstOrFail();

        $html = (string) $this->get('/board')->assertOk()->getContent();

        // The button carries the issue id, so its presence is per-card and precise.
        $this->assertStringContainsString("removeIssue({$mine->id}, '{$mine->issue_key}')", $html);
        $this->assertStringNotContainsString("removeIssue({$other->id}, '{$other->issue_key}')", $html);
    }

    public function test_deleting_answers_json_and_soft_deletes(): void
    {
        $issue = Issue::firstOrFail();

        $json = $this->deleteJson("/issues/{$issue->id}")->assertOk()->json();

        $this->assertTrue($json['ok']);
        $this->assertSame($issue->issue_key, $json['key']);
        $this->assertSoftDeleted('issues', ['id' => $issue->id]);
    }

    public function test_the_panel_form_still_redirects(): void
    {
        $issue = Issue::firstOrFail();

        $this->delete("/issues/{$issue->id}")->assertRedirect(route('board.index'));
        $this->assertSoftDeleted('issues', ['id' => $issue->id]);
    }

    public function test_a_deleted_issue_leaves_the_board(): void
    {
        $issue = Issue::firstOrFail();
        $this->deleteJson("/issues/{$issue->id}")->assertOk();

        $html = (string) $this->get('/board', ['X-Live-Region' => 'board'])->assertOk()->getContent();

        $this->assertStringNotContainsString("data-issue-id=\"{$issue->id}\"", $html);
    }
}
