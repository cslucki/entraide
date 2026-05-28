<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembersPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_returns_setup_page_when_no_organization(): void
    {
        $response = $this->get('/membres');

        $response->assertOk();
        $response->assertViewIs('members.setup-required');
        $response->assertSee('Base de données à initialiser');
    }

    public function test_members_displays_directory_when_organization_exists(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        User::factory()->count(3)->create(['organization_id' => $org->id]);

        app()->instance('current_organization', $org);

        $response = $this->get('/membres');

        $response->assertOk();
        $response->assertViewIs('members.index');
        $response->assertSee('Annuaire des membres');
        $response->assertSee('3 membres');
    }

    public function test_members_shows_correct_member_count(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        User::factory()->count(5)->create(['organization_id' => $org->id]);

        app()->instance('current_organization', $org);

        $response = $this->get('/membres');

        $response->assertOk();
        $response->assertSee('5 membres');
    }

    public function test_other_business_routes_still_404_without_organization(): void
    {
        $response = $this->get('/echanges');
        $response->assertNotFound();
    }

    public function test_other_business_routes_still_404_without_organization_services(): void
    {
        $response = $this->get('/services');
        $response->assertNotFound();
    }
}
