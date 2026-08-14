<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TASK1076PostCreationInviteTest extends TestCase
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
        $loop = Loop::factory()->create(['organization_id' => $organization->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);

        return $loop;
    }

    // ── Parcours ────────────────────────────────────────────────────────────

    public function test_creating_a_loop_redirects_to_the_optional_invite_step(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $response = $this->actingAs($user)->post(route('loops.store'), ['name' => 'Nouvelle Boucle']);

        $loop = Loop::where('name', 'Nouvelle Boucle')->firstOrFail();
        $response->assertRedirect(route('loops.invite', $loop))->assertSessionHas('success');

        // The step is reachable and never blocking: the creator is already owner.
        $this->actingAs($user)->get(route('loops.invite', $loop))->assertOk();
        $this->assertSame(1, LoopMember::where('loop_id', $loop->id)->where('role', 'owner')->count());
    }

    public function test_invite_step_offers_later_and_open_the_loop(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->get(route('loops.invite', $loop))
            ->assertOk()
            ->assertSee(__('loops.invite_later'))
            ->assertSee(__('loops.cta_open_workspace'))
            ->assertSee(route('loops.show', $loop), false);
    }

    public function test_invite_step_lists_only_organization_members_not_already_in_the_loop(): void
    {
        $org = $this->org();
        $otherOrg = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id, 'name' => 'Owner Person']);
        $loop = $this->ownedLoop($org, $owner);

        $candidate = User::factory()->create(['organization_id' => $org->id, 'name' => 'Candidate Person']);
        $alreadyIn = User::factory()->create(['organization_id' => $org->id, 'name' => 'Already Person']);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $alreadyIn->id, 'status' => 'active']);
        $foreigner = User::factory()->create(['organization_id' => $otherOrg->id, 'name' => 'Foreign Person']);
        $banned = User::factory()->create(['organization_id' => $org->id, 'name' => 'Banned Person', 'banned_at' => now()]);
        $this->withCurrentOrganization($org);

        $response = $this->actingAs($owner)
            ->get(route('loops.invite', $loop))
            ->assertOk()
            ->assertSee('Candidate Person');

        // Personne d'une autre Organization, ni compte desactive : ces deux-la
        // n'ont rien a faire nulle part sur cet ecran.
        $response->assertDontSee('Foreign Person')
            ->assertDontSee('Banned Person');

        // « Already Person » est bien sur la page — la liste des membres de la
        // Boucle y figure desormais, c'est meme l'interet de l'ecran. Ce que la
        // regle interdit, c'est de la proposer une seconde fois : on verifie
        // donc l'absence de sa case a cocher, pas l'absence de son nom.
        $response->assertSee('Already Person')
            ->assertDontSee('value="'.$alreadyIn->id.'"', false)
            ->assertSee('value="'.$candidate->id.'"', false);
    }

    // ── Permissions ─────────────────────────────────────────────────────────

    public function test_organization_admin_can_open_the_invite_step(): void
    {
        $orgAdmin = User::factory()->create();
        $org = $this->org(['admin_id' => $orgAdmin->id]);
        $orgAdmin->update(['organization_id' => $org->id]);
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($orgAdmin->fresh())->get(route('loops.invite', $loop))->assertOk();
    }

    public function test_standard_member_cannot_open_the_invite_step(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $member = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $member->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($member)->get(route('loops.invite', $loop))->assertForbidden();
    }

    public function test_user_from_another_organization_gets_404(): void
    {
        $org = $this->org();
        $otherOrg = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $otherOrg->id]);
        $outsider = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($outsider)->get(route('loops.invite', $loop))->assertNotFound();
    }

    public function test_standard_member_cannot_post_members(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $member = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $member->id]);
        $target = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($member)
            ->post(route('loops.invite.members', $loop), ['user_ids' => [$target->id]])
            ->assertForbidden();

        $this->assertDatabaseMissing('loop_members', ['loop_id' => $loop->id, 'user_id' => $target->id]);
    }

    // ── Ajout de membres ────────────────────────────────────────────────────

    public function test_owner_adds_one_member(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $target = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->post(route('loops.invite.members', $loop), ['user_ids' => [$target->id]])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $loop->id,
            'user_id' => $target->id,
            'role' => 'member',
            'status' => 'active',
        ]);
        $this->assertNotNull(LoopMember::where('loop_id', $loop->id)->where('user_id', $target->id)->first()->joined_at);
    }

    public function test_owner_adds_several_members_at_once(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $targets = User::factory()->count(3)->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->post(route('loops.invite.members', $loop), ['user_ids' => $targets->pluck('id')->all()])
            ->assertRedirect();

        $this->assertSame(4, LoopMember::where('loop_id', $loop->id)->where('status', 'active')->count());
    }

    public function test_adding_the_same_member_twice_is_idempotent(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $target = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)->post(route('loops.invite.members', $loop), ['user_ids' => [$target->id]])->assertRedirect();
        $this->actingAs($owner)->post(route('loops.invite.members', $loop), ['user_ids' => [$target->id]])->assertRedirect();

        $this->assertSame(1, LoopMember::where('loop_id', $loop->id)->where('user_id', $target->id)->count());
    }

    public function test_a_user_from_another_organization_is_never_added(): void
    {
        $org = $this->org();
        $otherOrg = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $foreigner = User::factory()->create(['organization_id' => $otherOrg->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->post(route('loops.invite.members', $loop), ['user_ids' => [$foreigner->id]])
            ->assertRedirect();

        $this->assertDatabaseMissing('loop_members', ['loop_id' => $loop->id, 'user_id' => $foreigner->id]);
    }

    public function test_a_deactivated_user_is_never_added(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $banned = User::factory()->create(['organization_id' => $org->id, 'banned_at' => now()]);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->post(route('loops.invite.members', $loop), ['user_ids' => [$banned->id]])
            ->assertRedirect();

        $this->assertDatabaseMissing('loop_members', ['loop_id' => $loop->id, 'user_id' => $banned->id]);
    }

    // ── Interaction avec une demande en attente ─────────────────────────────

    public function test_adding_someone_with_a_pending_request_closes_it_and_creates_one_membership(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $applicant = User::factory()->create(['organization_id' => $org->id]);
        $joinRequest = LoopJoinRequest::factory()->create([
            'loop_id' => $loop->id,
            'organization_id' => $org->id,
            'user_id' => $applicant->id,
        ]);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->post(route('loops.invite.members', $loop), ['user_ids' => [$applicant->id]])
            ->assertRedirect();

        $joinRequest->refresh();
        $this->assertSame(LoopJoinRequest::STATUS_ACCEPTED, $joinRequest->status);
        $this->assertSame($owner->id, $joinRequest->decided_by);
        $this->assertNotNull($joinRequest->decided_at);
        $this->assertSame(1, LoopMember::where('loop_id', $loop->id)->where('user_id', $applicant->id)->count());
    }

    public function test_validation_requires_at_least_one_user(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = $this->ownedLoop($org, $owner);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->post(route('loops.invite.members', $loop), ['user_ids' => []])
            ->assertSessionHasErrors('user_ids');
    }
}
