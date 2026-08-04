<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopGovernanceService;
use App\Services\LoopService;
use App\Support\Loops\LoopRoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-owner governance and the facilitator role.
 *
 * The invariant under test throughout: a Loop always keeps at least one active
 * owner, on every path that could break it — demotion, removal, departure,
 * membership deactivation.
 */
class TASK1079GovernanceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Loop $loop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['loops_enabled' => true, 'is_active' => true]);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        $this->loop = Loop::factory()->create(['organization_id' => $this->org->id, 'status' => 'active']);
        LoopMember::factory()->owner()->create(['loop_id' => $this->loop->id, 'user_id' => $this->owner->id]);

        app()->instance('current_organization', $this->org);
    }

    private function service(): LoopGovernanceService
    {
        return app(LoopGovernanceService::class);
    }

    private function member(string $role = 'member'): LoopMember
    {
        return LoopMember::factory()->create([
            'loop_id' => $this->loop->id,
            'user_id' => User::factory()->create(['organization_id' => $this->org->id])->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    private function ownerMembership(): LoopMember
    {
        return LoopMember::where('loop_id', $this->loop->id)->where('user_id', $this->owner->id)->sole();
    }

    // ── Multi-owner ─────────────────────────────────────────────────────────

    public function test_a_loop_starts_with_exactly_one_owner(): void
    {
        $this->assertSame(1, $this->service()->countActiveOwners($this->loop));
    }

    public function test_a_second_and_third_owner_can_be_appointed(): void
    {
        $this->assertSame(LoopGovernanceService::RESULT_OK, $this->service()->promoteToOwner($this->member()));
        $this->assertSame(LoopGovernanceService::RESULT_OK, $this->service()->promoteToOwner($this->member()));

        $this->assertSame(3, $this->service()->countActiveOwners($this->loop));
    }

    public function test_all_owners_are_returned_deterministically(): void
    {
        $second = $this->member();
        $this->service()->promoteToOwner($second);

        $ids = $this->service()->activeOwners($this->loop)->pluck('id')->all();

        $this->assertCount(2, $ids);
        // Ordered by joined_at, so the answer never depends on row order.
        $this->assertSame($ids, $this->service()->activeOwners($this->loop->fresh())->pluck('id')->all());
    }

    public function test_promoting_an_existing_owner_is_idempotent(): void
    {
        $this->assertSame(
            LoopGovernanceService::RESULT_UNCHANGED,
            $this->service()->promoteToOwner($this->ownerMembership()),
        );
        $this->assertSame(1, $this->service()->countActiveOwners($this->loop));
    }

    public function test_an_owner_can_be_demoted_while_another_remains(): void
    {
        $second = $this->member();
        $this->service()->promoteToOwner($second);

        $this->assertSame(LoopGovernanceService::RESULT_OK, $this->service()->demoteToMember($this->ownerMembership()));
        $this->assertSame(1, $this->service()->countActiveOwners($this->loop));
    }

    // ── Protection du dernier propriétaire, sur les quatre chemins ──────────

    public function test_the_last_owner_cannot_be_demoted(): void
    {
        $this->assertSame(
            LoopGovernanceService::RESULT_LAST_OWNER,
            $this->service()->demoteToMember($this->ownerMembership()),
        );
        $this->assertSame(LoopRoleRegistry::OWNER, $this->ownerMembership()->role);
    }

    public function test_the_last_owner_cannot_be_removed(): void
    {
        $this->assertSame(
            LoopGovernanceService::RESULT_LAST_OWNER,
            $this->service()->removeMember($this->ownerMembership()),
        );
        $this->assertSame('active', $this->ownerMembership()->status);
    }

    public function test_the_last_owner_cannot_leave(): void
    {
        $this->assertSame(
            LoopGovernanceService::RESULT_LAST_OWNER,
            $this->service()->leave($this->loop, $this->owner->id),
        );
        $this->assertSame('active', $this->ownerMembership()->status);
    }

    public function test_the_last_owner_membership_cannot_be_deactivated(): void
    {
        $this->assertSame(
            LoopGovernanceService::RESULT_LAST_OWNER,
            $this->service()->deactivateMembership($this->ownerMembership()),
        );
        $this->assertSame('active', $this->ownerMembership()->status);
    }

    public function test_an_owner_may_leave_once_another_owner_exists(): void
    {
        $this->service()->promoteToOwner($this->member());

        $this->assertSame(LoopGovernanceService::RESULT_OK, $this->service()->leave($this->loop, $this->owner->id));
        $this->assertSame(1, $this->service()->countActiveOwners($this->loop));
    }

    public function test_the_legacy_service_entry_point_still_guards_the_last_owner(): void
    {
        $this->expectException(\RuntimeException::class);
        app(LoopService::class)->removeMember($this->ownerMembership());
    }

    public function test_a_non_owner_is_removed_without_ceremony(): void
    {
        $member = $this->member();

        $this->assertSame(LoopGovernanceService::RESULT_OK, $this->service()->removeMember($member));
        $this->assertSame('left', $member->fresh()->status);
    }

    // ── Animateur ───────────────────────────────────────────────────────────

    public function test_a_member_becomes_a_facilitator_and_back(): void
    {
        $member = $this->member();

        $this->assertSame(LoopGovernanceService::RESULT_OK, $this->service()->promoteToFacilitator($member));
        $this->assertSame(LoopRoleRegistry::FACILITATOR, $member->fresh()->role);

        $this->assertSame(LoopGovernanceService::RESULT_OK, $this->service()->demoteToMember($member->fresh()));
        $this->assertSame(LoopRoleRegistry::MEMBER, $member->fresh()->role);
    }

    public function test_a_facilitator_becomes_an_owner(): void
    {
        $member = $this->member('facilitator');

        $this->assertSame(LoopGovernanceService::RESULT_OK, $this->service()->promoteToOwner($member));
        $this->assertSame(2, $this->service()->countActiveOwners($this->loop));
    }

    public function test_an_owner_becomes_a_facilitator_only_if_another_owner_remains(): void
    {
        $this->assertSame(
            LoopGovernanceService::RESULT_LAST_OWNER,
            $this->service()->promoteToFacilitator($this->ownerMembership()),
        );

        $this->service()->promoteToOwner($this->member());

        $this->assertSame(
            LoopGovernanceService::RESULT_OK,
            $this->service()->promoteToFacilitator($this->ownerMembership()),
        );
    }

    // ── moderator, alias legacy ─────────────────────────────────────────────

    public function test_a_stored_moderator_reads_as_a_facilitator(): void
    {
        $roles = app(LoopRoleRegistry::class);

        $this->assertSame(LoopRoleRegistry::FACILITATOR, $roles->canonical('moderator'));
        $this->assertTrue($roles->isLegacyAlias('moderator'));
        $this->assertFalse($roles->isCanonical('moderator'));
    }

    public function test_touching_a_legacy_moderator_row_writes_a_canonical_value(): void
    {
        $legacy = $this->member('moderator');

        $this->assertSame(LoopGovernanceService::RESULT_OK, $this->service()->promoteToFacilitator($legacy));
        $this->assertSame(LoopRoleRegistry::FACILITATOR, $legacy->fresh()->role);
    }

    public function test_a_legacy_moderator_counts_as_a_facilitator_not_an_owner(): void
    {
        $this->member('moderator');

        $this->assertSame(1, $this->service()->countActiveOwners($this->loop));
    }

    public function test_an_unknown_role_reads_as_the_least_privileged(): void
    {
        // The only safe answer for a value we do not understand.
        $this->assertSame(LoopRoleRegistry::MEMBER, app(LoopRoleRegistry::class)->canonical('wizard'));
    }

    public function test_an_unknown_target_role_is_refused(): void
    {
        $this->assertSame(
            LoopGovernanceService::RESULT_INVALID_ROLE,
            $this->service()->changeRole($this->member(), 'wizard'),
        );
    }

    // ── Réactivation ────────────────────────────────────────────────────────

    public function test_reactivating_a_former_member_applies_the_requested_role(): void
    {
        $former = $this->member();
        $userId = $former->user_id;
        $this->service()->removeMember($former);

        app(LoopService::class)->addMemberByUserId($this->loop, $userId, LoopRoleRegistry::OWNER);

        // Used to silently hand back the old role, leaving the caller convinced
        // they had appointed an owner.
        $this->assertSame(LoopRoleRegistry::OWNER, $former->fresh()->role);
        $this->assertSame('active', $former->fresh()->status);
        $this->assertSame(2, $this->service()->countActiveOwners($this->loop));
    }

    public function test_reactivation_defaults_to_member_when_no_role_is_asked_for(): void
    {
        $former = $this->member('facilitator');
        $userId = $former->user_id;
        $this->service()->removeMember($former);

        app(LoopService::class)->addMemberByUserId($this->loop, $userId);

        $this->assertSame(LoopRoleRegistry::MEMBER, $former->fresh()->role);
    }

    // ── Adhésion inactive ───────────────────────────────────────────────────

    public function test_an_inactive_membership_cannot_be_promoted(): void
    {
        $member = $this->member();
        $this->service()->removeMember($member);

        $this->assertSame(
            LoopGovernanceService::RESULT_INACTIVE,
            $this->service()->promoteToOwner($member->fresh()),
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

        // Promoting it must not touch this Loop's owner count.
        $this->service()->promoteToOwner($foreign);

        $this->assertSame(1, $this->service()->countActiveOwners($this->loop));
    }

    // ── leave() via le contrôleur ───────────────────────────────────────────

    public function test_leaving_through_the_route_refuses_the_last_owner(): void
    {
        $this->actingAs($this->owner)
            ->post(route('loops.leave', $this->loop))
            ->assertRedirect();

        $this->assertSame('active', $this->ownerMembership()->status);
    }

    public function test_leaving_through_the_route_succeeds_once_another_owner_exists(): void
    {
        $this->service()->promoteToOwner($this->member());

        $this->actingAs($this->owner)->post(route('loops.leave', $this->loop))->assertRedirect();

        $this->assertSame('left', $this->ownerMembership()->status);
    }
}
