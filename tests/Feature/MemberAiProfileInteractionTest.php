<?php

namespace Tests\Feature;

use App\Livewire\InlineMemberAgent;
use App\Models\MemberAiProfile;
use App\Models\MemberAiProfileInteraction;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MemberAiProfileInteractionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private User $visitor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['ai_profiles_enabled' => true]);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        $this->visitor = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);
    }

    public function test_guest_redirected_from_interactions_page(): void
    {
        $this->get(route('agent-ia.interactions'))
            ->assertRedirect(route('login'));
    }

    public function test_owner_sees_empty_interactions_page(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)
            ->get(route('agent-ia.interactions'))
            ->assertOk()
            ->assertSee(__('ai.no_interactions_title'));
    }

    public function test_owner_without_profile_sees_prompt_to_create(): void
    {
        $this->actingAs($this->owner)
            ->get(route('agent-ia.interactions'))
            ->assertOk()
            ->assertSee(__('ai.no_profile_title'));
    }

    public function test_owner_cannot_see_other_owner_interactions(): void
    {
        $profile = MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
        ]);

        MemberAiProfileInteraction::factory()->count(3)->create([
            'organization_id' => $this->org->id,
            'member_ai_profile_id' => $profile->id,
            'profile_owner_user_id' => $this->owner->id,
        ]);

        $otherOwner = User::factory()->create(['organization_id' => $this->org->id]);

        $otherProfile = MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $otherOwner->id,
        ]);

        MemberAiProfileInteraction::factory()->count(2)->create([
            'organization_id' => $this->org->id,
            'member_ai_profile_id' => $otherProfile->id,
            'profile_owner_user_id' => $otherOwner->id,
        ]);

        $this->actingAs($this->owner)
            ->get(route('agent-ia.interactions'))
            ->assertOk()
            ->assertSee(__('ai.interactions_title'));

        $this->assertEquals(3, MemberAiProfileInteraction::where('profile_owner_user_id', $this->owner->id)->count());
    }

    public function test_inline_agent_logs_interaction(): void
    {
        $profile = MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'skills' => ['SEO', 'Rédaction'],
        ]);

        Livewire::actingAs($this->visitor)
            ->test(InlineMemberAgent::class, ['user' => $this->owner])
            ->set('question', 'Quelles compétences ?')
            ->call('askQuestion');

        $this->assertDatabaseHas('member_ai_profile_interactions', [
            'organization_id' => $this->org->id,
            'member_ai_profile_id' => $profile->id,
            'profile_owner_user_id' => $this->owner->id,
            'visitor_user_id' => $this->visitor->id,
            'visitor_type' => 'user',
            'provider' => 'rule_based',
        ]);
    }
}
