<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class LoopPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function org(array $overrides = []): Organization
    {
        return Organization::factory()->create(array_merge([
            'loops_enabled' => true,
            'members_can_create_loops' => true,
        ], $overrides));
    }

    public function test_active_member_is_authorized_when_both_flags_are_on(): void
    {
        $organization = $this->org();
        $member = User::factory()->create(['organization_id' => $organization->id]);

        $this->assertTrue(Gate::forUser($member)->allows('create', [Loop::class, $organization]));
    }

    public function test_active_member_is_refused_when_members_can_create_loops_is_off(): void
    {
        $organization = $this->org(['members_can_create_loops' => false]);
        $member = User::factory()->create(['organization_id' => $organization->id]);

        $this->assertFalse(Gate::forUser($member)->allows('create', [Loop::class, $organization]));
    }

    public function test_organization_admin_is_authorized_even_when_members_can_create_loops_is_off(): void
    {
        $orgAdmin = User::factory()->create();
        $organization = $this->org(['members_can_create_loops' => false, 'admin_id' => $orgAdmin->id]);
        $orgAdmin->update(['organization_id' => $organization->id]);

        $this->assertTrue(Gate::forUser($orgAdmin->fresh())->allows('create', [Loop::class, $organization]));
    }

    public function test_everyone_is_refused_when_loops_enabled_is_off(): void
    {
        $orgAdmin = User::factory()->create();
        $organization = $this->org(['loops_enabled' => false, 'members_can_create_loops' => true, 'admin_id' => $orgAdmin->id]);
        $orgAdmin->update(['organization_id' => $organization->id]);
        $member = User::factory()->create(['organization_id' => $organization->id]);

        $this->assertFalse(Gate::forUser($orgAdmin->fresh())->allows('create', [Loop::class, $organization]));
        $this->assertFalse(Gate::forUser($member)->allows('create', [Loop::class, $organization]));
    }

    public function test_user_from_another_organization_is_refused(): void
    {
        $organization = $this->org();
        $otherOrganization = $this->org();
        $outsider = User::factory()->create(['organization_id' => $otherOrganization->id]);

        $this->assertFalse(Gate::forUser($outsider)->allows('create', [Loop::class, $organization]));
    }

    public function test_deactivated_user_is_refused(): void
    {
        $organization = $this->org();
        $banned = User::factory()->create(['organization_id' => $organization->id, 'banned_at' => now()]);

        $this->assertFalse(Gate::forUser($banned)->allows('create', [Loop::class, $organization]));
    }

    public function test_user_without_organization_is_refused(): void
    {
        $organization = $this->org();
        $noOrgUser = User::factory()->create(['organization_id' => null]);

        $this->assertFalse(Gate::forUser($noOrgUser)->allows('create', [Loop::class, $organization]));
    }

    public function test_super_admin_is_not_implicitly_bypassed_by_the_policy(): void
    {
        $organization = $this->org(['members_can_create_loops' => false]);
        $superAdmin = User::factory()->create(['organization_id' => $organization->id, 'is_admin' => true]);

        // is_admin belongs to the organization as a plain member here (not admin_id) —
        // the policy must treat them like any other standard member, never bypassing
        // members_can_create_loops just because is_admin is true.
        $this->assertFalse(Gate::forUser($superAdmin)->allows('create', [Loop::class, $organization]));
    }
}
