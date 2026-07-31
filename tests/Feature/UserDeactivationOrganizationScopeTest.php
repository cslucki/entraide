<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDeactivationOrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_admin_can_toggle_ban_for_own_org_user(): void
    {
        $organization = Organization::factory()->create([
            'slug' => 'cpme',
            'is_active' => true,
        ]);
        $orgAdmin = User::factory()->create([
            'organization_id' => $organization->id,
            'is_admin' => false,
        ]);
        $organization->update(['admin_id' => $orgAdmin->id]);
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'banned_at' => null,
        ]);

        $this->actingAs($orgAdmin)
            ->patch(route('organization.admin.users.toggle-ban', [$organization, $user]))
            ->assertRedirect();

        $this->assertNotNull($user->fresh()->banned_at);

        $this->actingAs($orgAdmin)
            ->patch(route('organization.admin.users.toggle-ban', [$organization, $user]))
            ->assertRedirect();

        $this->assertNull($user->fresh()->banned_at);
    }

    public function test_org_admin_cannot_toggle_ban_for_other_org_user(): void
    {
        $organization = Organization::factory()->create([
            'slug' => 'cpme',
            'is_active' => true,
        ]);
        $otherOrganization = Organization::factory()->create([
            'slug' => 'main',
            'is_active' => true,
        ]);
        $orgAdmin = User::factory()->create([
            'organization_id' => $organization->id,
            'is_admin' => false,
        ]);
        $organization->update(['admin_id' => $orgAdmin->id]);
        $user = User::factory()->create([
            'organization_id' => $otherOrganization->id,
            'banned_at' => null,
        ]);

        $this->actingAs($orgAdmin)
            ->patch(route('organization.admin.users.toggle-ban', [$organization, $user]))
            ->assertNotFound();

        $this->assertNull($user->fresh()->banned_at);
    }
}
