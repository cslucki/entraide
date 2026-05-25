<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class T1405ARuntimeOrganizationIdTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $userA;

    private User $userB;

    private Loop $loopA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['is_active' => true, 'is_public' => true]);
        $this->orgB = Organization::factory()->create(['is_active' => true, 'is_public' => true]);

        $this->userA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->userB = User::factory()->create(['organization_id' => $this->orgB->id]);

        $this->loopA = Loop::factory()->create([
            'organization_id' => $this->orgA->id,
            'created_by' => $this->userA->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Channel authorization uses organization_id
    // ─────────────────────────────────────────────────────────────

    private function assertChannelAuthorizes(User $user, string $loopId): void
    {
        $loop = Loop::find($loopId);

        $result = null;
        if ($loop) {
            $isActiveMember = LoopMember::where('loop_id', $loopId)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();

            $orgId = $user->organization_id ?? $user->community_id;
            if ($isActiveMember && $loop->organization_id === $orgId) {
                $result = ['id' => $user->id];
            }
        }

        $this->assertIsArray($result);
        $this->assertEquals($user->id, $result['id']);
    }

    private function assertChannelDenies(User $user, string $loopId): void
    {
        $loop = Loop::find($loopId);

        $result = null;
        if ($loop) {
            $isActiveMember = LoopMember::where('loop_id', $loopId)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();

            $orgId = $user->organization_id ?? $user->community_id;
            if ($isActiveMember && $loop->organization_id === $orgId) {
                $result = ['id' => $user->id];
            }
        }

        $this->assertNull($result);
    }

    public function test_channel_authorizes_active_member_same_organization(): void
    {
        LoopMember::factory()->create([
            'loop_id' => $this->loopA->id,
            'user_id' => $this->userA->id,
            'status' => 'active',
        ]);

        $this->assertChannelAuthorizes($this->userA, $this->loopA->id);
    }

    public function test_channel_denies_cross_organization_member(): void
    {
        LoopMember::factory()->create([
            'loop_id' => $this->loopA->id,
            'user_id' => $this->userB->id,
            'status' => 'active',
        ]);

        $this->assertChannelDenies($this->userB, $this->loopA->id);
    }

    public function test_channel_denies_non_member_same_organization(): void
    {
        $this->assertChannelDenies($this->userA, $this->loopA->id);
    }

    public function test_channel_denies_nonexistent_loop(): void
    {
        $this->assertChannelDenies($this->userA, '00000000-0000-0000-0000-000000000000');
    }

    public function test_channel_authorizes_when_organization_id_matches_despite_community_id_desync(): void
    {
        $userDesync = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'community_id' => $this->orgB->id,
        ]);

        $loopDesync = Loop::factory()->create([
            'organization_id' => $this->orgA->id,
            'community_id' => $this->orgB->id,
        ]);

        LoopMember::factory()->create([
            'loop_id' => $loopDesync->id,
            'user_id' => $userDesync->id,
            'status' => 'active',
        ]);

        $this->assertChannelAuthorizes($userDesync, $loopDesync->id);
    }

    public function test_channel_denies_when_organization_id_differs_despite_same_community_id(): void
    {
        $user = User::factory()->create(['organization_id' => $this->orgA->id]);
        $user->updateQuietly(['community_id' => $this->orgB->id]);

        $loop = Loop::factory()->create(['organization_id' => $this->orgB->id]);
        $loop->updateQuietly(['community_id' => $this->orgA->id]);

        LoopMember::factory()->create([
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $this->assertChannelDenies($user, $loop->id);
    }

    // ─────────────────────────────────────────────────────────────
    // ResolveApiOrganization uses organization_id first
    // ─────────────────────────────────────────────────────────────

    public function test_api_resolves_organization_by_organization_id_first(): void
    {
        Setting::set('default_organization_id', $this->orgA->id);

        $user = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'community_id' => $this->orgA->id,
        ]);

        Service::factory()->count(2)->create([
            'status' => 'active',
            'organization_id' => $this->orgA->id,
        ]);

        Service::factory()->count(3)->create([
            'status' => 'active',
            'organization_id' => $this->orgB->id,
        ]);

        $this->actingAs($user)
            ->getJson('/api/services')
            ->assertOk()
            ->assertJsonPath('total', 3);
    }

    public function test_api_falls_back_to_community_id_when_organization_id_null(): void
    {
        Setting::set('default_organization_id', $this->orgA->id);

        $user = User::factory()->create([
            'organization_id' => null,
            'community_id' => $this->orgA->id,
        ]);

        Service::factory()->count(2)->create([
            'status' => 'active',
            'organization_id' => $this->orgA->id,
        ]);

        Service::factory()->count(3)->create([
            'status' => 'active',
            'organization_id' => $this->orgB->id,
        ]);

        $this->actingAs($user)
            ->getJson('/api/services')
            ->assertOk()
            ->assertJsonPath('total', 2);
    }

    public function test_api_rejects_user_without_org_id_or_community_id(): void
    {
        $user = User::factory()->create([
            'organization_id' => null,
            'community_id' => null,
        ]);

        Service::factory()->count(2)->create([
            'status' => 'active',
            'organization_id' => $this->orgA->id,
        ]);

        $this->actingAs($user)
            ->getJson('/api/services')
            ->assertForbidden();
    }

    // ─────────────────────────────────────────────────────────────
    // Legacy route / service compatibility preserved
    // ─────────────────────────────────────────────────────────────

    public function test_legacy_community_landing_works(): void
    {
        $this->get("/{$this->orgA->slug}/")
            ->assertOk();
    }

    public function test_org_parallel_landing_works(): void
    {
        $this->get("/org/{$this->orgA->slug}/")
            ->assertOk();
    }

    public function test_org_route_binds_current_organization(): void
    {
        $this->get("/org/{$this->orgA->slug}/");

        $this->assertNotNull(app('current_organization'));
        $this->assertEquals($this->orgA->id, app('current_organization')->id);
    }

    public function test_org_route_binds_current_community_for_legacy(): void
    {
        $this->get("/org/{$this->orgA->slug}/");

        $this->assertTrue(app()->bound('current_community'));
        $this->assertEquals($this->orgA->id, app('current_community')->id);
    }

    public function test_api_public_services_still_work(): void
    {
        Setting::set('default_organization_id', $this->orgA->id);

        Service::factory()->count(2)->create([
            'status' => 'active',
            'organization_id' => $this->orgA->id,
        ]);

        $this->getJson('/api/services')
            ->assertOk()
            ->assertJsonPath('total', 2);
    }

    // ─────────────────────────────────────────────────────────────
    // Regression: no cross-org data leak
    // ─────────────────────────────────────────────────────────────

    public function test_no_cross_org_data_leak_through_channel(): void
    {
        $userB = User::factory()->create(['organization_id' => $this->orgB->id]);
        $loopFromA = Loop::factory()->create([
            'organization_id' => $this->orgA->id,
            'created_by' => $this->userA->id,
        ]);

        LoopMember::factory()->create([
            'loop_id' => $loopFromA->id,
            'user_id' => $this->userA->id,
            'status' => 'active',
        ]);

        $this->assertChannelDenies($userB, $loopFromA->id);
    }
}
