<?php

namespace Tests\Feature\Livewire;

use App\Livewire\AiAgentChat;
use App\Models\MemberAiProfile;
use App\Models\MemberAiProfileInteraction;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InlineMemberAgentTest extends TestCase
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

    public function test_chat_hidden_when_no_profile(): void
    {
        Livewire::actingAs($this->visitor)
            ->test(AiAgentChat::class, ['user' => $this->member])
            ->assertSet('profile', null);
    }

    public function test_chat_visible_when_profile_published(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'member_profile_summary' => 'Consultant en marketing digital',
        ]);

        Livewire::actingAs($this->visitor)
            ->test(AiAgentChat::class, ['user' => $this->member])
            ->assertSet('profile.id', fn ($id) => is_string($id))
            ->assertSee('Agent IA de')
            ->assertSee('Bonjour');
    }

    public function test_send_message(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'skills' => ['SEO', 'Marketing Digital'],
            'experience_context' => '5 ans en agence',
        ]);

        Livewire::actingAs($this->visitor)
            ->test(AiAgentChat::class, ['user' => $this->member])
            ->set('question', 'Quelles sont ses compétences ?')
            ->call('sendMessage')
            ->assertSet('error', null)
            ->assertSet('isTyping', false);
    }

    public function test_empty_question_does_nothing(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
        ]);

        $c = Livewire::actingAs($this->visitor)
            ->test(AiAgentChat::class, ['user' => $this->member]);

        $initialCount = count($c->messages);

        $c->set('question', '   ')
            ->call('sendMessage')
            ->assertSet('isTyping', false);

        $this->assertCount($initialCount, $c->messages);
    }

    public function test_send_message_logs_interaction(): void
    {
        $profile = MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'skills' => ['SEO'],
        ]);

        Livewire::actingAs($this->visitor)
            ->test(AiAgentChat::class, ['user' => $this->member])
            ->set('question', 'Quelles compétences ?')
            ->call('sendMessage');

        $this->assertDatabaseHas('member_ai_profile_interactions', [
            'member_ai_profile_id' => $profile->id,
            'profile_owner_user_id' => $this->member->id,
            'visitor_user_id' => $this->visitor->id,
            'status' => 'success',
        ]);
    }

    public function test_reset_conversation(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'skills' => ['SEO'],
        ]);

        $c = Livewire::actingAs($this->visitor)
            ->test(AiAgentChat::class, ['user' => $this->member]);

        $c->set('question', 'Quelles compétences ?')
            ->call('sendMessage');

        $this->assertGreaterThan(1, count($c->messages));

        $c->call('resetConversation');

        $this->assertCount(1, $c->messages);
        $this->assertEquals('assistant', $c->messages[0]['role']);
    }

    public function test_profile_page_shows_ai_agent_chat_entrypoint(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'member_profile_summary' => 'Consultant SEO',
        ]);

        $response = $this->actingAs($this->visitor)
            ->get(route('profile.show', $this->member));

        $response->assertStatus(200);

        $response->assertSee('Agent de profil IA');
        $response->assertSeeText('Posez une question sur ce que ce membre peut vous apporter');
        $response->assertSee(route('agent-ia.profile.chat', $this->member), false);
    }

    public function test_ai_agent_chat_page_shows_chat_interface(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'member_profile_summary' => 'Consultant SEO',
        ]);

        $response = $this->actingAs($this->visitor)
            ->get(route('agent-ia.profile.chat', $this->member));

        $response->assertStatus(200);
        $response->assertSee('Agent de profil IA');
        $response->assertSee('Agent IA de');
        $response->assertSee('Posez votre question');
        $response->assertSee('Écrire à');
    }

    public function test_profile_page_shows_agent_active_for_own_profile(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'member_profile_summary' => 'Consultant SEO',
        ]);

        $response = $this->actingAs($this->member)
            ->get(route('profile.show', $this->member));

        $response->assertStatus(200);
        $response->assertSee('Agent IA activé');
    }

    public function test_profile_page_hides_agent_when_no_profile(): void
    {
        $response = $this->actingAs($this->visitor)
            ->get(route('profile.show', $this->member));

        $response->assertStatus(200)
            ->assertDontSee('Agent IA de');
    }

    public function test_guest_sees_ai_agent_chat_entrypoint_on_profile(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'member_profile_summary' => 'Consultant SEO',
        ]);

        $response = $this->get(route('profile.show', $this->member));

        $response->assertStatus(200)
            ->assertSee('Agent de profil IA')
            ->assertSeeText('Posez une question sur ce que ce membre peut vous apporter')
            ->assertSee(route('agent-ia.profile.chat', $this->member), false);
    }
}
