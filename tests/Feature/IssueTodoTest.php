<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\IssueTodo;
use Database\Seeders\IssueSeeder;
use Database\Seeders\MemberSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An issue's checklist is data, not a Markdown block (app/Models/IssueTodo.php).
 *
 * Ticking a box, renaming one, reordering and deleting are single requests that answer
 * with the whole list and its progress — which is exactly what the panel renders.
 */
class IssueTodoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MemberSeeder::class, IssueSeeder::class]);
        $this->signInAs();
    }

    public function test_items_can_be_added_and_progress_follows(): void
    {
        $issue = Issue::firstOrFail();

        $json = $this->postJson("/issues/{$issue->id}/todos", ['title' => '도면 확인'])->assertOk()->json();

        $this->assertSame(1, $json['total']);
        $this->assertSame(0, $json['done']);
        $this->assertSame('도면 확인', $json['todos'][0]['title']);

        $todo = IssueTodo::firstOrFail();

        $json = $this->patchJson("/todos/{$todo->id}", ['done' => true])->assertOk()->json();

        $this->assertSame(1, $json['done']);
        $this->assertSame(100, $json['percent']);
        $this->assertTrue($todo->fresh()->done);
    }

    public function test_items_can_be_renamed_reordered_and_removed(): void
    {
        $issue = Issue::firstOrFail();

        foreach (['하나', '둘'] as $title) {
            $this->postJson("/issues/{$issue->id}/todos", ['title' => $title])->assertOk();
        }

        [$first, $second] = IssueTodo::orderBy('position')->get()->all();

        $this->patchJson("/todos/{$first->id}", ['title' => '하나 (수정)'])->assertOk();
        $this->assertSame('하나 (수정)', $first->fresh()->title);

        $json = $this->postJson("/todos/{$second->id}/move", ['direction' => 'up'])->assertOk()->json();
        $this->assertSame('둘', $json['todos'][0]['title']);
        $this->assertSame('하나 (수정)', $json['todos'][1]['title']);

        $json = $this->deleteJson("/todos/{$first->id}")->assertOk()->json();
        $this->assertSame(1, $json['total']);
        $this->assertNull(IssueTodo::find($first->id));
    }

    public function test_the_panel_renders_the_checklist_with_its_progress(): void
    {
        $issue = Issue::firstOrFail();

        $this->postJson("/issues/{$issue->id}/todos", ['title' => '장비 점검'])->assertOk();
        $this->postJson("/issues/{$issue->id}/todos", ['title' => '결과 정리'])->assertOk();
        $this->patchJson('/todos/'.IssueTodo::orderBy('id')->first()->id, ['done' => true])->assertOk();

        $html = (string) $this->get("/issues/{$issue->id}", ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->getContent();

        $this->assertStringContainsString('issueTodos(', $html);
        $this->assertStringContainsString('장비 점검', $html);
        $this->assertStringContainsString('결과 정리', $html);
        // Js::from escapes quotes as \u0022, so match the payload the way it ships.
        $this->assertStringContainsString('\u0022percent\u0022:50', $html);
    }

    public function test_a_hard_deleted_issue_takes_its_checklist_with_it(): void
    {
        $issue = Issue::firstOrFail();
        $this->postJson("/issues/{$issue->id}/todos", ['title' => '정리'])->assertOk();

        $this->assertSame(1, IssueTodo::count());

        // Issues are soft-deleted, so the cascade only fires on a real row removal —
        // which is what the database constraint is there for.
        $issue->forceDelete();

        $this->assertSame(0, IssueTodo::count());
    }
}
