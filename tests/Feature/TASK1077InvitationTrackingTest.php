<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tracking of Loop invitations on the personal /invitations screen.
 *
 * The visibility rule under test is deliberate: that page has always been
 * scoped to the signed-in user, so Loop invitations widen by role
 * (own sends → owned Loops → whole Organization for its admin) instead of
 * listing everything to everyone.
 */
class TASK1077InvitationTrackingTest extends TestCase
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

    private function ownedLoop(Organization $organization, User $owner, string $name = 'Boucle'): Loop
    {
        $loop = Loop::factory()->create([
            'organization_id' => $organization->id, 'status' => 'active', 'name' => $name,
        ]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);

        return $loop;
    }

    private function invitation(Loop $loop, User $sender, array $overrides = []): LoopInvitation
    {
        return LoopInvitation::factory()->create(array_merge([
            'loop_id' => $loop->id,
            'organization_id' => $loop->organization_id,
            'sender_id' => $sender->id,
        ], $overrides));
    }

    // ── Affichage ───────────────────────────────────────────────────────────

    public function test_a_loop_invitation_is_listed_with_its_key_fields(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id, 'name' => 'Olga Owner']);
        $loop = $this->ownedLoop($org, $owner, 'Boucle Entraide');
        $this->invitation($loop, $owner, [
            'recipient_email' => 'target@example.com',
            'recipient_name' => 'Tina Target',
        ]);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->get(route('invitations.index'))
            ->assertOk()
            ->assertSee(__('loops.invitations_loop_section_title'))
            ->assertSee('Tina Target')
            ->assertSee('target@example.com')
            ->assertSee('Boucle Entraide')
            ->assertSee(__('loops.invitations_status_pending'));
    }

    public function test_the_token_is_never_rendered(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $invitation = $this->invitation($loop, $owner);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->get(route('invitations.index'))
            ->assertOk()
            ->assertDontSee($invitation->token);
    }

    public function test_every_status_is_rendered_with_its_own_label(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $this->invitation($loop, $owner, ['recipient_email' => 'a@example.com']);
        $this->invitation($loop, $owner, ['recipient_email' => 'b@example.com', 'status' => LoopInvitation::STATUS_ACCEPTED, 'accepted_at' => now()]);
        $this->invitation($loop, $owner, ['recipient_email' => 'c@example.com', 'status' => LoopInvitation::STATUS_REVOKED]);
        $this->invitation($loop, $owner, ['recipient_email' => 'd@example.com', 'expires_at' => now()->subDay()]);
        $this->withCurrentOrganization($org);

        $response = $this->actingAs($owner)->get(route('invitations.index'))->assertOk();

        foreach (['pending', 'accepted', 'expired', 'revoked'] as $state) {
            $response->assertSee(__('loops.invitations_status_'.$state));
        }
    }

    public function test_recipient_type_is_shown(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $this->invitation($loop, $owner, ['invitation_type' => LoopInvitation::TYPE_EXISTING_MEMBER]);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->get(route('invitations.index'))
            ->assertOk()
            ->assertSee(__('loops.invitations_recipient_existing_member'));
    }

    public function test_the_target_loop_is_clickable(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $this->invitation($loop, $owner);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->get(route('invitations.index'))
            ->assertOk()
            ->assertSee(route('loops.show', $loop), false);
    }

    public function test_the_empty_state_is_shown_when_there_is_nothing(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)
            ->get(route('invitations.index'))
            ->assertOk()
            ->assertSee(__('loops.invitations_loop_section_empty'));
    }

    public function test_the_filters_and_search_are_present_when_there_is_data(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $this->invitation($loop, $owner);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->get(route('invitations.index'))
            ->assertOk()
            ->assertSee(__('loops.invitations_search_placeholder'))
            ->assertSee(__('loops.invitations_filter_all'));
    }

    // ── Non-régression de l'existant ────────────────────────────────────────

    public function test_the_existing_referral_tracking_is_untouched(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $referred = User::factory()->create(['organization_id' => $org->id]);
        Referral::factory()->create([
            'referrer_user_id' => $user->id,
            'referred_user_id' => $referred->id,
            'organization_id' => $org->id,
        ]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)
            ->get(route('invitations.index'))
            ->assertOk()
            ->assertSee(__('points.invite_peers_title'))
            ->assertSee(__('points.sent_invitations_history'))
            ->assertSee(__('points.joined_invitations_history'));
    }

    // ── Visibilité ──────────────────────────────────────────────────────────

    public function test_a_user_sees_the_invitations_they_sent(): void
    {
        $org = $this->org();
        $sender = User::factory()->create(['organization_id' => $org->id]);
        $someoneElse = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $someoneElse);
        $this->invitation($loop, $sender, ['recipient_email' => 'mine@example.com']);
        $this->withCurrentOrganization($org);

        $this->actingAs($sender)
            ->get(route('invitations.index'))
            ->assertOk()
            ->assertSee('mine@example.com');
    }

    public function test_a_standard_member_does_not_see_other_peoples_invitations(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $this->invitation($loop, $owner, ['recipient_email' => 'not-for-you@example.com']);

        $bystander = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $bystander->id, 'status' => 'active']);
        $this->withCurrentOrganization($org);

        $this->actingAs($bystander)
            ->get(route('invitations.index'))
            ->assertOk()
            ->assertDontSee('not-for-you@example.com')
            ->assertSee(__('loops.invitations_loop_section_empty'));
    }

    public function test_an_owner_sees_every_invitation_of_their_own_loop(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $otherSender = User::factory()->create(['organization_id' => $org->id]);
        $this->invitation($loop, $otherSender, ['recipient_email' => 'sent-by-someone-else@example.com']);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->get(route('invitations.index'))
            ->assertOk()
            ->assertSee('sent-by-someone-else@example.com');
    }

    public function test_an_organization_admin_sees_every_loop_invitation(): void
    {
        $orgAdmin = User::factory()->create();
        $org = $this->org(['admin_id' => $orgAdmin->id]);
        $orgAdmin->update(['organization_id' => $org->id]);
        $stranger = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $stranger);
        $this->invitation($loop, $stranger, ['recipient_email' => 'anywhere@example.com']);
        $this->withCurrentOrganization($org);

        $this->actingAs($orgAdmin->fresh())
            ->get(route('invitations.index'))
            ->assertOk()
            ->assertSee('anywhere@example.com');
    }

    public function test_invitations_of_another_organization_are_never_listed(): void
    {
        $org = $this->org();
        $otherOrg = $this->org();
        $orgAdmin = User::factory()->create(['organization_id' => $org->id]);
        $org->update(['admin_id' => $orgAdmin->id]);

        $foreignSender = User::factory()->create(['organization_id' => $otherOrg->id]);
        $foreignLoop = $this->ownedLoop($otherOrg, $foreignSender);
        $this->invitation($foreignLoop, $foreignSender, ['recipient_email' => 'foreign@example.com']);
        $this->withCurrentOrganization($org);

        $this->actingAs($orgAdmin->fresh())
            ->get(route('invitations.index'))
            ->assertOk()
            ->assertDontSee('foreign@example.com');
    }

    public function test_a_guest_cannot_reach_the_tracking_page(): void
    {
        $this->get(route('invitations.index'))->assertRedirect();
    }

    // ── Révocation depuis le suivi ──────────────────────────────────────────

    public function test_owner_revokes_from_the_tracking_page_and_every_screen_agrees(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $invitation = $this->invitation($loop, $owner, ['recipient_email' => 'bye@example.com']);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->from(route('invitations.index'))
            ->post(route('loop-invitations.revoke', $invitation))
            ->assertRedirect(route('invitations.index'));

        $this->assertSame(LoopInvitation::STATUS_REVOKED, $invitation->fresh()->status);

        // One service, one rule: the post-creation screen reflects it at once.
        $this->actingAs($owner)
            ->get(route('loops.invite', $loop))
            ->assertOk()
            ->assertSee(__('loops.invitations_status_revoked'));
    }

    public function test_the_revoke_action_is_absent_for_a_non_pending_invitation(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $this->invitation($loop, $owner, [
            'recipient_email' => 'done@example.com',
            'status' => LoopInvitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
        ]);
        $this->withCurrentOrganization($org);

        $response = $this->actingAs($owner)->get(route('invitations.index'))->assertOk();

        $this->assertStringNotContainsString(
            'loop-invitations/'.LoopInvitation::first()->id.'/revoke',
            $response->getContent(),
        );
    }
}
