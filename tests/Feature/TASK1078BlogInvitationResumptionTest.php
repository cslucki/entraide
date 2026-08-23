<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\BlogPostInvitation;
use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\BlogInvitationService;
use App\Services\LoopInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Blog invitation acceptance, and its resumption after authentication.
 *
 * The bug this covers: the guest branch parked a token under `invitation_token`
 * that nothing ever read, so a recipient who was not signed in reached the login
 * page and their acceptance was never picked up. Alongside it, accept() checked
 * neither the recipient address nor the Organization.
 */
class TASK1078BlogInvitationResumptionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private BlogPost $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'is_active' => true, 'is_default' => true, 'slug' => 'main-t1078',
        ]);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        app()->instance('current_organization', $this->org);

        // No BlogPostFactory in this codebase — created explicitly, as in
        // BlogInvitationTest.
        $this->post = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'title' => 'Article T1078',
            'slug' => 'article-t1078',
            'content' => 'Some content.',
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    private function invitation(array $overrides = []): BlogPostInvitation
    {
        return BlogPostInvitation::create(array_merge([
            'blog_post_id' => $this->post->id,
            'sender_id' => $this->owner->id,
            'recipient_email' => 'invitee@example.com',
            'invitation_type' => 'external',
            'status' => 'pending',
            'organization_id' => $this->org->id,
        ], $overrides));
    }

    private function service(): BlogInvitationService
    {
        return app(BlogInvitationService::class);
    }

    private function invitee(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'email' => 'invitee@example.com',
            'password' => bcrypt('password-1234'),
        ], $overrides));
    }

    // ── Acceptation directe ─────────────────────────────────────────────────

    public function test_the_recipient_accepts_and_becomes_co_author(): void
    {
        $invitation = $this->invitation();
        $user = $this->invitee();

        $this->actingAs($user)
            ->post(route('blog.invite.accept', $invitation->token))
            ->assertRedirect(route('organization.blog.edit', ['organization' => $this->org->slug, 'post' => $this->post->slug]));

        $this->assertDatabaseHas('blog_post_user', [
            'blog_post_id' => $this->post->id,
            'user_id' => $user->id,
            'role' => 'coauthor',
        ]);
        $this->assertSame('accepted', $invitation->fresh()->status);
        $this->assertSame($user->id, $invitation->fresh()->accepted_by_user_id);
    }

    public function test_a_token_can_never_be_accepted_by_another_email(): void
    {
        $invitation = $this->invitation();
        $intruder = User::factory()->create([
            'organization_id' => $this->org->id, 'email' => 'intruder@example.com',
        ]);

        $this->actingAs($intruder)
            ->post(route('blog.invite.accept', $invitation->token))
            ->assertRedirect(route('blog.invite.show', $invitation->token))
            ->assertSessionHas('error', __('blog-invitation.accept_email_mismatch'));

        $this->assertDatabaseMissing('blog_post_user', [
            'blog_post_id' => $this->post->id, 'user_id' => $intruder->id,
        ]);
        $this->assertSame('pending', $invitation->fresh()->status);

        // The reason must actually reach the page: a silent bounce leaves the
        // visitor with no idea why nothing happened.
        $this->actingAs($intruder)
            ->get(route('blog.invite.show', $invitation->token))
            ->assertOk()
            ->assertSee(__('blog-invitation.accept_email_mismatch'));
    }

    public function test_email_comparison_ignores_case_and_whitespace(): void
    {
        $invitation = $this->invitation(['recipient_email' => '  MiXeD@Example.Com ']);
        $user = $this->invitee(['email' => 'mixed@example.com']);

        $this->assertSame(
            BlogInvitationService::RESULT_ACCEPTED,
            $this->service()->accept($invitation->token, $user)['result'],
        );
    }

    public function test_a_user_from_another_organization_cannot_accept(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true, 'slug' => 'other-t1078']);
        $invitation = $this->invitation();
        $foreigner = User::factory()->create([
            'organization_id' => $otherOrg->id, 'email' => 'invitee@example.com',
        ]);

        $outcome = $this->service()->accept($invitation->token, $foreigner);

        $this->assertSame(BlogInvitationService::RESULT_POST_UNAVAILABLE, $outcome['result']);
        $this->assertDatabaseMissing('blog_post_user', [
            'blog_post_id' => $this->post->id, 'user_id' => $foreigner->id,
        ]);
    }

    public function test_a_deactivated_user_cannot_accept(): void
    {
        $invitation = $this->invitation();
        $banned = $this->invitee(['banned_at' => now()]);

        $this->assertSame(
            BlogInvitationService::RESULT_USER_DEACTIVATED,
            $this->service()->accept($invitation->token, $banned)['result'],
        );
    }

    public function test_a_deleted_article_takes_its_invitations_with_it(): void
    {
        $invitation = $this->invitation();
        $user = $this->invitee();
        $this->post->forceDelete();

        // blog_post_invitations.blog_post_id cascades on delete, so the row is
        // gone rather than dangling — the token simply no longer resolves.
        $this->assertSame(
            BlogInvitationService::RESULT_NOT_FOUND,
            $this->service()->accept($invitation->token, $user)['result'],
        );
        $this->assertDatabaseMissing('blog_post_invitations', ['id' => $invitation->id]);
    }

    public function test_an_unknown_token_is_refused(): void
    {
        $user = $this->invitee();

        $this->assertSame(
            BlogInvitationService::RESULT_NOT_FOUND,
            $this->service()->accept('nope', $user)['result'],
        );
    }

    // ── Idempotence ─────────────────────────────────────────────────────────

    public function test_double_accept_creates_a_single_co_author_row(): void
    {
        $invitation = $this->invitation();
        $user = $this->invitee();

        $first = $this->service()->accept($invitation->token, $user);
        $second = $this->service()->accept($invitation->token, $user);

        $this->assertSame(BlogInvitationService::RESULT_ACCEPTED, $first['result']);
        $this->assertSame(BlogInvitationService::RESULT_ALREADY_ACCEPTED_BY_SAME_USER, $second['result']);
        $this->assertSame(1, $this->post->coAuthors()->where('user_id', $user->id)->count());
    }

    public function test_an_already_authorised_user_closes_the_invitation_without_duplicating(): void
    {
        $user = $this->invitee();
        $this->post->coAuthors()->attach($user->id, ['role' => 'coauthor', 'added_by' => $this->owner->id]);
        $invitation = $this->invitation();

        $outcome = $this->service()->accept($invitation->token, $user);

        $this->assertSame(BlogInvitationService::RESULT_ACCEPTED, $outcome['result']);
        $this->assertSame('accepted', $invitation->fresh()->status);
        $this->assertSame(1, $this->post->coAuthors()->where('user_id', $user->id)->count());
    }

    public function test_an_invitation_already_accepted_cannot_be_reused_by_another_account(): void
    {
        $first = $this->invitee();
        $invitation = $this->invitation();
        $this->service()->accept($invitation->token, $first);

        // Two accounts cannot hold one address at a time, so the only way to
        // reach this guard is an address that changed hands.
        $first->update(['email' => 'moved@example.com']);
        $second = $this->invitee();

        $outcome = $this->service()->accept($invitation->token, $second);

        $this->assertSame(BlogInvitationService::RESULT_TAKEN_BY_ANOTHER_USER, $outcome['result']);
        $this->assertDatabaseMissing('blog_post_user', [
            'blog_post_id' => $this->post->id, 'user_id' => $second->id,
        ]);
    }

    public function test_an_expired_invitation_is_refused_and_flipped(): void
    {
        $invitation = $this->invitation(['expires_at' => now()->subDay()]);
        $user = $this->invitee();

        $this->assertSame(
            BlogInvitationService::RESULT_EXPIRED,
            $this->service()->accept($invitation->token, $user)['result'],
        );
        $this->assertSame('expired', $invitation->fresh()->status);
    }

    // ── Aucune mutation sur GET ─────────────────────────────────────────────

    public function test_the_landing_page_is_read_only(): void
    {
        $invitation = $this->invitation();

        $this->get(route('blog.invite.show', $invitation->token))->assertOk();

        $this->assertSame('pending', $invitation->fresh()->status);
        $this->assertSame(0, $this->post->coAuthors()->count());
    }

    public function test_accept_rejects_a_get_request(): void
    {
        $invitation = $this->invitation();

        $this->actingAs($this->invitee())
            ->get('/blog-invitations/'.$invitation->token.'/accept')
            ->assertStatus(405);
    }

    public function test_prepare_rejects_a_get_request(): void
    {
        $invitation = $this->invitation();

        $this->get('/blog-invitations/'.$invitation->token.'/prepare')->assertStatus(405);
    }

    // ── Reprise après authentification ──────────────────────────────────────

    public function test_prepare_parks_the_token_under_a_blog_specific_key(): void
    {
        $invitation = $this->invitation();

        $this->post(route('blog.invite.prepare', $invitation->token), ['intent' => 'login'])
            ->assertRedirect()
            ->assertSessionHas(BlogInvitationService::SESSION_KEY, $invitation->token);

        // The dead `invitation_token` key must not come back, and the Loop key
        // must never be reused — the two flows would claim each other's tokens.
        $this->assertNull(session('invitation_token'));
        $this->assertNull(session(LoopInvitation::SESSION_KEY));
    }

    public function test_a_guest_posting_accept_is_sent_to_authenticate_with_the_token_parked(): void
    {
        $invitation = $this->invitation();

        $this->post(route('blog.invite.accept', $invitation->token))
            ->assertRedirect()
            ->assertSessionHas(BlogInvitationService::SESSION_KEY, $invitation->token);

        $this->assertSame('pending', $invitation->fresh()->status);
    }

    public function test_an_existing_account_logs_in_and_the_invitation_is_resumed(): void
    {
        $invitation = $this->invitation();
        $user = $this->invitee();

        $this->post(route('blog.invite.prepare', $invitation->token), ['intent' => 'login']);
        $this->post(route('login'), ['email' => 'invitee@example.com', 'password' => 'password-1234'])
            ->assertRedirect(route('organization.blog.edit', ['organization' => $this->org->slug, 'post' => $this->post->slug]));

        $this->assertDatabaseHas('blog_post_user', [
            'blog_post_id' => $this->post->id, 'user_id' => $user->id,
        ]);
        $this->assertSame('accepted', $invitation->fresh()->status);
        // Consumed, not left behind for a later request.
        $this->assertNull(session(BlogInvitationService::SESSION_KEY));
    }

    public function test_an_external_visitor_registers_and_the_invitation_is_resumed(): void
    {
        $invitation = $this->invitation(['recipient_email' => 'newcomer@example.com']);

        $this->post(route('blog.invite.prepare', $invitation->token), ['intent' => 'register']);

        $this->post(route('organization.register', ['organization' => $this->org->slug]), [
            'name' => 'Newcomer',
            'first_name' => 'New',
            'email' => 'newcomer@example.com',
            'phone' => '0600000000',
            'country_code' => 'FR',
            'password' => 'password-1234',
            'password_confirmation' => 'password-1234',
        ])->assertRedirect(route('organization.blog.edit', ['organization' => $this->org->slug, 'post' => $this->post->slug]));

        $user = User::where('email', 'newcomer@example.com')->sole();
        $this->assertDatabaseHas('blog_post_user', [
            'blog_post_id' => $this->post->id, 'user_id' => $user->id,
        ]);
        $this->assertSame('accepted', $invitation->fresh()->status);
        $this->assertNull(session(BlogInvitationService::SESSION_KEY));
    }

    public function test_logging_in_as_someone_else_does_not_claim_the_token(): void
    {
        $invitation = $this->invitation();
        $other = User::factory()->create([
            'organization_id' => $this->org->id,
            'email' => 'someone.else@example.com',
            'password' => bcrypt('password-1234'),
        ]);

        $this->post(route('blog.invite.prepare', $invitation->token), ['intent' => 'login']);
        $this->post(route('login'), ['email' => 'someone.else@example.com', 'password' => 'password-1234']);

        $this->assertDatabaseMissing('blog_post_user', [
            'blog_post_id' => $this->post->id, 'user_id' => $other->id,
        ]);
        $this->assertSame('pending', $invitation->fresh()->status);
        // The failed attempt still consumes the key: it must not linger and fire
        // again on an unrelated later sign-in.
        $this->assertNull(session(BlogInvitationService::SESSION_KEY));
    }

    public function test_a_normal_login_without_any_invitation_is_unchanged(): void
    {
        $user = $this->invitee();

        $this->post(route('login'), ['email' => 'invitee@example.com', 'password' => 'password-1234'])
            ->assertRedirect($user->getLoginRedirectTarget());
    }

    public function test_a_normal_registration_without_any_invitation_is_unchanged(): void
    {
        $response = $this->post(route('organization.register', ['organization' => $this->org->slug]), [
            'name' => 'Plain User',
            'first_name' => 'Plain',
            'email' => 'plain@example.com',
            'phone' => '0600000001',
            'country_code' => 'FR',
            'password' => 'password-1234',
            'password_confirmation' => 'password-1234',
        ]);

        $response->assertRedirect();
        $this->assertNotSame(
            route('blog.edit', ['post' => $this->post->slug]),
            $response->headers->get('Location'),
        );
    }

    // ── Non-régression TASK-1077 : les deux flux ne se marchent pas dessus ──

    public function test_a_parked_loop_token_is_not_consumed_by_the_blog_flow(): void
    {
        $loop = Loop::factory()->create(['organization_id' => $this->org->id, 'status' => 'active']);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $this->owner->id]);
        $loopInvitation = app(LoopInvitationService::class)->invite($loop, $this->owner, 'invitee@example.com');
        $user = $this->invitee();
        $blogInvitation = $this->invitation();

        // Only the Loop token is parked: the Blog service must find nothing.
        session([LoopInvitation::SESSION_KEY => $loopInvitation->token]);

        $this->assertNull($this->service()->resumeFromSession($user));
        $this->assertSame('pending', $blogInvitation->fresh()->status);
        $this->assertSame(LoopInvitation::STATUS_PENDING, $loopInvitation->fresh()->status);
    }

    public function test_a_parked_blog_token_is_not_consumed_by_the_loop_flow(): void
    {
        $loop = Loop::factory()->create(['organization_id' => $this->org->id, 'status' => 'active']);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $this->owner->id]);
        $loopInvitation = app(LoopInvitationService::class)->invite($loop, $this->owner, 'invitee@example.com');
        $user = $this->invitee();
        $blogInvitation = $this->invitation();

        session([BlogInvitationService::SESSION_KEY => $blogInvitation->token]);

        $this->assertNull(app(LoopInvitationService::class)->resumeFromSession($user));
        $this->assertSame(LoopInvitation::STATUS_PENDING, $loopInvitation->fresh()->status);
    }

    public function test_a_loop_invitation_still_resumes_after_login(): void
    {
        $loop = Loop::factory()->create(['organization_id' => $this->org->id, 'status' => 'active']);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $this->owner->id]);
        $loopInvitation = app(LoopInvitationService::class)->invite($loop, $this->owner, 'invitee@example.com');
        $user = $this->invitee();

        $this->post(route('loop-invitations.prepare', $loopInvitation->token), ['intent' => 'login']);
        $this->post(route('login'), ['email' => 'invitee@example.com', 'password' => 'password-1234'])
            ->assertRedirect(route('organization.loops.show', ['organization' => $this->org->slug, 'loop' => $loop->id]));

        $this->assertSame(LoopInvitation::STATUS_ACCEPTED, $loopInvitation->fresh()->status);
    }
}
