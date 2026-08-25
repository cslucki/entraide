<?php

namespace Tests\Feature;

use App\Models\AdminAiPrompt;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiUserCreditSettings;
use App\Services\BlogAiService;
use App\Support\Ai\AiRefusedException;
use App\Support\Ai\BlogExplorerFacilitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1249 — les quatre methodes de facilitation de Roger dans le chat
 * Explorer d'article : `method_code` explicite (identifiants canoniques de
 * `BlogAiService::METHOD_SELECTION_METHODS`), definition methodologique
 * courte resolue par le repository `AdminAiPrompt` (scenario
 * `blog_explorer_method_{method}_{locale}`, fallback `_fr`, puis fallback
 * code jamais vide), regles de facilitation toujours presentes, posture
 * DISTINCTE par methode, garde economique T1248 intacte, `callProvider()`
 * aveugle a la methode (ledger/trace inchanges).
 */
#[Group('ai')]
class TASK1249BlogExplorerFacilitationMethodsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $author;

    private User $admin;

    private BlogPost $post;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai_pricing.version' => 'test-catalog',
            'ai_pricing.overrides' => [],
            'ai_pricing.models' => [
                'openai' => [
                    'gpt-catalogued' => ['input_per_1m' => 1.0, 'output_per_1m' => 4.0],
                ],
            ],
            'ai.default_provider' => 'openai',
            'ai.default_model' => null,
            'ai.openai.api_key' => 'platform-test-key',
            'ai.openai.model' => 'gpt-catalogued',
            'ai.openai.base_url' => 'https://api.openai.test/v1',
            'ai.blog.economic_guard.monthly_budget_usd' => 2.00,
            'ai.blog.economic_guard.monthly_unknown_limit' => 10,
        ]);

        $this->organization = Organization::factory()->create(['is_active' => true, 'slug' => 'orga-1249']);

        $this->author = User::factory()->create(['organization_id' => $this->organization->id, 'preferred_locale' => 'fr']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'is_admin' => true]);

        $this->post = BlogPost::create([
            'user_id' => $this->author->id,
            'organization_id' => $this->organization->id,
            'title' => 'Article TASK-1249 sur la facilitation',
            'slug' => 'article-task-1249',
            'content' => '<p>'.str_repeat('Un contenu sauvegarde que Roger aide a questionner. ', 12).'</p>',
            'summary' => 'Resume T1249',
            'status' => 'draft',
        ]);

        app()->instance('current_organization', $this->organization);
        app()->setLocale('fr');

        Http::preventStrayRequests();
    }

    private function fakeChatCompletion(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => 'Un angle : les faits. Quelle donnee soutient votre premiere affirmation ?']]],
                'usage' => ['prompt_tokens' => 800, 'completion_tokens' => 60],
            ]),
        ]);
    }

    private function chat(array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->author)
            ->postJson(route('blog.explorer.chat', $this->post), $payload + ['message' => 'Par ou commencer ?']);
    }

    /**
     * Le prompt systeme effectivement envoye au provider (premier message).
     */
    private function sentSystemPrompt(): string
    {
        $system = null;
        Http::assertSent(function (ClientRequest $request) use (&$system) {
            $payload = $request->data();
            $system = $payload['messages'][0]['content'] ?? null;

            return ($payload['messages'][0]['role'] ?? null) === 'system';
        });

        $this->assertIsString($system);

        return $system;
    }

    // =====================================================================
    // A. Validation du method_code
    // =====================================================================

    public function test_the_method_codes_are_exactly_the_canonical_ones_shared_with_the_selection_suggestion(): void
    {
        $this->assertEqualsCanonicalizing(['explorer', 'slow_down', 'clarifier', 'invent'], BlogAiService::METHOD_SELECTION_METHODS);
        $this->assertSame(BlogAiService::METHOD_SELECTION_METHODS, BlogExplorerFacilitation::methods());
    }

    public function test_an_unknown_method_code_is_rejected_before_any_call(): void
    {
        Http::fake();

        $this->chat(['method_code' => 'six_hats'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['method_code']);

        // Aussi un ancien nom jamais adopte pour la meme notion.
        $this->chat(['method_code' => 'clarify'])->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(0, AiProviderInvocation::query()->count());
        $this->assertSame(0, AiInteraction::query()->count());
    }

    public function test_without_method_code_or_with_null_the_historical_generic_prompt_is_unchanged(): void
    {
        $generic = AdminAiPrompt::where('scenario_id', 'blog_explorer_dialogue_fr')->where('is_active', true)->value('prompt_text');
        $this->assertIsString($generic, 'Le scenario generique historique est seme par migration.');

        $this->fakeChatCompletion();
        $this->chat([])->assertOk()->assertJsonStructure(['text']);
        $systemWithout = $this->sentSystemPrompt();

        Http::fake(); // reset du journal
        $this->fakeChatCompletion();
        $this->chat(['method_code' => null])->assertOk();
        $systemNull = $this->sentSystemPrompt();

        foreach ([$systemWithout, $systemNull] as $system) {
            $this->assertStringStartsWith($generic, $system, 'Sans methode : le prompt generique ouvre le systeme, comme avant.');
            $this->assertStringContainsString('ARTICLE SAUVEGARDÉ À ANALYSER', $system);
            $this->assertStringContainsString('Article TASK-1249 sur la facilitation', $system);
            $this->assertStringNotContainsString('RÈGLES DE FACILITATION', $system, 'Le bloc de facilitation n\'apparait qu\'avec une methode.');
        }
    }

    // =====================================================================
    // B. Une posture distincte par methode, facilitation toujours presente
    // =====================================================================

    public function test_each_method_yields_a_distinct_system_prompt_carrying_its_posture_the_facilitation_rules_and_the_article(): void
    {
        $markers = [
            'explorer' => ['« Explorer »', 'angle', 'faits', 'ressentis', 'risques', 'opportunités', 'alternatives'],
            'slow_down' => ['« Ralentir »', 'suspend', 'système', 'hypothèses', 'signaux faibles', 'action', 'réversible'],
            'clarifier' => ['« Clarifier »', 'termes', 'affirmations', 'hypothèses', 'points de vue', 'désaccords'],
            'invent' => ['« Inventer »', 'analogie', 'modél', 'inverser', 'échelle', 'rapprochement inattendu'],
        ];

        $systems = [];

        foreach (BlogAiService::METHOD_SELECTION_METHODS as $method) {
            Http::fake();
            $this->fakeChatCompletion();

            $this->chat(['method_code' => $method])->assertOk()->assertJsonStructure(['text']);
            $system = $this->sentSystemPrompt();
            $systems[$method] = $system;

            foreach ($markers[$method] as $marker) {
                $this->assertStringContainsStringIgnoringCase($marker, $system, "Posture « {$method} » : marqueur « {$marker} » attendu.");
            }

            // Contrainte 6 : facilitation, jamais directive — portee par le prompt systeme.
            $this->assertStringContainsString('RÈGLES DE FACILITATION', $system);
            $this->assertStringContainsString('jamais directif', $system);
            $this->assertStringContainsString('Jamais toute la méthode en un seul message', $system);
            $this->assertStringContainsString('Tu ne réponds pas à la place de l\'humain', $system);
            $this->assertStringContainsString('Tu ne passes jamais automatiquement à l\'étape suivante', $system);
            $this->assertStringContainsString('La validation humaine est toujours l\'étape finale', $system);

            // L'article et son contexte, comme avant.
            $this->assertStringContainsString('ARTICLE SAUVEGARDÉ À ANALYSER', $system);
            $this->assertStringContainsString('Article TASK-1249 sur la facilitation', $system);
            $this->assertStringContainsString('Resume T1249', $system);

            // Le prompt generique « six perspectives » ne se superpose PAS a la methode.
            $this->assertStringNotContainsString('Six perspectives', $system);
        }

        $this->assertCount(4, array_unique($systems), 'Quatre prompts systeme distincts, pas une variation cosmetique.');

        // Distinction reelle : le marqueur propre d'une methode est absent des trois autres.
        $this->assertStringNotContainsString('« Ralentir »', $systems['explorer']);
        $this->assertStringNotContainsString('« Explorer »', $systems['slow_down']);
        $this->assertStringNotContainsString('« Inventer »', $systems['clarifier']);
        $this->assertStringNotContainsString('« Clarifier »', $systems['invent']);
    }

    public function test_the_big_reference_texts_are_not_injected_at_runtime(): void
    {
        $this->fakeChatCompletion();
        $this->chat(['method_code' => 'clarifier'])->assertOk();
        $system = $this->sentSystemPrompt();

        // Definition courte + regles + article : bien en dessous d'une reference
        // de 15-20 Ko. Le contexte article de ce test fait ~700 caracteres.
        $this->assertLessThan(4_500, mb_strlen($system), 'Le prompt systeme reste une definition courte, pas la reference methodologique complete.');
        $this->assertStringNotContainsString('mouvement 1', mb_strtolower($system));
    }

    // =====================================================================
    // C. Repository AdminAiPrompt : override admin, fallback _fr, fallback code
    // =====================================================================

    public function test_an_active_admin_prompt_for_the_method_overrides_the_coded_default_and_the_rules_still_follow(): void
    {
        AdminAiPrompt::create([
            'scenario_id' => BlogExplorerFacilitation::scenarioId('slow_down', 'fr'),
            'name' => 'Ralentir v1',
            'prompt_text' => 'DEFINITION ADMIN RALENTIR V1 : suspends et observe le systeme.',
            'version' => 1,
            'is_active' => true,
        ]);
        AdminAiPrompt::create([
            'scenario_id' => BlogExplorerFacilitation::scenarioId('slow_down', 'fr'),
            'name' => 'Ralentir v2',
            'prompt_text' => 'DEFINITION ADMIN RALENTIR V2 : suspends, observe, signaux faibles.',
            'version' => 2,
            'is_active' => true,
        ]);
        AdminAiPrompt::create([
            'scenario_id' => BlogExplorerFacilitation::scenarioId('slow_down', 'fr'),
            'name' => 'Ralentir v3 inactive',
            'prompt_text' => 'DEFINITION ADMIN RALENTIR V3 INACTIVE.',
            'version' => 3,
            'is_active' => false,
        ]);

        $this->fakeChatCompletion();
        $this->chat(['method_code' => 'slow_down'])->assertOk();
        $system = $this->sentSystemPrompt();

        $this->assertStringStartsWith('DEFINITION ADMIN RALENTIR V2', $system, 'Version active la plus haute du repository.');
        $this->assertStringNotContainsString('V3 INACTIVE', $system);
        $this->assertStringNotContainsString('F. David Peat', $system, 'Le fallback code n\'est pas concatene a la definition admin.');
        $this->assertStringContainsString('RÈGLES DE FACILITATION', $system, 'Les regles de facilitation suivent TOUJOURS, quelle que soit la definition admin.');
        $this->assertStringContainsString('ARTICLE SAUVEGARDÉ À ANALYSER', $system);
    }

    public function test_an_inactive_admin_prompt_falls_back_to_the_coded_default_never_empty(): void
    {
        AdminAiPrompt::create([
            'scenario_id' => BlogExplorerFacilitation::scenarioId('invent', 'fr'),
            'name' => 'Inventer inactive',
            'prompt_text' => 'DEFINITION ADMIN INVENTER INACTIVE.',
            'version' => 1,
            'is_active' => false,
        ]);

        $this->fakeChatCompletion();
        $this->chat(['method_code' => 'invent'])->assertOk();
        $system = $this->sentSystemPrompt();

        $this->assertStringNotContainsString('INACTIVE', $system);
        $this->assertStringStartsWith(BlogExplorerFacilitation::defaultPrompt('invent', 'fr'), $system);
    }

    public function test_the_english_locale_resolves_the_en_scenario_then_the_fr_admin_row_then_the_coded_english_default(): void
    {
        // La locale de la requete vient du middleware `SetLocale` (session,
        // puis `preferred_locale` du demandeur) : l'auteur prefere l'anglais.
        $this->author->forceFill(['preferred_locale' => 'en'])->saveQuietly();

        // 1. Rien en base : fallback code ANGLAIS (definition + regles).
        $this->fakeChatCompletion();
        $this->chat(['method_code' => 'clarifier'])->assertOk();
        $system = $this->sentSystemPrompt();
        $this->assertStringStartsWith(BlogExplorerFacilitation::defaultPrompt('clarifier', 'en'), $system);
        $this->assertStringContainsString('FACILITATION RULES', $system);
        $this->assertStringNotContainsString('RÈGLES DE FACILITATION', $system);

        // 2. Une ligne admin FR seulement : repli `_fr` du repository, regles EN.
        AdminAiPrompt::create([
            'scenario_id' => BlogExplorerFacilitation::scenarioId('clarifier', 'fr'),
            'name' => 'Clarifier FR',
            'prompt_text' => 'DEFINITION ADMIN CLARIFIER FR.',
            'version' => 1,
            'is_active' => true,
        ]);
        Http::fake();
        $this->fakeChatCompletion();
        $this->chat(['method_code' => 'clarifier'])->assertOk();
        $system = $this->sentSystemPrompt();
        $this->assertStringStartsWith('DEFINITION ADMIN CLARIFIER FR.', $system);
        $this->assertStringContainsString('FACILITATION RULES', $system);

        // 3. Une ligne admin EN : elle prime sur le repli FR.
        AdminAiPrompt::create([
            'scenario_id' => BlogExplorerFacilitation::scenarioId('clarifier', 'en'),
            'name' => 'Clarify EN',
            'prompt_text' => 'ADMIN DEFINITION CLARIFY EN.',
            'version' => 1,
            'is_active' => true,
        ]);
        Http::fake();
        $this->fakeChatCompletion();
        $this->chat(['method_code' => 'clarifier'])->assertOk();
        $system = $this->sentSystemPrompt();
        $this->assertStringStartsWith('ADMIN DEFINITION CLARIFY EN.', $system);
        $this->assertStringNotContainsString('CLARIFIER FR', $system);
    }

    public function test_the_admin_prompt_whitelist_accepts_the_eight_method_scenarios(): void
    {
        foreach (BlogAiService::METHOD_SELECTION_METHODS as $method) {
            foreach (BlogExplorerFacilitation::LOCALES as $locale) {
                $scenarioId = BlogExplorerFacilitation::scenarioId($method, $locale);

                $this->actingAs($this->admin)
                    ->post(route('admin.ai-prompts.store'), [
                        'scenario_id' => $scenarioId,
                        'name' => "Prompt {$scenarioId}",
                        'prompt_text' => "Definition {$method} {$locale}.",
                    ])
                    ->assertSessionHasNoErrors()
                    ->assertRedirect(route('admin.ai-prompts'));

                $this->assertDatabaseHas('admin_ai_prompts', ['scenario_id' => $scenarioId, 'version' => 1]);
            }
        }
    }

    // =====================================================================
    // D. La garde economique T1248 reste entiere ; callProvider() est aveugle
    // =====================================================================

    public function test_a_method_code_never_bypasses_the_economic_guard(): void
    {
        Http::fake();
        app(AiUserCreditSettings::class)->updatePlatform([
            'free_enabled' => true, 'monthly_uses' => 0, 'alert_percent' => 80, 'offer_subscription' => true,
        ], $this->admin);

        foreach (BlogAiService::METHOD_SELECTION_METHODS as $method) {
            $this->chat(['method_code' => $method])
                ->assertStatus(429)
                ->assertJsonPath('code', AiRefusedException::CODE_USER_CREDIT_EXHAUSTED)
                ->assertJsonStructure(['error', 'code', 'offers_url'])
                ->assertJsonMissingPath('text');
        }

        Http::assertNothingSent();
        $this->assertSame(0, AiProviderInvocation::query()->count());
        $this->assertSame(0, AiInteraction::query()->count());
    }

    public function test_a_method_dialogue_writes_exactly_the_same_ledger_and_trace_as_before(): void
    {
        $this->fakeChatCompletion();

        $this->chat(['method_code' => 'explorer'])->assertOk()->assertJsonStructure(['text']);
        Http::assertSentCount(1);

        $ledger = AiProviderInvocation::query()->get();
        $this->assertCount(1, $ledger);
        $row = $ledger->first();
        $this->assertSame($this->post->organization_id, $row->organization_id, 'Tenant = Organization de l\'article.');
        $this->assertSame('blog_explorer', $row->feature, 'La methode ne change ni la feature…');
        $this->assertSame('blog.explorer_dialogue', $row->process, '… ni le process (meme enveloppe economique).');
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $row->credential_source);
        $this->assertSame(AiProviderInvocation::STATUS_SUCCESS, $row->status);

        $interaction = AiInteraction::query()->firstOrFail();
        $this->assertSame($row->correlation_id, $interaction->correlation_id);
        $this->assertSame('blog_explorer', $interaction->feature);
        $this->assertSame(1, AiInteraction::query()->count(), 'Zero double comptage.');
    }

    public function test_the_organization_route_alias_accepts_the_method_code_by_delegation(): void
    {
        $this->fakeChatCompletion();

        $this->actingAs($this->author)
            ->postJson(route('organization.blog.explorer.chat', [
                'organization' => $this->organization->slug,
                'post' => $this->post,
            ]), ['message' => 'Par ou commencer ?', 'method_code' => 'slow_down'])
            ->assertOk()
            ->assertJsonStructure(['text']);

        $this->assertStringContainsString('« Ralentir »', $this->sentSystemPrompt());

        $this->actingAs($this->author)
            ->postJson(route('organization.blog.explorer.chat', [
                'organization' => $this->organization->slug,
                'post' => $this->post,
            ]), ['message' => 'Encore ?', 'method_code' => 'nope'])
            ->assertStatus(422);
    }

    public function test_the_method_travels_with_the_conversation_history_not_as_a_separate_message(): void
    {
        $this->fakeChatCompletion();

        $history = [
            ['role' => 'user', 'text' => 'Premier message'],
            ['role' => 'assistant', 'text' => 'Premiere intervention'],
        ];

        $this->chat(['method_code' => 'clarifier', 'messages' => $history])->assertOk();

        Http::assertSent(function (ClientRequest $request) {
            $messages = $request->data()['messages'] ?? [];

            // system + 2 historiques + le nouveau message : la methode n'ajoute
            // AUCUN message, elle vit dans le prompt systeme.
            return count($messages) === 4
                && $messages[0]['role'] === 'system'
                && str_contains($messages[0]['content'], '« Clarifier »')
                && $messages[1] === ['role' => 'user', 'content' => 'Premier message']
                && $messages[2] === ['role' => 'assistant', 'content' => 'Premiere intervention']
                && $messages[3] === ['role' => 'user', 'content' => 'Par ou commencer ?'];
        });
    }
}
