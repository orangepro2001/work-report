<?php

namespace Tests\Unit;

use App\Models\Issue;
use App\Models\Member;
use App\Support\Markdown;
use Database\Seeders\IssueSeeder;
use Database\Seeders\MemberSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `tasks` block (app/Support/Markdown/Tasks).
 *
 * The point of the block is that a document does not carry a stale copy of the
 * board: a row naming an issue key is rendered from the issue itself.
 */
class MarkdownTasksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MemberSeeder::class, IssueSeeder::class]);
        $this->signInAs(Member::firstOrFail());
    }

    public function test_an_issue_key_is_rendered_from_the_board(): void
    {
        $issue = Issue::where('status_key', 'done')->firstOrFail();

        $html = Markdown::toHtml("```tasks\n{$issue->issue_key}\n```");

        $this->assertStringContainsString('class="tasks"', $html);
        $this->assertStringContainsString(e($issue->summary), $html);
        $this->assertStringContainsString('data-issue-id="'.$issue->id.'"', $html);
        $this->assertStringContainsString('100% 완료', $html);
    }

    public function test_plain_rows_carry_priority_progress_and_status(): void
    {
        $html = Markdown::toHtml("```tasks\n절차서 초안 작성 | high | 40 | in_progress\n검토 | low | 0 | planned\n```");

        $this->assertStringContainsString('절차서 초안 작성', $html);
        $this->assertStringContainsString('High', $html);
        $this->assertStringContainsString('40%', $html);
        $this->assertStringContainsString('진행 중', $html);
        // Nobody is done, so the header meter reads zero.
        $this->assertStringContainsString('0% 완료', $html);
    }

    public function test_the_header_meter_counts_finished_rows(): void
    {
        $done = Issue::where('status_key', 'done')->firstOrFail();
        $open = Issue::where('status_key', '!=', 'done')->firstOrFail();

        $html = Markdown::toHtml("```tasks\n{$done->issue_key}\n{$open->issue_key}\n```");

        $this->assertStringContainsString('1 / 2', $html);
        $this->assertStringContainsString('50% 완료', $html);
    }

    public function test_an_unrelated_fence_is_left_alone(): void
    {
        $html = Markdown::toHtml("```php\n\$a = 1;\n```");

        $this->assertStringContainsString('<pre><code', $html);
        $this->assertStringNotContainsString('class="tasks"', $html);
    }

    public function test_markup_in_a_title_is_escaped(): void
    {
        $html = Markdown::toHtml("```tasks\n<script>alert(1)</script> | high | 10 | planned\n```");

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
