<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralEmailInvitationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'testorg', 'is_active' => true]);

        $this->user = User::factory()->create([
            'organization_id' => $this->org->id,
            'referral_code' => 'testref',
            'first_name' => 'Alice',
            'name' => 'Martin',
        ]);

        app()->instance('current_organization', $this->org);
    }

    public function test_invitation_page_shows_invitation_tools(): void
    {
        $response = $this->actingAs($this->user)->get(route('organization.invitations.index', ['organization' => 'testorg']));

        $response->assertStatus(200);
        $response->assertSee(__('points.invite_peers_title'));
        $response->assertSee('id="invitations"', false);
    }

    public function test_whatsapp_button_present_with_localized_message(): void
    {
        $response = $this->actingAs($this->user)->get(route('organization.invitations.index', ['organization' => 'testorg']));

        $response->assertStatus(200);
        $response->assertSee('WhatsApp');

        $referralLink = route('organization.register', ['organization' => 'testorg', 'ref' => 'testref']);
        $expectedMessage = __('points.whatsapp_message')."\n".$referralLink;

        $response->assertSee(urlencode($expectedMessage), false);
    }

    public function test_email_button_present(): void
    {
        $response = $this->actingAs($this->user)->get(route('organization.invitations.index', ['organization' => 'testorg']));

        $response->assertStatus(200);
        $response->assertSee(__('points.email'));
    }

    public function test_email_modal_renders_in_html(): void
    {
        $response = $this->actingAs($this->user)->get(route('organization.invitations.index', ['organization' => 'testorg']));

        $response->assertStatus(200);
        $response->assertSee(__('points.send_by_email'));
        $response->assertSee(__('points.recipient_email'));
        $response->assertSee(__('points.invitation_message'));
        $response->assertSee(__('points.preview'));
        $response->assertSee(__('points.send'));
        $response->assertSee(__('points.cancel'));
    }

    public function test_validation_requires_recipient_email(): void
    {
        $response = $this->actingAs($this->user)
            ->from(route('organization.invitations.index', ['organization' => 'testorg']))
            ->post(route('organization.points.invitation.send', ['organization' => 'testorg']), [
                'recipient_email' => '',
                'message' => 'Join me!',
            ]);

        $response->assertSessionHasErrors('recipient_email');
    }

    public function test_validation_requires_valid_email(): void
    {
        $response = $this->actingAs($this->user)
            ->from(route('organization.invitations.index', ['organization' => 'testorg']))
            ->post(route('organization.points.invitation.send', ['organization' => 'testorg']), [
                'recipient_email' => 'not-an-email',
                'message' => 'Join me!',
            ]);

        $response->assertSessionHasErrors('recipient_email');
    }

    public function test_message_is_optional_and_defaults_to_placeholder(): void
    {
        $response = $this->actingAs($this->user)
            ->from(route('organization.invitations.index', ['organization' => 'testorg']))
            ->post(route('organization.points.invitation.send', ['organization' => 'testorg']), [
                'recipient_email' => 'friend@example.com',
                'message' => '',
            ]);

        $response->assertSessionMissing('error');
        $response->assertSessionHas('success', __('points.email_success'));

        $this->assertDatabaseHas('email_logs', [
            'to_email' => 'friend@example.com',
            'status' => 'sent',
            'subject' => __('points.email_default_subject'),
        ]);
    }

    public function test_sends_email_via_system_template_and_logs(): void
    {
        $template = SystemEmailTemplate::create([
            'organization_id' => $this->org->id,
            'locale' => app()->getLocale(),
            'slug' => 'referral_invitation',
            'name' => 'Referral invitation',
            'subject' => '{{ sender_name }} invites you to join {{ organization }}',
            'content_html' => '<h1>{{ sender_name }} invites you</h1><blockquote>{{ sender_message }}</blockquote>',
            'variables' => ['sender_name', 'recipient_name', 'sender_message', 'referral_link'],
            'enabled' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->from(route('organization.invitations.index', ['organization' => 'testorg']))
            ->post(route('organization.points.invitation.send', ['organization' => 'testorg']), [
                'recipient_email' => 'friend@example.com',
                'recipient_name' => 'Bob',
                'message' => 'Join our community! It is great.',
            ]);

        $response->assertSessionMissing('error');
        $response->assertSessionHas('success', __('points.email_success'));

        $this->assertDatabaseHas('email_logs', [
            'to_email' => 'friend@example.com',
            'status' => 'sent',
            'user_id' => $this->user->id,
            'organization_id' => $this->org->id,
        ]);

        $log = EmailLog::where('to_email', 'friend@example.com')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Alice Martin', $log->subject);
        $this->assertEquals(['source' => 'referral-invitation'], $log->data);
    }

    public function test_sends_email_via_fallback_when_no_template(): void
    {
        $response = $this->actingAs($this->user)
            ->from(route('organization.invitations.index', ['organization' => 'testorg']))
            ->post(route('organization.points.invitation.send', ['organization' => 'testorg']), [
                'recipient_email' => 'friend@example.com',
                'recipient_name' => 'Bob',
                'message' => 'Join our community! It is great.',
            ]);

        $response->assertSessionMissing('error');
        $response->assertSessionHas('success', __('points.email_success'));

        $this->assertDatabaseHas('email_logs', [
            'to_email' => 'friend@example.com',
            'status' => 'sent',
            'subject' => __('points.email_default_subject'),
        ]);
    }

    public function test_invitation_card_hidden_when_no_referral_code(): void
    {
        $user = User::factory()->create([
            'organization_id' => $this->org->id,
            'referral_code' => null,
        ]);

        $response = $this->actingAs($user)->get(route('organization.invitations.index', ['organization' => 'testorg']));

        $response->assertStatus(200);
        $response->assertDontSee('id="invitations"');
        $response->assertDontSee(__('points.email'));
    }

    public function test_history_and_chart_always_visible(): void
    {
        $response = $this->actingAs($this->user)->get(route('organization.points.index', ['organization' => 'testorg']));

        $response->assertStatus(200);
        $response->assertSee(__('points.history_title'));
        $response->assertSee(__('dashboard.dashboard_shortcut'));
        $response->assertSee(__('dashboard.invitations'));
        $response->assertDontSee('data-user-dashboard-nav-link="points"', false);
        $response->assertSee(__('points.current_balance'));
        $response->assertSee(__('points.total_earned'));
        $response->assertSee(__('points.total_spent'));
        $response->assertSee(__('points.balance_chart'));
        $response->assertDontSee('WhatsApp');
        $response->assertDontSee(__('points.email'));
    }

    public function test_org_route_shows_points_page(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('organization.points.index', ['organization' => 'testorg']));

        $response->assertStatus(200);
        $response->assertSee(__('points.history_title'));
        $response->assertDontSee('WhatsApp');
        $response->assertDontSee(__('points.email'));
    }

    public function test_org_route_shows_invitations_page(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('organization.invitations.index', ['organization' => 'testorg']));

        $response->assertStatus(200);
        $response->assertSee(__('points.invite_peers_title'));
        $response->assertSee(__('dashboard.dashboard_shortcut'));
        $response->assertSee(__('dashboard.points_history'));
        $response->assertDontSee('data-user-dashboard-nav-link="invitations"', false);
        $response->assertSee(__('points.sent_invitations_history'));
        $response->assertSee('WhatsApp');
    }
}
