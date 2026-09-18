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
        $response->assertSee(__('directory.setup_title'));
    }

    /*
     * TASK-1479 (P0 privacy) — ces pages ne sont plus servies a un visiteur
     * ANONYME.
     *
     * Mesure faite avant correctif : `/membres` et `/explorer` etaient rendus en
     * HTTP 200 sans aucun cookie, sur des Organizations `is_public = false`
     * comprises, avec noms reels, villes, biographies et affiliations. La forme
     * non prefixee exposait 42 personnes de l'Organization par defaut.
     *
     * Ce que ces tests protegent — le rendu de l'annuaire et sa page
     * de configuration — n'a pas change d'un mot. Seul
     * le VISITEUR change : ils agissent desormais comme un membre. La couverture
     * est identique ; la porte, elle, est fermee.
     */

    public function test_members_displays_directory_when_organization_exists(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $members = User::factory()->count(3)->create(['organization_id' => $org->id]);

        app()->instance('current_organization', $org);

        $response = $this->actingAs($members->first())->get('/membres');

        $response->assertOk();
        $response->assertViewIs('members.index');
        $response->assertSee(__('directory.title'));
        $response->assertSee(trans_choice('directory.member_count', 3, ['count' => 3]));
    }

    public function test_public_pages_show_setup_without_organization(): void
    {
        $routes = ['/membres', '/echanges', '/explorer', '/boucles', '/blog', '/search'];

        foreach ($routes as $route) {
            $response = $this->get($route);
            $response->assertOk();
            $response->assertSee(__('directory.setup_title'));
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
        $members = User::factory()->count(2)->create(['organization_id' => $org->id]);

        app()->instance('current_organization', $org);

        $response = $this->actingAs($members->first())->get('/membres');
        $response->assertOk();
        $response->assertSee(__('directory.title'));
    }
}
