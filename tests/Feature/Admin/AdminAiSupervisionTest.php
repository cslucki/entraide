<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminAiSupervisionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.openai.supervision_enabled' => true,
            'ai.openai.api_key' => 'sk-test-secret-1234567890',
            'ai.openai.base_url' => 'https://api.openai.com/v1',
            'ai.openai.model' => 'gpt-4o-mini',
            'ai.openai.max_output_tokens' => 900,
            'ai.openai.timeout' => 15,
            'ai.openai.input_price_per_1m' => 0.15,
            'ai.openai.output_price_per_1m' => 0.60,
            'ai.ollama.enabled' => false,
            'ai.openrouter.enabled' => false,
            'ai.supervision.enabled' => true,
        ]);

        Http::preventStrayRequests();
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function fakeOpenAiResponse(array $payload): array
    {
        return [
            'id' => 'resp_test_1',
            'object' => 'response',
            'status' => 'completed',
            'model' => 'gpt-4o-mini',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($payload),
                ]],
            ]],
            'usage' => [
                'input_tokens' => 120,
                'output_tokens' => 80,
            ],
        ];
    }

    private function basePayload(): array
    {
        return [
            'summary' => 'Message neutre demandant de l\'aide.',
            'risk_level' => 'low',
            'category' => ['slug' => 'redaction', 'label' => 'Rédaction'],
            'skills' => [['slug' => 'correctionrelecture', 'label' => 'Correction/Relecture']],
            'unmatched_terms' => [],
            'needs_human_category_review' => false,
            'category_review_reason' => '',
            'recommendations' => ['Laisser passer.'],
            'moderation_flag' => false,
            'notes' => 'Contenu acceptable.',
        ];
    }

    public function test_guest_cannot_access_supervision_center(): void
    {
        $this->get(route('admin.ai-supervision'))->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_supervision_center(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.ai-supervision'))->assertStatus(403);
    }

    public function test_admin_can_view_supervision_index(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->get(route('admin.ai-supervision'))
            ->assertOk()
            ->assertSee('Laboratoire IA interne BouclePro')
            ->assertSee('Testez un scénario avec un provider et un modèle configurés');
    }

    public function test_default_scenario_is_supervision_content(): void
    {
        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->get(route('admin.ai-supervision'));
        $response->assertOk();
        $response->assertSee('value="supervision_content"', false);
    }

    public function test_index_passes_scenario_compatibility_to_view(): void
    {
        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->get(route('admin.ai-supervision'));
        $response->assertOk();
        $response->assertSee('scenarioCompat', false);
    }

    public function test_cloud_only_banner_shown_when_only_openai_enabled(): void
    {
        config([
            'ai.ollama.enabled' => false,
            'ai.openrouter.enabled' => false,
            'ai.openai.supervision_enabled' => true,
        ]);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->get(route('admin.ai-supervision'));
        $response->assertOk();
        $response->assertSee('Cloud uniquement');
    }

    public function test_no_cloud_only_banner_when_ollama_enabled(): void
    {
        config(['ai.ollama.enabled' => true]);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->get(route('admin.ai-supervision'));
        $response->assertOk();
        $response->assertDontSee('Cloud uniquement');
        $response->assertDontSee('Aucun provider IA actif');
    }

    public function test_scenario_options_include_supported_providers(): void
    {
        config(['ai.ollama.enabled' => true]);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->get(route('admin.ai-supervision'));
        $response->assertOk();
        $response->assertSee('data-supported-providers', false);
    }

    public function test_admin_can_analyze_content_with_mocked_openai_response(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response($this->fakeOpenAiResponse($this->basePayload()), 200),
        ]);

        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => "J'aimerais aider quelqu'un cette semaine.",
            'provider' => 'openai',
            'scenario' => 'supervision_content',
        ]);

        $response->assertOk();
        $response->assertSee('Message neutre demandant de l\'aide.');
        $response->assertSee('Risque faible');
        $response->assertSee('Rédaction');
        $response->assertSee('gpt-4o-mini');
    }

    public function test_payload_uses_responses_api_with_max_output_tokens_and_json_schema(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response($this->fakeOpenAiResponse($this->basePayload()), 200),
        ]);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => 'Contenu de test à analyser.',
            'provider' => 'openai',
            'scenario' => 'supervision_content',
        ])->assertOk();

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api.openai.com/v1/responses') {
                return false;
            }

            $body = $request->data();

            $this->assertSame('gpt-4o-mini', $body['model'] ?? null);
            $this->assertSame(900, $body['max_output_tokens'] ?? null);
            $this->assertArrayNotHasKey('max_tokens', $body);
            $this->assertFalse($body['store'] ?? true);
            $this->assertSame('json_schema', data_get($body, 'text.format.type'));
            $this->assertTrue(data_get($body, 'text.format.strict'));
            $this->assertSame('object', data_get($body, 'text.format.schema.type'));

            $props = data_get($body, 'text.format.schema.properties');
            $this->assertArrayHasKey('risk_level', $props);
            $this->assertArrayHasKey('category', $props);
            $this->assertArrayHasKey('skills', $props);
            $this->assertArrayHasKey('unmatched_terms', $props);
            $this->assertArrayHasKey('needs_human_category_review', $props);
            $this->assertArrayNotHasKey('categories', $props);

            // skills[].slug must be constrained by enum
            $skillSlugEnum = data_get($body, 'text.format.schema.properties.skills.items.properties.slug.enum');
            $this->assertIsArray($skillSlugEnum);
            $this->assertNotEmpty($skillSlugEnum);

            return true;
        });
    }

    public function test_invalid_content_is_rejected(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->from(route('admin.ai-supervision'))
            ->post(route('admin.ai-supervision.analyze'), ['content' => ''])
            ->assertSessionHasErrors('content');
    }

    public function test_api_key_and_bearer_never_leak_in_response(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response($this->fakeOpenAiResponse($this->basePayload()), 200),
        ]);

        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => 'Contenu à analyser pour le test de fuite.',
        ]);

        $body = $response->getContent();

        $this->assertStringNotContainsString('sk-test-secret-1234567890', $body);
        $this->assertStringNotContainsString('Bearer ', $body);
        $this->assertStringNotContainsString('OPENAI_API_KEY', $body);
    }

    public function test_authorization_bearer_header_is_sent_to_openai(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response($this->fakeOpenAiResponse($this->basePayload()), 200),
        ]);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => 'Contenu pour vérifier le header.',
        ])->assertOk();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer sk-test-secret-1234567890');
        });
    }

    public function test_openai_failure_is_caught_and_shown_to_admin(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => 'oops'], 500),
        ]);

        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => 'Contenu de test.',
        ]);

        $response->assertOk();
        $response->assertSee('Réponse OpenAI invalide');
    }

    public function test_disabled_supervision_blocks_analyze(): void
    {
        config(['ai.supervision.enabled' => false]);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.ai-supervision.analyze'), ['content' => 'Test désactivé.'])
            ->assertStatus(403);
    }

    public function test_writer_content_maps_to_redaction_category(): void
    {
        $payload = array_merge($this->basePayload(), [
            'category' => ['slug' => 'redaction', 'label' => 'Rédaction'],
            'skills' => [
                ['slug' => 'correctionrelecture', 'label' => 'Correction/Relecture'],
                ['slug' => 'ateliers-creatifs', 'label' => 'Ateliers créatifs'],
            ],
            'unmatched_terms' => ['transcription', 'récits de vie'],
            'needs_human_category_review' => false,
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response($this->fakeOpenAiResponse($payload), 200),
        ]);

        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => 'Je propose mes services pour la relecture/correction de tout document, la rédaction d\'articles pour sites ou blog, la transcription de documents oraux. Je suis écrivain public depuis 2011 avec LA PLUME ALERTE.',
            'scenario' => 'supervision_content',
        ]);

        $response->assertOk();
        $response->assertSee('Rédaction');
        $response->assertSee('redaction');
        $response->assertSee('Correction/Relecture');
    }

    public function test_unmatched_terms_are_displayed_separately(): void
    {
        $payload = array_merge($this->basePayload(), [
            'unmatched_terms' => ['transcription', 'récits de vie'],
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response($this->fakeOpenAiResponse($payload), 200),
        ]);

        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => 'Je propose transcription et récits de vie comme services.',
            'scenario' => 'supervision_content',
        ]);

        $response->assertOk();
        $response->assertSee('transcription');
        $response->assertSee('Termes non mappés');
    }

    public function test_needs_human_review_flag_is_shown_when_mapping_incomplete(): void
    {
        $payload = array_merge($this->basePayload(), [
            'category' => ['slug' => 'autre', 'label' => 'Autre'],
            'needs_human_category_review' => true,
            'category_review_reason' => 'Le contenu mêle plusieurs domaines sans catégorie dominante claire.',
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response($this->fakeOpenAiResponse($payload), 200),
        ]);

        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => 'Je peux faire du coaching, de la traduction, du développement web et des ateliers de poterie.',
            'scenario' => 'supervision_content',
        ]);

        $response->assertOk();
        $response->assertSee('Validation humaine suggérée');
        $response->assertSee('Le contenu mêle plusieurs domaines');
    }

    public function test_skills_enum_in_schema_reflects_taxonomy_from_config(): void
    {
        $customSkills = [
            ['slug' => 'test-skill-a', 'label' => 'Test Skill A'],
            ['slug' => 'test-skill-b', 'label' => 'Test Skill B'],
        ];

        config(['ai.supervision.taxonomy.skills' => $customSkills]);

        Http::fake([
            'api.openai.com/*' => Http::response($this->fakeOpenAiResponse(array_merge($this->basePayload(), [
                'skills' => [],
            ])), 200),
        ]);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => 'Contenu de test skills enum override.',
            'scenario' => 'supervision_content',
        ])->assertOk();

        Http::assertSent(function ($request) use ($customSkills) {
            $enum = data_get($request->data(), 'text.format.schema.properties.skills.items.properties.slug.enum');

            $this->assertSame(array_column($customSkills, 'slug'), $enum);

            return true;
        });
    }

    public function test_non_audited_skill_slugs_absent_from_config_and_schema(): void
    {
        $forbiddenSlugs = ['graphisme', 'seo', 'formation-professionnelle'];

        $configSkillSlugs = array_column(config('ai.supervision.taxonomy.skills', []), 'slug');

        foreach ($forbiddenSlugs as $slug) {
            $this->assertNotContains($slug, $configSkillSlugs, "Slug non audité présent dans la config : {$slug}");
        }

        Http::fake([
            'api.openai.com/*' => Http::response($this->fakeOpenAiResponse($this->basePayload()), 200),
        ]);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => 'Contenu de test slugs non audités.',
            'scenario' => 'supervision_content',
        ])->assertOk();

        Http::assertSent(function ($request) use ($forbiddenSlugs) {
            $enum = data_get($request->data(), 'text.format.schema.properties.skills.items.properties.slug.enum');

            foreach ($forbiddenSlugs as $slug) {
                $this->assertNotContains($slug, $enum ?? [], "Slug non audité présent dans l'enum schema : {$slug}");
            }

            return true;
        });
    }

    public function test_category_enum_in_schema_reflects_taxonomy_from_config(): void
    {
        $customCategories = [
            ['slug' => 'test-slug-a', 'label' => 'Test A'],
            ['slug' => 'test-slug-b', 'label' => 'Test B'],
            ['slug' => 'autre',       'label' => 'Autre'],
        ];

        config(['ai.supervision.taxonomy.categories' => $customCategories]);

        Http::fake([
            'api.openai.com/*' => Http::response($this->fakeOpenAiResponse(array_merge($this->basePayload(), [
                'category' => ['slug' => 'autre', 'label' => 'Autre'],
            ])), 200),
        ]);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => 'Contenu de test taxonomy override.',
            'scenario' => 'supervision_content',
        ])->assertOk();

        Http::assertSent(function ($request) use ($customCategories) {
            $enum = data_get($request->data(), 'text.format.schema.properties.category.properties.slug.enum');

            $this->assertSame(array_column($customCategories, 'slug'), $enum);

            return true;
        });
    }

    public function test_free_form_category_strings_are_not_rendered_as_controlled_taxonomy(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response($this->fakeOpenAiResponse($this->basePayload()), 200),
        ]);

        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => 'Je propose des services de relecture et transcription.',
            'scenario' => 'supervision_content',
        ]);

        $response->assertOk();
        // Free-form terms from old schema must not appear as taxonomy badges
        $response->assertDontSee('demande_aide');
        $response->assertDontSee('services">');
        $response->assertDontSee('écriture">');
        // The controlled slug must be present
        $response->assertSee('redaction');
    }

    public function test_admin_can_use_clarify_help_request_scenario(): void
    {
        config(['ai.openai.supervision_enabled' => true]);

        Http::fake([
            'api.openai.com/*' => Http::response($this->fakeOpenAiResponse([
                'title' => 'Aide pour rédaction CV',
                'clarified_request' => 'Le membre cherche de l\'aide pour rédiger un CV professionnel.',
                'help_type' => 'service_offer',
                'suggested_category' => 'redaction',
                'suggested_loop' => 'Rédaction pro',
                'questions_for_user' => [],
                'publishable_draft' => 'Je propose mon aide pour la rédaction de CV.',
                'confidence' => 0.9,
                'needs_human_review' => false,
            ]), 200),
        ]);

        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => 'Je voudrais de l\'aide pour faire mon CV.',
            'scenario' => 'clarify_help_request',
        ]);

        $response->assertOk();
        $response->assertSee('Aide pour rédaction CV');
        $response->assertSee('service_offer');
        $response->assertSee('Confiance');
        $response->assertSee('90%');
    }

    public function test_clarify_help_request_vague_input_generates_questions(): void
    {
        config(['ai.openai.supervision_enabled' => true]);

        Http::fake([
            'api.openai.com/*' => Http::response($this->fakeOpenAiResponse([
                'title' => 'Demande vague',
                'clarified_request' => 'Le membre a un besoin non spécifié.',
                'help_type' => 'other',
                'suggested_category' => 'autre',
                'suggested_loop' => '',
                'questions_for_user' => [
                    'Quel type d\'aide recherchez-vous précisément ?',
                    'Dans quel domaine ?',
                ],
                'publishable_draft' => '',
                'confidence' => 0.3,
                'needs_human_review' => true,
            ]), 200),
        ]);

        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => 'Bonjour, j\'ai besoin d\'aide.',
            'scenario' => 'clarify_help_request',
        ]);

        $response->assertOk();
        $response->assertSee('Questions de clarification');
        $response->assertSee('Quel type d\'aide');
        $response->assertSee('Relecture humaine suggérée');
        $response->assertSee('30%');
    }

    public function test_clarify_help_request_payload_uses_store_false_and_json_schema(): void
    {
        config(['ai.openai.supervision_enabled' => true]);

        Http::fake([
            'api.openai.com/*' => Http::response($this->fakeOpenAiResponse([
                'title' => 'Test',
                'clarified_request' => 'Test.',
                'help_type' => 'information',
                'suggested_category' => 'autre',
                'suggested_loop' => '',
                'questions_for_user' => [],
                'publishable_draft' => '',
                'confidence' => 0.5,
                'needs_human_review' => false,
            ]), 200),
        ]);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => 'Test payload.',
            'scenario' => 'clarify_help_request',
        ])->assertOk();

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api.openai.com/v1/responses') {
                return false;
            }
            $body = $request->data();
            $this->assertFalse($body['store'] ?? true);
            $this->assertSame('json_schema', data_get($body, 'text.format.type'));
            $this->assertTrue(data_get($body, 'text.format.strict'));
            $this->assertSame('clarify_help_request', data_get($body, 'text.format.name'));
            $props = data_get($body, 'text.format.schema.properties');
            $this->assertArrayHasKey('title', $props);
            $this->assertArrayHasKey('clarified_request', $props);
            $this->assertArrayHasKey('help_type', $props);
            $this->assertArrayHasKey('questions_for_user', $props);
            $this->assertArrayHasKey('confidence', $props);
            $this->assertArrayHasKey('needs_human_review', $props);
            $this->assertArrayNotHasKey('risk_level', $props); // différent du schema supervision_content
            return true;
        });
    }

    public function test_supervision_content_still_works_after_adding_new_scenario(): void
    {
        // Vérifie que le scénario par défaut (supervision_content) n'est pas cassé
        Http::fake([
            'api.openai.com/*' => Http::response($this->fakeOpenAiResponse($this->basePayload()), 200),
        ]);

        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => 'Test de contenu pour supervision.',
            'scenario' => 'supervision_content',
        ]);

        $response->assertOk();
        $response->assertSee('Message neutre demandant de l\'aide.');
        $response->assertSee('Risque faible');
        $response->assertSee('Rédaction');
    }

    public function test_default_provider_is_ollama_when_enabled(): void
    {
        config(['ai.ollama.enabled' => true]);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->get(route('admin.ai-supervision'));
        $response->assertOk();
        $response->assertSee('Ollama (local)');
    }

    public function test_default_provider_is_openai_when_ollama_disabled_but_openai_supervision_enabled(): void
    {
        config([
            'ai.ollama.enabled' => false,
            'ai.openrouter.enabled' => false,
            'ai.openai.supervision_enabled' => true,
        ]);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->get(route('admin.ai-supervision'));
        $response->assertOk();
        $response->assertSee('OpenAI');
    }

    public function test_openai_not_shown_when_supervision_disabled(): void
    {
        config([
            'ai.ollama.enabled' => false,
            'ai.openrouter.enabled' => false,
            'ai.openai.supervision_enabled' => false,
        ]);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->get(route('admin.ai-supervision'));
        $response->assertOk();
        $response->assertDontSee('value="openai"', false);
        $response->assertSee('Aucun provider IA actif');
    }

    public function test_no_active_provider_shows_message(): void
    {
        config([
            'ai.ollama.enabled' => false,
            'ai.openrouter.enabled' => false,
            'ai.openai.supervision_enabled' => false,
        ]);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->get(route('admin.ai-supervision'));
        $response->assertOk();
        $response->assertSee('Aucun provider IA actif');
        $response->assertSee('OLLAMA_ENABLED=true');
    }

    public function test_ollama_is_first_provider_when_enabled(): void
    {
        config([
            'ai.ollama.enabled' => true,
            'ai.openai.supervision_enabled' => true,
        ]);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->get(route('admin.ai-supervision'));
        $response->assertOk();
        $response->assertSee('Ollama (local)');
    }

    public function test_clarify_help_request_hidden_when_openai_supervision_disabled(): void
    {
        config([
            'ai.ollama.enabled' => true,
            'ai.openai.supervision_enabled' => false,
        ]);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->get(route('admin.ai-supervision'));
        $response->assertOk();
        $response->assertDontSee('Clarification de demande');
    }

    public function test_clarify_help_request_visible_when_openai_supervision_enabled(): void
    {
        config([
            'ai.openai.supervision_enabled' => true,
        ]);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->get(route('admin.ai-supervision'));
        $response->assertOk();
        $response->assertSee('Clarification de demande');
    }

    public function test_analyze_rejects_clarify_when_openai_supervision_disabled(): void
    {
        config([
            'ai.ollama.enabled' => true,
            'ai.openai.supervision_enabled' => false,
        ]);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->from(route('admin.ai-supervision'))
            ->post(route('admin.ai-supervision.analyze'), [
                'content' => 'Test clarify.',
                'provider' => 'ollama',
                'scenario' => 'clarify_help_request',
            ]);

        $response->assertRedirect(route('admin.ai-supervision'));
        $response->assertSessionHas('error');
    }

    public function test_openrouter_visible_when_enabled(): void
    {
        config([
            'ai.openrouter.enabled' => true,
            'ai.openai.supervision_enabled' => false,
        ]);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->get(route('admin.ai-supervision'));
        $response->assertOk();
        $response->assertSee('OpenRouter');
        $response->assertDontSee('OpenAI');
    }

    public function test_clarify_help_request_is_not_supported_with_ollama(): void
    {
        config([
            'ai.ollama.enabled' => true,
            'ai.openai.supervision_enabled' => true,
        ]);

        Http::fake([
            'localhost:11434/*' => Http::response([
                'model' => 'llama3.2',
                'response' => json_encode($this->basePayload()),
                'done' => true,
            ], 200),
        ]);

        config(['ai.ollama.enabled' => true]);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => 'Test avec Ollama.',
            'provider' => 'ollama',
            'scenario' => 'clarify_help_request',
        ]);

        $response->assertOk();
        $response->assertSee('nécessite OpenAI');
    }

    public function test_clarify_help_request_is_not_supported_with_openrouter(): void
    {
        config([
            'ai.openrouter.enabled' => true,
            'ai.openai.supervision_enabled' => true,
        ]);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => 'Test avec OpenRouter.',
            'provider' => 'openrouter',
            'scenario' => 'clarify_help_request',
        ]);

        $response->assertOk();
        $response->assertSee('nécessite OpenAI');
    }

    public function test_supervision_content_with_ollama_provider(): void
    {
        Http::fake([
            'localhost:11434/*' => Http::response([
                'model' => 'llama3.2',
                'response' => json_encode($this->basePayload()),
                'done' => true,
            ], 200),
        ]);

        config(['ai.ollama.enabled' => true]);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => 'Test avec Ollama.',
            'provider' => 'ollama',
            'scenario' => 'supervision_content',
        ]);

        $response->assertOk();
        $response->assertSee('Message neutre demandant de l\'aide.');
    }

    public function test_supervision_content_with_openrouter_provider(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode($this->basePayload()),
                    ],
                ]],
                'model' => 'openai/gpt-4o-mini',
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 80,
                ],
            ], 200),
        ]);

        config([
            'ai.openrouter.enabled' => true,
            'ai.openrouter.api_key' => 'test-key',
        ]);

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => 'Test avec OpenRouter.',
            'provider' => 'openrouter',
            'scenario' => 'supervision_content',
        ]);

        $response->assertOk();
        $response->assertSee('Message neutre demandant de l\'aide.');
    }
}
