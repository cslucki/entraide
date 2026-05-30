<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\LoopMember;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class T07411RoutesTenantSafetyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    private User $userWithoutCommunity;

    private User $admin;

    private LoopService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->community = Organization::factory()->create(['is_active' => true]);
        $this->user = User::factory()->create([
            'organization_id' => $this->community->id,
            'organization_id' => $this->community->id,
        ]);
        $this->userWithoutCommunity = User::factory()->create([
            'organization_id' => null,
            'organization_id' => null,
        ]);
        $this->admin = User::factory()->create([
            'is_admin' => true,
            'organization_id' => $this->community->id,
            'organization_id' => $this->community->id,
        ]);
        $this->service = new LoopService;
    }

    // ── /loops (global member route) ──────────────────────────────────────

    public function test_loops_index_returns_200_for_user_with_community(): void
    {
        $loop = $this->service->createLoop($this->user, 'My Loop');
        $response = $this->actingAs($this->user)->get(route('loops.index'));
        $response->assertOk();
        $response->assertSee('My Loop');
    }

    public function test_loops_index_returns_404_for_user_without_community(): void
    {
        $response = $this->actingAs($this->userWithoutCommunity)->get('/loops');
        $response->assertNotFound();
    }

    public function test_loops_index_redirects_guest_to_login(): void
    {
        $this->get('/loops')->assertRedirect(route('login'));
    }

    // ── /loops/create ─────────────────────────────────────────────────────

    public function test_loops_create_returns_200_for_user_with_community(): void
    {
        $response = $this->actingAs($this->user)->get(route('loops.create'));
        $response->assertOk();
    }

    public function test_loops_create_returns_404_for_user_without_community(): void
    {
        $response = $this->actingAs($this->userWithoutCommunity)->get('/loops/create');
        $response->assertNotFound();
    }

    public function test_loops_create_returns_200_for_admin_with_community(): void
    {
        $response = $this->actingAs($this->admin)->get(route('loops.create'));
        $response->assertOk();
    }

    public function test_loops_create_returns_404_for_admin_without_community(): void
    {
        $adminWithoutCommunity = User::factory()->create([
            'is_admin' => true,
            'organization_id' => null,
            'organization_id' => null,
        ]);
        $response = $this->actingAs($adminWithoutCommunity)->get('/loops/create');
        $response->assertNotFound();
    }

    public function test_loops_create_redirects_guest_to_login(): void
    {
        $this->get('/loops/create')->assertRedirect(route('login'));
    }

    public function test_loops_index_shows_create_cta_for_user_with_community(): void
    {
        $response = $this->actingAs($this->user)->get('/loops');
        $response->assertOk();
        $response->assertSee('Nouvelle');
        $response->assertSee('Créer votre première boucle');
    }

    public function test_loops_index_returns_404_for_user_without_community_no_cta(): void
    {
        $response = $this->actingAs($this->userWithoutCommunity)->get('/loops');
        $response->assertNotFound();
    }

    // ── /boucles (public legacy route) ────────────────────────────────────

    public function test_boucles_index_is_public(): void
    {
        $this->get(route('boucles.index'))->assertOk();
    }

    // ── /admin/loops ──────────────────────────────────────────────────────

    public function test_admin_loops_redirects_guest(): void
    {
        $this->get(route('admin.loops'))->assertRedirect(route('login'));
    }

    public function test_admin_loops_returns_403_for_non_admin(): void
    {
        $this->actingAs($this->user)->get(route('admin.loops'))->assertForbidden();
    }

    public function test_admin_loops_returns_200_for_admin(): void
    {
        $this->actingAs($this->admin)->get(route('admin.loops'))->assertOk();
    }

    // ── /admin/messages ───────────────────────────────────────────────────

    public function test_admin_messages_redirects_guest(): void
    {
        $this->get(route('admin.messages'))->assertRedirect(route('login'));
    }

    public function test_admin_messages_returns_403_for_non_admin(): void
    {
        $this->actingAs($this->user)->get(route('admin.messages'))->assertForbidden();
    }

    public function test_admin_messages_returns_200_for_admin(): void
    {
        $this->actingAs($this->admin)->get(route('admin.messages'))->assertOk();
    }

    // ── Tenant isolation (member) ─────────────────────────────────────────

    public function test_user_sees_only_own_community_loops_on_index(): void
    {
        $otherCommunity = Organization::factory()->create();
        $otherUser = User::factory()->create(['organization_id' => $otherCommunity->id]);
        $this->service->createLoop($otherUser, 'Other Community Loop');

        $this->service->createLoop($this->user, 'My Loop');

        $response = $this->actingAs($this->user)->get(route('loops.index'));
        $response->assertOk();
        $response->assertSee('My Loop');
        $response->assertDontSee('Other Community Loop');
    }

    // ── Blocker 1: No community → residual membership hidden ─────────────

    public function test_loops_index_returns_404_for_user_without_community_with_membership(): void
    {
        $loop = $this->service->createLoop($this->user, 'Residual Loop');
        LoopMember::factory()->create([
            'loop_id' => $loop->id,
            'user_id' => $this->userWithoutCommunity->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->userWithoutCommunity)->get('/loops');
        $response->assertNotFound();
    }

    // ── Blocker 2: Legacy /{community}/loops cross-tenant isolation ──────

    public function test_legacy_community_loops_denies_cross_tenant_access(): void
    {
        $otherCommunity = Organization::factory()->create(['is_active' => true]);
        $otherUser = User::factory()->create(['organization_id' => $otherCommunity->id]);
        $loop = $this->service->createLoop($otherUser, 'Other Tenant Loop');

        LoopMember::factory()->create([
            'loop_id' => $loop->id,
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user)
            ->get("/{$otherCommunity->slug}/loops");
        $response->assertNotFound();
    }

    // ── Named routes consistency ──────────────────────────────────────────

    public function test_loops_named_routes_exist(): void
    {
        $this->assertNotNull(route('loops.index'));
        $this->assertNotNull(route('loops.create'));
        $this->assertNotNull(route('boucles.index'));
        $this->assertNotNull(route('admin.loops'));
        $this->assertNotNull(route('admin.messages'));
    }
}
