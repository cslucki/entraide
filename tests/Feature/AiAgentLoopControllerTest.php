<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAgentLoopControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $member;

    private User $visitor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->member = User::factory()->create(['organization_id' => $this->org->id]);
        $this->visitor = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);
    }

    public function test_guest_redirected_to_login(): void
    {
        $response = $this->post(route('agent-ia.conversation.start', $this->member));

        $response->assertRedirect(route('login'));
    }

    public function test_creates_new_loop_conversation(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
        ]);

        $this->actingAs($this->visitor)
            ->post(route('agent-ia.conversation.start', $this->member));

        $profile = MemberAiProfile::first();

        $this->assertDatabaseHas('loops', [
            'type' => 'ai_agent',
            'visibility' => 'private',
            'member_ai_profile_id' => $profile->id,
        ]);

        $loop = Loop::first();

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $loop->id,
            'user_id' => $this->visitor->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $loop->id,
            'user_id' => $this->member->id,
            'status' => 'active',
        ]);
    }

    public function test_reuses_existing_loop(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
        ]);

        $this->actingAs($this->visitor)
            ->post(route('agent-ia.conversation.start', $this->member));

        $this->actingAs($this->visitor)
            ->post(route('agent-ia.conversation.start', $this->member));

        $this->assertEquals(1, Loop::count());
    }

    public function test_owner_cannot_start_conversation(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
        ]);

        $this->actingAs($this->member)
            ->post(route('agent-ia.conversation.start', $this->member))
            ->assertForbidden();
    }

    public function test_redirects_to_loops_show(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
        ]);

        $response = $this->actingAs($this->visitor)
            ->post(route('agent-ia.conversation.start', $this->member));

        $loop = Loop::first();

        $response->assertRedirect(route('loops.show', $loop));
    }

    public function test_no_profile_returns_404(): void
    {
        $this->actingAs($this->visitor)
            ->post(route('agent-ia.conversation.start', $this->member))
            ->assertNotFound();
    }
}
