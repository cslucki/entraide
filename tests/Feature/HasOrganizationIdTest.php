<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for HasOrganizationId Organization-first sync strategy.
 *
 * Validates that organization_id is the canonical source on the code side,
 * with community_id acting as a legacy DB compatibility column.
 *
 * Rules:
 * - organization_id provided → canonical, community_id backfilled
 * - only community_id provided (legacy) → organization_id backfilled
 * - both differ → organization_id wins (no silent community_id overwrite)
 * - neither set → null safety (skip sync)
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
        $this->assertEquals($org->id, $user->community_id);
    }

    public function test_creating_with_community_id_legacy_backfills_organization_id(): void
    {
        $org = Organization::factory()->create();

        $user = User::factory()->create([
            'community_id' => $org->id,
        ]);

        $this->assertEquals($org->id, $user->community_id);
        $this->assertEquals($org->id, $user->organization_id);
    }

    public function test_creating_with_both_consistent(): void
    {
        $org = Organization::factory()->create();

        $user = User::factory()->create([
            'organization_id' => $org->id,
            'community_id' => $org->id,
        ]);

        $this->assertEquals($org->id, $user->organization_id);
        $this->assertEquals($org->id, $user->community_id);
    }

    public function test_creating_with_both_divergent_organization_wins(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $user = User::factory()->create([
            'organization_id' => $orgA->id,
            'community_id' => $orgB->id,
        ]);

        // organization_id must win — community_id is overwritten
        $this->assertEquals($orgA->id, $user->organization_id);
        $this->assertEquals($orgA->id, $user->community_id);
    }

    public function test_creating_with_neither_set(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->organization_id);
        $this->assertNull($user->community_id);
    }

    // -------------------------------------------------------------------------
    // Update: organization_id remains canonical
    // -------------------------------------------------------------------------

    public function test_updating_organization_id_syncs_community_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $orgA->id]);
        $this->assertEquals($orgA->id, $user->community_id);

        $user->update(['organization_id' => $orgB->id]);

        $user->refresh();
        $this->assertEquals($orgB->id, $user->organization_id);
        $this->assertEquals($orgB->id, $user->community_id);
    }

    public function test_updating_community_id_legacy_backfills_organization_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $orgA->id]);

        $user->update(['community_id' => $orgB->id]);

        $user->refresh();
        $this->assertEquals($orgB->id, $user->community_id);
        $this->assertEquals($orgB->id, $user->organization_id);
    }

    public function test_updating_both_divergent_organization_wins(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $orgC = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $orgA->id]);

        $user->update([
            'organization_id' => $orgB->id,
            'community_id' => $orgC->id,
        ]);

        $user->refresh();
        $this->assertEquals($orgB->id, $user->organization_id);
        $this->assertEquals($orgB->id, $user->community_id);
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
        $this->assertEquals($org->id, $user->community_id);
    }

    public function test_multiple_updates_maintain_consistency(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $orgC = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $orgA->id]);
        $this->assertEquals($orgA->id, $user->community_id);

        $user->update(['organization_id' => $orgB->id]);
        $user->refresh();
        $this->assertEquals($orgB->id, $user->organization_id);
        $this->assertEquals($orgB->id, $user->community_id);

        $user->update(['community_id' => $orgC->id]);
        $user->refresh();
        $this->assertEquals($orgC->id, $user->organization_id);
        $this->assertEquals($orgC->id, $user->community_id);
    }

    // -------------------------------------------------------------------------
    // Null safety
    // -------------------------------------------------------------------------

    public function test_null_organization_id_does_not_clear_community_id_on_create(): void
    {
        $org = Organization::factory()->create();

        $user = User::factory()->create([
            'organization_id' => null,
            'community_id' => $org->id,
        ]);

        $this->assertEquals($org->id, $user->community_id);
        $this->assertEquals($org->id, $user->organization_id);
    }

    public function test_null_community_id_does_not_clear_organization_id_on_create(): void
    {
        $org = Organization::factory()->create();

        $user = User::factory()->create([
            'organization_id' => $org->id,
            'community_id' => null,
        ]);

        $this->assertEquals($org->id, $user->organization_id);
        $this->assertEquals($org->id, $user->community_id);
    }

    public function test_update_setting_organization_id_to_null_clears_community_id(): void
    {
        $org = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->assertEquals($org->id, $user->community_id);

        $user->update(['organization_id' => null]);

        $user->refresh();
        $this->assertNull($user->organization_id);
        $this->assertNull($user->community_id);
    }

    public function test_update_setting_community_id_to_null_clears_organization_id(): void
    {
        $org = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $org->id]);

        $user->update(['community_id' => null]);

        $user->refresh();
        $this->assertNull($user->community_id);
        $this->assertNull($user->organization_id);
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    public function test_organization_id_is_not_overwritten_when_community_id_changes_on_create(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $user = User::factory()->make([
            'organization_id' => $orgA->id,
            'community_id' => $orgB->id,
        ]);

        $user->save();

        $this->assertEquals($orgA->id, $user->organization_id);
        $this->assertEquals($orgA->id, $user->community_id);
    }
}
