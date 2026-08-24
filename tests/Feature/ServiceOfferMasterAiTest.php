<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServiceOfferMasterAiTest extends TestCase
{
    private Organization $org;

    private User $user;

    private User $otherOrgUser;

    private Category $cat1;

    private Category $cat2;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.openrouter.enabled' => true,
            'ai.openrouter.api_key' => 'sk-test-openrouter-'.bin2hex(random_bytes(8)),
            'ai.openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'ai.openrouter.model' => 'mistralai/ministral-3b-2512',
            'ai.openrouter.timeout' => 15,
            'ai.openrouter.max_output_tokens' => 900,
            'ai.openai.supervision_enabled' => false,
            'ai.ollama.enabled' => false,
        ]);

        Http::preventStrayRequests();

        $this->org = Organization::factory()->create([
            'service_points_min' => 20,
            'service_points_max' => 200,
            'locale' => 'fr',
        ]);

        $this->cat1 = Category::factory()->create([
            'organization_id' => $this->org->id,
            'name_b2c' => 'Développement Web',
            'slug' => 'dev-web',
        ]);

        $this->cat2 = Category::factory()->create([
            'organization_id' => $this->org->id,
            'name_b2c' => 'Design Graphique',
            'slug' => 'design',
        ]);

        $this->user = User::factory()->create([
            'organization_id' => $this->org->id,
            'first_name' => 'Jean',
            'phone' => '0123456789',
            'city' => 'Paris',
            'country_code' => 'FR',
            'bio' => 'Test bio for profile completeness.',
        ]);

        $otherOrg = Organization::factory()->create();
        Category::factory()->create([
            'organization_id' => $otherOrg->id,
            'name_b2c' => 'Catégorie Externe',
            'slug' => 'external',
        ]);

        $this->otherOrgUser = User::factory()->create([
            'organization_id' => $otherOrg->id,
            'first_name' => 'Jane',
            'phone' => '0987654321',
            'city' => 'Lyon',
            'country_code' => 'FR',
            'bio' => 'Other user bio.',
        ]);

        app()->instance('current_organization', $this->org);
    }

    private function fakeAiResponse(array $payload): array
    {
        return [
            'id' => 'chatcmpl-test-'.uniqid(),
            'object' => 'chat.completion',
            'model' => 'mistralai/ministral-3b-2512',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode($payload),
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 200,
                'completion_tokens' => 100,
                'total_tokens' => 300,
            ],
        ];
    }

    // ── Authorization & Tenant ──

    public function test_authenticated_user_in_organization_can_call_formulate(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Développement de site vitrine en Laravel',
                'description_markdown' => "Je vous propose un développement complet de site vitrine avec Laravel.\n\n## Ce que je propose\n\n- Analyse de vos besoins\n- Design responsive",
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'remote',
                'points_cost' => 50,
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Je fais des sites web',
                'description' => 'Je peux créer des sites internet pour votre entreprise.',
            ]);

        $response->assertOk();
        $response->assertJsonPath('suggestion.title', 'Développement de site vitrine en Laravel');
    }

    public function test_guest_cannot_call_formulate(): void
    {
        $response = $this->postJson('/services/ai-formulate', [
            'title' => 'Some intention',
        ]);

        $response->assertUnauthorized();
    }

    /**
     * TASK-1291 : ce que le nom historique promettait sans le tenir. Avant le
     * correctif, l'etranger REUSSISSAIT (200) — seule la categorie cross-org
     * etait rejetee. Desormais la garde d'appartenance (meme regle que
     * RequestController::organizationFor()) refuse fail-closed AVANT tout
     * appel provider.
     */
    public function test_user_from_other_org_cannot_call_formulate(): void
    {
        // Ambiante = $this->org (setUp) ; l'acteur appartient a l'autre
        // Organization : etranger au tenant de la requete.
        $this->actingAs($this->otherOrgUser)
            ->postJson('/services/ai-formulate', [
                'title' => 'test',
                'description' => 'test description',
            ])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    /**
     * L'ancien corps du test ci-dessus, sous son vrai nom : un MEMBRE de
     * l'Organization courante formule, et la categorie cross-org renvoyee
     * par l'IA est rejetee par validateAiSuggestion() (repli chaine vide).
     */
    public function test_cross_org_category_in_ai_response_falls_back_to_empty_for_a_member(): void
    {
        $otherOrg = Organization::where('id', '!=', $this->org->id)->first();
        app()->instance('current_organization', $otherOrg);

        // The AI response returns $this->cat1->id, which belongs to $this->org, NOT $otherOrg.
        // validateAiSuggestion() must reject the cross-org category and fall back to empty string.
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Test externe suffisamment long',
                'description_markdown' => 'Une description de test assez longue pour passer la validation minimum de cent caractères pour le test.',
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'remote',
                'points_cost' => 50,
            ]), 200),
        ]);

        // L'acteur est MEMBRE de $otherOrg : la garde d'appartenance passe,
        // c'est bien la validation de la suggestion qui est mesuree.
        $response = $this->actingAs($this->otherOrgUser)
            ->postJson('/services/ai-formulate', [
                'title' => 'test',
                'description' => 'test description',
            ]);

        $response->assertOk();
        $this->assertEquals('', $response->json('suggestion.category_id'));
    }

    public function test_org_points_bounds_are_enforced(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Service dans les bornes de points bien',
                'description_markdown' => 'Un service bien décrit avec une description suffisamment longue pour dépasser les cent caractères minimum requis.',
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'remote',
                'points_cost' => 500, // Above org max (200)
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Test',
                'description' => 'Description test',
                'points_cost' => '30',
            ]);

        $response->assertOk();
        // points_cost=500 should be rejected (above max), falls back to current value (30)
        $this->assertEquals(30, $response->json('suggestion.points_cost'));
    }

    public function test_org_points_min_bounds_enforced(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Service dans les bornes min bien',
                'description_markdown' => 'Une proposition de service bien rédigée et complète, avec assez de contenu pour valider le seuil de cent caractères facilement.',
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'remote',
                'points_cost' => 5, // Below org min (20)
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Test',
                'description' => 'Test description',
                'points_cost' => '50',
            ]);

        $response->assertOk();
        // points_cost=5 rejected (below min), falls back to 50
        $this->assertEquals(50, $response->json('suggestion.points_cost'));
    }

    // ── AI Response Validation ──

    public function test_valid_structured_response_is_accepted(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Création de votre site web sur mesure',
                'description_markdown' => "## Développement Web\n\nJe vous propose de créer votre site internet de A à Z.\n\n- Design responsive\n- Optimisé SEO\n- Administration simple",
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'remote',
                'points_cost' => 60,
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Site web',
                'description' => 'Je veux créer un site internet pour une entreprise.',
            ]);

        $response->assertOk()
            ->assertJsonPath('suggestion.title', 'Création de votre site web sur mesure')
            ->assertJsonPath('suggestion.category_id', $this->cat1->id)
            ->assertJsonPath('suggestion.delivery_mode', 'remote')
            ->assertJsonPath('suggestion.points_cost', 60);
    }

    public function test_invalid_json_response_returns_error(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'id' => 'chatcmpl-test-invalid',
                'object' => 'chat.completion',
                'model' => 'mistralai/ministral-3b-2512',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'this is not valid json at all',
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 20,
                    'completion_tokens' => 10,
                    'total_tokens' => 30,
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Test',
                'description' => 'Test description for AI formulation.',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', __('ai.service_formulation_error'));
    }

    public function test_invalid_category_rejected_and_falls_back(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Formation en design et UX',
                'description_markdown' => 'Je vous propose une formation complète en design UX/UI, adaptée à vos besoins et votre niveau actuel.',
                'category_id' => '11111111-1111-1111-1111-111111111111', // Not a real category
                'delivery_mode' => 'onsite',
                'points_cost' => 80,
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Test',
                'description' => 'Test description',
                'category_id' => $this->cat2->id, // Current selected category
            ]);

        $response->assertOk();
        // Invalid category should fall back to the current value
        $this->assertEquals($this->cat2->id, $response->json('suggestion.category_id'));
    }

    public function test_invalid_delivery_mode_rejected_and_falls_back(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Conseil en stratégie digitale',
                'description_markdown' => 'Je vous accompagne dans la définition de votre stratégie digitale, de l\'audit initial au plan d\'action concret.',
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'teleportation', // Invalid mode
                'points_cost' => 40,
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Test',
                'description' => 'Test description',
                'delivery_mode' => 'both', // Current mode
            ]);

        $response->assertOk();
        $this->assertEquals('both', $response->json('suggestion.delivery_mode'));
    }

    public function test_title_too_short_rejected_and_falls_back(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Court', // Under 10 chars
                'description_markdown' => 'Je vous propose de développer une application web sur mesure avec les dernières technologies.',
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'remote',
                'points_cost' => 40,
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Titre actuel suffisant',
                'description' => 'Test description for AI formulation with enough characters.',
            ]);

        $response->assertOk();
        $this->assertEquals('Titre actuel suffisant', $response->json('suggestion.title'));
    }

    public function test_description_too_short_rejected_and_falls_back(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Audit de sécurité pour votre entreprise',
                'description_markdown' => 'Trop court.', // Under 100 chars
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'remote',
                'points_cost' => 30,
            ]), 200),
        ]);

        $currentDesc = 'Ma description actuelle qui est assez longue pour passer la validation minimale de cent caractères sans aucun problème.';
        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Test',
                'description' => $currentDesc,
            ]);

        $response->assertOk();
        $this->assertEquals($currentDesc, $response->json('suggestion.description_markdown'));
    }

    public function test_missing_fields_fallback_to_current(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Développement application mobile',
                // Missing: description_markdown, category_id, delivery_mode, points_cost
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'App mobile',
                'description' => 'Je développe des applications mobiles iOS et Android avec React Native et Flutter.',
                'category_id' => $this->cat2->id,
                'delivery_mode' => 'onsite',
                'points_cost' => '100',
            ]);

        $response->assertOk();
        $this->assertEquals('Développement application mobile', $response->json('suggestion.title'));
        $this->assertEquals('Je développe des applications mobiles iOS et Android avec React Native et Flutter.', $response->json('suggestion.description_markdown'));
        $this->assertEquals($this->cat2->id, $response->json('suggestion.category_id'));
        $this->assertEquals('onsite', $response->json('suggestion.delivery_mode'));
        $this->assertEquals(100, $response->json('suggestion.points_cost'));
    }

    public function test_html_in_description_is_stripped(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Formation en ligne pour équipes',
                'description_markdown' => "<h1>Formation</h1><p>Je vous propose une <b>formation</b> complète pour vos équipes techniques, couvrant le développement web, l'architecture logicielle et les bonnes pratiques de travail en équipe.</p><script>alert('xss')</script>",
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'remote',
                'points_cost' => 75,
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Test formation',
                'description' => 'Je veux proposer une formation en ligne pour des équipes techniques.',
            ]);

        $response->assertOk();
        $desc = $response->json('suggestion.description_markdown');
        $this->assertStringNotContainsString('<script>', $desc);
        $this->assertStringNotContainsString('<h1>', $desc);
        $this->assertStringNotContainsString('<b>', $desc);
        $this->assertStringContainsString('Formation', $desc);
    }

    public function test_empty_ai_response_keeps_current_values(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'id' => 'chatcmpl-test-empty',
                'object' => 'chat.completion',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => ''],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 0, 'total_tokens' => 20],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Titre existant valide',
                'description' => 'La description actuelle de l\'utilisateur est suffisamment longue pour dépasser cent caractères facilement.',
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'both',
                'points_cost' => '50',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', __('ai.service_formulation_error'));
    }

    public function test_negative_points_cost_rejected(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Conseil RH pour PME et startups',
                'description_markdown' => 'Je vous propose un service de conseil en ressources humaines, adapté aux besoins des PME et startups.',
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'onsite',
                'points_cost' => -10,
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Test RH',
                'description' => 'Test description RH pour les besoins de l\'entreprise.',
                'points_cost' => '30',
            ]);

        $response->assertOk();
        $this->assertEquals(30, $response->json('suggestion.points_cost'));
    }

    // ── Localization ──

    public function test_french_locale_produces_french_response(): void
    {
        app()->setLocale('fr');

        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Audit et optimisation SEO avancé',
                'description_markdown' => "## Optimisation SEO\n\nJe réalise un audit complet de votre site et je mets en place les optimisations nécessaires.\n\n- Analyse des mots-clés\n- Optimisation on-page\n- Suivi des performances",
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'remote',
                'points_cost' => 90,
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'SEO',
                'description' => 'Amélioration du référencement naturel de sites web.',
            ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('suggestion.title'));
    }

    public function test_english_locale_produces_english_response(): void
    {
        app()->setLocale('en');

        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Professional web development services',
                'description_markdown' => "## Web Development\n\nI offer professional web development services including custom websites and web applications.\n\n- Full-stack development\n- Responsive design\n- SEO optimization",
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'remote',
                'points_cost' => 80,
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Web dev',
                'description' => 'I want to offer web development services for businesses in need of a website.',
            ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('suggestion.title'));
    }

    // ── Service Create Form Regression ──

    public function test_create_form_still_works_without_ai(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/services/create');

        $response->assertOk();
        $response->assertSee(__('ai.service_formulate_cta'));
        $response->assertSee('name="title', false);
        $response->assertSee('name="description', false);
    }

    // ── AI Provider Errors ──

    public function test_provider_timeout_returns_graceful_error(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response('', 504),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Test',
                'description' => 'Test description for timeout scenario testing.',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', __('ai.service_formulation_error'));
    }

    public function test_service_provider_unavailable_returns_error(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response('', 503),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Test',
                'description' => 'Test description for unavailable provider testing.',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', __('ai.service_formulation_error'));
    }

    public function test_no_provider_configured_returns_unavailable(): void
    {
        config([
            'ai.openrouter.enabled' => false,
            'ai.ollama.enabled' => false,
            'ai.openai.supervision_enabled' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Test',
                'description' => 'Test description for no provider scenario.',
            ]);

        $response->assertStatus(503);
    }

    public function test_empty_title_and_description_returns_422(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => '',
                'description' => '',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', __('ai.service_formulation_intention_required'));
    }

    public function test_whitespace_only_title_and_description_returns_422(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => '   ',
                'description' => "\t\n  ",
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', __('ai.service_formulation_intention_required'));
    }

    public function test_title_only_allows_formulation(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Développement de sites web professionnels',
                'description_markdown' => 'Je vous propose un service complet de création de sites web sur mesure, adapté à vos besoins spécifiques et à votre budget.',
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'remote',
                'points_cost' => 50,
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Développement web',
                'description' => '',
            ]);

        $response->assertStatus(200);
        $this->assertArrayHasKey('title', $response->json('suggestion'));
    }

    public function test_description_only_allows_formulation(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Développement de sites web professionnels sur mesure',
                'description_markdown' => 'Je vous propose un service complet de création de sites web sur mesure, adapté à vos besoins spécifiques et à votre budget.',
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'remote',
                'points_cost' => 50,
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => '',
                'description' => 'Je veux créer un site web pour mon entreprise.',
            ]);

        $response->assertStatus(200);
        $this->assertArrayHasKey('title', $response->json('suggestion'));
    }

    public function test_provider_not_called_when_both_fields_empty(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => '',
                'description' => '',
            ]);

        $response->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_intention_required_message_in_french(): void
    {
        app()->setLocale('fr');

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => '',
                'description' => '',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Décrivez au moins', $response->json('error'));
    }

    public function test_intention_required_message_in_english(): void
    {
        $this->org->update(['locale' => 'en']);
        app()->setLocale('en');

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => '',
                'description' => '',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Enter at least', $response->json('error'));
    }

    // ── description_preview_html ──

    public function test_description_preview_html_renders_markdown(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Développement Web React',
                'description_markdown' => "Je vous propose **du développement web** React et Next.js avec une expertise solide en TypeScript.\n\n## Pourquoi moi\n\n- Expert React depuis 8 ans\n- 5 ans d'expérience en production\n- Approche pédagogique et rigoureuse",
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'remote',
                'points_cost' => 150,
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', ['title' => 'Dev Web']);

        $response->assertOk();
        $suggestion = $response->json('suggestion');
        $this->assertIsString($suggestion['description_preview_html']);
        $this->assertNotEmpty($suggestion['description_preview_html']);
        $this->assertStringContainsString('<strong>', $suggestion['description_preview_html']);
        $this->assertStringNotContainsString('**', $suggestion['description_preview_html']);
        $this->assertStringContainsString('<h2>', $suggestion['description_preview_html']);
        $this->assertStringContainsString('<ul>', $suggestion['description_preview_html']);
        $this->assertStringContainsString('<li>', $suggestion['description_preview_html']);
    }

    public function test_description_markdown_unchanged_after_preview_generation(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Développement Web React',
                'description_markdown' => 'Je vous propose **du développement web** React avec une expertise approfondie en TypeScript, Next.js et Tailwind CSS pour des projets professionnels de qualité.',
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'remote',
                'points_cost' => 150,
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', ['title' => 'Dev Web']);

        $response->assertOk();
        $suggestion = $response->json('suggestion');
        $this->assertStringContainsString('**', $suggestion['description_markdown']);
        $this->assertStringContainsString('du développement web', $suggestion['description_markdown']);
    }

    public function test_raw_html_in_ai_response_is_stripped_before_preview(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Design Graphique Pro',
                'description_markdown' => '<script>alert(1)</script> Je propose du design graphique professionnel avec une longue description détaillée pour atteindre la limite de caractères exigée par la plateforme.',
                'category_id' => $this->cat2->id,
                'delivery_mode' => 'remote',
                'points_cost' => 100,
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', ['title' => 'Design']);

        $response->assertOk();
        $suggestion = $response->json('suggestion');

        $this->assertStringNotContainsString('<script>', $suggestion['description_preview_html']);
        $this->assertStringNotContainsString('</script>', $suggestion['description_preview_html']);
        $this->assertStringNotContainsString('<script>', $suggestion['description_markdown']);
        $this->assertStringNotContainsString('</script>', $suggestion['description_markdown']);
        $this->assertStringNotContainsString('<', $suggestion['description_markdown']);
        $this->assertStringNotContainsString('>', $suggestion['description_markdown']);
    }

    public function test_img_onerror_in_ai_response_is_stripped_before_preview(): void
    {
        $descriptionWithOnError = '<img src=x onerror=alert(1)> Je propose du design graphique professionnel avec une longue description détaillée pour atteindre la limite de caractères de la plateforme.';

        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Design Graphique Pro',
                'description_markdown' => $descriptionWithOnError,
                'category_id' => $this->cat2->id,
                'delivery_mode' => 'remote',
                'points_cost' => 100,
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', ['title' => 'Design']);

        $response->assertOk();
        $suggestion = $response->json('suggestion');

        $this->assertStringNotContainsString('<img', $suggestion['description_preview_html']);
        $this->assertStringNotContainsString('onerror', $suggestion['description_preview_html']);
        $this->assertStringNotContainsString('alert(1)', $suggestion['description_preview_html']);
    }

    public function test_dangerous_markdown_link_is_escaped_in_preview(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Développement Web React',
                'description_markdown' => "Cliquez [ici](javascript:alert('xss')) pour plus d'infos sur mon service de développement web professionnel avec une longue description.",
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'remote',
                'points_cost' => 150,
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', ['title' => 'Dev Web']);

        $response->assertOk();
        $suggestion = $response->json('suggestion');

        $this->assertStringNotContainsString('javascript:', $suggestion['description_preview_html']);
    }

    public function test_preview_html_fallback_current_description_is_safe(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'T',
                'description_markdown' => 'Court',
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'remote',
                'points_cost' => 150,
            ]), 200),
        ]);

        $currentDescription = 'Je propose **du coaching** en développement personnel.';
        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Coaching personnel pour améliorer vos compétences professionnelles',
                'description' => $currentDescription,
            ]);

        $response->assertOk();
        $suggestion = $response->json('suggestion');

        $this->assertStringContainsString('du coaching', $suggestion['description_markdown']);
        $this->assertStringContainsString('<strong>', $suggestion['description_preview_html']);
        $this->assertStringNotContainsString('<script', $suggestion['description_preview_html']);
    }

    public function test_preview_html_not_accepted_from_request(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Développement Web React',
                'description_markdown' => 'Je vous propose **du développement web**.',
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'remote',
                'points_cost' => 150,
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Dev Web',
                'description_preview_html' => '<script>alert(1)</script>',
            ]);

        $response->assertOk();
        $suggestion = $response->json('suggestion');
        $this->assertStringNotContainsString('<script>', $suggestion['description_preview_html']);
    }

    public function test_compacted_single_line_description_is_normalized_into_markdown(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Création de logo professionnel',
                'description_markdown' => "Concept visuel : Design d'un logo moderne et reconnaissable pour votre pizzeria, incluant les éléments clés (forme, couleurs, typographie) en cohérence avec votre identité de marque (ex : pizza, ambiance chaleureuse, etc.).- Services inclus :  - Modèles adaptés aux réseaux sociaux et affiches.  - Version vectorielle (éditable) et haute résolution.  - Conseils sur les couleurs et polices pour une identité harmonieuse.- Personnalisation :  - Adaptation aux besoins spécifiques (ex : mention de votre nom, slogan, ou éléments locaux).  - Livraison sous 48h (si commande prioritaire).- Expertise : Utilisation d'outils professionnels (Adobe Illustrator, Canva Pro) pour un rendu pro et scalable.À fournir par vous :  - Palette de couleurs préférées.  - Style général souhaité (traditionnel, moderne, etc.).  - Éléments graphiques existants (si disponibles).",
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'remote',
                'points_cost' => 60,
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Logo',
            ]);

        $response->assertOk();
        $suggestion = $response->json('suggestion');

        $descMarkdown = $suggestion['description_markdown'];
        $descPreview = $suggestion['description_preview_html'];

        // The markdown should no longer be a single compacted line
        $this->assertStringContainsString("\n\n", $descMarkdown);
        $this->assertStringContainsString('-', $descMarkdown);

        // The preview HTML should contain proper list structure, not a single <li>
        $this->assertStringContainsString('<ul>', $descPreview);
        $this->assertStringContainsString('<li>', $descPreview);
        $this->assertStringContainsString('</li>', $descPreview);
        // Intro should be in its own paragraph
        $this->assertStringContainsString('<p>Concept visuel', $descPreview);
        // Should NOT be a single flat line — should contain at least one paragraph break
        $this->assertStringContainsString('</p>', $descPreview);
        $this->assertGreaterThan(2, substr_count($descPreview, '<p>') + substr_count($descPreview, '<li>'));
    }

    public function test_well_formed_markdown_from_llm_is_preserved(): void
    {
        $wellFormed = "## Ce que je propose\n\nCréation d'un logo professionnel pour votre pizzeria.\n\n## Services inclus\n\n- Design vectoriel\n- Haute résolution\n- Conseils couleurs et polices\n\n## À fournir par vous\n\n- Palette de couleurs\n- Style souhaité";

        Http::fake([
            'openrouter.ai/*' => Http::response($this->fakeAiResponse([
                'title' => 'Création de logo professionnel',
                'description_markdown' => $wellFormed,
                'category_id' => $this->cat1->id,
                'delivery_mode' => 'remote',
                'points_cost' => 60,
            ]), 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/services/ai-formulate', [
                'title' => 'Logo',
            ]);

        $response->assertOk();
        $suggestion = $response->json('suggestion');

        $descMarkdown = $suggestion['description_markdown'];

        // Well-formed markdown should pass through the normalizer unchanged
        $this->assertStringContainsString('## Ce que je propose', $descMarkdown);
        $this->assertStringContainsString('## Services inclus', $descMarkdown);
        $this->assertStringContainsString('## À fournir par vous', $descMarkdown);
        $this->assertStringContainsString('- Design vectoriel', $descMarkdown);
    }
}
