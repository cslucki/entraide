<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveUrlOrganization;
use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

/**
 * T075.2 — Default Organization Resolution Audit & Fix
 *
 * Vérifie que les routes publiques métier résolvent correctement
 * l'Organization par défaut et restent Organization-scopées.
 * Public ≠ global. Admin reste global et non scopé.
 */
class T0752DefaultOrganizationResolutionTest extends TestCase
{
    protected function tearDown(): void
    {
        ResolveUrlOrganization::$defaultOrganizationId = null;

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // Membres — scoped to default Organization
    // ─────────────────────────────────────────────────────────────

    public function test_membres_returns_200_and_binds_org(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        ResolveUrlOrganization::$defaultOrganizationId = $org->id;

        $response = $this->get('/membres');

        $response->assertOk();
        $this->assertEquals($org->id, app('current_organization')->id);
    }

    public function test_membres_shows_only_scoped_users(): void
    {
        $orgA = Organization::factory()->create(['is_active' => true]);
        $orgB = Organization::factory()->create(['is_active' => true]);
        ResolveUrlOrganization::$defaultOrganizationId = $orgA->id;

        $userInA = User::factory()->create(['name' => 'User In Org A', 'organization_id' => $orgA->id]);
        $userInB = User::factory()->create(['name' => 'User In Org B', 'organization_id' => $orgB->id]);

        $this->get('/membres')
            ->assertOk()
            ->assertSeeText('User In Org A')
            ->assertDontSeeText('User In Org B');
    }

    // ─────────────────────────────────────────────────────────────
    // Explorer — resolves default Organization
    // ─────────────────────────────────────────────────────────────

    public function test_explorer_returns_200_and_binds_org(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        ResolveUrlOrganization::$defaultOrganizationId = $org->id;

        $response = $this->get('/explorer');

        $response->assertOk();
        $this->assertEquals($org->id, app('current_organization')->id);
    }

    // ─────────────────────────────────────────────────────────────
    // Blog — scoped to default Organization
    // ─────────────────────────────────────────────────────────────

    public function test_blog_index_returns_200_with_default_org(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        ResolveUrlOrganization::$defaultOrganizationId = $org->id;

        $this->get(route('blog.index'))
            ->assertOk();
    }

    public function test_blog_index_filters_by_resolved_org(): void
    {
        $orgA = Organization::factory()->create(['is_active' => true]);
        $orgB = Organization::factory()->create(['is_active' => true]);
        ResolveUrlOrganization::$defaultOrganizationId = $orgA->id;

        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);

        BlogPost::create([
            'user_id' => $userA->id,
            'organization_id' => $orgA->id,
            'title' => 'Visible Org A Post',
            'content' => str_repeat('Contenu de test pour vérifier le scoping. ', 3),
            'status' => 'published',
            'published_at' => now(),
        ]);

        BlogPost::create([
            'user_id' => $userB->id,
            'organization_id' => $orgB->id,
            'title' => 'Hidden Org B Post',
            'content' => str_repeat('Contenu interdit pour Org A. ', 3),
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->get(route('blog.index'))
            ->assertOk()
            ->assertSeeText('Visible Org A Post')
            ->assertDontSeeText('Hidden Org B Post');
    }

    // ─────────────────────────────────────────────────────────────
    // Admin dashboard — remains global (not Organization-scoped)
    // ─────────────────────────────────────────────────────────────

    public function test_admin_dashboard_does_not_bind_org(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'organization_id' => null]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk();

        $this->assertFalse(app()->bound('current_organization'));
    }

    // ─────────────────────────────────────────────────────────────
    // Cross-Organization isolation — no leak between requests
    // ─────────────────────────────────────────────────────────────

    public function test_membres_does_not_show_users_from_other_org_after_rebind(): void
    {
        $orgA = Organization::factory()->create(['is_active' => true]);
        $orgB = Organization::factory()->create(['is_active' => true]);

        $userInA = User::factory()->create(['name' => 'Only In A', 'organization_id' => $orgA->id]);
        $userInB = User::factory()->create(['name' => 'Only In B', 'organization_id' => $orgB->id]);

        ResolveUrlOrganization::$defaultOrganizationId = $orgA->id;
        $this->get('/membres')
            ->assertOk()
            ->assertSeeText('Only In A')
            ->assertDontSeeText('Only In B');

        app()->forgetInstance('current_organization');

        ResolveUrlOrganization::$defaultOrganizationId = $orgB->id;
        $this->get('/membres')
            ->assertOk()
            ->assertSeeText('Only In B')
            ->assertDontSeeText('Only In A');
    }
}
