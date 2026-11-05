<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\Member;
use Database\Seeders\IssueSeeder;
use Database\Seeders\MemberSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The document library (docs/documents.md).
 *
 * Two behaviours matter beyond CRUD: a save built on a stale revision is refused
 * with the newer text (never an overwrite), and a private document is invisible to
 * everyone who was not invited.
 */
class DocumentLibraryTest extends TestCase
{
    use RefreshDatabase;

    private Member $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MemberSeeder::class, IssueSeeder::class]);
        $this->author = $this->signInAs(Member::where('name', '이성민')->firstOrFail());
    }

    /** Mirrors the controller: a document always starts with its first revision. */
    private function document(array $attributes = []): Document
    {
        $document = Document::create($attributes + [
            'slug' => 'proc-'.uniqid(),
            'title' => '검사 절차서',
            'body' => '초안',
            'owner_id' => $this->author->id,
            'revision' => 1,
        ]);

        DocumentRevision::create([
            'document_id' => $document->id,
            'revision' => 1,
            'title' => $document->title,
            'body' => $document->body,
            'editor_id' => $this->author->id,
            'created_at' => now(),
        ]);

        return $document;
    }

    public function test_a_document_can_be_created_and_read(): void
    {
        $this->post('/docs', ['title' => '검사 절차서', 'visibility' => 'team'])
            ->assertRedirect();

        $document = Document::firstOrFail();

        $this->assertSame('검사 절차서', $document->title);
        $this->assertSame(1, $document->revision);
        $this->assertSame(1, DocumentRevision::where('document_id', $document->id)->count());

        $this->get('/docs/'.$document->slug)->assertOk()->assertSee('검사 절차서');
        $this->get('/docs')->assertOk()->assertSee('검사 절차서');
    }

    public function test_a_save_increments_the_revision_and_writes_history(): void
    {
        $document = $this->document();

        $json = $this->patchJson('/docs/'.$document->slug, [
            'title' => '검사 절차서 v2',
            'body' => '## 절차\n\n1. 준비',
            'revision' => 1,
        ])->assertOk()->json();

        $this->assertSame(2, $json['revision']);
        $this->assertSame(2, $document->fresh()->revision);
        $this->assertSame(2, DocumentRevision::where('document_id', $document->id)->count());
    }

    public function test_a_stale_revision_is_refused_and_the_newer_text_handed_back(): void
    {
        $document = $this->document();

        // Someone else saves first…
        $this->patchJson('/docs/'.$document->slug, ['body' => '다른 사람 저장', 'revision' => 1])->assertOk();

        // …and this browser, still on revision 1, must not overwrite it.
        $response = $this->patchJson('/docs/'.$document->slug, ['body' => '내 저장', 'revision' => 1])
            ->assertStatus(409);

        $this->assertSame('다른 사람 저장', $response->json('body'));
        $this->assertSame('다른 사람 저장', $document->fresh()->body);
        $this->assertTrue($response->json('conflict'));
    }

    public function test_a_private_document_is_invisible_to_everyone_else(): void
    {
        $document = $this->document(['visibility' => Document::VISIBILITY_PRIVATE]);

        $this->get('/docs')->assertOk()->assertSee($document->title);

        $this->signInAs(Member::where('name', '정태훈')->firstOrFail());

        $this->get('/docs')->assertOk()->assertDontSee($document->title);
        $this->get('/docs/'.$document->slug)->assertForbidden();
        $this->getJson('/docs/'.$document->slug.'/state')->assertForbidden();
    }

    public function test_sharing_opens_a_private_document_for_one_person(): void
    {
        $document = $this->document(['visibility' => Document::VISIBILITY_PRIVATE]);
        $guest = Member::where('name', '정태훈')->firstOrFail();

        $this->post('/docs/'.$document->slug.'/share', ['member_id' => $guest->id, 'role' => 'viewer'])
            ->assertRedirect();

        $this->signInAs($guest);
        $this->get('/docs/'.$document->slug)->assertOk();

        // Viewer, not editor: the API refuses the write.
        $this->patchJson('/docs/'.$document->slug, ['body' => 'x', 'revision' => 1])->assertForbidden();
    }

    public function test_a_revision_can_be_restored(): void
    {
        $document = $this->document();

        $this->patchJson('/docs/'.$document->slug, ['body' => '나쁜 편집', 'revision' => 1])->assertOk();
        $this->post('/docs/'.$document->slug.'/restore/1')->assertRedirect();

        $this->assertSame('초안', $document->fresh()->body);
        // The restore is itself a revision, so an undo can be undone.
        $this->assertSame(3, $document->fresh()->revision);
    }

    public function test_the_state_endpoint_reports_readers_and_revision(): void
    {
        $document = $this->document();

        $this->get('/docs/'.$document->slug)->assertOk();

        $json = $this->getJson('/docs/'.$document->slug.'/state')->assertOk()->json();

        $this->assertSame(1, $json['revision']);
        $this->assertSame($this->author->id, $json['readers'][0]['id']);
        $this->assertTrue($json['readers'][0]['me']);
    }

    public function test_markdown_body_is_rendered_through_the_shared_renderer(): void
    {
        $document = $this->document([
            'body' => "**굵게** @이성민\n\n```tasks\n검토 | high | 40 | in_progress\n```",
        ]);

        // The editor shows the source; read mode shows what everybody else sees.
        $this->get('/docs/'.$document->slug.'?read=1')->assertOk()
            ->assertSee('class="tasks"', false)
            ->assertSee('<span class="mention">@이성민</span>', false);
    }
}
