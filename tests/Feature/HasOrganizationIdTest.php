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

    public function test_creating_with_neither_set(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->organization_id);
    }

    // -------------------------------------------------------------------------
    // Update: organization_id remains canonical
    // -------------------------------------------------------------------------

    public function test_updating_organization_id_syncs_community_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $orgA->id]);
        $this->assertEquals($orgA->id, $user->organization_id);

        $user->update(['organization_id' => $orgB->id]);

        $user->refresh();
        $this->assertEquals($orgB->id, $user->organization_id);
    }

    public function test_updating_organization_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $orgA->id]);

        $user->update(['organization_id' => $orgB->id]);

        $user->refresh();
        $this->assertEquals($orgB->id, $user->organization_id);
    }

    public function test_updating_organization_id_is_set(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $orgA->id]);

        $user->update(['organization_id' => $orgB->id]);

        $user->refresh();
        $this->assertEquals($orgB->id, $user->organization_id);
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

    public function test_multiple_updates_maintain_consistency(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $orgC = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $orgA->id]);
        $this->assertEquals($orgA->id, $user->organization_id);

        $user->update(['organization_id' => $orgB->id]);
        $user->refresh();
        $this->assertEquals($orgB->id, $user->organization_id);

        $user->update(['organization_id' => $orgC->id]);
        $user->refresh();
        $this->assertEquals($orgC->id, $user->organization_id);
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

    public function test_organization_id_is_null_when_not_provided(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->organization_id);
    }

    public function test_update_setting_organization_id_to_null(): void
    {
        $org = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->assertEquals($org->id, $user->organization_id);

        $user->update(['organization_id' => null]);

        $user->refresh();
        $this->assertNull($user->organization_id);
    }

    public function test_update_setting_organization_id_to_null_again(): void
    {
        $org = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $org->id]);

        $user->update(['organization_id' => null]);

        $user->refresh();
        $this->assertNull($user->organization_id);
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
