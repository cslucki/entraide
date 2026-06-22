<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoopRouteBindingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $user;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'test-org']);
        $this->otherOrganization = Organization::factory()->create(['slug' => 'other-org']);

        $this->user = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->loop = Loop::factory()->public()->create([
            'organization_id' => $this->organization->id,
            'slug' => 'test-loop',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_non_org_uuid_route_resolves(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('loops.show', $this->loop));

        $response->assertOk();
    }

    public function test_org_route_resolves_by_uuid(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('organization.loops.show', [
                'organization' => $this->organization->slug,
                'loop' => $this->loop->id,
            ]));

        $response->assertOk();
    }

    public function test_org_route_resolves_by_slug(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('organization.loops.show', [
                'organization' => $this->organization->slug,
                'loop' => $this->loop->slug,
            ]));

        $response->assertOk();
    }

    public function test_org_route_returns_404_for_cross_tenant_slug(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('organization.loops.show', [
                'organization' => $this->otherOrganization->slug,
                'loop' => $this->loop->slug,
            ]));

        $response->assertNotFound();
    }

    public function test_org_route_returns_404_for_cross_tenant_uuid(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('organization.loops.show', [
                'organization' => $this->otherOrganization->slug,
                'loop' => $this->loop->id,
            ]));

        $response->assertNotFound();
    }

    public function test_non_org_route_returns_404_for_slug(): void
    {
        $response = $this->actingAs($this->user)
            ->get("/loops/{$this->loop->slug}");

        $response->assertNotFound();
    }

    public function test_org_route_returns_404_for_nonexistent_slug(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('organization.loops.show', [
                'organization' => $this->organization->slug,
                'loop' => 'nonexistent-slug',
            ]));

        $response->assertNotFound();
    }

    public function test_org_route_returns_404_for_nonexistent_uuid(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('organization.loops.show', [
                'organization' => $this->organization->slug,
                'loop' => '00000000-0000-0000-0000-000000000000',
            ]));

        $response->assertNotFound();
    }
}
