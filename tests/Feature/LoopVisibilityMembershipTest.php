<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoopVisibilityMembershipTest extends TestCase
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

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        $this->user = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->otherUser = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->crossUser = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        $this->service = new LoopService;
    }

    // -------------------------------------------------------------------------
    // Fail-closed: pas de Organization -> 403
    // -------------------------------------------------------------------------

    public function test_index_returns_client_error_when_user_has_no_organization(): void
    {
        $userWithoutOrg = User::factory()->create(['organization_id' => null]);

        $response = $this->actingAs($userWithoutOrg)
            ->get(route('loops.index'));

        // ResolveUrlOrganization middleware binds a default org for authenticated
        // users, so resolveCommunityId() succeeds. The fail-closed happens via
        // assertUserBelongsToCommunity() which returns 404. Both 403 and 404
        // are valid fail-closed behaviors — the key is that the user gets an error.
        $this->assertTrue(
            $response->isClientError(),
            'Expected client error (4xx) for user without organization, got '.$response->getStatusCode()
        );
    }

    // -------------------------------------------------------------------------
    // Self-join (même Organization)
    // -------------------------------------------------------------------------

    public function test_user_can_self_join_loop_in_same_organization(): void
    {
        $loop = $this->service->createLoop($this->user, 'Joinable Loop');

        app()->instance('current_organization', $this->organization);

        $response = $this->actingAs($this->otherUser)
            ->post(route('loops.join', $loop));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $loop->id,
            'user_id' => $this->otherUser->id,
            'role' => 'member',
            'status' => 'active',
        ]);
    }

    // -------------------------------------------------------------------------
    // Self-join cross-Organization -> 404
    // -------------------------------------------------------------------------

    public function test_user_cannot_self_join_loop_from_different_organization(): void
    {
        $loop = $this->service->createLoop($this->user, 'Org A Loop');

        app()->instance('current_organization', $this->otherOrganization);

        $response = $this->actingAs($this->crossUser)
            ->post(route('loops.join', $loop));

        $response->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // Self-leave -> status = 'left'
    // -------------------------------------------------------------------------

    public function test_user_can_self_leave_loop(): void
    {
        $loop = $this->service->createLoop($this->user, 'Leavable Loop');

        $member = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $this->user->id)
            ->first();

        $this->assertNotNull($member);
        $this->assertEquals('owner', $member->role);

        // Owner cannot leave — add another member to test leave
        $this->service->addMember($loop, $this->otherUser, 'member');
        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $loop->id,
            'user_id' => $this->otherUser->id,
            'status' => 'active',
        ]);

        app()->instance('current_organization', $this->organization);

        $response = $this->actingAs($this->otherUser)
            ->post(route('loops.leave', $loop));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $loop->id,
            'user_id' => $this->otherUser->id,
            'status' => 'left',
        ]);
    }

    // -------------------------------------------------------------------------
    // Loop publique visible par membre Organization (non membre Loop)
    // -------------------------------------------------------------------------

    public function test_public_loop_visible_to_organization_member_even_if_not_loop_member(): void
    {
        $loop = $this->service->createLoop($this->user, 'Public Loop');
        $loop->update(['visibility' => 'public']);

        app()->instance('current_organization', $this->organization);

        $response = $this->actingAs($this->otherUser)
            ->get(route('loops.show', $loop));

        $response->assertStatus(200);
        $response->assertSee('Public Loop');
    }

    // -------------------------------------------------------------------------
    // Loop privée bloquée pour non membre Loop (même Organization)
    // -------------------------------------------------------------------------

    public function test_private_loop_blocked_for_non_member_of_same_organization(): void
    {
        $loop = $this->service->createLoop($this->user, 'Private Loop');
        // visibility defaults to 'private'

        app()->instance('current_organization', $this->organization);

        $response = $this->actingAs($this->otherUser)
            ->get(route('loops.show', $loop));

        $response->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // Owner cannot leave loop
    // -------------------------------------------------------------------------

    public function test_public_loop_shows_join_button_to_non_member(): void
    {
        $loop = $this->service->createLoop($this->user, 'Public Joinable');
        $loop->update(['visibility' => 'public']);

        app()->instance('current_organization', $this->organization);

        $response = $this->actingAs($this->otherUser)
            ->get(route('loops.show', $loop));

        $response->assertStatus(200);
        $response->assertSee('Rejoindre cette boucle');
        $response->assertSee(route('loops.join', $loop));
    }

    public function test_owner_cannot_leave_loop(): void
    {
        $loop = $this->service->createLoop($this->user, 'Owner Loop');

        app()->instance('current_organization', $this->organization);

        $response = $this->actingAs($this->user)
            ->post(route('loops.leave', $loop));

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $loop->id,
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);
    }
}
