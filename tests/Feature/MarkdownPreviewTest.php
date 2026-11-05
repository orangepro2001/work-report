<?php

namespace Tests\Feature;

use App\Models\Issue;
use Database\Seeders\IssueSeeder;
use Database\Seeders\MemberSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The editor's preview pane asks the application to render Markdown, so the
 * person previewing sees exactly what will be stored and shown to everybody.
 */
class MarkdownPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MemberSeeder::class, IssueSeeder::class]);
        $this->signInAs();
    }

    public function test_the_preview_renders_the_same_markdown_saved_comments_use(): void
    {
        $response = $this->postJson('/markdown', [
            'text' => "**굵게** and @이성민\n\n| a | b |\n| - | - |\n| 1 | 2 |",
        ])->assertOk();

        $html = (string) $response->json('html');

        $this->assertStringContainsString('<strong>굵게</strong>', $html);
        $this->assertStringContainsString('<span class="mention">@이성민</span>', $html);
        $this->assertStringContainsString('<table>', $html);
    }

    public function test_the_preview_strips_raw_html(): void
    {
        $html = (string) $this->postJson('/markdown', ['text' => '<img src=x onerror=alert(1)>'])
            ->assertOk()->json('html');

        // Escaped, so it is inert text rather than a tag.
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function test_a_comment_round_trips_through_the_renderer(): void
    {
        $issue = Issue::firstOrFail();

        $html = (string) $this->postJson("/issues/{$issue->id}/comments", [
            'body' => "**중요** @이성민\n\n- 하나\n- 둘",
        ])->assertCreated()->json('comment.html');

        $this->assertStringContainsString('<strong>중요</strong>', $html);
        $this->assertStringContainsString('<span class="mention">@이성민</span>', $html);
        $this->assertStringContainsString('<li>하나</li>', $html);
    }

    public function test_the_preview_needs_a_signed_in_person(): void
    {
        $this->flushSession();

        $this->postJson('/markdown', ['text' => 'x'])->assertRedirect(route('sso.redirect'));
    }
}
