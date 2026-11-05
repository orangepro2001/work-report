<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Member;
use Database\Seeders\IssueSeeder;
use Database\Seeders\MemberSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The people page (app/Http/Controllers/MemberController.php).
 *
 * The rule it exists to enforce: a member with history is deactivated, never deleted.
 * Fourteen of the eighteen foreign keys pointing at `members` are `on delete set null`,
 * so deleting somebody silently erases who wrote what — the board would keep the text and
 * forget the author.
 */
class MemberAdminTest extends TestCase
{
    use RefreshDatabase;

    private Member $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MemberSeeder::class, IssueSeeder::class]);
        $this->me = $this->signInAs(Member::where('name', '이성민')->firstOrFail());
    }

    public function test_a_member_can_be_added_with_a_key_that_links_the_sso_account(): void
    {
        $this->post('/members', [
            'name' => '홍길동',
            'key' => 'hong-gildong',
            'title' => '사원',
        ])->assertRedirect();

        $member = Member::where('key', 'hong-gildong')->firstOrFail();

        $this->assertSame('홍길동', $member->name);
        $this->assertTrue($member->active);
        $this->assertNull($member->oidc_subject, 'linking happens at the first sign-in');
    }

    public function test_a_member_can_be_deactivated_and_reactivated(): void
    {
        $member = Member::where('key', 'jung-taehun')->firstOrFail();

        $this->patch("/members/{$member->id}", ['active' => 0])->assertRedirect();
        $this->assertFalse($member->fresh()->active);

        // An inactive member leaves every picker…
        $html = (string) $this->get('/board')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-member-id="'.$member->id.'"', $html);

        $this->patch("/members/{$member->id}", ['active' => 1])->assertRedirect();
        $this->assertTrue($member->fresh()->active);
    }

    public function test_a_member_with_history_cannot_be_deleted(): void
    {
        $member = Member::where('name', '채길용')->firstOrFail();
        $this->assertGreaterThan(0, $member->referenceCount());

        $this->delete("/members/{$member->id}")->assertSessionHasErrors('member');

        $this->assertNotNull($member->fresh());
    }

    public function test_a_member_without_history_can_be_deleted(): void
    {
        $member = Member::create([
            'key' => 'temp-person', 'name' => '임시', 'color' => '#7F7F7F',
            'position' => 9, 'active' => true, 'is_external' => true,
        ]);

        $this->delete("/members/{$member->id}")->assertRedirect();

        $this->assertNull(Member::find($member->id));
    }

    public function test_nobody_can_delete_themselves(): void
    {
        $this->delete("/members/{$this->me->id}")->assertForbidden();
        $this->assertNotNull($this->me->fresh());
    }

    public function test_the_page_lists_people_and_their_state(): void
    {
        $html = (string) $this->get('/members')->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.members.title'), $html);
        foreach (['이성민', '정태훈', '채길용'] as $name) {
            $this->assertStringContainsString($name, $html);
        }
        // The delete buttons of people with history are disabled, not merely guarded.
        $this->assertStringContainsString('disabled', $html);
    }

    public function test_a_deactivated_member_keeps_their_history(): void
    {
        $member = Member::where('name', '채길용')->firstOrFail();
        $before = Issue::where('assignee_id', $member->id)->count();

        $this->patch("/members/{$member->id}", ['active' => 0])->assertRedirect();

        $this->assertSame($before, Issue::where('assignee_id', $member->id)->count());
        $this->assertSame('채길용', Issue::where('assignee_id', $member->id)->first()->assignee->name);
    }
}
