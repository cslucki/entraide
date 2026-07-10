<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\BlogPostInvitation;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BlogInvitationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $otherOrg;

    private User $owner;

    private User $member;

    private User $nonMember;

    private User $crossOrgUser;

    private BlogPost $post;

    private BlogPost $draftPost;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'testorg', 'is_active' => true, 'is_default' => true]);
        $this->otherOrg = Organization::factory()->create(['slug' => 'otherorg']);

        $this->owner = User::factory()->create(['organization_id' => $this->org->id, 'name' => 'Owner']);
        $this->member = User::factory()->create(['organization_id' => $this->org->id, 'name' => 'Member', 'email' => 'member@test.com']);
        $this->nonMember = User::factory()->create(['organization_id' => $this->org->id, 'name' => 'NonMember']);
        $this->crossOrgUser = User::factory()->create(['organization_id' => $this->otherOrg->id, 'name' => 'CrossOrg']);

        app()->instance('current_organization', $this->org);

        $this->post = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'title' => 'Published Article',
            'slug' => 'published-article',
            'content' => 'Some content.',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->draftPost = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'title' => 'Draft Article',
            'slug' => 'draft-article',
            'content' => 'Draft content.',
            'status' => 'draft',
        ]);
    }

    public function test_owner_can_send_invitation_to_existing_member(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->owner)->postJson(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => $this->member->email,
            'recipient_name' => 'Member',
            'message' => 'Please read this.',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true, 'invitation_type' => 'existing_member']);

        $invitation = BlogPostInvitation::where('recipient_email', $this->member->email)->first();
        $this->assertNotNull($invitation);
        $this->assertEquals($this->post->id, $invitation->blog_post_id);
        $this->assertEquals($this->owner->id, $invitation->sender_id);
        $this->assertEquals('existing_member', $invitation->invitation_type);
        $this->assertNotNull($invitation->token);
        $this->assertNotNull($invitation->expires_at);

        $log = EmailLog::where('to_email', $this->member->email)->first();
        $this->assertNotNull($log);
        $this->assertEquals('sent', $log->status);
        $this->assertEquals('blog-contribution-invitation', $log->data['source']);
        $this->assertEquals('existing_member', $log->data['invitation_type']);
    }

    public function test_owner_can_send_invitation_to_external_email(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->owner)->postJson(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => 'external@example.com',
            'recipient_name' => 'External Person',
            'message' => 'Check this out.',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true, 'invitation_type' => 'external']);

        $invitation = BlogPostInvitation::where('recipient_email', 'external@example.com')->first();
        $this->assertNotNull($invitation);
        $this->assertEquals('external', $invitation->invitation_type);

        $log = EmailLog::where('to_email', 'external@example.com')->first();
        $this->assertNotNull($log);
        $this->assertEquals('sent', $log->status);
        $this->assertEquals('blog-contribution-invitation', $log->data['source']);
    }

    public function test_owner_can_send_invitation_on_draft_article(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->owner)->postJson(route('blog.invite.store', ['post' => $this->draftPost]), [
            'recipient_email' => 'someone@example.com',
            'recipient_name' => 'Someone',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $invitation = BlogPostInvitation::where('recipient_email', 'someone@example.com')->first();
        $this->assertNotNull($invitation);
        $this->assertEquals($this->draftPost->id, $invitation->blog_post_id);
    }

    public function test_non_owner_cannot_send_invitation(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->nonMember)->postJson(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => 'someone@example.com',
        ]);

        $response->assertForbidden();
    }

    public function test_email_logs_contains_correct_data_fields(): void
    {
        Mail::fake();

        $this->actingAs($this->owner)->postJson(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => 'test@test.com',
            'recipient_name' => 'Test User',
            'message' => 'Hello!',
        ]);

        $log = EmailLog::where('to_email', 'test@test.com')->first();
        $this->assertNotNull($log);
        $this->assertEquals($this->org->id, $log->organization_id);
        $this->assertEquals($this->owner->id, $log->user_id);
        $this->assertArrayHasKey('blog_post_id', $log->data);
        $this->assertArrayHasKey('blog_post_slug', $log->data);
        $this->assertArrayHasKey('sender_id', $log->data);
        $this->assertArrayHasKey('invitation_type', $log->data);
        $this->assertArrayHasKey('invitation_id', $log->data);
        $this->assertArrayHasKey('invitation_token', $log->data);
    }

    public function test_invitation_email_contains_invitation_url(): void
    {
        Mail::fake();

        $this->actingAs($this->owner)->postJson(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => 'external@example.com',
            'recipient_name' => 'External',
        ]);

        $invitation = BlogPostInvitation::where('recipient_email', 'external@example.com')->first();
        $this->assertNotNull($invitation);

        $html = view('emails.blog-invitation', [
            'senderName' => $this->owner->fullName,
            'recipientName' => 'External',
            'senderMessage' => __('blog-invitation.default_message'),
            'invitationUrl' => route('blog.invite.show', ['token' => $invitation->token]),
            'articleTitle' => $this->post->title,
            'registerUrl' => null,
            'isExistingMember' => false,
        ])->render();

        $this->assertStringContainsString(route('blog.invite.show', ['token' => $invitation->token]), $html);
        $this->assertStringContainsString('Accept the invitation', $html);
    }

    public function test_invitation_uses_system_email_template_when_available(): void
    {
        SystemEmailTemplate::create([
            'organization_id' => $this->org->id,
            'locale' => 'fr',
            'slug' => 'blog_contribution_invitation',
            'name' => 'Invitation contribuer',
            'subject' => '{{ sender_name }} vous invite à contribuer à « {{ article_title }} »',
            'content_html' => '<h1>Invitation de {{ sender_name }}</h1><p>{{ sender_message }}</p><p><a href="{{ invitation_url }}">Accepter</a></p>',
            'variables' => ['sender_name', 'recipient_name', 'sender_message', 'invitation_url', 'article_title'],
            'enabled' => true,
        ]);

        Mail::fake();

        $this->actingAs($this->owner)->postJson(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => 'test@test.com',
            'recipient_name' => 'Test',
            'message' => 'Read this please.',
        ]);

        $log = EmailLog::where('to_email', 'test@test.com')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Owner', $log->subject);
    }

    public function test_validation_requires_recipient_email(): void
    {
        $response = $this->actingAs($this->owner)->postJson(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_name' => 'No Email',
        ]);

        $response->assertJsonValidationErrors('recipient_email');
    }

    public function test_validation_requires_valid_email(): void
    {
        $response = $this->actingAs($this->owner)->postJson(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => 'not-an-email',
        ]);

        $response->assertJsonValidationErrors('recipient_email');
    }

    public function test_i18n_keys_present_in_blog_invitation_lang(): void
    {
        $this->assertIsString(__('blog-invitation.email_subject', ['sender' => 'Test', 'title' => 'Article']));
        $this->assertIsString(__('blog-invitation.modal_title'));
        $this->assertIsString(__('blog-invitation.modal_btn_send'));
        $this->assertIsString(__('blog-invitation.btn_invite_email'));
        $this->assertIsString(__('blog-invitation.card_title'));
        $this->assertIsString(__('blog-invitation.card_empty'));
        $this->assertIsString(__('blog-invitation.invite_title', ['sender' => 'Test']));
        $this->assertIsString(__('blog-invitation.invite_btn_accept'));
    }

    public function test_org_scoped_route_works(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->owner)->postJson(
            route('organization.blog.invite.store', ['organization' => 'testorg', 'post' => $this->post]),
            [
                'recipient_email' => 'orgtest@test.com',
                'recipient_name' => 'Org Test',
            ]
        );

        $response->assertOk();
        $response->assertJson(['success' => true]);
    }

    public function test_cannot_invite_with_empty_email(): void
    {
        $response = $this->actingAs($this->owner)->postJson(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => '',
        ]);

        $response->assertJsonValidationErrors('recipient_email');
    }

    public function test_message_is_optional(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->owner)->postJson(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => 'optional@test.com',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
    }

    public function test_recipient_name_is_optional(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->owner)->postJson(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => 'noname@test.com',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
    }

    public function test_cross_org_user_treated_as_external(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->owner)->postJson(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => $this->crossOrgUser->email,
            'recipient_name' => 'CrossOrg User',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true, 'invitation_type' => 'external']);

        $log = EmailLog::where('to_email', $this->crossOrgUser->email)->first();
        $this->assertNotNull($log);
        $this->assertEquals('external', $log->data['invitation_type']);
    }

    public function test_index_returns_invitation_history(): void
    {
        BlogPostInvitation::create([
            'blog_post_id' => $this->post->id,
            'sender_id' => $this->owner->id,
            'recipient_email' => 'prev@test.com',
            'token' => 'test-token-123',
            'invitation_type' => 'external',
            'organization_id' => $this->org->id,
        ]);

        $response = $this->actingAs($this->owner)->getJson(route('blog.invite.index', ['post' => $this->post]));

        $response->assertOk();
        $response->assertJsonCount(1, 'invitations');
        $response->assertJsonPath('invitations.0.to_email', 'prev@test.com');
        $response->assertJsonPath('invitations.0.invitation_type', 'external');
    }

    public function test_index_only_returns_own_post_invitations(): void
    {
        $otherPost = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'title' => 'Other',
            'slug' => 'other-post',
            'content' => 'Content.',
            'status' => 'published',
            'published_at' => now(),
        ]);

        BlogPostInvitation::create([
            'blog_post_id' => $otherPost->id,
            'sender_id' => $this->owner->id,
            'recipient_email' => 'test@test.com',
            'token' => 'other-token-456',
            'invitation_type' => 'external',
            'organization_id' => $this->org->id,
        ]);

        $response = $this->actingAs($this->owner)->getJson(route('blog.invite.index', ['post' => $this->post]));

        $response->assertOk();
        $response->assertJsonCount(0, 'invitations');
    }

    public function test_non_owner_cannot_view_invitation_history(): void
    {
        $response = $this->actingAs($this->nonMember)->getJson(route('blog.invite.index', ['post' => $this->post]));

        $response->assertForbidden();
    }

    public function test_email_template_contains_notice_text(): void
    {
        $html = view('emails.blog-invitation', [
            'senderName' => 'Sender',
            'recipientName' => 'Recipient',
            'senderMessage' => 'Hello',
            'invitationUrl' => 'https://example.com/invitation/abc123',
            'articleTitle' => 'My Article',
            'registerUrl' => null,
            'isExistingMember' => true,
        ])->render();

        $this->assertStringContainsString('Sender', $html);
        $this->assertStringContainsString('My Article', $html);
        $this->assertStringContainsString('https://example.com/invitation/abc123', $html);
    }

    public function test_show_page_does_not_contain_invite_button(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get(route('blog.show', ['post' => $this->post]));

        $response->assertOk();
        $response->assertDontSee('openInvite');
        $response->assertDontSee('blog-invitation.modal_title');
    }

    public function test_org_scoped_index_route_works(): void
    {
        $response = $this->actingAs($this->owner)->getJson(
            route('organization.blog.invite.index', ['organization' => 'testorg', 'post' => $this->post])
        );

        $response->assertOk();
        $response->assertJson(['invitations' => []]);
    }

    public function test_guest_prompt_renders_login_link_not_literal_placeholder(): void
    {
        $response = $this->get(route('blog.show', ['post' => $this->post]));

        $response->assertOk();
        $response->assertDontSee(':login');
        $response->assertSee(route('login'), false);
        $response->assertSee(__('blog.login'));
    }

    public function test_guest_prompt_login_link_includes_ref_when_present(): void
    {
        $response = $this->get(route('blog.show', ['post' => $this->post, 'ref' => 'testref123']));

        $response->assertOk();
        $response->assertSee('ref=testref123', false);
    }

    public function test_login_page_register_link_includes_ref(): void
    {
        $response = $this->get(route('login', ['ref' => 'myref456']));

        $response->assertOk();
        $response->assertSee('ref=myref456', false);
    }

    // --- Invitation Token Flow Tests ---

    public function test_valid_token_shows_invitation_page(): void
    {
        $invitation = BlogPostInvitation::create([
            'blog_post_id' => $this->post->id,
            'sender_id' => $this->owner->id,
            'recipient_email' => 'invitee@test.com',
            'recipient_name' => 'Invitee',
            'token' => 'valid-token-abc',
            'message' => 'Come write with us!',
            'invitation_type' => 'external',
            'organization_id' => $this->org->id,
        ]);

        $response = $this->get(route('blog.invite.show', ['token' => 'valid-token-abc']));

        $response->assertOk();
        $response->assertSee('Published Article');
        $response->assertSee('Come write with us!');
        $response->assertSee($this->owner->fullName);
    }

    public function test_expired_token_shows_expired_message(): void
    {
        $invitation = BlogPostInvitation::create([
            'blog_post_id' => $this->post->id,
            'sender_id' => $this->owner->id,
            'recipient_email' => 'invitee@test.com',
            'token' => 'expired-token-xyz',
            'invitation_type' => 'external',
            'expires_at' => now()->subDay(),
            'organization_id' => $this->org->id,
        ]);

        $response = $this->get(route('blog.invite.show', ['token' => 'expired-token-xyz']));

        $response->assertOk();
        $response->assertSee(__('blog-invitation.invite_expired_title'));
    }

    public function test_accepted_token_shows_accepted_message(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);

        $invitation = BlogPostInvitation::create([
            'blog_post_id' => $this->post->id,
            'sender_id' => $this->owner->id,
            'recipient_email' => 'invitee@test.com',
            'token' => 'accepted-token-abc',
            'invitation_type' => 'existing_member',
            'status' => 'accepted',
            'accepted_at' => now(),
            'accepted_by_user_id' => $user->id,
            'organization_id' => $this->org->id,
        ]);

        $response = $this->get(route('blog.invite.show', ['token' => 'accepted-token-abc']));

        $response->assertOk();
        $response->assertSee(__('blog-invitation.invite_accepted_title'));
    }

    public function test_invalid_token_returns_404(): void
    {
        $response = $this->get(route('blog.invite.show', ['token' => 'nonexistent-token']));

        $response->assertNotFound();
    }

    public function test_accept_redirects_unauthenticated_to_login(): void
    {
        $this->owner->update(['referral_code' => 'testref']);

        $invitation = BlogPostInvitation::create([
            'blog_post_id' => $this->post->id,
            'sender_id' => $this->owner->id,
            'recipient_email' => 'invitee@test.com',
            'token' => 'accept-token-123',
            'invitation_type' => 'external',
            'organization_id' => $this->org->id,
        ]);

        $response = $this->post(route('blog.invite.accept', ['token' => 'accept-token-123']));

        $response->assertRedirect();
        $this->assertStringContainsString('/login', $response->headers->get('Location'));
        $this->assertStringContainsString('ref=testref', $response->headers->get('Location'));
    }

    public function test_accept_adds_co_author_and_redirects_to_edit(): void
    {
        $this->actingAs($this->member);

        $invitation = BlogPostInvitation::create([
            'blog_post_id' => $this->post->id,
            'sender_id' => $this->owner->id,
            'recipient_email' => $this->member->email,
            'token' => 'accept-member-token',
            'invitation_type' => 'existing_member',
            'organization_id' => $this->org->id,
        ]);

        $response = $this->post(route('blog.invite.accept', ['token' => 'accept-member-token']));

        $response->assertRedirect(route('blog.edit', ['post' => $this->post->slug]));

        $this->assertDatabaseHas('blog_post_user', [
            'blog_post_id' => $this->post->id,
            'user_id' => $this->member->id,
        ]);

        $invitation->refresh();
        $this->assertEquals('accepted', $invitation->status);
        $this->assertEquals($this->member->id, $invitation->accepted_by_user_id);
    }

    public function test_accept_expired_token_fails(): void
    {
        $this->actingAs($this->member);

        BlogPostInvitation::create([
            'blog_post_id' => $this->post->id,
            'sender_id' => $this->owner->id,
            'recipient_email' => $this->member->email,
            'token' => 'expired-accept-token',
            'invitation_type' => 'existing_member',
            'status' => 'pending',
            'expires_at' => now()->subDay(),
            'organization_id' => $this->org->id,
        ]);

        $response = $this->post(route('blog.invite.accept', ['token' => 'expired-accept-token']));

        $response->assertNotFound();
    }

    public function test_invitation_token_stored_in_email_log(): void
    {
        Mail::fake();

        $this->actingAs($this->owner)->postJson(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => 'logtest@test.com',
        ]);

        $log = EmailLog::where('to_email', 'logtest@test.com')->first();
        $this->assertNotNull($log);
        $this->assertArrayHasKey('invitation_token', $log->data);
        $this->assertArrayHasKey('invitation_id', $log->data);

        $invitation = BlogPostInvitation::where('recipient_email', 'logtest@test.com')->first();
        $this->assertEquals($invitation->token, $log->data['invitation_token']);
        $this->assertEquals($invitation->id, $log->data['invitation_id']);
    }

    public function test_invitation_expires_in_30_days_by_default(): void
    {
        Mail::fake();

        $this->actingAs($this->owner)->postJson(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => 'expiry@test.com',
        ]);

        $invitation = BlogPostInvitation::where('recipient_email', 'expiry@test.com')->first();
        $this->assertNotNull($invitation);
        $this->assertTrue($invitation->expires_at->isFuture());
        $this->assertTrue($invitation->expires_at->isPast() === false);
        $this->assertEquals(now()->addDays(30)->startOfMinute(), $invitation->expires_at->startOfMinute());
    }
}
