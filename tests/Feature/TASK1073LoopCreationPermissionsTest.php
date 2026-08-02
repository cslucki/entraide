<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TASK1073LoopCreationPermissionsTest extends TestCase
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

    public function test_create_button_is_visible_when_member_is_authorized(): void
    {
        $organization = $this->org();
        $member = User::factory()->create(['organization_id' => $organization->id]);
        Loop::factory()->count(2)->create(['organization_id' => $organization->id]);
        $this->withCurrentOrganization($organization);

        $this->actingAs($member)
            ->get(route('loops.index'))
            ->assertOk()
            ->assertViewHas('canCreate', true);
    }

    public function test_create_button_is_hidden_when_members_can_create_loops_is_off(): void
    {
        $organization = $this->org(['members_can_create_loops' => false]);
        $member = User::factory()->create(['organization_id' => $organization->id]);
        Loop::factory()->count(2)->create(['organization_id' => $organization->id]);
        $this->withCurrentOrganization($organization);

        $this->actingAs($member)
            ->get(route('loops.index'))
            ->assertOk()
            ->assertViewHas('canCreate', false);
    }

    public function test_direct_get_to_create_form_is_refused_when_policy_denies(): void
    {
        $organization = $this->org(['members_can_create_loops' => false]);
        $member = User::factory()->create(['organization_id' => $organization->id]);
        $this->withCurrentOrganization($organization);

        $this->actingAs($member)
            ->get(route('loops.create'))
            ->assertForbidden();
    }

    public function test_direct_post_to_store_is_refused_when_policy_denies(): void
    {
        $organization = $this->org(['members_can_create_loops' => false]);
        $member = User::factory()->create(['organization_id' => $organization->id]);
        $this->withCurrentOrganization($organization);

        $this->actingAs($member)
            ->post(route('loops.store'), ['name' => 'Contournement UI'])
            ->assertForbidden();

        $this->assertDatabaseMissing('loops', ['name' => 'Contournement UI']);
    }

    public function test_organization_admin_can_create_even_when_members_can_create_loops_is_off(): void
    {
        $orgAdmin = User::factory()->create();
        $organization = $this->org(['members_can_create_loops' => false, 'admin_id' => $orgAdmin->id]);
        $orgAdmin->update(['organization_id' => $organization->id]);
        $this->withCurrentOrganization($organization);

        $this->actingAs($orgAdmin->fresh())
            ->post(route('loops.store'), ['name' => 'Boucle admin org'])
            ->assertRedirect();

        $this->assertDatabaseHas('loops', ['name' => 'Boucle admin org', 'organization_id' => $organization->id]);
    }

    public function test_loops_store_is_throttled(): void
    {
        $organization = $this->org();
        $member = User::factory()->create(['organization_id' => $organization->id]);
        $this->withCurrentOrganization($organization);

        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($member)
                ->post(route('loops.store'), ['name' => "Boucle $i"])
                ->assertRedirect();
        }

        $this->actingAs($member)
            ->post(route('loops.store'), ['name' => 'Boucle 6'])
            ->assertStatus(429);
    }

    public function test_admin_loop_controller_store_still_works_unaffected(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true]);
        $organization = $this->org();
        $owner = User::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($superAdmin)
            ->post(route('admin.loops.store'), [
                'name' => 'Boucle super-admin',
                'visibility' => 'private',
                'owner_id' => $owner->id,
                'organization_id' => $organization->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('loops', ['name' => 'Boucle super-admin', 'organization_id' => $organization->id]);
        $this->assertDatabaseHas('loop_members', [
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
    }
}
