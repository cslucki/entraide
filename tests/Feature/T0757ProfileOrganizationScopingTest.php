<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

/**
 * T075.7 — Profile Organization Scoping + Containment
 *
 * Vérifie que le profil public est strictement scopé à l'Organization résolue :
 * un utilisateur d'une Organisation A ne peut pas voir le profil d'un utilisateur
 * de l'Organisation B.
 */
class T0757ProfileOrganizationScopingTest extends TestCase
{
    public function test_profile_show_returns_user_in_resolved_organization(): void
    {
        $org = Organization::factory()->create();
        app()->instance('current_organization', $org);

        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->get(route('profile.show', $user))
            ->assertOk();
    }

    public function test_profile_show_blocks_cross_organization(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        app()->instance('current_organization', $orgA);

        $userInB = User::factory()->create(['organization_id' => $orgB->id]);

        $this->get(route('profile.show', $userInB))
            ->assertNotFound();
    }

    public function test_profile_show_fails_without_organization(): void
    {
        app()->forgetInstance('current_organization');
        app()->forgetInstance('current_community');

        $user = User::factory()->create(['organization_id' => null]);

        $this->get(route('profile.show', $user))
            ->assertNotFound();
    }
}
