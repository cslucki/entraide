<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WebLoginOrganizationTest extends TestCase
{
    public function test_non_admin_without_organization_is_redirected_to_home_on_login(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'organization_id' => null,
            'password' => Hash::make('password'),
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/dashboard');
    }

    public function test_non_admin_with_organization_lands_on_chatloop_on_login(): void
    {
        $organization = Organization::factory()->create(['is_active' => true, 'slug' => 'test-org']);
        $user = User::factory()->create([
            'is_admin' => false,
            'organization_id' => $organization->id,
            'password' => Hash::make('password'),
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/org/'.$organization->slug.'/loops');
    }

    public function test_non_admin_with_inactive_organization_is_redirected_to_home_on_login(): void
    {
        $organization = Organization::factory()->create(['is_active' => false, 'slug' => 'inactive-org']);
        $user = User::factory()->create([
            'is_admin' => false,
            'organization_id' => $organization->id,
            'password' => Hash::make('password'),
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/dashboard');
    }
}
