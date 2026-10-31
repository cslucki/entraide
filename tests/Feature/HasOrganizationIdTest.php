<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Users\Exceptions\UserOrganizationImmutableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for HasOrganizationId: organization_id is set at CREATION, and never
 * changes afterwards.
 *
 * Rules:
 * - organization_id provided at creation → canonical
 * - nothing provided → the trait/factory assigns one
 * - after creation → immutable (TASK-1726)
 *
 * ## What TASK-1726 changed here, and why these tests had to flip
 *
 * Six tests in the "Update" and "Null safety" sections asserted that
 * `$user->update(['organization_id' => $other->id])` MOVED the account, and
 * that `=> null` DETACHED it. Both now throw.
 *
 * They were not deleted. A test whose verdict reverses is the most valuable
 * record a decision can leave: deleting it would erase the fact that the old
 * behaviour was deliberate, documented, and covered — and the next reader
 * would take the new refusal for an accident. Each one below therefore
 * asserts the REFUSAL, on the same fixture, in the same place.
 *
 * The product reason lives in `User::booted()`; the full proof, including the
 * paths that must keep working, lives in
 * `tests/Feature/TASK1726OrganizationImmuableTest.php`.
 */
class HasOrganizationIdTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Creation: organization_id is canonical
    // -------------------------------------------------------------------------

    public function test_creating_with_organization_id_syncs_community_id(): void
    {
        $org = Organization::factory()->create();

        $user = User::factory()->create([
            'organization_id' => $org->id,
        ]);

        $this->assertEquals($org->id, $user->organization_id);
    }

    public function test_creating_with_organization_id(): void
    {
        $org = Organization::factory()->create();

        $user = User::factory()->create([
            'organization_id' => $org->id,
        ]);

        $this->assertEquals($org->id, $user->organization_id);
    }

    public function test_creating_with_organization_id_is_set(): void
    {
        $org = Organization::factory()->create();

        $user = User::factory()->create([
            'organization_id' => $org->id,
        ]);

        $this->assertEquals($org->id, $user->organization_id);
    }

    public function test_factory_creation_assigns_an_organization_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->organization_id);
    }

    // -------------------------------------------------------------------------
    // Update: organization_id is immutable (TASK-1726)
    // -------------------------------------------------------------------------

    /**
     * Was `test_updating_organization_id_syncs_community_id`, and asserted the
     * move succeeded. The three near-identical tests that followed it asserted
     * the same thing three more times; they are folded into this one, because
     * three copies of one assertion were never three guards.
     */
    public function test_updating_organization_id_is_refused(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $orgA->id]);
        $this->assertEquals($orgA->id, $user->organization_id);

        $this->expectException(UserOrganizationImmutableException::class);

        try {
            $user->update(['organization_id' => $orgB->id]);
        } finally {
            // In the `finally`, so that persistence is checked even as the
            // exception propagates: a guard that threw AFTER writing would
            // still satisfy `expectException()` on its own.
            $this->assertEquals($orgA->id, $user->fresh()->organization_id);
        }
    }

    public function test_updating_neither_does_not_sync(): void
    {
        $org = Organization::factory()->create();

        $user = User::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Original',
        ]);

        $user->update(['name' => 'Updated']);

        $user->refresh();
        $this->assertEquals($org->id, $user->organization_id);
    }

    /**
     * Was `test_multiple_updates_maintain_consistency`: A → B → C, each move
     * asserted. The first one is now refused, so there is no chain left to
     * keep consistent — what this test guards is that the FIRST refusal is
     * final, and that a second attempt does not slip through on a model whose
     * in-memory attribute was already dirtied by the first.
     */
    public function test_a_refused_move_leaves_nothing_half_done(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $orgC = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $orgA->id]);

        foreach ([$orgB, $orgC] as $cible) {
            try {
                $user->update(['organization_id' => $cible->id]);
                $this->fail('the move should have been refused');
            } catch (UserOrganizationImmutableException) {
                // expected
            }

            $this->assertEquals($orgA->id, $user->fresh()->organization_id);
        }
    }

    // -------------------------------------------------------------------------
    // Null safety
    // -------------------------------------------------------------------------

    public function test_organization_id_is_set_when_provided(): void
    {
        $org = Organization::factory()->create();

        $user = User::factory()->create([
            'organization_id' => $org->id,
        ]);

        $this->assertEquals($org->id, $user->organization_id);
    }

    public function test_organization_id_is_assigned_when_not_provided(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->organization_id);
    }

    /**
     * Was `test_update_setting_organization_id_to_null` (and a duplicate of
     * it): detaching an account used to be allowed through the model.
     *
     * It is the less obvious half of the same defect. Detaching is not "doing
     * nothing" — it is losing the tenant, and everything scoped to it. Only
     * deleting the Organization itself may do that, and it does so by a mass
     * UPDATE, which fires no model event; `TASK1726OrganizationImmuableTest`
     * proves that path still works.
     */
    public function test_detaching_an_account_from_its_organization_is_refused(): void
    {
        $org = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->assertEquals($org->id, $user->organization_id);

        $this->expectException(UserOrganizationImmutableException::class);

        try {
            $user->update(['organization_id' => null]);
        } finally {
            $this->assertEquals($org->id, $user->fresh()->organization_id);
        }
    }

    /**
     * The other direction, without which the refusal above could be a blanket
     * `throw`: an account with NO Organization can still be given one. That is
     * not a move — it is the first assignment of an account that the deletion
     * of a tenant left behind, and the repair surfaces rely on it.
     */
    public function test_an_orphan_account_can_still_be_attached(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => null]);

        $user->update(['organization_id' => $org->id]);

        $this->assertEquals($org->id, $user->fresh()->organization_id);
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    public function test_organization_id_is_preserved_on_create(): void
    {
        $org = Organization::factory()->create();

        $user = User::factory()->make([
            'organization_id' => $org->id,
        ]);

        $user->save();

        $this->assertEquals($org->id, $user->organization_id);
    }
}
