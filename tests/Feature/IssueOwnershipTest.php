<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\IssueTodo;
use App\Models\Member;
use Database\Seeders\IssueSeeder;
use Database\Seeders\MemberSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Strict isolation: an issue belongs to its assignee and its reporter.
 *
 * Everyone signed in can read the board — that is the point of it — but editing and
 * deleting are for the person responsible for that card. The rule is enforced here, on
 * the server, and the panel only reflects it: a hidden button is not a permission.
 */
class IssueOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Member $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MemberSeeder::class, IssueSeeder::class]);
        $this->me = $this->signInAs(Member::where('name', '이성민')->firstOrFail());
    }

    /** A card that is neither assigned to nor reported by the actor. */
    private function foreign(): Issue
    {
        return Issue::where('assignee_id', '!=', $this->me->id)
            ->where('reporter_id', '!=', $this->me->id)
            ->firstOrFail();
    }

    private function mine(): Issue
    {
        return Issue::where('assignee_id', $this->me->id)->firstOrFail();
    }

    public function test_somebody_elses_card_cannot_be_edited(): void
    {
        $issue = $this->foreign();

        $this->patchJson("/issues/{$issue->id}", ['status_key' => 'done'])->assertForbidden();
        $this->assertNotSame('done', $issue->fresh()->status_key);
    }

    public function test_somebody_elses_card_cannot_be_moved(): void
    {
        $issue = $this->foreign();

        $this->postJson("/issues/{$issue->id}/move", ['status_key' => 'done', 'board_rank' => 1])
            ->assertForbidden();
    }

    public function test_somebody_elses_card_cannot_be_deleted(): void
    {
        $issue = $this->foreign();

        $this->deleteJson("/issues/{$issue->id}")->assertForbidden();
        $this->assertNotSoftDeleted('issues', ['id' => $issue->id]);
    }

    public function test_somebody_elses_checklist_cannot_be_changed(): void
    {
        $issue = $this->foreign();

        $this->postJson("/issues/{$issue->id}/todos", ['title' => '몰래 추가'])->assertForbidden();
        $this->assertSame(0, IssueTodo::where('issue_id', $issue->id)->count());
    }

    public function test_reading_and_commenting_stay_open(): void
    {
        $issue = $this->foreign();

        $this->get("/issues/{$issue->id}", ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();

        // A comment is additive — the board is for collaborating, not for a silent wall.
        $this->postJson("/issues/{$issue->id}/comments", ['body' => '확인 부탁드립니다'])
            ->assertCreated();
    }

    public function test_a_comment_can_only_be_removed_by_its_author(): void
    {
        $issue = $this->foreign();
        $commentId = $this->postJson("/issues/{$issue->id}/comments", ['body' => '내 댓글'])
            ->assertCreated()->json('comment.id');

        // Somebody else cannot remove it…
        $other = Member::where('name', '채길용')->firstOrFail();
        $this->signInAs($other);
        $this->deleteJson("/comments/{$commentId}")->assertForbidden();

        // …its author can.
        $this->signInAs($this->me);
        $this->deleteJson("/comments/{$commentId}")->assertOk();
    }

    public function test_the_panel_shows_a_foreign_card_as_read_only(): void
    {
        $issue = $this->foreign();

        $html = (string) $this->get("/issues/{$issue->id}", ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-owned="0"', $html);
        $this->assertStringContainsString(__('ui.issue.read_only'), $html);
        $this->assertStringContainsString('disabled', $html);
    }

    public function test_the_owner_still_gets_the_editable_panel(): void
    {
        $issue = $this->mine();

        $html = (string) $this->get("/issues/{$issue->id}", ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-owned="1"', $html);
        $this->assertStringNotContainsString(__('ui.issue.read_only'), $html);

        $this->patchJson("/issues/{$issue->id}", ['percent' => 55])->assertOk();
        $this->assertSame(55, $issue->fresh()->percent);
    }
}
