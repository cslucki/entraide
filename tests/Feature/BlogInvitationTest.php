<?php

namespace Tests\Feature;

use App\Models\BlogPost;
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

        $log = EmailLog::where('to_email', $this->member->email)->first();
        $this->assertNotNull($log);
        $this->assertEquals('sent', $log->status);
        $this->assertEquals('blog-contribution-invitation', $log->data['source']);
        $this->assertEquals('existing_member', $log->data['invitation_type']);
        $this->assertEquals($this->post->id, $log->data['blog_post_id']);
        $this->assertEquals($this->owner->id, $log->data['sender_id']);
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

        $log = EmailLog::where('to_email', 'external@example.com')->first();
        $this->assertNotNull($log);
        $this->assertEquals('sent', $log->status);
        $this->assertEquals('blog-contribution-invitation', $log->data['source']);
        $this->assertEquals('external', $log->data['invitation_type']);
    }

    public function test_non_owner_cannot_send_invitation(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->nonMember)->postJson(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => 'someone@example.com',
        ]);

        $response->assertForbidden();
    }

    public function test_cannot_invite_on_draft_article(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->owner)->postJson(route('blog.invite.store', ['post' => $this->draftPost]), [
            'recipient_email' => 'someone@example.com',
            'recipient_name' => 'Someone',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('recipient_email');
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
        $this->assertArrayHasKey('template_slug', $log->data);
        $this->assertEquals('published-article', $log->data['blog_post_slug']);
    }

    public function test_external_invitation_email_contains_registration_link(): void
    {
        $this->owner->update(['referral_code' => 'ownertest']);

        Mail::fake();

        $this->actingAs($this->owner)->postJson(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => 'external@example.com',
            'recipient_name' => 'External',
        ]);

        $log = EmailLog::where('to_email', 'external@example.com')->first();
        $this->assertNotNull($log);
        $this->assertEquals('sent', $log->status);

        $html = view('emails.blog-invitation', [
            'senderName' => $this->owner->fullName,
            'recipientName' => 'External',
            'senderMessage' => __('blog-invitation.default_message'),
            'articleUrl' => route('blog.show', ['post' => $this->post]),
            'articleTitle' => $this->post->title,
            'registerUrl' => route('organization.register', [
                'organization' => $this->org->slug,
                'ref' => 'ownertest',
            ]),
            'isExistingMember' => false,
        ])->render();

        $this->assertStringContainsString('register', $html);
        $this->assertStringContainsString('ownertest', $html);
    }

    public function test_existing_member_invitation_email_contains_article_link(): void
    {
        Mail::fake();

        $this->actingAs($this->owner)->postJson(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => $this->member->email,
            'recipient_name' => 'Member',
        ]);

        $log = EmailLog::where('to_email', $this->member->email)->first();
        $this->assertNotNull($log);
        $this->assertEquals('existing_member', $log->data['invitation_type']);

        $html = view('emails.blog-invitation', [
            'senderName' => $this->owner->fullName,
            'recipientName' => 'Member',
            'senderMessage' => __('blog-invitation.default_message'),
            'articleUrl' => route('blog.show', ['post' => $this->post]),
            'articleTitle' => $this->post->title,
            'registerUrl' => null,
            'isExistingMember' => true,
        ])->render();

        $this->assertStringContainsString('published-article', $html);
    }

    public function test_invitation_uses_system_email_template_when_available(): void
    {
        SystemEmailTemplate::create([
            'organization_id' => $this->org->id,
            'locale' => 'fr',
            'slug' => 'blog_contribution_invitation',
            'name' => 'Invitation contribuer',
            'subject' => '{{ sender_name }} vous invite à lire « {{ article_title }} »',
            'content_html' => '<h1>Invitation de {{ sender_name }}</h1><p>{{ sender_message }}</p><p><a href="{{ article_url }}">Lire</a></p>',
            'variables' => ['sender_name', 'recipient_name', 'sender_message', 'article_url', 'article_title'],
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
        $this->assertIsString(__('blog-invitation.draft_not_allowed'));
        $this->assertIsString(__('blog-invitation.btn_invite_email'));
        $this->assertIsString(__('blog-invitation.card_title'));
        $this->assertIsString(__('blog-invitation.card_empty'));
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
        EmailLog::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'to_email' => 'prev@test.com',
            'subject' => 'Test',
            'status' => 'sent',
            'data' => [
                'source' => 'blog-contribution-invitation',
                'template_slug' => 'blog_contribution_invitation',
                'blog_post_id' => $this->post->id,
                'blog_post_slug' => $this->post->slug,
                'sender_id' => $this->owner->id,
                'invitation_type' => 'external',
            ],
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

        EmailLog::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'to_email' => 'test@test.com',
            'subject' => 'Test',
            'status' => 'sent',
            'data' => [
                'source' => 'blog-contribution-invitation',
                'template_slug' => 'blog_contribution_invitation',
                'blog_post_id' => $otherPost->id,
                'blog_post_slug' => $otherPost->slug,
                'sender_id' => $this->owner->id,
                'invitation_type' => 'external',
            ],
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
            'articleUrl' => 'https://example.com/article',
            'articleTitle' => 'My Article',
            'registerUrl' => null,
            'isExistingMember' => true,
        ])->render();

        $this->assertStringContainsString('Sender', $html);
        $this->assertStringContainsString('My Article', $html);
        $this->assertStringContainsString('https://example.com/article', $html);
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
}
