<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TASK358Lot7EnsureProfileCompleteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
    }

    private function minimalCompleteUser(): User
    {
        return User::factory()->create([
            'organization_id' => $this->org->id,
            'first_name' => 'Jean',
            'name' => 'Dupont',
            'phone' => '0123456789',
            'city' => 'Paris',
            'country_code' => 'FR',
            'bio' => 'Développeur web.',
        ]);
    }

    private function incompleteUser(array $missing = []): User
    {
        $defaults = [
            'organization_id' => $this->org->id,
            'first_name' => 'Jean',
            'name' => 'Dupont',
            'phone' => '0123456789',
            'city' => 'Paris',
            'country_code' => 'FR',
            'bio' => 'Développeur web.',
        ];

        foreach ($missing as $key) {
            $defaults[$key] = null;
        }

        return User::factory()->create($defaults);
    }

    public function test_complete_user_passes_middleware(): void
    {
        $user = $this->minimalCompleteUser();

        $this->actingAs($user)
            ->get(route('services.create'))
            ->assertStatus(200);
    }

    public function test_user_without_first_name_is_incomplete(): void
    {
        $user = $this->incompleteUser(['first_name']);

        $this->actingAs($user)
            ->get(route('services.create'))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('profile_required');
    }

    public function test_user_without_city_is_incomplete(): void
    {
        $user = $this->incompleteUser(['city']);

        $this->actingAs($user)
            ->get(route('services.create'))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('profile_required');
    }

    public function test_user_without_country_code_is_incomplete(): void
    {
        $user = $this->incompleteUser(['country_code']);

        $this->actingAs($user)
            ->get(route('services.create'))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('profile_required');
    }

    public function test_location_alone_no_longer_makes_profile_complete(): void
    {
        $user = User::factory()->create([
            'organization_id' => $this->org->id,
            'first_name' => null,
            'city' => null,
            'country_code' => null,
            'bio' => 'Bio remplie.',
            'location' => 'Paris',
            'phone' => '0123456789',
        ]);

        $this->actingAs($user)
            ->get(route('services.create'))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('profile_required');
    }

    public function test_incomplete_user_can_access_profile_edit(): void
    {
        $user = $this->incompleteUser(['first_name']);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk();
    }

    public function test_incomplete_user_can_update_profile(): void
    {
        $user = $this->incompleteUser(['first_name']);

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'first_name' => 'Nouveau',
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'city' => $user->city,
                'country_code' => $user->country_code,
                'bio' => $user->bio,
            ])
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect();

        $user->refresh();
        $this->assertSame('Nouveau', $user->first_name);
    }

    public function test_incomplete_user_can_logout(): void
    {
        $user = $this->incompleteUser(['first_name']);

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect('/');
    }

    public function test_unauthenticated_user_is_not_affected(): void
    {
        $this->get(route('login'))
            ->assertOk();
    }

    public function test_organization_scoping_is_unchanged(): void
    {
        $user = $this->minimalCompleteUser();

        $otherOrg = Organization::factory()->create(['is_active' => true, 'slug' => 'other-org']);
        $otherUser = User::factory()->create([
            'organization_id' => $otherOrg->id,
            'first_name' => 'Other',
            'name' => 'User',
            'phone' => '0987654321',
            'city' => 'Lyon',
            'country_code' => 'FR',
            'bio' => 'Autre profil.',
        ]);

        $this->actingAs($user)->get(route('services.create'))->assertStatus(200);
        $this->actingAs($otherUser)->get(route('services.create'))->assertStatus(200);
    }
}
