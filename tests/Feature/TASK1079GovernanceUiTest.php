<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopGovernanceService;
use App\Services\LoopPermissionSettingsService;
use App\Support\Loops\LoopRoleRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Role management across the three screens.
 *
 * The property that matters most here: controls follow the *resolved*
 * permissions, never a role label read in a template — so hiding a button is
 * never what enforces a rule.
 */
class TASK1079GovernanceUiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $superAdmin;

    private User $orgAdmin;

    private Loop $loop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['is_admin' => true]);
        $this->orgAdmin = User::factory()->create();
        $this->org = Organization::factory()->create([
            'loops_enabled' => true, 'is_active' => true, 'admin_id' => $this->orgAdmin->id,
        ]);
        $this->orgAdmin->update(['organization_id' => $this->org->id]);
        $this->superAdmin->update(['organization_id' => $this->org->id]);

        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        $this->loop = Loop::factory()->create([
            'organization_id' => $this->org->id, 'status' => 'active', 'type' => 'general',
            'created_by' => $this->owner->id,
        ]);
        app(LoopTypeRegistry::class)->applyPreset($this->loop);
        LoopMember::factory()->owner()->create([
            'loop_id' => $this->loop->id, 'user_id' => $this->owner->id, 'joined_at' => now(),
        ]);

        app()->instance('current_organization', $this->org);
    }

    private function member(string $role = 'member'): LoopMember
    {
        return LoopMember::factory()->create([
            'loop_id' => $this->loop->id,
            'user_id' => User::factory()->create(['organization_id' => $this->org->id])->id,
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);
    }

    private function ownerMembership(): LoopMember
    {
        return LoopMember::where('loop_id', $this->loop->id)->where('user_id', $this->owner->id)->sole();
    }

    private function orgLoopUrl(string $suffix = ''): string
    {
        return '/org/'.$this->org->slug.'/admin/loops/'.$this->loop->id.$suffix;
    }

    // ── Admin global ────────────────────────────────────────────────────────

    public function test_the_global_admin_lists_the_three_role_sections(): void
    {
        $this->member('facilitator');
        $this->member();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.edit', $this->loop))
            ->assertOk()
            ->assertSee(__('loops.governance_owners'))
            ->assertSee(__('loops.governance_facilitators'))
            ->assertSee(__('loops.governance_members'));
    }

    public function test_the_global_admin_promotes_and_demotes(): void
    {
        $member = $this->member();

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.members.role', [$this->loop, $member]), ['role' => 'facilitator'])
            ->assertRedirect();
        $this->assertSame(LoopRoleRegistry::FACILITATOR, $member->fresh()->role);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.members.role', [$this->loop, $member]), ['role' => 'owner'])
            ->assertRedirect();
        $this->assertSame(LoopRoleRegistry::OWNER, $member->fresh()->role);
    }

    public function test_the_global_admin_cannot_demote_the_last_owner(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.members.role', [$this->loop, $this->ownerMembership()]), ['role' => 'member'])
            ->assertRedirect()
            ->assertSessionHas('error', __('loops.governance_refused_last_owner'));

        $this->assertSame(LoopRoleRegistry::OWNER, $this->ownerMembership()->role);
    }

    public function test_the_global_admin_adds_someone_directly_as_owner(): void
    {
        $newcomer = User::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.loops.members.add', $this->loop), ['user_id' => $newcomer->id, 'role' => 'owner'])
            ->assertRedirect();

        $this->assertSame(
            LoopRoleRegistry::OWNER,
            LoopMember::where('loop_id', $this->loop->id)->where('user_id', $newcomer->id)->value('role'),
        );
    }

    public function test_a_membership_of_another_loop_is_refused(): void
    {
        $otherLoop = Loop::factory()->create(['organization_id' => $this->org->id]);
        $foreign = LoopMember::factory()->create([
            'loop_id' => $otherLoop->id,
            'user_id' => User::factory()->create(['organization_id' => $this->org->id])->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.members.role', [$this->loop, $foreign]), ['role' => 'owner'])
            ->assertNotFound();
    }

    // ── Admin Organization ──────────────────────────────────────────────────

    public function test_the_organization_admin_manages_roles_in_their_own_loop(): void
    {
        $member = $this->member();

        $this->actingAs($this->orgAdmin->fresh())
            ->put($this->orgLoopUrl('/members/'.$member->id.'/role'), ['role' => 'facilitator'])
            ->assertRedirect();

        $this->assertSame(LoopRoleRegistry::FACILITATOR, $member->fresh()->role);
    }

    public function test_the_organization_admin_adds_a_member_with_a_role(): void
    {
        $newcomer = User::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->orgAdmin->fresh())
            ->post($this->orgLoopUrl('/members'), ['user_id' => $newcomer->id, 'role' => 'facilitator'])
            ->assertRedirect();

        $this->assertSame(
            LoopRoleRegistry::FACILITATOR,
            LoopMember::where('loop_id', $this->loop->id)->where('user_id', $newcomer->id)->value('role'),
        );
    }

    public function test_the_organization_admin_cannot_add_someone_from_another_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreigner = User::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->orgAdmin->fresh())
            ->post($this->orgLoopUrl('/members'), ['user_id' => $foreigner->id])
            ->assertSessionHasErrors('user_id');

        $this->assertDatabaseMissing('loop_members', [
            'loop_id' => $this->loop->id, 'user_id' => $foreigner->id,
        ]);
    }

    public function test_the_organization_admin_cannot_touch_a_loop_of_another_organization(): void
    {
        $otherOrg = Organization::factory()->create(['loops_enabled' => true]);
        $foreignLoop = Loop::factory()->create(['organization_id' => $otherOrg->id]);
        $foreignMember = LoopMember::factory()->create([
            'loop_id' => $foreignLoop->id,
            'user_id' => User::factory()->create(['organization_id' => $otherOrg->id])->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->orgAdmin->fresh())
            ->put('/org/'.$this->org->slug.'/admin/loops/'.$foreignLoop->id.'/members/'.$foreignMember->id.'/role', ['role' => 'owner'])
            ->assertNotFound();
    }

    public function test_the_organization_admin_cannot_demote_the_last_owner(): void
    {
        $this->actingAs($this->orgAdmin->fresh())
            ->put($this->orgLoopUrl('/members/'.$this->ownerMembership()->id.'/role'), ['role' => 'member'])
            ->assertRedirect()
            ->assertSessionHas('error', __('loops.governance_refused_last_owner'));

        $this->assertSame(LoopRoleRegistry::OWNER, $this->ownerMembership()->role);
    }

    // ── Card Membres (workspace) ────────────────────────────────────────────

    public function test_an_owner_sees_the_governance_actions_in_the_workspace(): void
    {
        $this->member();

        $this->actingAs($this->owner)
            ->get(route('loops.show', $this->loop))
            ->assertOk()
            ->assertSee(__('loops.governance_promote_owner'))
            ->assertSee(__('loops.governance_promote_facilitator'));
    }

    public function test_a_facilitator_does_not_see_the_structural_actions(): void
    {
        $facilitator = $this->member('facilitator');
        $this->member();

        $this->actingAs($facilitator->user)
            ->get(route('loops.show', $this->loop))
            ->assertOk()
            ->assertDontSee(__('loops.governance_promote_owner'))
            ->assertDontSee(__('loops.governance_promote_facilitator'));
    }

    public function test_a_member_does_not_see_any_governance_action(): void
    {
        $member = $this->member();

        $this->actingAs($member->user)
            ->get(route('loops.show', $this->loop))
            ->assertOk()
            ->assertDontSee(__('loops.governance_promote_owner'))
            ->assertDontSee(__('loops.governance_demote_member'));
    }

    public function test_hiding_the_button_is_not_the_guard(): void
    {
        $facilitator = $this->member('facilitator');
        $target = $this->member();

        // Calling the endpoint directly must be refused, not merely unlinked.
        $this->actingAs($facilitator->user)
            ->put(route('loops.members.role', $target), ['role' => 'owner'])
            ->assertForbidden();

        $this->assertSame(LoopRoleRegistry::MEMBER, $target->fresh()->role);
    }

    public function test_a_member_calling_the_endpoint_directly_is_refused(): void
    {
        $member = $this->member();
        $target = $this->member();

        $this->actingAs($member->user)
            ->put(route('loops.members.role', $target), ['role' => 'facilitator'])
            ->assertForbidden();
    }

    public function test_an_owner_promotes_from_the_workspace(): void
    {
        $target = $this->member();

        $this->actingAs($this->owner)
            ->put(route('loops.members.role', $target), ['role' => 'facilitator'])
            ->assertRedirect();

        $this->assertSame(LoopRoleRegistry::FACILITATOR, $target->fresh()->role);
    }

    public function test_an_owner_of_another_organization_cannot_reach_the_endpoint(): void
    {
        $otherOrg = Organization::factory()->create(['loops_enabled' => true]);
        $foreigner = User::factory()->create(['organization_id' => $otherOrg->id]);
        $target = $this->member();

        $this->actingAs($foreigner)
            ->put(route('loops.members.role', $target), ['role' => 'owner'])
            ->assertNotFound();
    }

    public function test_the_last_owner_explanation_appears_once(): void
    {
        $this->member();

        $html = $this->actingAs($this->owner)->get(route('loops.show', $this->loop))->assertOk()->getContent();

        // Said once for the roster, not repeated on every row.
        $this->assertSame(1, substr_count($html, __('loops.governance_last_owner')));
    }

    public function test_several_owners_are_all_listed(): void
    {
        $second = $this->member();
        app(LoopGovernanceService::class)->promoteToOwner($second);

        $this->actingAs($this->owner)
            ->get(route('loops.show', $this->loop))
            ->assertOk()
            ->assertSee($this->owner->publicDisplayName())
            ->assertSee($second->user->publicDisplayName());
    }

    public function test_a_facilitator_gains_the_actions_once_the_organization_allows_it(): void
    {
        $facilitator = $this->member('facilitator');
        $this->member();

        $this->actingAs($facilitator->user)
            ->get(route('loops.show', $this->loop))
            ->assertDontSee(__('loops.governance_promote_facilitator'));

        // The control follows the resolved permission, so an Organization
        // override changes what the screen offers.
        app(LoopPermissionSettingsService::class)
            ->setOrganization($this->org, 'general', 'facilitator', 'loops.manage_facilitators', true);

        $this->actingAs($facilitator->user)
            ->get(route('loops.show', $this->loop))
            ->assertOk()
            ->assertSee(__('loops.governance_promote_facilitator'));
    }
}
