<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class RegisterOrganizationAssignmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Organization::where('is_default', true)->update(['is_default' => false]);
    }

    public function test_global_registration_assigns_the_default_active_organization(): void
    {

        $organization = Organization::factory()->create([
            'slug' => 'main',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->post(route('register'), [
            'name' => 'Dupont',
            'first_name' => 'Alice',
            'email' => 'alice@example.com',
            'phone' => '+33612345678',
            'country_code' => 'FR',
            'password' => 'password',
            'password_confirmation' => 'password',
        // TASK-1880 (demande Cyril, 22/11) — un membre arrive sur le Flux.
        ])->assertRedirect(route('flux'));

        $this->assertDatabaseHas('users', [
            'email' => 'alice@example.com',
            'organization_id' => $organization->id,
        ]);

        $this->assertAuthenticated();
        $this->assertInstanceOf(User::class, auth()->user());
    }
}
