<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\Member;
use App\Support\CollabToken;
use Database\Seeders\IssueSeeder;
use Database\Seeders\MemberSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pass into a document's real-time room (docs/documents.md).
 *
 * The Yjs service trusts the signature and nothing else, so the decision "may this
 * person type here?" has to be made exactly once, here.
 */
class CollabTokenTest extends TestCase
{
    use RefreshDatabase;

    private Member $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MemberSeeder::class, IssueSeeder::class]);
        $this->author = $this->signInAs(Member::where('name', '이성민')->firstOrFail());
    }

    private function document(array $attributes = []): Document
    {
        return Document::create($attributes + [
            'slug' => 'collab-'.uniqid(),
            'title' => '절차서',
            'body' => '초안',
            'owner_id' => $this->author->id,
            'revision' => 1,
        ]);
    }

    public function test_an_editor_gets_a_token_for_that_one_document(): void
    {
        $document = $this->document();

        $json = $this->postJson('/docs/'.$document->slug.'/collab')->assertOk()->json();

        $this->assertTrue($json['editable']);
        $this->assertSame($document->slug, $json['room']);
        $this->assertStringContainsString('/collab', $json['url']);

        $claims = CollabToken::verify($json['token']);
        $this->assertNotNull($claims);
        $this->assertSame($document->slug, $claims['room']);
        $this->assertSame($this->author->id, $claims['sub']);
        $this->assertGreaterThan(time(), $claims['exp']);
    }

    public function test_a_viewer_gets_no_token(): void
    {
        $document = $this->document(['visibility' => Document::VISIBILITY_PRIVATE]);
        $viewer = Member::where('name', '정태훈')->firstOrFail();

        DocumentShare::create([
            'document_id' => $document->id,
            'member_id' => $viewer->id,
            'role' => DocumentShare::ROLE_VIEWER,
        ]);

        $this->signInAs($viewer);

        $json = $this->postJson('/docs/'.$document->slug.'/collab')->assertOk()->json();

        $this->assertFalse($json['editable']);
        $this->assertArrayNotHasKey('token', $json);
    }

    public function test_an_outsider_cannot_ask(): void
    {
        $document = $this->document(['visibility' => Document::VISIBILITY_PRIVATE]);

        $this->signInAs(Member::where('name', '정운교')->firstOrFail());

        $this->postJson('/docs/'.$document->slug.'/collab')->assertForbidden();
    }

    public function test_a_token_does_not_verify_after_tampering(): void
    {
        $document = $this->document();
        $token = CollabToken::issue($document, $this->author);

        [$body, $signature] = explode('.', $token);

        $this->assertNotNull(CollabToken::verify($token));
        $this->assertNull(CollabToken::verify($body.'.'.$this->flip($signature)));
        $this->assertNull(CollabToken::verify('nonsense'));
        $this->assertNull(CollabToken::verify(null));
    }

    public function test_an_expired_token_is_refused(): void
    {
        $document = $this->document();
        $token = CollabToken::issue($document, $this->author, ttl: -60);

        $this->assertNull(CollabToken::verify($token));
    }

    private function flip(string $signature): string
    {
        $first = $signature[0] === 'a' ? 'b' : 'a';

        return $first.substr($signature, 1);
    }
}
