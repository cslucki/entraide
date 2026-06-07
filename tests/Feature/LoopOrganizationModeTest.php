<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoopOrganizationModeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $user;

    private User $otherUser;

    private User $crossUser;

    private LoopService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        $this->user = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->otherUser = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->crossUser = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        $this->service = new LoopService;
    }

    // -------------------------------------------------------------------------
    // Mono-loop mode: redirects to primary loop
    // -------------------------------------------------------------------------

    public function test_mono_loop_mode_redirects_to_primary_loop(): void
    {
        $loop = $this->service->createLoop($this->user, 'Primary Loop');
        $this->organization->update([
            'loop_mode' => 'mono',
            'primary_loop_id' => $loop->id,
        ]);

        app()->instance('current_organization', $this->organization);

        $response = $this->actingAs($this->otherUser)
            ->get(route('loops.index'));

        $response->assertRedirect(route('loops.show', $loop));
    }

    // -------------------------------------------------------------------------
    // Mono-loop mode without primary loop: shows warning
    // -------------------------------------------------------------------------

    public function test_mono_loop_mode_without_primary_loop_shows_warning(): void
    {
        $loop = $this->service->createLoop($this->user, 'Only Loop');
        $this->organization->update([
            'loop_mode' => 'mono',
            'primary_loop_id' => null,
        ]);

        app()->instance('current_organization', $this->organization);

        $response = $this->actingAs($this->otherUser)
            ->get(route('loops.index'));

        $response->assertStatus(200);
        $response->assertSee('Boucle par défaut');
    }

    // -------------------------------------------------------------------------
    // Multi-loop mode: shows list (default)
    // -------------------------------------------------------------------------

    public function test_multi_loop_mode_shows_list(): void
    {
        $loop1 = $this->service->createLoop($this->user, 'Loop A');
        $loop2 = $this->service->createLoop($this->user, 'Loop B');
        $this->organization->update(['loop_mode' => 'multi']);

        app()->instance('current_organization', $this->organization);

        $response = $this->actingAs($this->user)
            ->get(route('loops.index'));

        $response->assertStatus(200);
        $response->assertSee('Loop A');
        $response->assertSee('Loop B');
    }

    // -------------------------------------------------------------------------
    // Primary loop accessible by same-org member (no join required)
    // -------------------------------------------------------------------------

    public function test_primary_loop_accessible_by_organization_member_without_membership(): void
    {
        $loop = $this->service->createLoop($this->user, 'Primary Private Loop');
        $loop->update(['visibility' => 'private']);
        $this->organization->update([
            'loop_mode' => 'mono',
            'primary_loop_id' => $loop->id,
        ]);

        app()->instance('current_organization', $this->organization);

        $response = $this->actingAs($this->otherUser)
            ->get(route('loops.show', $loop));

        $response->assertStatus(200);
        $response->assertSee('Primary Private Loop');
    }

    // -------------------------------------------------------------------------
    // Cross-Organization: primary loop blocked for other org member
    // -------------------------------------------------------------------------

    public function test_primary_loop_blocked_for_cross_organization_user(): void
    {
        $loop = $this->service->createLoop($this->user, 'Private Primary');
        $loop->update(['visibility' => 'private']);
        $this->organization->update([
            'loop_mode' => 'mono',
            'primary_loop_id' => $loop->id,
        ]);

        app()->instance('current_organization', $this->otherOrganization);

        $response = $this->actingAs($this->crossUser)
            ->get(route('loops.show', $loop));

        $response->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // Admin can set loop_mode and primary_loop_id
    // -------------------------------------------------------------------------

    public function test_admin_can_set_loop_mode_and_primary_loop(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $loop = $this->service->createLoop($this->user, 'Designated Primary');

        $this->actingAs($admin);

        $response = $this->put(route('admin.organizations.update', $this->organization), [
            'name' => $this->organization->name,
            'slug' => $this->organization->slug,
            'welcome_points' => 100,
            'loops_enabled' => '1',
            'loop_mode' => 'mono',
            'primary_loop_id' => $loop->id,
            'platform_name' => $this->organization->platform_name ?? 'Test',
            'global_color_mode' => 'light',
            'blog_naming' => 'b2b',
            'transactions_naming' => 'b2c',
        ]);

        $response->assertRedirect(route('admin.organizations'));

        $this->organization->refresh();

        $this->assertEquals('mono', $this->organization->loop_mode);
        $this->assertEquals($loop->id, $this->organization->primary_loop_id);
    }

    public function test_admin_cannot_set_primary_loop_from_another_organization(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $foreignLoop = $this->service->createLoop($this->crossUser, 'Foreign Primary');

        $response = $this->actingAs($admin)->from(route('admin.organizations.edit', $this->organization))
            ->put(route('admin.organizations.update', $this->organization), [
                'name' => $this->organization->name,
                'slug' => $this->organization->slug,
                'welcome_points' => 100,
                'loops_enabled' => '1',
                'loop_mode' => 'mono',
                'primary_loop_id' => $foreignLoop->id,
                'platform_name' => $this->organization->platform_name ?? 'Test',
                'global_color_mode' => 'light',
                'blog_naming' => 'b2b',
                'transactions_naming' => 'b2c',
            ]);

        $response->assertRedirect(route('admin.organizations.edit', $this->organization));
        $response->assertSessionHasErrors('primary_loop_id');

        $this->organization->refresh();
        $this->assertNull($this->organization->primary_loop_id);
    }

    // -------------------------------------------------------------------------
    // Default loop_mode is 'multi'
    // -------------------------------------------------------------------------

    public function test_default_loop_mode_is_multi(): void
    {
        $org = Organization::factory()->create();

        $this->assertEquals('multi', $org->loop_mode);
    }

    // -------------------------------------------------------------------------
    // Organization model helpers
    // -------------------------------------------------------------------------

    public function test_organization_model_helpers(): void
    {
        $org = Organization::factory()->create(['loop_mode' => 'mono']);

        $this->assertTrue($org->isMonoLoop());
        $this->assertFalse($org->isMultiLoop());

        $org->update(['loop_mode' => 'multi']);

        $this->assertFalse($org->isMonoLoop());
        $this->assertTrue($org->isMultiLoop());
    }

    // -------------------------------------------------------------------------
    // Primary loop redirects owner too in mono mode
    // -------------------------------------------------------------------------

    public function test_mono_loop_redirects_loop_owner_to_primary_loop(): void
    {
        $loop = $this->service->createLoop($this->user, 'Primary');
        $this->organization->update([
            'loop_mode' => 'mono',
            'primary_loop_id' => $loop->id,
        ]);

        app()->instance('current_organization', $this->organization);

        $response = $this->actingAs($this->user)
            ->get(route('loops.index'));

        $response->assertRedirect(route('loops.show', $loop));
    }
}
