<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use App\Services\EmailerService;
use App\Services\LoopInvitationService;
use Database\Seeders\SystemEmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TASK1077LoopInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function org(array $overrides = []): Organization
    {
        return Organization::factory()->create(array_merge([
            'loops_enabled' => true,
            'members_can_create_loops' => true,
            'loop_mode' => 'multi',
        ], $overrides));
    }

    private function withCurrentOrganization(Organization $organization): void
    {
        app()->instance('current_organization', $organization);
    }

    private function ownedLoop(Organization $organization, User $owner): Loop
    {
        $loop = Loop::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);

        return $loop;
    }

    /**
     * Templates are stored one row per locale, and the request locale is
     * resolved by middleware — it is not necessarily the one active in the test
     * process. Seeding every locale mirrors SystemEmailTemplateSeeder and keeps
     * the assertion independent of that resolution.
     */
    private function systemTemplate(Organization $organization, string $subject, string $body, bool $enabled = true): void
    {
        foreach (['fr', 'en'] as $locale) {
            SystemEmailTemplate::create([
                'organization_id' => $organization->id,
                'locale' => $locale,
                'slug' => 'loop_invitation',
                'name' => 'Invitation Boucle',
                'subject' => $subject,
                'content_html' => $body,
                'variables' => [],
                'enabled' => $enabled,
            ]);
        }
    }

    private function service(): LoopInvitationService
    {
        return app(LoopInvitationService::class);
    }

    // ── Envoi ───────────────────────────────────────────────────────────────

    public function test_owner_can_send_an_invitation(): void
    {
        Mail::fake();
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->post(route('loops.invitations.store', $loop), ['email' => 'Nouveau@Example.COM'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('loop_invitations', [
            'loop_id' => $loop->id,
            'organization_id' => $org->id,
            'sender_id' => $owner->id,
            // Normalised on write: the strict match at accept time depends on it.
            'recipient_email' => 'nouveau@example.com',
            'status' => LoopInvitation::STATUS_PENDING,
        ]);
    }

    public function test_standard_member_cannot_send_an_invitation(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $member = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $member->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($member)
            ->post(route('loops.invitations.store', $loop), ['email' => 'x@example.com'])
            ->assertForbidden();

        $this->assertDatabaseCount('loop_invitations', 0);
    }

    public function test_only_one_pending_invitation_per_loop_and_email(): void
    {
        Mail::fake();
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $this->withCurrentOrganization($org);

        $first = $this->service()->invite($loop, $owner, 'dup@example.com');
        $second = $this->service()->invite($loop, $owner, 'DUP@example.com ');

        $this->assertSame($first->id, $second->id, 'The existing pending invitation must be reused.');
        $this->assertSame(1, LoopInvitation::where('loop_id', $loop->id)->count());
    }

    public function test_type_is_existing_member_when_the_address_belongs_to_the_organization(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $known = User::factory()->create(['organization_id' => $org->id, 'email' => 'known@example.com']);

        $inside = $this->service()->invite($loop, $owner, 'KNOWN@example.com');
        $outside = $this->service()->invite($loop, $owner, 'stranger@example.com');

        $this->assertSame(LoopInvitation::TYPE_EXISTING_MEMBER, $inside->invitation_type);
        $this->assertSame(LoopInvitation::TYPE_EXTERNAL, $outside->invitation_type);
    }

    // ── Acceptation ─────────────────────────────────────────────────────────

    public function test_invitee_accepts_and_becomes_an_active_member(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $invitee = User::factory()->create(['organization_id' => $org->id, 'email' => 'invitee@example.com']);
        $invitation = $this->service()->invite($loop, $owner, 'invitee@example.com');
        $this->withCurrentOrganization($org);

        $this->actingAs($invitee)
            ->post(route('loop-invitations.accept', $invitation->token))
            // TASK-1281 : l'acceptation atterrit dans l'Organization de la Boucle,
            // plus sur la route courte (qui resout l'Organization par defaut).
            ->assertRedirect(route('organization.loops.show', ['organization' => $org->slug, 'loop' => $loop->id]));

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $loop->id,
            'user_id' => $invitee->id,
            'role' => 'member',
            'status' => 'active',
        ]);
        $this->assertSame(LoopInvitation::STATUS_ACCEPTED, $invitation->fresh()->status);
        $this->assertSame($invitee->id, $invitation->fresh()->accepted_by_user_id);
    }

    public function test_a_token_can_never_be_accepted_by_another_email(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $invitation = $this->service()->invite($loop, $owner, 'target@example.com');
        $intruder = User::factory()->create(['organization_id' => $org->id, 'email' => 'intruder@example.com']);

        $outcome = $this->service()->accept($invitation->token, $intruder);

        $this->assertSame(LoopInvitationService::RESULT_EMAIL_MISMATCH, $outcome['result']);
        $this->assertDatabaseMissing('loop_members', ['loop_id' => $loop->id, 'user_id' => $intruder->id]);
        $this->assertSame(LoopInvitation::STATUS_PENDING, $invitation->fresh()->status);
    }

    public function test_email_comparison_ignores_case_and_whitespace(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $invitation = $this->service()->invite($loop, $owner, '  MiXeD@Example.Com ');
        $invitee = User::factory()->create(['organization_id' => $org->id, 'email' => 'mixed@example.com']);

        $this->assertSame(
            LoopInvitationService::RESULT_ACCEPTED,
            $this->service()->accept($invitation->token, $invitee)['result']
        );
    }

    public function test_double_accept_creates_a_single_membership(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $invitee = User::factory()->create(['organization_id' => $org->id, 'email' => 'twice@example.com']);
        $invitation = $this->service()->invite($loop, $owner, 'twice@example.com');

        $first = $this->service()->accept($invitation->token, $invitee);
        $second = $this->service()->accept($invitation->token, $invitee);

        $this->assertSame(LoopInvitationService::RESULT_ACCEPTED, $first['result']);
        $this->assertSame(LoopInvitationService::RESULT_ALREADY_ACCEPTED_BY_SAME_USER, $second['result']);
        $this->assertSame(1, LoopMember::where('loop_id', $loop->id)->where('user_id', $invitee->id)->count());
    }

    public function test_an_invitation_already_accepted_cannot_be_reused_by_another_account(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $first = User::factory()->create(['organization_id' => $org->id, 'email' => 'shared@example.com']);
        $invitation = $this->service()->invite($loop, $owner, 'shared@example.com');
        $this->service()->accept($invitation->token, $first);

        // Two accounts cannot hold the same address at once, so the only way to
        // reach this guard is an address that changed hands: the first account
        // frees it, a second one takes it and replays the spent token.
        $first->update(['email' => 'moved@example.com']);
        $second = User::factory()->create(['organization_id' => $org->id, 'email' => 'shared@example.com']);

        $outcome = $this->service()->accept($invitation->token, $second);

        $this->assertSame(LoopInvitationService::RESULT_TAKEN_BY_ANOTHER_USER, $outcome['result']);
        $this->assertDatabaseMissing('loop_members', ['loop_id' => $loop->id, 'user_id' => $second->id]);
        $this->assertSame($first->id, $invitation->fresh()->accepted_by_user_id);
    }

    public function test_pending_invitation_for_an_existing_member_is_closed_cleanly(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $already = User::factory()->create(['organization_id' => $org->id, 'email' => 'already@example.com']);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $already->id, 'status' => 'active']);
        $invitation = $this->service()->invite($loop, $owner, 'already@example.com');

        $outcome = $this->service()->accept($invitation->token, $already);

        $this->assertSame(LoopInvitationService::RESULT_ACCEPTED, $outcome['result']);
        $this->assertSame(LoopInvitation::STATUS_ACCEPTED, $invitation->fresh()->status);
        $this->assertSame(1, LoopMember::where('loop_id', $loop->id)->where('user_id', $already->id)->count());
    }

    public function test_expired_and_revoked_invitations_are_refused(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $invitee = User::factory()->create(['organization_id' => $org->id, 'email' => 'late@example.com']);

        $expired = LoopInvitation::factory()->expired()->create([
            'loop_id' => $loop->id, 'organization_id' => $org->id,
            'sender_id' => $owner->id, 'recipient_email' => 'late@example.com',
        ]);
        $revoked = LoopInvitation::factory()->revoked()->create([
            'loop_id' => $loop->id, 'organization_id' => $org->id,
            'sender_id' => $owner->id, 'recipient_email' => 'late@example.com',
        ]);

        $this->assertSame(LoopInvitationService::RESULT_EXPIRED, $this->service()->accept($expired->token, $invitee)['result']);
        $this->assertSame(LoopInvitationService::RESULT_REVOKED, $this->service()->accept($revoked->token, $invitee)['result']);
        $this->assertDatabaseMissing('loop_members', ['loop_id' => $loop->id, 'user_id' => $invitee->id]);
        // A stale pending row past its window is flipped to expired on the way.
        $this->assertSame(LoopInvitation::STATUS_EXPIRED, $expired->fresh()->status);
    }

    public function test_a_user_from_another_organization_cannot_accept(): void
    {
        $org = $this->org();
        $otherOrg = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $invitation = $this->service()->invite($loop, $owner, 'foreign@example.com');
        $foreigner = User::factory()->create(['organization_id' => $otherOrg->id, 'email' => 'foreign@example.com']);

        $outcome = $this->service()->accept($invitation->token, $foreigner);

        $this->assertSame(LoopInvitationService::RESULT_LOOP_UNAVAILABLE, $outcome['result']);
        $this->assertDatabaseMissing('loop_members', ['loop_id' => $loop->id, 'user_id' => $foreigner->id]);
    }

    public function test_a_deactivated_user_cannot_accept(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $invitation = $this->service()->invite($loop, $owner, 'banned@example.com');
        $banned = User::factory()->create([
            'organization_id' => $org->id, 'email' => 'banned@example.com', 'banned_at' => now(),
        ]);

        $this->assertSame(
            LoopInvitationService::RESULT_USER_DEACTIVATED,
            $this->service()->accept($invitation->token, $banned)['result']
        );
    }

    public function test_an_archived_loop_cannot_be_joined(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $invitation = $this->service()->invite($loop, $owner, 'late@example.com');
        $invitee = User::factory()->create(['organization_id' => $org->id, 'email' => 'late@example.com']);
        $loop->update(['status' => 'archived']);

        $this->assertSame(
            LoopInvitationService::RESULT_LOOP_UNAVAILABLE,
            $this->service()->accept($invitation->token, $invitee)['result']
        );
    }

    public function test_loops_disabled_on_the_organization_blocks_acceptance(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $invitation = $this->service()->invite($loop, $owner, 'late@example.com');
        $invitee = User::factory()->create(['organization_id' => $org->id, 'email' => 'late@example.com']);
        $org->update(['loops_enabled' => false]);

        $this->assertSame(
            LoopInvitationService::RESULT_LOOP_UNAVAILABLE,
            $this->service()->accept($invitation->token, $invitee->fresh())['result']
        );
    }

    public function test_accepting_closes_a_pending_join_request(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $invitee = User::factory()->create(['organization_id' => $org->id, 'email' => 'applicant@example.com']);
        $joinRequest = LoopJoinRequest::factory()->create([
            'loop_id' => $loop->id, 'organization_id' => $org->id, 'user_id' => $invitee->id,
        ]);
        $invitation = $this->service()->invite($loop, $owner, 'applicant@example.com');

        $this->service()->accept($invitation->token, $invitee);

        $this->assertSame(LoopJoinRequest::STATUS_ACCEPTED, $joinRequest->fresh()->status);
    }

    // ── Révocation ──────────────────────────────────────────────────────────

    public function test_owner_can_revoke_a_pending_invitation(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $invitation = $this->service()->invite($loop, $owner, 'revoke@example.com');
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->post(route('loop-invitations.revoke', $invitation))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(LoopInvitation::STATUS_REVOKED, $invitation->fresh()->status);
    }

    public function test_an_accepted_invitation_can_never_be_revoked(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $invitee = User::factory()->create(['organization_id' => $org->id, 'email' => 'done@example.com']);
        $invitation = $this->service()->invite($loop, $owner, 'done@example.com');
        $this->service()->accept($invitation->token, $invitee);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->post(route('loop-invitations.revoke', $invitation))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(LoopInvitation::STATUS_ACCEPTED, $invitation->fresh()->status);
    }

    public function test_standard_member_cannot_revoke(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $member = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $member->id]);
        $invitation = $this->service()->invite($loop, $owner, 'x@example.com');
        $this->withCurrentOrganization($org);

        $this->actingAs($member)->post(route('loop-invitations.revoke', $invitation))->assertForbidden();
        $this->assertSame(LoopInvitation::STATUS_PENDING, $invitation->fresh()->status);
    }

    // ── Page publique : lecture seule ───────────────────────────────────────

    public function test_landing_page_is_public_and_read_only(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $invitation = $this->service()->invite($loop, $owner, 'guest@example.com');

        $this->get(route('loop-invitations.show', $invitation->token))
            ->assertOk()
            ->assertSee($loop->name)
            ->assertSee(__('loops.invitation_login_cta'))
            ->assertSee(__('loops.invitation_register_cta'));

        // A GET must never mutate anything.
        $this->assertSame(LoopInvitation::STATUS_PENDING, $invitation->fresh()->status);
        $this->assertDatabaseCount('loop_members', 1);
    }

    public function test_accept_route_rejects_a_get_request(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $invitation = $this->service()->invite($loop, $owner, 'guest@example.com');

        $this->actingAs($owner)
            ->get('/loop-invitations/'.$invitation->token.'/accept')
            ->assertStatus(405);
    }

    public function test_unknown_token_returns_404(): void
    {
        $this->get(route('loop-invitations.show', 'nope'))->assertNotFound();
    }

    // ── Reprise après authentification ──────────────────────────────────────

    public function test_prepare_parks_the_token_in_the_session(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $invitation = $this->service()->invite($loop, $owner, 'guest@example.com');

        $this->post(route('loop-invitations.prepare', $invitation->token), ['intent' => 'login'])
            ->assertRedirect()
            ->assertSessionHas(LoopInvitation::SESSION_KEY, $invitation->token);
    }

    public function test_existing_account_logs_in_and_the_invitation_is_resumed(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $invitee = User::factory()->create([
            'organization_id' => $org->id,
            'email' => 'comeback@example.com',
            'password' => bcrypt('password-1234'),
        ]);
        $invitation = $this->service()->invite($loop, $owner, 'comeback@example.com');
        $this->withCurrentOrganization($org);

        $this->post(route('loop-invitations.prepare', $invitation->token), ['intent' => 'login']);

        $this->post(route('login'), ['email' => 'comeback@example.com', 'password' => 'password-1234'])
            // TASK-1281 : l'acceptation atterrit dans l'Organization de la Boucle,
            // plus sur la route courte (qui resout l'Organization par defaut).
            ->assertRedirect(route('organization.loops.show', ['organization' => $org->slug, 'loop' => $loop->id]));

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $loop->id, 'user_id' => $invitee->id, 'status' => 'active',
        ]);
        $this->assertSame(LoopInvitation::STATUS_ACCEPTED, $invitation->fresh()->status);
        // The token is consumed, not left behind for a later request.
        $this->assertNull(session(LoopInvitation::SESSION_KEY));
    }

    public function test_logging_in_as_someone_else_does_not_claim_the_token(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $other = User::factory()->create([
            'organization_id' => $org->id,
            'email' => 'someone.else@example.com',
            'password' => bcrypt('password-1234'),
        ]);
        $invitation = $this->service()->invite($loop, $owner, 'intended@example.com');
        $this->withCurrentOrganization($org);

        $this->post(route('loop-invitations.prepare', $invitation->token), ['intent' => 'login']);
        $this->post(route('login'), ['email' => 'someone.else@example.com', 'password' => 'password-1234']);

        $this->assertDatabaseMissing('loop_members', ['loop_id' => $loop->id, 'user_id' => $other->id]);
        $this->assertSame(LoopInvitation::STATUS_PENDING, $invitation->fresh()->status);
    }

    // ── Modèle email système ────────────────────────────────────────────────

    public function test_the_loop_invitation_system_template_is_seeded_and_idempotent(): void
    {
        $org = $this->org();

        $this->seed(SystemEmailTemplateSeeder::class);
        $this->seed(SystemEmailTemplateSeeder::class);

        foreach (['fr', 'en'] as $locale) {
            $this->assertSame(1, SystemEmailTemplate::where('slug', 'loop_invitation')
                ->where('organization_id', $org->id)
                ->where('locale', $locale)
                ->count(), "Duplicate template for locale {$locale}");
        }
    }

    public function test_the_administrable_subject_and_body_are_used(): void
    {
        Mail::fake();
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id, 'name' => 'Alice Sender']);
        $loop = $this->ownedLoop($org, $owner);
        $loop->update(['name' => 'Boucle Test', 'tagline' => 'Une accroche']);
        $this->systemTemplate($org, 'SUJET-PERSO {{ loop_name }}', '<p>CORPS-PERSO {{ sender_name }} / {{ loop_name }} / {{ invitation_url }} / {{ personal_message }}</p>');
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)->post(route('loops.invitations.store', $loop), [
            'email' => 'tpl@example.com',
            'message' => 'Mon message perso',
        ])->assertRedirect();

        $log = EmailLog::where('to_email', 'tpl@example.com')->firstOrFail();
        $this->assertSame('SUJET-PERSO Boucle Test', $log->subject);
        $this->assertSame('system_email_template', $log->data['template_used']);
        $this->assertNull($log->data['fallback_reason']);
    }

    public function test_a_missing_template_falls_back_and_records_why(): void
    {
        Mail::fake();
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->post(route('loops.invitations.store', $loop), ['email' => 'fb@example.com'])
            ->assertRedirect();

        $log = EmailLog::where('to_email', 'fb@example.com')->firstOrFail();
        $this->assertSame('blade_fallback', $log->data['template_used']);
        $this->assertSame('template_missing', $log->data['fallback_reason']);
    }

    public function test_a_disabled_template_falls_back_and_says_so(): void
    {
        Mail::fake();
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $this->systemTemplate($org, 'NE DOIT PAS SERVIR', '<p>NE DOIT PAS SERVIR</p>', enabled: false);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->post(route('loops.invitations.store', $loop), ['email' => 'off@example.com'])
            ->assertRedirect();

        $log = EmailLog::where('to_email', 'off@example.com')->firstOrFail();
        $this->assertSame('blade_fallback', $log->data['template_used']);
        $this->assertSame('template_disabled', $log->data['fallback_reason']);
        $this->assertNotSame('NE DOIT PAS SERVIR', $log->subject);
    }

    public function test_the_email_log_carries_the_full_payload(): void
    {
        Mail::fake();
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->post(route('loops.invitations.store', $loop), ['email' => 'payload@example.com'])
            ->assertRedirect();

        $invitation = LoopInvitation::where('recipient_email', 'payload@example.com')->firstOrFail();
        $log = EmailLog::where('to_email', 'payload@example.com')->firstOrFail();

        $this->assertSame('loop-invitation', $log->data['source']);
        $this->assertSame($invitation->id, $log->data['invitation_id']);
        $this->assertSame($loop->id, $log->data['loop_id']);
        $this->assertSame($org->id, $log->data['organization_id']);
        $this->assertSame($invitation->invitation_type, $log->data['invitation_type']);
        $this->assertSame('payload@example.com', $log->data['recipient_email']);
        $this->assertSame('sent', $log->status);
    }

    public function test_a_personal_message_is_escaped_in_the_rendered_body(): void
    {
        Mail::fake();
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $this->systemTemplate($org, 'Sujet', '<p>{{ personal_message }}</p>');
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)->post(route('loops.invitations.store', $loop), [
            'email' => 'xss@example.com',
            'message' => '<script>alert(1)</script>',
        ])->assertRedirect();

        // The guarantee lives in EmailerService::interpolate(), which runs e()
        // on every substituted value: the stored message keeps the raw text
        // while the rendered body can never carry an executable tag.
        $invitation = LoopInvitation::where('recipient_email', 'xss@example.com')->firstOrFail();
        $this->assertSame('<script>alert(1)</script>', $invitation->message);

        $rendered = app(EmailerService::class)->interpolate(
            '<p>{{ personal_message }}</p>',
            ['personal_message' => $invitation->message],
            ['personal_message'],
        );
        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
    }
}
