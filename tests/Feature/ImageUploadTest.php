<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Document;
use App\Models\Issue;
use App\Models\Member;
use App\Support\Markdown;
use Database\Seeders\IssueSeeder;
use Database\Seeders\MemberSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Images are host-local and access-controlled (docs/documents.md).
 *
 * The rule this file guards is the privacy one: an image inside a private document
 * must not be readable by anybody who is not on that document, and a Markdown image
 * pointing anywhere but /uploads/{id} must not render at all.
 */
class ImageUploadTest extends TestCase
{
    use RefreshDatabase;

    private Member $author;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed([MemberSeeder::class, IssueSeeder::class]);
        $this->author = $this->signInAs(Member::where('name', '이성민')->firstOrFail());
    }

    private function upload(array $extra = [], string $mime = 'image/png'): TestResponse
    {
        return $this->postJson('/uploads', [
            'file' => UploadedFile::fake()->image('설비도면.png', 40, 30)->mimeType($mime),
            ...$extra,
        ]);
    }

    public function test_an_image_is_stored_on_this_host_and_served_through_the_app(): void
    {
        $document = Document::create([
            'slug' => 'proc-1', 'title' => '절차서', 'body' => '', 'owner_id' => $this->author->id, 'revision' => 1,
        ]);

        $json = $this->upload(['document' => $document->id])->assertCreated()->json();

        // The browser is given the proxy path (/report/uploads/12); the application
        // itself registers routes at the root, which is what this test talks to.
        $this->assertStringStartsWith(config('workreport.base_path').'/uploads/', $json['url']);
        $this->assertStringNotContainsString('http', $json['url']);
        // EasyMDE picks "image" over "file link" by the extension, so the URL has to
        // end in one: /uploads/12/shot.png.
        $this->assertStringEndsWith('.png', $json['url']);
        $this->assertStringContainsString('](/', $json['markdown']);

        $attachment = Attachment::findOrFail($json['id']);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame($document->id, $attachment->attachable_id);

        $this->get('/uploads/'.$attachment->id)->assertOk();
    }

    public function test_only_images_are_accepted(): void
    {
        $this->postJson('/uploads', [
            'file' => UploadedFile::fake()->create('payload.pdf', 12, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_a_private_documents_image_is_not_readable_by_outsiders(): void
    {
        $document = Document::create([
            'slug' => 'private-1', 'title' => '비공개', 'body' => '',
            'visibility' => Document::VISIBILITY_PRIVATE, 'owner_id' => $this->author->id, 'revision' => 1,
        ]);

        $id = $this->upload(['document' => $document->id])->assertCreated()->json('id');

        // The author may read it…
        $this->get('/uploads/'.$id)->assertOk();

        // …somebody else may not, even with the exact URL.
        $this->signInAs(Member::where('name', '정태훈')->firstOrFail());
        $this->get('/uploads/'.$id)->assertForbidden();
    }

    public function test_an_unattached_upload_is_the_uploaders_alone(): void
    {
        $id = $this->upload()->assertCreated()->json('id');

        $this->get('/uploads/'.$id)->assertOk();

        $this->signInAs(Member::where('name', '정운교')->firstOrFail());
        $this->get('/uploads/'.$id)->assertForbidden();
    }

    public function test_markdown_renders_local_images_and_refuses_foreign_ones(): void
    {
        $local = Markdown::toHtml('![도면](uploads/12)');
        $this->assertStringContainsString('<img src="uploads/12"', $local);

        $prefixed = Markdown::toHtml('![도면](/report/uploads/12)');
        $this->assertStringContainsString('<img src="/report/uploads/12"', $prefixed);

        // With the filename EasyMDE writes (the shape the editor actually produces).
        $named = Markdown::toHtml('![도면](/report/uploads/12/설비도면.png)');
        $this->assertStringContainsString('<img src="/report/uploads/12/', $named);

        // No image host, no tracking pixel: the requirement is that images live here.
        $foreign = Markdown::toHtml('![추적](/pixel.gif) ![밖](https://tracker.example/x.png)');
        $this->assertStringNotContainsString('<img', $foreign);
        $this->assertStringContainsString('외부 이미지 차단', $foreign);
    }

    public function test_board_images_are_reachable_by_any_member(): void
    {
        $issue = Issue::firstOrFail();

        $id = $this->upload(['issue' => $issue->id])->assertCreated()->json('id');

        $this->signInAs(Member::where('name', '채길용')->firstOrFail());
        $this->get('/uploads/'.$id)->assertOk();
    }
}
