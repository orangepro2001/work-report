<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Member;
use Database\Seeders\IssueSeeder;
use Database\Seeders\MemberSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The drawer's live update.
 *
 * After a save the page re-fetches one region (X-Live-Region) instead of reloading:
 * the region is rendered by the same Blade as the first render, so a card that
 * changed column and three counters that changed value cannot disagree with the
 * database — and JavaScript never grows a second renderer.
 */
class LiveRegionTest extends TestCase
{
    use RefreshDatabase;

    private Member $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MemberSeeder::class, IssueSeeder::class]);
        $this->actor = $this->signInAs(Member::where('name', '이성민')->firstOrFail());
    }

    public function test_the_board_region_can_be_fetched_without_the_layout(): void
    {
        $full = (string) $this->get('/board')->assertOk()->getContent();
        $this->assertStringContainsString('<html', $full);

        $fragment = (string) $this->get('/board', ['X-Live-Region' => 'board'])->assertOk()->getContent();

        $this->assertStringNotContainsString('<html', $fragment);
        $this->assertStringNotContainsString('<nav class="nav"', $fragment);
        $this->assertStringContainsString('class="column', $fragment);
        $this->assertStringContainsString('data-issue-id=', $fragment);
    }

    public function test_every_lens_serves_the_same_region_name(): void
    {
        foreach (['/board', '/list', '/backlog'] as $uri) {
            $this->get($uri, ['X-Live-Region' => 'board'])
                ->assertOk()
                ->assertHeader('content-type', 'text/html; charset=UTF-8');
        }
    }

    public function test_a_status_change_is_visible_in_the_next_rendering(): void
    {
        // The 일보 grid is filtered to the signed-in person, so the issue has to be
        // one of theirs for all three lenses to show it.
        // 일보 filters by assignee/co-assignee (Issue::scopeForMember), not by reporter.
        $issue = Issue::where('status_key', '!=', 'done')
            ->where('assignee_id', $this->actor->id)
            ->orderBy('id')
            ->firstOrFail();

        $this->patchJson("/issues/{$issue->id}", ['status_key' => 'done'])->assertOk();

        // The list lens marks the row, the kanban moves the card, the 일보 grid dims it.
        $list = (string) $this->get('/list', ['X-Live-Region' => 'board'])->assertOk()->getContent();
        $this->assertStringContainsString('row--done', $list);
        $this->assertStringContainsString('data-issue-id="'.$issue->id.'"', $list);

        $board = (string) $this->get('/board', ['X-Live-Region' => 'board'])->assertOk()->getContent();
        $this->assertStringContainsString('card--done', $board);

        $daily = (string) $this->get('/daily', ['X-Live-Region' => 'daily'])->assertOk()->getContent();
        $this->assertStringNotContainsString('<html', $daily);
        $this->assertStringContainsString('is-done', $daily);
    }

    public function test_the_daily_grid_serves_its_own_region(): void
    {
        $fragment = (string) $this->get('/daily', ['X-Live-Region' => 'daily'])->assertOk()->getContent();

        $this->assertStringNotContainsString('<html', $fragment);
        $this->assertStringContainsString('grid--sheet', $fragment);
    }
}
