<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Services\OidcActorResolver;
use Database\Seeders\IssueSeeder;
use Database\Seeders\MemberSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The SSO picture, rendered on the board (resources/views/components/avatar.blade.php).
 *
 * The claim is a signed URL on the authorization center, kept on the member row at
 * sign-in. Without it the avatar is the initial on the member's colour — which is what
 * the screenshot-and-CSS trick relies on: the image covers the initial, so a dead URL
 * degrades instead of breaking.
 */
class MemberPictureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MemberSeeder::class, IssueSeeder::class]);
    }

    public function test_the_picture_claim_is_stored_and_refreshed_at_sign_in(): void
    {
        $member = Member::where('key', 'jung-taehun')->firstOrFail();
        $member->forceFill(['oidc_issuer' => 'https://sso.test/sso', 'oidc_subject' => '6'])->save();

        $claims = [
            'iss' => 'https://sso.test/sso', 'sub' => '6', 'name' => '정태훈 대리',
            'username' => 'tony', 'picture' => 'https://sso.test/sso/avatar/6/1?sig=abc',
        ];

        $this->withSession(['oidc.claims' => $claims]);
        app(OidcActorResolver::class)->current();

        $this->assertSame('https://sso.test/sso/avatar/6/1?sig=abc', $member->fresh()->picture_url);

        // A new upload at the SSO changes the URL; the next request follows it.
        $this->withSession(['oidc.claims' => array_merge($claims, [
            'picture' => 'https://sso.test/sso/avatar/6/2?sig=def',
        ])]);
        app(OidcActorResolver::class)->current();

        $this->assertSame('https://sso.test/sso/avatar/6/2?sig=def', $member->fresh()->picture_url);
    }

    public function test_the_avatar_component_renders_the_picture_over_the_initial(): void
    {
        $member = Member::where('key', 'jung-taehun')->firstOrFail();
        $member->forceFill(['picture_url' => 'https://sso.test/sso/avatar/6/1?sig=abc'])->save();

        $html = (string) view('components.avatar', ['member' => $member->fresh()])->render();

        $this->assertStringContainsString('avatar__text', $html);
        $this->assertStringContainsString($member->initials(), $html);
        $this->assertStringContainsString('src="https://sso.test/sso/avatar/6/1?sig=abc"', $html);
    }

    public function test_a_member_without_a_picture_renders_the_initial_only(): void
    {
        $member = Member::where('key', 'jung-taehun')->firstOrFail();

        $html = (string) view('components.avatar', ['member' => $member])->render();

        $this->assertStringContainsString('avatar__text', $html);
        $this->assertStringNotContainsString('<img', $html);
    }
}
