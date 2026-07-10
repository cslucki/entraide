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

        $response = $this->actingAs($this->owner)->post(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => $this->member->email,
            'recipient_name' => 'Member',
            'message' => 'Please read this.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

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

        $response = $this->actingAs($this->owner)->post(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => 'external@example.com',
            'recipient_name' => 'External Person',
            'message' => 'Check this out.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $log = EmailLog::where('to_email', 'external@example.com')->first();
        $this->assertNotNull($log);
        $this->assertEquals('sent', $log->status);
        $this->assertEquals('blog-contribution-invitation', $log->data['source']);
        $this->assertEquals('external', $log->data['invitation_type']);
    }

    public function test_non_owner_cannot_send_invitation(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->nonMember)->post(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => 'someone@example.com',
        ]);

        $response->assertForbidden();
    }

    public function test_cannot_invite_on_draft_article(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->owner)->post(route('blog.invite.store', ['post' => $this->draftPost]), [
            'recipient_email' => 'someone@example.com',
            'recipient_name' => 'Someone',
        ]);

        $response->assertSessionHasErrors('recipient_email');
    }

    public function test_email_logs_contains_correct_data_fields(): void
    {
        Mail::fake();

        $this->actingAs($this->owner)->post(route('blog.invite.store', ['post' => $this->post]), [
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
        $this->assertEquals('published-article', $log->data['blog_post_slug']);
    }

    public function test_external_invitation_email_contains_registration_link(): void
    {
        $this->owner->update(['referral_code' => 'ownertest']);

        Mail::fake();

        $this->actingAs($this->owner)->post(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => 'external@example.com',
            'recipient_name' => 'External',
        ]);

        Mail::assertNothingSent();

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

        $this->actingAs($this->owner)->post(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => $this->member->email,
            'recipient_name' => 'Member',
        ]);

        Mail::assertNothingSent();

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

        $this->actingAs($this->owner)->post(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => 'test@test.com',
            'recipient_name' => 'Test',
            'message' => 'Read this please.',
        ]);

        Mail::assertNothingSent();

        $log = EmailLog::where('to_email', 'test@test.com')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Owner', $log->subject);
    }

    public function test_validation_requires_recipient_email(): void
    {
        $response = $this->actingAs($this->owner)->post(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_name' => 'No Email',
        ]);

        $response->assertSessionHasErrors('recipient_email');
    }

    public function test_validation_requires_valid_email(): void
    {
        $response = $this->actingAs($this->owner)->post(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => 'not-an-email',
        ]);

        $response->assertSessionHasErrors('recipient_email');
    }

    public function test_i18n_keys_present_in_blog_invitation_lang(): void
    {
        $this->assertIsString(__('blog-invitation.email_subject', ['sender' => 'Test', 'title' => 'Article']));
        $this->assertIsString(__('blog-invitation.modal_title'));
        $this->assertIsString(__('blog-invitation.modal_btn_send'));
        $this->assertIsString(__('blog-invitation.draft_not_allowed'));
    }

    public function test_org_scoped_route_works(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->owner)->post(
            route('organization.blog.invite.store', ['organization' => 'testorg', 'post' => $this->post]),
            [
                'recipient_email' => 'orgtest@test.com',
                'recipient_name' => 'Org Test',
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_cannot_invite_with_empty_email(): void
    {
        $response = $this->actingAs($this->owner)->post(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => '',
        ]);

        $response->assertSessionHasErrors('recipient_email');
    }

    public function test_message_is_optional(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->owner)->post(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => 'optional@test.com',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_recipient_name_is_optional(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->owner)->post(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => 'noname@test.com',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_cross_org_user_treated_as_external(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->owner)->post(route('blog.invite.store', ['post' => $this->post]), [
            'recipient_email' => $this->crossOrgUser->email,
            'recipient_name' => 'CrossOrg User',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $log = EmailLog::where('to_email', $this->crossOrgUser->email)->first();
        $this->assertNotNull($log);
        $this->assertEquals('external', $log->data['invitation_type']);
    }
}
