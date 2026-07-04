<?php

namespace Tests\Feature;

use App\Livewire\AiAgentChat;
use App\Models\AdminAiInteraction;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\ProfileAgentConversation;
use App\Models\ProfileAgentMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TASK938VisitorChatTest extends TestCase
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

    public function test_visitor_can_access_chat_page(): void
    {
        $response = $this->actingAs($this->visitorUser)
            ->get(route('agent-ia.profile.chat', $this->profileOwner));

        $response->assertOk();
        $response->assertSeeText(__('ai.ai_agent_of', ['name' => $this->profileOwner->name]));
    }

    public function test_visitor_can_access_org_scoped_chat_page(): void
    {
        $response = $this->actingAs($this->visitorUser)
            ->get(route('organization.agent-ia.profile.chat', [
                'organization' => $this->org->slug,
                'user' => $this->profileOwner,
            ]));

        $response->assertOk();
        $response->assertSeeText(__('ai.ai_agent_of', ['name' => $this->profileOwner->name]));
    }

    public function test_visitor_chat_creates_conversation(): void
    {
        $component = Livewire::actingAs($this->visitorUser)
            ->test(AiAgentChat::class, ['user' => $this->profileOwner])
            ->assertSet('visitorTurnCount', 0)
            ->assertSet('maxTurnsReached', false);

        $conversation = ProfileAgentConversation::where('member_ai_profile_id', $this->profile->id)
            ->where('profile_owner_user_id', $this->profileOwner->id)
            ->where('visitor_user_id', $this->visitorUser->id)
            ->first();

        $this->assertNotNull($conversation);
        $this->assertEquals($this->org->id, $conversation->organization_id);
    }

    public function test_initial_assistant_message_uses_french_locale(): void
    {
        app()->setLocale('fr');

        $component = Livewire::actingAs($this->visitorUser)
            ->test(AiAgentChat::class, ['user' => $this->profileOwner]);

        $messages = $component->get('messages');

        $this->assertSame(__('ai.visitor_chat_initial_message', ['member_name' => $this->profileOwner->name]), $messages[0]['text']);
    }

    public function test_initial_assistant_message_uses_english_locale_without_french_fallback(): void
    {
        app()->setLocale('en');

        $component = Livewire::actingAs($this->visitorUser)
            ->test(AiAgentChat::class, ['user' => $this->profileOwner]);

        $messages = $component->get('messages');

        $this->assertSame(__('ai.visitor_chat_initial_message', ['member_name' => $this->profileOwner->name]), $messages[0]['text']);
        $this->assertStringStartsWith('Hello!', $messages[0]['text']);
        $this->assertStringNotContainsString('Bonjour', $messages[0]['text']);
        $this->assertStringNotContainsString('Je suis', $messages[0]['text']);
    }

    public function test_existing_conversation_messages_keep_original_language(): void
    {
        app()->setLocale('en');

        $conversation = ProfileAgentConversation::factory()
            ->create([
                'organization_id' => $this->org->id,
                'member_ai_profile_id' => $this->profile->id,
                'profile_owner_user_id' => $this->profileOwner->id,
                'visitor_user_id' => $this->visitorUser->id,
            ]);

        ProfileAgentMessage::factory()->assistantMessage()->create([
            'conversation_id' => $conversation->id,
            'content' => 'Bonjour ! Ancien message historique.',
        ]);

        $component = Livewire::actingAs($this->visitorUser)
            ->test(AiAgentChat::class, ['user' => $this->profileOwner]);

        $messages = $component->get('messages');

        $this->assertCount(1, $messages);
        $this->assertSame('Bonjour ! Ancien message historique.', $messages[0]['text']);
    }

    public function test_send_message_stores_user_and_assistant_messages(): void
    {
        $component = Livewire::actingAs($this->visitorUser)
            ->test(AiAgentChat::class, ['user' => $this->profileOwner]);

        $component->set('question', 'Quels sont vos services ?')
            ->call('sendMessage');

        $conversation = ProfileAgentConversation::where('member_ai_profile_id', $this->profile->id)
            ->where('profile_owner_user_id', $this->profileOwner->id)
            ->where('visitor_user_id', $this->visitorUser->id)
            ->first();

        $messages = ProfileAgentMessage::where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $messages);
        $this->assertEquals('user', $messages[0]->role);
        $this->assertEquals('Quels sont vos services ?', $messages[0]->content);
        $this->assertEquals('assistant', $messages[1]->role);
        $this->assertNotNull($messages[1]->content);
    }

    public function test_conversation_linked_to_owner(): void
    {
        Livewire::actingAs($this->visitorUser)
            ->test(AiAgentChat::class, ['user' => $this->profileOwner])
            ->set('question', 'Bonjour')
            ->call('sendMessage');

        $conversation = ProfileAgentConversation::where('member_ai_profile_id', $this->profile->id)
            ->where('profile_owner_user_id', $this->profileOwner->id)
            ->first();

        $this->assertNotNull($conversation);
        $this->assertEquals($this->profileOwner->id, $conversation->profile_owner_user_id);
    }

    public function test_conversation_linked_to_visitor(): void
    {
        Livewire::actingAs($this->visitorUser)
            ->test(AiAgentChat::class, ['user' => $this->profileOwner])
            ->set('question', 'Bonjour')
            ->call('sendMessage');

        $conversation = ProfileAgentConversation::where('visitor_user_id', $this->visitorUser->id)
            ->first();

        $this->assertNotNull($conversation);
    }

    public function test_owner_can_see_conversation_via_task936(): void
    {
        Livewire::actingAs($this->visitorUser)
            ->test(AiAgentChat::class, ['user' => $this->profileOwner])
            ->set('question', 'Bonjour')
            ->call('sendMessage');

        $response = $this->actingAs($this->profileOwner)
            ->get(route('agent-ia.conversations'));

        $response->assertOk();
        $response->assertSeeText(__('ai.conversations_title'));
    }

    public function test_owner_can_see_visitor_conversation_detail(): void
    {
        Livewire::actingAs($this->visitorUser)
            ->test(AiAgentChat::class, ['user' => $this->profileOwner])
            ->set('question', 'Bonjour')
            ->call('sendMessage');

        $conversation = ProfileAgentConversation::where('visitor_user_id', $this->visitorUser->id)
            ->first();

        $response = $this->actingAs($this->profileOwner)
            ->get(route('agent-ia.conversations.show', $conversation));

        $response->assertOk();
        $response->assertSeeText(__('ai.message_role_user'));
        $response->assertSeeText(__('ai.message_role_assistant'));
    }

    public function test_other_member_cannot_see_conversation(): void
    {
        Livewire::actingAs($this->visitorUser)
            ->test(AiAgentChat::class, ['user' => $this->profileOwner])
            ->set('question', 'Bonjour')
            ->call('sendMessage');

        $conversation = ProfileAgentConversation::where('visitor_user_id', $this->visitorUser->id)
            ->first();

        $response = $this->actingAs($this->otherUser)
            ->get(route('agent-ia.conversations.show', $conversation));

        $response->assertForbidden();
    }

    public function test_visitor_chat_logs_admin_ai_interaction(): void
    {
        Livewire::actingAs($this->visitorUser)
            ->test(AiAgentChat::class, ['user' => $this->profileOwner])
            ->set('question', 'Quels sont vos services ?')
            ->call('sendMessage');

        $interaction = AdminAiInteraction::where('scenario_id', 'profile_agent_visitor_chat')
            ->where('user_id', $this->visitorUser->id)
            ->first();

        $this->assertNotNull($interaction);
        $this->assertEquals('profile_agent_visitor_chat', $interaction->scenario_id);
    }

    public function test_turn_limit_stops_new_messages(): void
    {
        $conversation = ProfileAgentConversation::factory()
            ->create([
                'organization_id' => $this->org->id,
                'member_ai_profile_id' => $this->profile->id,
                'profile_owner_user_id' => $this->profileOwner->id,
                'visitor_user_id' => $this->visitorUser->id,
            ]);

        $maxTurns = AiAgentChat::MAX_VISITOR_TURNS;
        for ($i = 0; $i < $maxTurns; $i++) {
            ProfileAgentMessage::factory()->userMessage()->create([
                'conversation_id' => $conversation->id,
                'created_at' => now()->addSeconds($i),
            ]);
            ProfileAgentMessage::factory()->assistantMessage()->create([
                'conversation_id' => $conversation->id,
                'created_at' => now()->addSeconds($i)->addMilliseconds(500),
            ]);
        }

        $component = Livewire::actingAs($this->visitorUser)
            ->test(AiAgentChat::class, ['user' => $this->profileOwner]);

        $component->assertSet('maxTurnsReached', true)
            ->assertSet('visitorTurnCount', $maxTurns)
            ->set('question', 'Encore une question')
            ->call('sendMessage');

        $messagesAfter = ProfileAgentMessage::where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->count();

        $this->assertEquals($maxTurns, $messagesAfter);
    }

    public function test_ux_disclaimer_displayed(): void
    {
        $response = $this->actingAs($this->visitorUser)
            ->get(route('agent-ia.profile.chat', $this->profileOwner));

        $response->assertOk();
        $response->assertSeeText(__('ai.visitor_chat_disclaimer'));
    }

    public function test_no_regression_on_setup_page(): void
    {
        $response = $this->actingAs($this->profileOwner)
            ->get(route('agent-ia.setup'));

        $response->assertOk();
        $response->assertSeeText(__('ai.setup_title'));
    }

    public function test_no_regression_on_conversations_page(): void
    {
        ProfileAgentConversation::factory()
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
    }

    public function test_owner_can_see_conversation_on_org_scoped_history_page(): void
    {
        Livewire::actingAs($this->visitorUser)
            ->test(AiAgentChat::class, ['user' => $this->profileOwner])
            ->set('question', 'Bonjour')
            ->call('sendMessage');

        $response = $this->actingAs($this->profileOwner)
            ->get(route('organization.agent-ia.conversations', ['organization' => $this->org->slug]));

        $response->assertOk();
        $response->assertSeeText(__('ai.conversations_title'));
        $response->assertSeeText($this->visitorUser->name);
        $response->assertSee(route('organization.agent-ia.conversations.show', [
            'organization' => $this->org->slug,
            'conversation' => ProfileAgentConversation::where('visitor_user_id', $this->visitorUser->id)->first(),
        ]));
    }

    public function test_owner_can_see_conversation_detail_on_org_scoped_history_page(): void
    {
        Livewire::actingAs($this->visitorUser)
            ->test(AiAgentChat::class, ['user' => $this->profileOwner])
            ->set('question', 'Bonjour')
            ->call('sendMessage');

        $conversation = ProfileAgentConversation::where('visitor_user_id', $this->visitorUser->id)
            ->first();

        $response = $this->actingAs($this->profileOwner)
            ->get(route('organization.agent-ia.conversations.show', [
                'organization' => $this->org->slug,
                'conversation' => $conversation,
            ]));

        $response->assertOk();
        $response->assertSeeText(__('ai.message_role_user'));
        $response->assertSeeText(__('ai.message_role_assistant'));
        $response->assertSee(route('organization.agent-ia.conversations', ['organization' => $this->org->slug]));
    }

    public function test_visitor_can_send_multiple_messages(): void
    {
        $component = Livewire::actingAs($this->visitorUser)
            ->test(AiAgentChat::class, ['user' => $this->profileOwner]);

        $component->set('question', 'Première question')
            ->call('sendMessage');

        $component->assertSet('visitorTurnCount', 1)
            ->assertSet('maxTurnsReached', false);

        $component->set('question', 'Deuxième question')
            ->call('sendMessage');

        $component->assertSet('visitorTurnCount', 2);

        $conversation = ProfileAgentConversation::where('visitor_user_id', $this->visitorUser->id)
            ->first();

        $userMessages = ProfileAgentMessage::where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->count();

        $this->assertEquals(2, $userMessages);
    }

    public function test_guest_can_visit_chat_page(): void
    {
        session()->setId('test-session-id');

        $response = $this->get(route('agent-ia.profile.chat', $this->profileOwner));

        $response->assertOk();
        $response->assertSeeText(__('ai.ai_agent_of', ['name' => $this->profileOwner->name]));
    }
}
