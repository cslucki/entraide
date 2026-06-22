<?php

namespace Tests\Feature\Livewire;

use App\Livewire\BoundedMemberAgent;
use App\Models\AdminAiInteraction;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BoundedMemberAgentTest extends TestCase
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
        $response = $this->get(route('agent-ia.member.presentation', $this->member));

        $response->assertRedirect(route('login'));
    }

    public function test_agent_shows_fallback_when_no_profile(): void
    {
        Livewire::actingAs($this->visitor)
            ->test(BoundedMemberAgent::class, ['user' => $this->member])
            ->assertStatus(200)
            ->assertSet('error', "Ce membre n'a pas encore publié son profil IA.")
            ->assertSee("Ce membre n'a pas encore publié son profil IA.");
    }

    public function test_agent_shows_fallback_when_profile_not_published(): void
    {
        MemberAiProfile::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'status' => MemberAiProfile::STATUS_DRAFT,
        ]);

        Livewire::actingAs($this->visitor)
            ->test(BoundedMemberAgent::class, ['user' => $this->member])
            ->assertStatus(200)
            ->assertSet('error', "Ce membre n'a pas encore publié son profil IA.")
            ->assertSee("Ce membre n'a pas encore publié son profil IA.");
    }

    public function test_agent_shows_profile_when_published(): void
    {
        $profile = MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'member_profile_summary' => 'Consultant en marketing digital',
            'skills' => ['SEO', 'Marketing', 'Rédaction'],
            'help_types' => ['avis_rapide', 'repondre_question'],
            'boundaries' => ['pas_urgence', 'pas_travail_gratuit'],
            'preferred_contact_action' => 'envoyer_demande_echange',
            'tone' => 'chaleureux',
        ]);

        Livewire::actingAs($this->visitor)
            ->test(BoundedMemberAgent::class, ['user' => $this->member])
            ->assertStatus(200)
            ->assertSet('profile.id', $profile->id)
            ->assertSet('error', null)
            ->assertSee('Consultant en marketing digital')
            ->assertSee('SEO')
            ->assertSee('Agent IA de présentation');
    }

    public function test_agent_responds_to_question_about_skills(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'skills' => ['SEO', 'Marketing Digital', 'Rédaction Web'],
            'experience_context' => '5 ans en agence spécialiste SEO',
        ]);

        Livewire::actingAs($this->visitor)
            ->test(BoundedMemberAgent::class, ['user' => $this->member])
            ->set('question', 'Quelles sont ses compétences ?')
            ->call('askQuestion')
            ->assertSet('response', fn ($value) => str_contains($value, 'SEO') && str_contains($value, 'Marketing'))
            ->assertSet('error', null);
    }

    public function test_agent_responds_to_question_about_help_types(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'help_types' => ['avis_rapide', 'repondre_question'],
            'service_scope' => 'Accompagnement sur mesure',
        ]);

        Livewire::actingAs($this->visitor)
            ->test(BoundedMemberAgent::class, ['user' => $this->member])
            ->set('question', 'Quelle aide propose-t-il ?')
            ->call('askQuestion')
            ->assertSet('response', fn ($value) => str_contains($value, 'avis_rapide') || str_contains($value, 'Avis'))
            ->assertSet('error', null);
    }

    public function test_agent_refuses_out_of_scope_question(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'member_profile_summary' => 'Consultant',
        ]);

        $outOfScopeMessage = 'Ceci dépasse mon périmètre de présentation. Je peux uniquement vous renseigner sur les informations que le membre a partagées dans son profil IA.';

        Livewire::actingAs($this->visitor)
            ->test(BoundedMemberAgent::class, ['user' => $this->member])
            ->set('question', 'Quel est son salaire ?')
            ->call('askQuestion')
            ->assertSet('response', $outOfScopeMessage)
            ->assertSet('error', null);
    }

    public function test_agent_logs_interaction(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'skills' => ['SEO'],
        ]);

        Livewire::actingAs($this->visitor)
            ->test(BoundedMemberAgent::class, ['user' => $this->member])
            ->set('question', 'Quelles compétences ?')
            ->call('askQuestion');

        $this->assertDatabaseHas('admin_ai_interactions', [
            'organization_id' => $this->org->id,
            'user_id' => $this->visitor->id,
            'scenario_id' => 'bounded_member_presentation',
            'provider' => 'rule_based',
            'status' => 'success',
        ]);

        $this->assertEquals(1, AdminAiInteraction::count());
    }

    public function test_agent_responds_to_question_about_boundaries(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'boundaries' => ['pas_urgence', 'pas_travail_gratuit'],
        ]);

        Livewire::actingAs($this->visitor)
            ->test(BoundedMemberAgent::class, ['user' => $this->member])
            ->set('question', 'Quelles sont ses limites ?')
            ->call('askQuestion')
            ->assertSet('response', fn ($value) => str_contains($value, 'Limites'))
            ->assertSet('error', null);
    }

    public function test_agent_responds_to_question_about_contact(): void
    {
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'preferred_contact_action' => 'envoyer_message',
        ]);

        Livewire::actingAs($this->visitor)
            ->test(BoundedMemberAgent::class, ['user' => $this->member])
            ->set('question', 'Comment le contacter ?')
            ->call('askQuestion')
            ->assertSet('response', fn ($value) => str_contains($value, 'Contact préféré'))
            ->assertSet('error', null);
    }
}
