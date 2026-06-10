<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class RegisterOrganizationAssignmentTest extends TestCase
{
    public function test_global_registration_assigns_the_default_active_organization(): void
    {
        $organization = Organization::factory()->create([
            'slug' => 'main',
            'is_active' => true,
        ]);

        $this->post(route('register'), [
            'name' => 'Alice Dupont',
            'email' => 'alice@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('loops.index'));

        $this->assertDatabaseHas('users', [
            'email' => 'alice@example.com',
            'organization_id' => $organization->id,
        ]);

        $this->assertAuthenticated();
        $this->assertInstanceOf(User::class, auth()->user());
    }
}
