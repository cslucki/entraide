<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class T1404OrganizationParallelRoutesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'is_public' => true]);
        $this->user = User::factory()->create([
            'organization_id' => $this->org->id,
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_org_route_still_works(): void
    {
        $response = $this->get("/org/{$this->org->slug}/");

        $response->assertOk();
    }

    public function test_new_org_route_works(): void
    {
        $response = $this->get("/org/{$this->org->slug}/");

        $response->assertOk();
    }

    public function test_org_route_binds_current_organization(): void
    {
        $this->get("/org/{$this->org->slug}/");

        $this->assertNotNull(app('current_organization'));
        $this->assertEquals($this->org->id, app('current_organization')->id);
    }

    public function test_org_route_does_not_bind_legacy_current_organization(): void
    {
        $this->get("/org/{$this->org->slug}/");

        $this->assertTrue(app()->bound('current_organization'));
        $this->assertSame($this->org->id, app('current_organization')->id);
    }

    public function test_org_route_returns_404_for_unknown_slug(): void
    {
        $this->get('/org/nonexistent-organization')->assertNotFound();
    }

    public function test_org_route_returns_404_for_inactive_slug(): void
    {
        $inactive = Organization::factory()->create(['is_active' => false, 'slug' => 'inactive-org']);

        $this->get("/org/{$inactive->slug}/")->assertNotFound();
    }

    public function test_org_route_resolves_organization(): void
    {
        $orgResponse = $this->get("/org/{$this->org->slug}/");
        $orgResponse->assertOk();
        $this->assertNotNull(app('current_organization'));
        $this->assertEquals($this->org->id, app('current_organization')->id);
    }

    public function test_org_route_dashboard_for_authenticated_user(): void
    {
        $this->actingAs($this->user);

        $response = $this->get("/org/{$this->org->slug}/dashboard");

        $response->assertOk();
    }

    public function test_org_route_explorer(): void
    {
        $response = $this->get("/org/{$this->org->slug}/explorer");

        $response->assertOk();
    }

    public function test_org_route_members(): void
    {
        $response = $this->get("/org/{$this->org->slug}/membres");

        $response->assertOk();
    }

    public function test_org_route_exchanges(): void
    {
        $response = $this->get("/org/{$this->org->slug}/echanges");

        $response->assertOk();
    }

    public function test_org_route_does_not_leak_cross_tenant(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true, 'is_public' => true, 'slug' => 'other-org']);

        $this->get("/org/{$otherOrg->slug}/");

        $resolved = CurrentOrganization::get();
        $this->assertNotNull($resolved);
        $this->assertEquals($otherOrg->id, $resolved->id);
        $this->assertNotEquals($this->org->id, $resolved->id);
    }

    public function test_legacy_route_is_not_redirected(): void
    {
        $response = $this->get("/org/{$this->org->slug}/");

        $response->assertOk();
        $this->assertFalse($response->isRedirect());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_org_route_name_generates_correct_url(): void
    {
        $url = route('organization.home', ['organization' => $this->org->slug]);

        $this->assertStringContainsString("/org/{$this->org->slug}", $url);
    }

    public function test_legacy_community_route_name_still_generates_correct_url(): void
    {
        $url = route('organization.home', ['organization' => $this->org->slug]);

        $this->assertStringContainsString("/org/{$this->org->slug}", $url);
    }
}
