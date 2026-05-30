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

    public function test_public_pages_show_setup_without_organization(): void
    {
        $routes = ['/membres', '/echanges', '/explorer', '/boucles', '/blog', '/search'];

        foreach ($routes as $route) {
            $response = $this->get($route);
            $response->assertOk();
            $response->assertSee('Base de données à initialiser');
        }
    }

    public function test_unknown_routes_remain_404_without_organization(): void
    {
        $response = $this->get('/une-page-qui-nexiste-pas');
        $response->assertNotFound();
    }

    public function test_authenticated_routes_remain_405_without_organization(): void
    {
        $response = $this->get('/services');
        $response->assertStatus(405);
    }

    public function test_public_pages_show_content_when_organization_exists(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        User::factory()->count(2)->create(['organization_id' => $org->id]);

        app()->instance('current_organization', $org);

        $response = $this->get('/membres');
        $response->assertOk();
        $response->assertSee('Annuaire des membres');
    }
}
