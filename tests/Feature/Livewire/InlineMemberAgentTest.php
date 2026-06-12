<?php

namespace Tests\Feature\Livewire;

use App\Livewire\InlineMemberAgent;
use App\Models\AdminAiInteraction;
use App\Models\MemberAiProfile;
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

    public function test_card_hidden_when_no_profile(): void
    {
        Livewire::actingAs($this->visitor)
            ->test(InlineMemberAgent::class, ['user' => $this->member])
            ->assertSet('showCard', false)
            ->assertDontSee('Agent IA de profil');
    }

    public function test_card_hidden_when_profile_not_published(): void
    {
        MemberAiProfile::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'status' => MemberAiProfile::STATUS_DRAFT,
        ]);

        Livewire::actingAs($this->visitor)
            ->test(InlineMemberAgent::class, ['user' => $this->member])
            ->assertSet('showCard', false)
            ->assertDontSee('Agent IA de profil');
    }

    public function test_card_visible_when_profile_published(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'member_profile_summary' => 'Consultant en marketing digital',
            'skills' => ['SEO', 'Marketing', 'Rédaction'],
        ]);

        Livewire::actingAs($this->visitor)
            ->test(InlineMemberAgent::class, ['user' => $this->member])
            ->assertSet('showCard', true)
            ->assertSet('profile.id', fn ($id) => is_string($id))
            ->assertSee('Agent IA de profil')
            ->assertSee('Consultant en marketing digital')
            ->assertSee('SEO');
    }

    public function test_ask_question_about_skills(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'skills' => ['SEO', 'Marketing Digital'],
            'experience_context' => '5 ans en agence',
        ]);

        Livewire::actingAs($this->visitor)
            ->test(InlineMemberAgent::class, ['user' => $this->member])
            ->set('question', 'Quelles sont ses compétences ?')
            ->call('askQuestion')
            ->assertSet('response', fn ($value) => str_contains($value, 'SEO'))
            ->assertSet('error', null);
    }

    public function test_ask_question_about_help_types(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'help_types' => ['avis_rapide', 'repondre_question'],
            'service_scope' => 'Accompagnement sur mesure',
        ]);

        Livewire::actingAs($this->visitor)
            ->test(InlineMemberAgent::class, ['user' => $this->member])
            ->set('question', 'Quelle aide propose-t-il ?')
            ->call('askQuestion')
            ->assertSet('response', fn ($value) => str_contains($value, 'avis_rapide') || str_contains($value, 'Avis'))
            ->assertSet('error', null);
    }

    public function test_ask_question_about_prestation(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'member_profile_summary' => 'Consultant SEO local',
            'help_types' => ['avis_rapide'],
            'service_scope' => 'Audit SEO et optimisation de fiches Google Business Profile',
        ]);

        Livewire::actingAs($this->visitor)
            ->test(InlineMemberAgent::class, ['user' => $this->member])
            ->set('question', "C'est quoi ta prestation ?")
            ->call('askQuestion')
            ->assertSet('response', fn ($value) => str_contains($value, 'Audit SEO'))
            ->assertSet('error', null);
    }

    public function test_refuses_out_of_scope_question(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'member_profile_summary' => 'Consultant',
        ]);

        $outOfScopeMessage = 'Ceci dépasse mon périmètre de présentation. Je peux uniquement vous renseigner sur les informations que le membre a partagées dans son profil IA.';

        Livewire::actingAs($this->visitor)
            ->test(InlineMemberAgent::class, ['user' => $this->member])
            ->set('question', 'Quel est son salaire ?')
            ->call('askQuestion')
            ->assertSet('response', $outOfScopeMessage)
            ->assertSet('error', null);
    }

    public function test_logs_interaction(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'skills' => ['SEO'],
        ]);

        Livewire::actingAs($this->visitor)
            ->test(InlineMemberAgent::class, ['user' => $this->member])
            ->set('question', 'Quelles compétences ?')
            ->call('askQuestion');

        $this->assertDatabaseHas('admin_ai_interactions', [
            'organization_id' => $this->org->id,
            'user_id' => $this->visitor->id,
            'scenario_id' => 'inline_member_presentation',
            'provider' => 'rule_based',
            'status' => 'success',
        ]);

        $this->assertEquals(1, AdminAiInteraction::count());
    }

    public function test_empty_question_shows_error(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
        ]);

        Livewire::actingAs($this->visitor)
            ->test(InlineMemberAgent::class, ['user' => $this->member])
            ->set('question', '   ')
            ->call('askQuestion')
            ->assertSet('error', 'Veuillez poser une question.');
    }

    public function test_ask_question_about_boundaries(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'boundaries' => ['pas_urgence', 'pas_travail_gratuit'],
        ]);

        Livewire::actingAs($this->visitor)
            ->test(InlineMemberAgent::class, ['user' => $this->member])
            ->set('question', 'Quelles sont ses limites ?')
            ->call('askQuestion')
            ->assertSet('response', fn ($value) => str_contains($value, 'Limites'))
            ->assertSet('error', null);
    }

    public function test_ask_question_about_contact(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'preferred_contact_action' => 'envoyer_message',
        ]);

        Livewire::actingAs($this->visitor)
            ->test(InlineMemberAgent::class, ['user' => $this->member])
            ->set('question', 'Comment le contacter ?')
            ->call('askQuestion')
            ->assertSet('response', fn ($value) => str_contains($value, 'Contact préféré'))
            ->assertSet('error', null);
    }

    public function test_profile_page_shows_inline_agent(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'member_profile_summary' => 'Consultant SEO',
        ]);

        $response = $this->actingAs($this->visitor)
            ->get(route('profile.show', $this->member));

        $response->assertStatus(200);

        $response->assertSee('Discuter avec');
    }

    public function test_profile_page_shows_inline_agent_for_own_profile(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'member_profile_summary' => 'Consultant SEO',
        ]);

        $response = $this->actingAs($this->member)
            ->get(route('profile.show', $this->member));

        $response->assertStatus(200);
        $content = $response->getContent();
        dump(substr($content, 0, 5000));
        $response->assertSee('Agent IA activé');
    }

    public function test_profile_page_hides_inline_agent_when_no_profile(): void
    {
        $response = $this->actingAs($this->visitor)
            ->get(route('profile.show', $this->member));

        $response->assertStatus(200)
            ->assertDontSee('Agent IA de profil');
    }
}
