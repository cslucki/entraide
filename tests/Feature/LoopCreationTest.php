<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoopCreationTest extends TestCase
{
    use RefreshDatabase;

    private Community $community;
    private User $user;
    private LoopService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->community = Community::factory()->create();
        $this->user = User::factory()->create(['community_id' => $this->community->id]);
        $this->service = new LoopService;
    }

    public function test_service_creates_loop_in_users_community(): void
    {
        $loop = $this->service->createLoop($this->user, 'My Test Loop', 'A description');

        $this->assertInstanceOf(Loop::class, $loop);
        $this->assertEquals('My Test Loop', $loop->name);
        $this->assertEquals('A description', $loop->description);
        $this->assertEquals($this->community->id, $loop->community_id);
        $this->assertEquals($this->user->id, $loop->created_by);
        $this->assertEquals('custom', $loop->type);
        $this->assertEquals('active', $loop->status);
    }

    public function test_service_auto_generates_unique_slug_from_name(): void
    {
        $loop = $this->service->createLoop($this->user, 'My Amazing Loop');

        $this->assertEquals('my-amazing-loop', $loop->slug);
    }

    public function test_service_auto_adds_creator_as_owner_member(): void
    {
        $loop = $this->service->createLoop($this->user, 'Loop');

        $member = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $this->user->id)
            ->first();

        $this->assertNotNull($member);
        $this->assertEquals('owner', $member->role);
        $this->assertEquals('active', $member->status);
        $this->assertNotNull($member->joined_at);
    }

    public function test_service_throws_if_user_has_no_community(): void
    {
        $userWithoutCommunity = User::factory()->create(['community_id' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User has no community.');

        $this->service->createLoop($userWithoutCommunity, 'Loop');
    }

    public function test_authenticated_user_can_create_loop_via_web_route(): void
    {
        $response = $this->actingAs($this->user)->post(route('loops.store'), [
            'name' => 'Web Created Loop',
            'description' => 'Created via web',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $loop = Loop::where('slug', 'web-created-loop')->first();
        $this->assertNotNull($loop);
        $this->assertEquals($this->community->id, $loop->community_id);

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $loop->id,
            'user_id' => $this->user->id,
            'role' => 'owner',
        ]);
    }

    public function test_authenticated_user_can_view_their_loops(): void
    {
        $loop1 = $this->service->createLoop($this->user, 'Loop A');
        $loop2 = $this->service->createLoop($this->user, 'Loop B');

        $otherCommunity = Community::factory()->create();
        $otherUser = User::factory()->create(['community_id' => $otherCommunity->id]);
        $this->service->createLoop($otherUser, 'Other Loop');

        $response = $this->actingAs($this->user)->get(route('loops.index'));

        $response->assertStatus(200);
        $response->assertSee('Loop A');
        $response->assertSee('Loop B');
        $response->assertDontSee('Other Loop');
    }

    public function test_create_form_is_accessible(): void
    {
        $response = $this->actingAs($this->user)->get(route('loops.create'));

        $response->assertStatus(200);
    }

    public function test_guest_cannot_create_loop(): void
    {
        $response = $this->post(route('loops.store'), [
            'name' => 'Guest Loop',
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_create_requires_name(): void
    {
        $response = $this->actingAs($this->user)->post(route('loops.store'), [
            'name' => '',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_slug_is_unique_per_community_in_service(): void
    {
        $this->service->createLoop($this->user, 'Same Name');

        $loop2 = $this->service->createLoop($this->user, 'Same Name');

        $this->assertNotEquals($loop2->slug, 'same-name');
        $this->assertEquals('same-name-1', $loop2->slug);
    }
}
