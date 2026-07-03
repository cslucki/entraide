<?php

namespace Tests\Feature;

use App\Livewire\MemberAiProfileConversationalSetup;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\MemberProfileAgentResponder;
use App\Services\Ai\Persistence\AdminAiInteractionPersistence;
use Database\Seeders\AiPromptSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TASK934ConversationalSetupTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['ai_profiles_enabled' => true]);
        $this->member = User::factory()->create(['organization_id' => $this->org->id, 'name' => 'TEST_MEMBER1']);

        $this->actingAs($this->member);
        app()->instance('current_organization', $this->org);

        $this->seed(AiPromptSeeder::class);
    }

    public function test_page_renders(): void
    {
        $response = $this->get(route('agent-ia.setup'));

        $response->assertOk();
        $response->assertSee(__('ai.setup_title'));
    }

    public function test_guest_redirected_to_login(): void
    {
        auth()->logout();

        $response = $this->get(route('agent-ia.setup'));

        $response->assertRedirect(route('login'));
    }

    public function test_initial_state_shows_start_button_and_wizard_link(): void
    {
        Livewire::test(MemberAiProfileConversationalSetup::class)
            ->assertSee(__('ai.setup_start_btn'))
            ->assertSee(__('ai.setup_use_form'))
            ->assertDontSee(__('ai.setup_chat_title'));
    }

    public function test_start_triggers_ai_and_logs_interaction(): void
    {
        $this->mock(MemberProfileAgentResponder::class)
            ->shouldReceive('chatWithSetupPrompt')
            ->once()
            ->andReturn([
                'response' => 'Bonjour ! Pour commencer, pouvez-vous vous présenter en quelques phrases ?',
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'latency_ms' => 500,
            ])
            ->shouldReceive('logSetupInteraction')
            ->once();

        Livewire::test(MemberAiProfileConversationalSetup::class)
            ->call('start')
            ->assertSet('started', true)
            ->assertSee('Bonjour ! Pour commencer, pouvez-vous vous présenter en quelques phrases ?');
    }

    public function test_send_message_triggers_ai_and_logs_interaction(): void
    {
        $this->mock(MemberProfileAgentResponder::class)
            ->shouldReceive('chatWithSetupPrompt')
            ->twice()
            ->andReturn(
                [
                    'response' => 'Parfait ! Et quels sont vos principaux domaines de compétence ?',
                    'provider' => 'openai',
                    'model' => 'gpt-4o-mini',
                    'latency_ms' => 450,
                ],
                [
                    'response' => 'Merci ! Quels types de services proposez-vous à vos clients ?',
                    'provider' => 'openai',
                    'model' => 'gpt-4o-mini',
                    'latency_ms' => 480,
                ],
            )
            ->shouldReceive('logSetupInteraction')
            ->twice();

        Livewire::test(MemberAiProfileConversationalSetup::class)
            ->call('start')
            ->set('currentInput', 'Je suis développeur PHP depuis 10 ans.')
            ->call('send')
            ->assertSet('turnCount', 1)
            ->assertSee('Merci ! Quels types de services proposez-vous');
    }

    public function test_json_response_triggers_preview(): void
    {
        $jsonResponse = "Voici le résumé de votre profil :\n\n```json\n{\n    \"summary\": \"Développeur PHP expérimenté avec 10 ans d'expérience.\",\n    \"service_scope\": \"Conseil et développement web\",\n    \"experience_context\": \"10 ans dans le développement web\",\n    \"skills\": [\"PHP\", \"Laravel\", \"API\"],\n    \"help_types\": [\"Conseil\", \"Développement\"],\n    \"target_audience\": \"PME et startups\",\n    \"problems_helped\": \"Aide les entreprises à construire leurs applications web\",\n    \"boundaries\": [\"Pas de design graphique\"],\n    \"preferred_contact_action\": \"email\",\n    \"tone\": \"professionnel\"\n}\n```\n\nSouhaitez-vous valider ce profil ou apporter des modifications ?";

        $this->mock(MemberProfileAgentResponder::class)
            ->shouldReceive('chatWithSetupPrompt')
            ->times(3)
            ->andReturn(
                [
                    'response' => 'Parfait ! Dites-m\'en plus sur votre expérience.',
                    'provider' => 'openai',
                    'model' => 'gpt-4o-mini',
                    'latency_ms' => 400,
                ],
                [
                    'response' => 'Intéressant ! Et quel type d\'aide proposez-vous ?',
                    'provider' => 'openai',
                    'model' => 'gpt-4o-mini',
                    'latency_ms' => 420,
                ],
                [
                    'response' => $jsonResponse,
                    'provider' => 'openai',
                    'model' => 'gpt-4o-mini',
                    'latency_ms' => 800,
                ],
            )
            ->shouldReceive('logSetupInteraction')
            ->times(3);

        Livewire::test(MemberAiProfileConversationalSetup::class)
            ->call('start')
            ->set('currentInput', 'Je fais du PHP.')
            ->call('send')
            ->set('currentInput', 'Conseil et formation.')
            ->call('send')
            ->assertSet('showPreview', true)
            ->assertSee('Développeur PHP expérimenté');
    }

    public function test_validate_and_save_creates_draft_profile(): void
    {
        $previewData = [
            'summary' => 'Expert Laravel',
            'service_scope' => 'Développement sur mesure',
            'experience_context' => '5 ans',
            'skills' => ['PHP', 'Laravel'],
            'help_types' => ['Développement'],
            'target_audience' => 'Startups',
            'problems_helped' => 'Aide à construire des apps',
            'boundaries' => ['Pas de design'],
            'preferred_contact_action' => 'email',
            'tone' => 'direct',
        ];

        $this->mock(MemberProfileAgentResponder::class)
            ->shouldReceive('chatWithSetupPrompt')
            ->once()
            ->andReturn([
                'response' => 'Bonjour ! Présentez-vous.',
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'latency_ms' => 300,
            ])
            ->shouldReceive('logSetupInteraction')
            ->once();

        Livewire::test(MemberAiProfileConversationalSetup::class)
            ->call('start')
            ->set('previewData', $previewData)
            ->set('showPreview', true)
            ->call('validateAndSave')
            ->assertSet('showPreview', false)
            ->assertSet('previewData', null);

        $profile = MemberAiProfile::forUser($this->member)
            ->forOrganization($this->org)
            ->first();

        $this->assertNotNull($profile);
        $this->assertEquals(MemberAiProfile::STATUS_DRAFT, $profile->status);
        $this->assertEquals($previewData, $profile->structured_profile);
        $this->assertEquals('Expert Laravel', $profile->member_profile_summary);
    }

    public function test_validate_and_save_updates_existing_profile(): void
    {
        $existingProfile = MemberAiProfile::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'status' => MemberAiProfile::STATUS_DRAFT,
            'structured_profile' => [
                'summary' => 'Ancien résumé',
                'skills' => ['Old Skill'],
            ],
        ]);

        $newPreviewData = [
            'summary' => 'Nouveau résumé mis à jour',
            'service_scope' => 'Conseil',
            'experience_context' => '8 ans',
            'skills' => ['PHP', 'Laravel', 'Vue'],
            'help_types' => ['Conseil'],
            'target_audience' => 'PME',
            'problems_helped' => 'Accompagnement technique',
            'boundaries' => ['Pas d\'urgence'],
            'preferred_contact_action' => 'email',
            'tone' => 'chaleureux',
        ];

        $this->mock(MemberProfileAgentResponder::class)
            ->shouldReceive('chatWithSetupPrompt')
            ->once()
            ->andReturn([
                'response' => 'Bonjour ! Que souhaitez-vous mettre à jour ?',
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'latency_ms' => 300,
            ])
            ->shouldReceive('logSetupInteraction')
            ->once();

        Livewire::test(MemberAiProfileConversationalSetup::class)
            ->call('start')
            ->set('previewData', $newPreviewData)
            ->set('showPreview', true)
            ->call('validateAndSave');

        $existingProfile->refresh();

        $this->assertEquals($newPreviewData, $existingProfile->structured_profile);
        $this->assertEquals('Nouveau résumé mis à jour', $existingProfile->member_profile_summary);
    }

    public function test_interaction_is_logged_to_admin_ai_interactions(): void
    {
        $this->mock(MemberProfileAgentResponder::class)
            ->shouldReceive('chatWithSetupPrompt')
            ->once()
            ->andReturn([
                'response' => 'Présentez-vous s\'il vous plaît.',
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'latency_ms' => 500,
            ])
            ->shouldReceive('logSetupInteraction')
            ->once()
            ->andReturnUsing(function (string $question, string $response, array $result, $profile = null) {
                app(AdminAiInteractionPersistence::class)->persist([
                    'scenario_id' => 'profile_agent_setup',
                    'provider' => $result['provider'],
                    'model' => $result['model'],
                    'content' => $question,
                    'result_summary' => $response,
                    'latency_ms' => $result['latency_ms'],
                ]);
            });

        Livewire::test(MemberAiProfileConversationalSetup::class)
            ->call('start');

        $this->assertDatabaseHas('admin_ai_interactions', [
            'scenario_id' => 'profile_agent_setup',
        ]);
    }

    public function test_fallback_on_non_json_response(): void
    {
        $this->mock(MemberProfileAgentResponder::class)
            ->shouldReceive('chatWithSetupPrompt')
            ->times(11)
            ->andReturn(
                ...array_fill(0, 10, [
                    'response' => 'Pouvez-vous préciser ?',
                    'provider' => 'openai',
                    'model' => 'gpt-4o-mini',
                    'latency_ms' => 200,
                ]),
                ...[[
                    'response' => 'Fin de la conversation.',
                    'provider' => 'openai',
                    'model' => 'gpt-4o-mini',
                    'latency_ms' => 200,
                ]],
            )
            ->shouldReceive('logSetupInteraction')
            ->times(11);

        Livewire::test(MemberAiProfileConversationalSetup::class)
            ->call('start')
            ->set('currentInput', 'a')
            ->call('send')
            ->set('currentInput', 'b')
            ->call('send')
            ->set('currentInput', 'c')
            ->call('send')
            ->set('currentInput', 'd')
            ->call('send')
            ->set('currentInput', 'e')
            ->call('send')
            ->set('currentInput', 'f')
            ->call('send')
            ->set('currentInput', 'g')
            ->call('send')
            ->set('currentInput', 'h')
            ->call('send')
            ->set('currentInput', 'i')
            ->call('send')
            ->set('currentInput', 'j')
            ->call('send')
            ->assertSet('showPreview', true);
    }

    public function test_loads_existing_profile_in_mount(): void
    {
        MemberAiProfile::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'status' => MemberAiProfile::STATUS_DRAFT,
            'structured_profile' => ['summary' => 'Existing profile'],
        ]);

        Livewire::test(MemberAiProfileConversationalSetup::class)
            ->assertSet('profile.id', function ($id) {
                return $id !== null;
            });
    }

    public function test_empty_state_shows_for_new_users(): void
    {
        Livewire::test(MemberAiProfileConversationalSetup::class)
            ->assertSet('profile', null);
    }

    public function test_restart_resets_conversation(): void
    {
        $this->mock(MemberProfileAgentResponder::class)
            ->shouldReceive('chatWithSetupPrompt')
            ->twice()
            ->andReturn(
                [
                    'response' => 'Première question',
                    'provider' => 'openai',
                    'model' => 'gpt-4o-mini',
                    'latency_ms' => 300,
                ],
                [
                    'response' => 'Nouvelle question après restart',
                    'provider' => 'openai',
                    'model' => 'gpt-4o-mini',
                    'latency_ms' => 300,
                ],
            )
            ->shouldReceive('logSetupInteraction')
            ->twice();

        Livewire::test(MemberAiProfileConversationalSetup::class)
            ->call('start')
            ->assertSet('started', true)
            ->call('restart')
            ->assertSet('started', true)
            ->assertSee('Nouvelle question après restart');
    }

    public function test_abandon_redirects_to_wizard(): void
    {
        Livewire::test(MemberAiProfileConversationalSetup::class)
            ->call('abandon')
            ->assertRedirect(route('agent-ia.wizard'));
    }

    public function test_wizard_still_works_after_setup(): void
    {
        MemberAiProfile::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'status' => MemberAiProfile::STATUS_DRAFT,
            'structured_profile' => ['summary' => 'Setup test'],
        ]);

        $response = $this->get(route('agent-ia.wizard'));
        $response->assertOk();
        $response->assertSee(__('ai.wizard_title'));
    }
}
