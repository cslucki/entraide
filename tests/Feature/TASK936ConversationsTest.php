<?php

namespace Tests\Feature;

use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\ProfileAgentConversation;
use App\Models\ProfileAgentMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TASK936ConversationsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $profileOwner;

    private User $visitorUser;

    private User $otherUser;

    private MemberAiProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['ai_profiles_enabled' => true]);
        $this->profileOwner = User::factory()->create(['organization_id' => $this->org->id]);
        $this->visitorUser = User::factory()->create(['organization_id' => $this->org->id]);
        $this->otherUser = User::factory()->create(['organization_id' => $this->org->id]);

        $this->profile = MemberAiProfile::factory()
            ->published()
            ->create([
                'organization_id' => $this->org->id,
                'user_id' => $this->profileOwner->id,
            ]);
    }

    public function test_owner_can_list_conversations(): void
    {
        ProfileAgentConversation::factory()
            ->count(3)
            ->create([
                'organization_id' => $this->org->id,
                'member_ai_profile_id' => $this->profile->id,
                'profile_owner_user_id' => $this->profileOwner->id,
                'visitor_user_id' => $this->visitorUser->id,
            ]);

        $response = $this->actingAs($this->profileOwner)
            ->get(route('agent-ia.conversations'));

        $response->assertOk();
        $response->assertSeeText(__('ai.conversations_title'));
        $response->assertSeeText($this->visitorUser->name);
    }

    public function test_owner_can_view_conversation_detail(): void
    {
        $conversation = ProfileAgentConversation::factory()
            ->create([
                'organization_id' => $this->org->id,
                'member_ai_profile_id' => $this->profile->id,
                'profile_owner_user_id' => $this->profileOwner->id,
                'visitor_user_id' => $this->visitorUser->id,
            ]);

        ProfileAgentMessage::factory()->userMessage()->create([
            'conversation_id' => $conversation->id,
            'created_at' => now(),
        ]);
        ProfileAgentMessage::factory()->assistantMessage()->create([
            'conversation_id' => $conversation->id,
            'created_at' => now()->addSecond(),
        ]);

        $response = $this->actingAs($this->profileOwner)
            ->get(route('agent-ia.conversations.show', $conversation));

        $response->assertOk();
        $response->assertSeeText(__('ai.message_role_user'));
        $response->assertSeeText(__('ai.message_role_assistant'));
        $response->assertSeeText($this->visitorUser->name);
    }

    public function test_other_user_cannot_see_conversation(): void
    {
        $conversation = ProfileAgentConversation::factory()
            ->create([
                'organization_id' => $this->org->id,
                'member_ai_profile_id' => $this->profile->id,
                'profile_owner_user_id' => $this->profileOwner->id,
                'visitor_user_id' => $this->visitorUser->id,
            ]);

        $response = $this->actingAs($this->otherUser)
            ->get(route('agent-ia.conversations.show', $conversation));

        $response->assertForbidden();
    }

    public function test_anonymous_visitor_is_displayed_properly(): void
    {
        $conversation = ProfileAgentConversation::factory()
            ->anonymousVisitor()
            ->create([
                'organization_id' => $this->org->id,
                'member_ai_profile_id' => $this->profile->id,
                'profile_owner_user_id' => $this->profileOwner->id,
            ]);

        ProfileAgentMessage::factory()->userMessage()->create([
            'conversation_id' => $conversation->id,
        ]);
        ProfileAgentMessage::factory()->assistantMessage()->create([
            'conversation_id' => $conversation->id,
        ]);

        $response = $this->actingAs($this->profileOwner)
            ->get(route('agent-ia.conversations.show', $conversation));

        $response->assertOk();
        $response->assertSeeText(__('ai.visitor_anonymous'));
        $response->assertSeeText(__('ai.message_role_user'));
        $response->assertSeeText(__('ai.message_role_assistant'));
    }

    public function test_messages_displayed_in_order(): void
    {
        $conversation = ProfileAgentConversation::factory()
            ->create([
                'organization_id' => $this->org->id,
                'member_ai_profile_id' => $this->profile->id,
                'profile_owner_user_id' => $this->profileOwner->id,
                'visitor_user_id' => $this->visitorUser->id,
            ]);

        ProfileAgentMessage::factory()->userMessage()->create([
            'conversation_id' => $conversation->id,
            'content' => 'First question',
            'created_at' => now(),
        ]);
        ProfileAgentMessage::factory()->assistantMessage()->create([
            'conversation_id' => $conversation->id,
            'content' => 'First response',
            'created_at' => now()->addSecond(),
        ]);
        ProfileAgentMessage::factory()->userMessage()->create([
            'conversation_id' => $conversation->id,
            'content' => 'Second question',
            'created_at' => now()->addSeconds(2),
        ]);

        $response = $this->actingAs($this->profileOwner)
            ->get(route('agent-ia.conversations.show', $conversation));

        $response->assertOk();
        $response->assertSeeTextInOrder([
            'First question',
            'First response',
            'Second question',
        ]);
    }

    public function test_existing_interactions_page_still_works(): void
    {
        $response = $this->actingAs($this->profileOwner)
            ->get(route('agent-ia.interactions'));

        $response->assertOk();
        $response->assertSeeText(__('ai.interactions_title'));
    }

    public function test_guest_redirected_to_login(): void
    {
        $response = $this->get(route('agent-ia.conversations'));

        $response->assertRedirect(route('login'));
    }

    public function test_empty_state_shows_when_no_conversations(): void
    {
        $response = $this->actingAs($this->profileOwner)
            ->get(route('agent-ia.conversations'));

        $response->assertOk();
        $response->assertSeeText(__('ai.no_conversations_title'));
    }
}
