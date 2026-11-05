<?php

namespace Tests\Feature;

use App\Contracts\ActorResolver;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\Member;
use App\Models\Project;
use Database\Seeders\IssueSeeder;
use Database\Seeders\MemberSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The collaborative surface: create, move, comment, and the timeline that renders
 * the result. The timeline bug this suite guards against ("Array to string
 * conversion") only appeared when a *status change* existed to render.
 */
class IssueCollaborationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MemberSeeder::class, IssueSeeder::class]);
        $this->signInAs(Member::where('name', '이성민')->firstOrFail());
    }

    public function test_creating_an_issue_allocates_the_next_key(): void
    {
        $project = Project::where('abbr', 'GOAL')->firstOrFail();
        $before = Issue::where('project_id', $project->id)->max('issue_no');

        $response = $this->postJson('/issues', [
            'project_id' => $project->id,
            'summary' => '새 이슈',
            'priority_key' => 'high',
        ]);

        $response->assertCreated();
        $this->assertSame($before + 1, $response->json('issue.key') === null ? null : $before + 1);
        $this->assertSame(
            'GOAL-'.str_pad((string) ($before + 1), 2, '0', STR_PAD_LEFT),
            $response->json('issue.key')
        );

        $this->assertDatabaseHas('issue_activities', ['verb' => 'created']);
    }

    public function test_moving_an_issue_records_the_status_change(): void
    {
        $issue = Issue::where('status_key', 'planned')->firstOrFail();

        $this->postJson("/issues/{$issue->id}/move", ['status_key' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('issue.status_key', 'in_progress')
            ->assertJsonPath('status_label', '진행 중');

        $activity = IssueActivity::where('issue_id', $issue->id)
            ->where('verb', 'status_changed')->firstOrFail();

        $this->assertSame('planned', $activity->changes['from']);
        $this->assertSame('in_progress', $activity->changes['to']);
    }

    public function test_moving_to_done_forces_one_hundred_percent(): void
    {
        $issue = Issue::where('status_key', 'in_progress')->firstOrFail();

        $this->postJson("/issues/{$issue->id}/move", ['status_key' => 'done'])->assertOk();

        $issue->refresh();
        $this->assertSame(100, $issue->percent);
        $this->assertNotNull($issue->completed_at);
    }

    public function test_the_drawer_renders_a_status_change_without_an_error(): void
    {
        $issue = Issue::where('status_key', 'planned')->firstOrFail();
        $this->postJson("/issues/{$issue->id}/move", ['status_key' => 'on_hold'])->assertOk();

        // This is the regression: the timeline printed the whole status config entry
        // (an array) instead of its label and raised "Array to string conversion".
        $response = $this->get("/issues/{$issue->id}");

        $response->assertOk();
        $response->assertSee('보류', escape: false);
        $response->assertDontSee('Array to string conversion', escape: false);
    }

    public function test_comments_are_rendered_with_mentions_and_emphasis(): void
    {
        $issue = Issue::firstOrFail();

        $response = $this->postJson("/issues/{$issue->id}/comments", [
            'body' => '@이성민 확인 부탁드립니다 **중요**',
        ]);

        $response->assertCreated();
        $html = $response->json('comment.html');

        $this->assertStringContainsString('class="mention"', $html);
        $this->assertStringContainsString('<strong>중요</strong>', $html);
        $this->assertDatabaseHas('issue_activities', [
            'issue_id' => $issue->id,
            'verb' => 'commented',
        ]);
    }

    public function test_comment_html_escapes_scripts_and_rejects_javascript_urls(): void
    {
        $issue = Issue::firstOrFail();

        $html = $this->postJson("/issues/{$issue->id}/comments", [
            // Multi-line on purpose: a body that *starts* with an HTML block keeps the
            // rest of that line verbatim (escaped), which is CommonMark's behaviour.
            'body' => "**중요** <script>alert(1)</script>\n\n`code` [x](javascript:alert(1))",
        ])->assertCreated()->json('comment.html');

        $this->assertStringNotContainsString('<script>', $html);
        // The text may still contain the literal string; what matters is that it was
        // never turned into a link.
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
    }

    public function test_an_anonymous_visitor_is_sent_to_the_sso(): void
    {
        // A fresh session with no claims: the middleware must hand over to the SSO
        // instead of rendering the board.
        $this->flushSession();

        $this->get('/board')->assertRedirect(route('sso.redirect'));
        $this->assertNull(app(ActorResolver::class)->current());
    }

    public function test_the_sso_redirect_carries_state_client_and_scope(): void
    {
        config([
            'workreport.sso.issuer' => 'https://sso.test/sso',
            'workreport.sso.client_id' => 'sso_client',
            'workreport.sso.redirect_uri' => 'https://board.test/report/auth/sso/callback',
        ]);

        $target = $this->get('/auth/sso/redirect')->assertRedirect()->headers->get('Location');
        $parts = parse_url((string) $target);
        parse_str($parts['query'] ?? '', $query);

        $this->assertSame('https://sso.test/sso/oauth/authorize', $parts['scheme'].'://'.$parts['host'].$parts['path']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('sso_client', $query['client_id']);
        $this->assertSame('openid profile', $query['scope']);
        $this->assertSame('https://board.test/report/auth/sso/callback', $query['redirect_uri']);
        $this->assertSame(session('oidc.state'), $query['state']);
    }

    /**
     * A stale or missing state is not something the person can act on: the flow
     * simply starts again. Only a browser that keeps losing the state (no cookies)
     * reaches a page, and that page says what to check.
     */
    public function test_a_reply_from_another_session_starts_the_flow_over(): void
    {
        $this->withSession(['oidc.state' => 'ours'])
            ->get('/auth/sso/callback?state=theirs&code=abc')
            ->assertRedirect(route('sso.redirect'));

        // Third consecutive failure: drop the stale session and hand the person to
        // the SSO's own sign-in page instead of bouncing again.
        $this->withSession(['oidc.state' => 'ours', 'oidc.restarts' => 2])
            ->get('/auth/sso/callback?state=theirs&code=abc')
            ->assertRedirect('https://521985.xyz/sso/login');
    }

    /**
     * A session that cannot identify anybody is dropped before signing in, so the
     * SSO is never handed a state from a previous, broken round trip.
     */
    public function test_a_stale_identity_is_dropped_but_an_in_flight_login_is_kept(): void
    {
        // Unusable claims go away…
        $this->withSession([
            'oidc.claims' => ['iss' => 'x', 'sub' => ''],
            'wr.actor_id' => 3,
            'oidc.state' => 'a-round-trip-that-is-still-moving',
        ])->get('/list?status=done')->assertRedirect(route('sso.redirect'));

        $this->assertNull(session('oidc.claims'));
        $this->assertNull(session('wr.actor_id'));

        // …but the state of a round trip in flight must survive, or another tab (or a
        // reload) would invalidate the reply the browser is about to come back with.
        $this->assertSame('a-round-trip-that-is-still-moving', session('oidc.state'));

        // Where the person was going is remembered with its mount prefix.
        $this->assertSame(url('/list?status=done'), session('url.intended'));
    }

    public function test_a_successful_callback_clears_the_restart_counter(): void
    {
        config([
            'workreport.sso.issuer' => 'https://sso.test/sso',
            'workreport.sso.internal_base' => 'http://127.0.0.1:3550',
            'workreport.sso.redirect_uri' => 'https://board.test/report/auth/sso/callback',
        ]);

        Http::fake([
            'http://127.0.0.1:3550/oauth/token' => Http::response(['access_token' => 'tok']),
            'http://127.0.0.1:3550/oauth/userinfo' => Http::response(['sub' => 'sub-1', 'preferred_username' => 'lee-sungmin']),
        ]);

        $this->withSession(['oidc.state' => 'st', 'oidc.restarts' => 1])
            ->get('/auth/sso/callback?state=st&code=abc')
            ->assertRedirect(route('board'));

        $this->assertNull(session('oidc.restarts'));
    }

    /**
     * The whole dance, with the two server-to-server calls faked. This is the test
     * that was missing when the token exchange was pointed at the public issuer and
     * timed out: the app must talk to the SSO over internal_base, not to the address
     * the browser uses (docs/auth-seam.md).
     */
    /**
     * Moving the authorization center to its own origin changed every `iss` claim. The
     * subject did not change, so a member linked under the old issuer must be re-linked
     * rather than duplicated — otherwise the first sign-in after a migration creates a
     * second "person" with the same name.
     */
    public function test_a_member_linked_under_the_old_issuer_is_re_linked(): void
    {
        config(['workreport.sso.legacy_issuers' => ['https://sso.test']]);

        $member = Member::where('key', 'jung-taehun')->firstOrFail();
        $member->forceFill([
            'oidc_issuer' => 'https://sso.test',
            'oidc_subject' => '6',
        ])->save();

        $this->withSession(['oidc.claims' => [
            'iss' => 'https://sso.test/sso',   // the new origin
            'sub' => '6',
            'name' => '정태훈 대리',
            'username' => 'tony',
        ]]);

        $resolved = app(ActorResolver::class)->require();

        $this->assertSame($member->id, $resolved->id);
        $this->assertSame('https://sso.test/sso', $member->fresh()->oidc_issuer);
        $this->assertSame(4, Member::count());
    }

    public function test_the_callback_exchanges_the_code_and_adopts_the_member(): void
    {
        config([
            'workreport.sso.issuer' => 'https://sso.test/sso',
            'workreport.sso.internal_base' => 'http://127.0.0.1:3550',
            'workreport.sso.client_id' => 'sso_client',
            'workreport.sso.client_secret' => 'sso_secret',
            'workreport.sso.redirect_uri' => 'https://board.test/report/auth/sso/callback',
        ]);

        // 정태훈 is already linked to SSO sub 6, so the claims must land on it. The
        // issuer is the configured one: userinfo does not repeat `iss`, so the app
        // stores config('workreport.sso.issuer') and the link must use the same value.
        $member = Member::where('key', 'jung-taehun')->firstOrFail();
        $member->forceFill(['oidc_issuer' => 'https://sso.test/sso', 'oidc_subject' => '6'])->save();

        Http::fake([
            'http://127.0.0.1:3550/oauth/token' => Http::response(['access_token' => 'tok']),
            'http://127.0.0.1:3550/oauth/userinfo' => Http::response([
                'sub' => '6', 'name' => '정태훈 대리', 'preferred_username' => 'tony',
            ]),
        ]);

        $this->withSession(['oidc.state' => 'round-trip'])
            ->get('/auth/sso/callback?state=round-trip&code=abc')
            ->assertRedirect(route('board'));

        // Sent to the loopback base and with the registered redirect_uri, not the
        // public host the browser came from.
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://127.0.0.1:3550/oauth/token')
            && $request['redirect_uri'] === 'https://board.test/report/auth/sso/callback'
            && $request['client_secret'] === 'sso_secret');

        $this->assertSame('tony', session('oidc.claims.username'));
        $this->assertSame($member->id, app(ActorResolver::class)->require()->id);
        // Nothing new was created: the subject was already linked.
        $this->assertSame(4, Member::count());
    }

    /**
     * The mount prefix has to survive the round trip. The proxy strips /report before
     * PHP runs, so remembering the request as-is sent people to https://host/board —
     * the portal's 404 — instead of https://host/report/board after signing in.
     */
    public function test_the_intended_url_keeps_the_mount_prefix(): void
    {
        config([
            'workreport.sso.issuer' => 'https://sso.test/sso',
            'workreport.sso.internal_base' => 'http://127.0.0.1:3550',
            'workreport.sso.redirect_uri' => 'https://board.test/report/auth/sso/callback',
        ]);

        $this->flushSession();

        $this->get('/list?status=done')->assertRedirect(route('sso.redirect'));

        $this->assertSame(url('/list?status=done'), session('url.intended'));

        // …and the callback sends the browser exactly there.
        Http::fake([
            'http://127.0.0.1:3550/oauth/token' => Http::response(['access_token' => 'tok']),
            'http://127.0.0.1:3550/oauth/userinfo' => Http::response([
                'sub' => 'sub-1', 'name' => '이성민', 'preferred_username' => 'lee-sungmin',
            ]),
        ]);

        $this->withSession(['oidc.state' => 'st'])
            ->get('/auth/sso/callback?state=st&code=abc')
            ->assertRedirect(url('/list?status=done'));
    }

    /**
     * A stale intended URL must not become the landing page: before the mount
     * prefix was fixed, browsers were sent to https://host/board, and the portal
     * answered that with "404 page not found".
     */
    public function test_a_foreign_intended_url_is_not_replayed(): void
    {
        config([
            'workreport.sso.issuer' => 'https://sso.test/sso',
            'workreport.sso.internal_base' => 'http://127.0.0.1:3550',
            'workreport.sso.redirect_uri' => 'https://board.test/report/auth/sso/callback',
        ]);

        Http::fake([
            'http://127.0.0.1:3550/oauth/token' => Http::response(['access_token' => 'tok']),
            'http://127.0.0.1:3550/oauth/userinfo' => Http::response(['sub' => 'sub-1', 'preferred_username' => 'lee-sungmin']),
        ]);

        $this->withSession([
            'oidc.state' => 'st',
            'url.intended' => 'https://board.test/board',   // no /report prefix
        ])->get('/auth/sso/callback?state=st&code=abc')
            ->assertRedirect(route('board'));
    }

    public function test_logging_out_hands_the_browser_to_the_sso(): void
    {
        config(['workreport.sso.issuer' => 'https://sso.test/sso']);

        $this->post('/auth/logout')
            ->assertRedirect('https://sso.test/sso/login?next='.urlencode(url('/')));

        $this->assertNull(session('oidc.claims'));
    }

    public function test_an_unreachable_sso_is_reported_instead_of_a_500(): void
    {
        config([
            'workreport.sso.internal_base' => 'http://127.0.0.1:1',
            'workreport.sso.redirect_uri' => 'https://board.test/report/auth/sso/callback',
        ]);

        Http::fake(['*' => Http::failedConnection()]);

        $this->withSession(['oidc.state' => 'st'])
            ->get('/auth/sso/callback?state=st&code=abc')
            ->assertRedirect(route('signed-out'))
            ->assertSessionHas('status', __('ui.identity.unreachable'));
    }

    public function test_the_panel_serves_a_fragment_or_a_page(): void
    {
        $issue = Issue::firstOrFail();

        // The board asks for the fragment…
        $fragment = (string) $this->get("/issues/{$issue->id}", ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('<html', $fragment);
        $this->assertStringContainsString('id="issue-description-preview"', $fragment);

        // …and opening the same URL directly is a page of its own (full screen mode).
        $page = (string) $this->get("/issues/{$issue->id}")->assertOk()->getContent();
        $this->assertStringContainsString('<html', $page);
        $this->assertStringContainsString('panel-page', $page);
        $this->assertStringContainsString('id="issue-description-preview"', $page);
    }

    public function test_a_tasks_block_in_the_description_is_rendered_in_the_drawer(): void
    {
        $issue = Issue::where('status_key', 'done')->firstOrFail();

        $this->patchJson("/issues/{$issue->id}", [
            'description' => "**절차**\n\n```tasks\n{$issue->issue_key}\n검토 | high | 40 | in_progress\n```",
        ])->assertOk();

        $html = (string) $this->get("/issues/{$issue->id}")->assertOk()->getContent();

        // The drawer used to render the textarea and nothing else, so a tasks block
        // typed there was invisible — the whole point of the block.
        $this->assertStringContainsString('id="issue-description-preview"', $html);
        $this->assertStringContainsString('class="tasks"', $html);
        $this->assertStringContainsString('data-issue-id="'.$issue->id.'"', $html);
        $this->assertStringContainsString('<strong>절차</strong>', $html);
    }

    public function test_an_outsider_can_be_named_as_the_reporter(): void
    {
        $issue = Issue::firstOrFail();

        $json = $this->patchJson("/issues/{$issue->id}", ['reporter_name' => '김철수 과장'])->assertOk()->json();

        $reporter = Member::where('name', '김철수 과장')->firstOrFail();
        $this->assertTrue($reporter->is_external);
        $this->assertNull($reporter->oidc_subject);
        $this->assertSame($reporter->id, $issue->fresh()->reporter_id);
        $this->assertSame($reporter->id, $json['issue']['reporter_id']);
    }

    public function test_the_same_outside_name_is_reused_not_duplicated(): void
    {
        $first = Issue::orderBy('id')->firstOrFail();
        $second = Issue::orderByDesc('id')->firstOrFail();

        $this->patchJson("/issues/{$first->id}", ['reporter_name' => '박영희'])->assertOk();
        $this->patchJson("/issues/{$second->id}", ['reporter_name' => '박영희'])->assertOk();

        $this->assertSame(1, Member::where('name', '박영희')->count());
    }

    public function test_an_outside_reporter_is_never_offered_as_an_assignee(): void
    {
        $issue = Issue::firstOrFail();
        $this->patchJson("/issues/{$issue->id}", ['reporter_name' => '김철수 과장'])->assertOk();

        // The fragment, the way the board fetches it for the drawer/modal.
        $html = (string) $this->get("/issues/{$issue->id}", ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->getContent();

        // The reporter field carries the typed name…
        $this->assertStringContainsString('value="김철수 과장"', $html);

        // …while the assignee picker is a <select> of accounts only: as many options
        // as there are internal people, and the outsider is not one of them.
        $internal = Member::active()->internal()->count();
        $options = preg_match_all('/<option value="\d+"[^>]*>[^<]+<\/option>/', $html, $matches) ? $matches[0] : [];
        $this->assertCount($internal, $options);
        foreach ($options as $option) {
            $this->assertStringNotContainsString('김철수 과장', $option);
        }
    }

    public function test_the_reporter_can_be_changed(): void
    {
        $issue = Issue::firstOrFail();
        $other = Member::whereKeyNot($issue->reporter_id)->firstOrFail();

        $json = $this->patchJson("/issues/{$issue->id}", ['reporter_id' => $other->id])
            ->assertOk()->json();

        $this->assertSame($other->id, $issue->fresh()->reporter_id);
        $this->assertSame($other->id, $json['issue']['reporter_id']);
        // The timeline says what happened, in words, not "reporter_id changed".
        $this->assertStringContainsString(__('activity.reported_by_changed'), $json['activity']);
    }

    public function test_validation_rejects_an_unknown_status(): void
    {
        $issue = Issue::firstOrFail();

        $this->postJson("/issues/{$issue->id}/move", ['status_key' => 'archived'])
            ->assertStatus(422);
    }
}
