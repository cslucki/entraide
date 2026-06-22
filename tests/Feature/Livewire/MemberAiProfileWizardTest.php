<?php

namespace Tests\Feature\Livewire;

use App\Livewire\MemberAiProfileWizard;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MemberAiProfileWizardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private User $otherUser;

    private Organization $otherOrg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->otherOrg = Organization::factory()->create();
        $this->user = User::factory()->create(['organization_id' => $this->org->id]);
        $this->otherUser = User::factory()->create(['organization_id' => $this->otherOrg->id]);
        app()->instance('current_organization', $this->org);
    }

    public function test_guest_redirected_to_login(): void
    {
        $response = $this->get(route('agent-ia.wizard'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_wizard_page(): void
    {
        $response = $this->actingAs($this->user)->get(route('agent-ia.wizard'));

        $response->assertOk();
        $response->assertSee('Mon profil IA');
    }

    public function test_component_renders_without_existing_profile(): void
    {
        Livewire::actingAs($this->user)
            ->test(MemberAiProfileWizard::class)
            ->assertStatus(200)
            ->assertSet('step', 1)
            ->assertSee('Qui êtes-vous ?');
    }

    public function test_component_renders_with_existing_draft(): void
    {
        MemberAiProfile::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'member_profile_summary' => 'Test summary',
        ]);

        Livewire::actingAs($this->user)
            ->test(MemberAiProfileWizard::class)
            ->assertStatus(200)
            ->assertSet('member_profile_summary', 'Test summary')
            ->assertSet('step', 1);
    }

    public function test_save_draft_creates_profile_and_persists_data(): void
    {
        Livewire::actingAs($this->user)
            ->test(MemberAiProfileWizard::class)
            ->set('member_profile_summary', 'Consultant en marketing')
            ->call('saveDraft')
            ->assertDispatched('profile-saved');

        $this->assertDatabaseHas('member_ai_profiles', [
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'status' => 'draft',
        ]);

        $profile = MemberAiProfile::forUser($this->user)
            ->forOrganization($this->org)
            ->first();

        $this->assertEquals('Consultant en marketing', $profile->member_profile_summary);
        $this->assertNotNull($profile->last_saved_at);
    }

    public function test_save_and_continue_step_1_saves_and_advances(): void
    {
        Livewire::actingAs($this->user)
            ->test(MemberAiProfileWizard::class)
            ->set('member_profile_summary', 'Consultant en marketing')
            ->set('target_audience', ['entrepreneurs'])
            ->set('problems_helped_raw', "Stratégie de marque\nSEO")
            ->call('saveAndContinue')
            ->assertSet('step', 2);

        $profile = MemberAiProfile::forUser($this->user)
            ->forOrganization($this->org)
            ->first();

        $this->assertEquals('Consultant en marketing', $profile->member_profile_summary);
    }

    public function test_save_and_continue_step_2_saves_and_advances(): void
    {
        $profile = MemberAiProfile::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        Livewire::actingAs($this->user)
            ->test(MemberAiProfileWizard::class)
            ->call('goToStep', 2)
            ->set('service_scope', 'Accompagnement SEO')
            ->set('skillsInput', 'SEO, Marketing, Rédaction')
            ->set('experience_context', '5 ans en agence')
            ->set('help_types', ['avis_rapide', 'repondre_question'])
            ->call('saveAndContinue')
            ->assertSet('step', 3);

        $profile->refresh();
        $this->assertEquals('Accompagnement SEO', $profile->service_scope);
    }

    public function test_save_and_continue_step_3_saves_and_advances(): void
    {
        MemberAiProfile::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        Livewire::actingAs($this->user)
            ->test(MemberAiProfileWizard::class)
            ->call('goToStep', 3)
            ->set('boundaries', ['pas_urgence', 'pas_travail_gratuit'])
            ->set('preferred_contact_action', 'envoyer_demande_echange')
            ->set('tone', 'chaleureux')
            ->call('saveAndContinue')
            ->assertSet('step', 4);
    }

    public function test_save_and_continue_step_4_saves_and_advances(): void
    {
        MemberAiProfile::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        Livewire::actingAs($this->user)
            ->test(MemberAiProfileWizard::class)
            ->call('goToStep', 4)
            ->set('goodExampleInput', 'Aide pour stratégie de contenu')
            ->call('addGoodExample')
            ->call('saveAndContinue')
            ->assertSet('step', 5);
    }

    public function test_submit_for_validation_sets_pending_validation_and_goes_to_review(): void
    {
        $profile = MemberAiProfile::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        Livewire::actingAs($this->user)
            ->test(MemberAiProfileWizard::class)
            ->set('member_profile_summary', 'Consultant en marketing')
            ->set('target_audience', ['entrepreneurs'])
            ->set('problems_helped_raw', "Stratégie de marque\nSEO")
            ->set('service_scope', 'Accompagnement SEO')
            ->set('skillsInput', 'SEO, Marketing')
            ->set('experience_context', '5 ans en agence')
            ->set('help_types', ['avis_rapide'])
            ->set('boundaries', ['pas_urgence'])
            ->set('preferred_contact_action', 'envoyer_demande_echange')
            ->set('tone', 'chaleureux')
            ->set('good_request_examples', ['Aide pour stratégie'])
            ->call('submitForValidation')
            ->assertSet('step', 5);

        $profile->refresh();
        $this->assertEquals(MemberAiProfile::STATUS_PENDING_VALIDATION, $profile->status);
        $this->assertNotNull($profile->last_saved_at);
    }

    public function test_publish_sets_published_status(): void
    {
        MemberAiProfile::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        Livewire::actingAs($this->user)
            ->test(MemberAiProfileWizard::class)
            ->set('member_profile_summary', 'Consultant en marketing')
            ->set('target_audience', ['entrepreneurs'])
            ->set('problems_helped_raw', "Stratégie de marque\nSEO")
            ->set('service_scope', 'Accompagnement SEO')
            ->set('skillsInput', 'SEO, Marketing')
            ->set('experience_context', '5 ans en agence')
            ->set('help_types', ['avis_rapide'])
            ->set('boundaries', ['pas_urgence'])
            ->set('preferred_contact_action', 'envoyer_demande_echange')
            ->set('tone', 'chaleureux')
            ->set('good_request_examples', ['Aide pour stratégie'])
            ->call('publish')
            ->assertDispatched('profile-published');

        $profile = MemberAiProfile::forUser($this->user)
            ->forOrganization($this->org)
            ->first();

        $this->assertEquals(MemberAiProfile::STATUS_PUBLISHED, $profile->status);
        $this->assertNotNull($profile->validated_at);
        $this->assertNotNull($profile->published_at);
    }

    public function test_publish_fails_without_minimum_fields(): void
    {
        Livewire::actingAs($this->user)
            ->test(MemberAiProfileWizard::class)
            ->call('publish')
            ->assertHasErrors([
                'member_profile_summary',
                'target_audience',
                'problems_helped_raw',
            ]);
    }

    public function test_submit_for_validation_fails_without_minimum_fields(): void
    {
        Livewire::actingAs($this->user)
            ->test(MemberAiProfileWizard::class)
            ->call('submitForValidation')
            ->assertHasErrors([
                'member_profile_summary',
                'target_audience',
                'problems_helped_raw',
            ]);
    }
}
