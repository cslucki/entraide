<?php

namespace Tests\Feature;

use App\Livewire\InlineMemberAgent;
use App\Models\AdminAiInteraction;
use App\Models\AiInteraction;
use App\Models\BlogPost;
use App\Models\MemberAiProfile;
use App\Models\MemberAiProfileInteraction;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AiBudgetExceeded;
use App\Services\BlogAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1132 / IA P1-2 — verdict économique aux sites d'écriture réels.
 *
 * Couvre :
 * - 1 / 2 : tarif connu vs tarif inconnu, de bout en bout jusqu'à la base ;
 * - 3 : le zéro légitime d'une réponse sans LLM ;
 * - 9 : aucune double écriture ;
 * - 10 : `CheckAiBudgets` et les pages admin ne régressent pas.
 */
class TASK1132AiCostWriteSitesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $author;

    private BlogPost $post;

    protected function setUp(): void
    {
        parent::setUp();

        // Catalogue déterministe : ni les tarifs réels livrés, ni le `.env` de
        // la machine ne doivent influencer ces tests.
        config([
            'ai_pricing.version' => 'test-catalog',
            'ai_pricing.overrides' => [],
            'ai_pricing.models' => [
                'openai' => [
                    'gpt-catalogued' => ['input_per_1m' => 1.0, 'output_per_1m' => 4.0],
                ],
                'ollama' => [
                    '*' => ['input_per_1m' => 0.0, 'output_per_1m' => 0.0, 'free' => true],
                ],
                'rule_based' => [
                    '*' => ['input_per_1m' => 0.0, 'output_per_1m' => 0.0, 'free' => true],
                ],
            ],
            'ai.default_provider' => 'openai',
            'ai.openai.api_key' => 'test-key',
            'ai.openai.model' => 'gpt-catalogued',
        ]);

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'ai_profiles_enabled' => true,
        ]);

        $this->author = User::factory()->create([
            'organization_id' => $this->organization->id,
            'preferred_locale' => 'fr',
        ]);

        $this->post = BlogPost::create([
            'user_id' => $this->author->id,
            'organization_id' => $this->organization->id,
            'title' => 'Article TASK-1132',
            'slug' => 'article-task-1132',
            'content' => '<p>'.str_repeat('Un contenu suffisamment long pour etre explore. ', 12).'</p>',
            'summary' => 'Resume',
            'status' => 'draft',
        ]);

        app()->instance('current_organization', $this->organization);
        app()->setLocale('fr');

        Http::preventStrayRequests();
    }

    /**
     * Réponse `chat/completions` réaliste : l'usage est rapporté sous
     * `prompt_tokens` / `completion_tokens`, les clés que le code ne lisait pas.
     */
    private function fakeChatCompletion(int $promptTokens = 1_000_000, int $completionTokens = 250_000): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => str_repeat('Reponse du fake. ', 20)]]],
                'usage' => [
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                ],
            ]),
        ]);
    }

    /**
     * 1 — tarif connu + usage observé = coût réellement calculé et écrit.
     *
     * 1 000 000 in x 1.0 + 250 000 out x 4.0 = 1.0 + 1.0 = 2.0
     * Ce test échouerait aussi si le code lisait les mauvaises clés d'usage,
     * puisque le coût retomberait à zéro.
     */
    public function test_a_catalogued_model_produces_a_measured_cost(): void
    {
        $this->fakeChatCompletion();

        app(BlogAiService::class)->generate($this->post, $this->author);

        $interaction = AiInteraction::query()->where('feature', 'blog_generate')->firstOrFail();

        $this->assertFalse($interaction->cost_unknown, 'A catalogued model must not be reported unknown.');
        $this->assertSame('2.000000', (string) $interaction->cost_usd);
        $this->assertSame(1_000_000, $interaction->input_tokens);
        $this->assertSame(250_000, $interaction->output_tokens);
    }

    /**
     * 2 — modèle absent du catalogue : `cost_unknown = true` et coût NULL.
     *
     * C'est exactement le cas OpenRouter d'avant P1-2, qui écrivait 0.
     */
    public function test_a_model_absent_from_the_catalog_is_written_as_unknown_never_zero(): void
    {
        config([
            'ai.default_provider' => 'openrouter',
            'ai.openrouter.api_key' => 'test-key',
            'ai.openrouter.model' => 'un/modele-hors-catalogue',
            'ai.default_model' => null,
        ]);

        $this->fakeChatCompletion();

        app(BlogAiService::class)->generate($this->post, $this->author);

        $interaction = AiInteraction::query()->where('feature', 'blog_generate')->firstOrFail();

        $this->assertTrue($interaction->cost_unknown);
        $this->assertNull($interaction->cost_usd, 'An unknown rate must never be persisted as 0.');

        // L'usage observé reste tracé : c'est le TARIF qui manque, pas l'usage.
        $this->assertSame(1_000_000, $interaction->input_tokens);
    }

    /**
     * 3 — Ollama tourne en local : coût nul, légitime, et CONNU.
     *
     * Sans cette distinction, un modèle réellement gratuit serait inexprimable.
     */
    public function test_a_local_ollama_call_costs_zero_without_being_unknown(): void
    {
        config([
            'ai.default_provider' => 'ollama',
            'ai.ollama.base_url' => 'http://localhost:11434',
            'ai.ollama.model' => 'ministral-3:3b',
            'ai.default_model' => null,
        ]);

        Http::fake([
            '*' => Http::response([
                'response' => str_repeat('Reponse locale. ', 20),
                'eval_count' => 128,
            ]),
        ]);

        app(BlogAiService::class)->generate($this->post, $this->author);

        $interaction = AiInteraction::query()->where('feature', 'blog_generate')->firstOrFail();

        $this->assertFalse($interaction->cost_unknown, 'Local execution is genuinely free, not unknown.');
        $this->assertSame('0.000000', (string) $interaction->cost_usd);
    }

    /**
     * 3 bis — l'agent inline répond sans aucun appel LLM : zéro connu, sur les
     * DEUX traces qu'il écrit.
     */
    public function test_a_rule_based_answer_costs_a_known_zero_on_both_traces(): void
    {
        $visitor = User::factory()->create(['organization_id' => $this->organization->id]);

        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->author->id,
            'skills' => ['SEO', 'Redaction'],
        ]);

        Livewire::actingAs($visitor)
            ->test(InlineMemberAgent::class, ['user' => $this->author])
            ->set('question', 'Quelles competences ?')
            ->call('askQuestion');

        $admin = AdminAiInteraction::query()
            ->where('scenario_id', 'inline_member_presentation')
            ->firstOrFail();

        $this->assertFalse($admin->cost_unknown);
        $this->assertSame('0.00000000', (string) $admin->cost_usd);

        $member = MemberAiProfileInteraction::query()->firstOrFail();

        $this->assertFalse($member->cost_unknown);
        $this->assertSame('0.00000000', (string) $member->cost_usd);
    }

    /**
     * 9 — aucune double écriture : un appel produit exactement une ligne par
     * trace concernée, le verdict économique n'en ajoute aucune.
     */
    public function test_the_cost_verdict_does_not_duplicate_any_trace_row(): void
    {
        $this->fakeChatCompletion();

        app(BlogAiService::class)->generate($this->post, $this->author);

        $this->assertSame(1, AiInteraction::query()->count(), 'One AI call, one ai_interactions row.');

        // L'agent inline écrit sur deux tables distinctes : une ligne chacune,
        // pas deux sur l'une d'elles.
        $visitor = User::factory()->create(['organization_id' => $this->organization->id]);

        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->author->id,
            'skills' => ['SEO', 'Redaction'],
        ]);

        Livewire::actingAs($visitor)
            ->test(InlineMemberAgent::class, ['user' => $this->author])
            ->set('question', 'Quelles competences ?')
            ->call('askQuestion');

        $this->assertSame(
            1,
            AdminAiInteraction::query()->where('scenario_id', 'inline_member_presentation')->count(),
        );
        $this->assertSame(1, MemberAiProfileInteraction::query()->count());
    }

    /**
     * 10 — `CheckAiBudgets` ne régresse pas.
     *
     * L'arithmétique est inchangée : SUM() ignore les NULL, donc les appels non
     * mesurables ne gonflent ni ne réduisent le total, exactement comme les
     * anciens zéros. Le seuil déclenche donc à l'identique.
     */
    public function test_check_ai_budgets_still_sums_and_alerts_identically(): void
    {
        Notification::fake();
        User::factory()->create(['is_admin' => true]);

        config(['ai.budget_alerts' => ['supervision_content' => 1.00]]);

        // Deux coûts mesurés sous le seuil.
        $this->makeAdminInteraction(['cost_usd' => 0.30, 'cost_unknown' => false]);
        $this->makeAdminInteraction(['cost_usd' => 0.20, 'cost_unknown' => false]);

        // Un appel non mesurable : il ne doit pas être compté comme un coût.
        $this->makeAdminInteraction(['cost_usd' => null, 'cost_unknown' => true]);

        $this->artisan('ai:check-budgets')->assertSuccessful();
        Notification::assertNothingSent();

        // Franchissement du seuil par des coûts mesurés.
        $this->makeAdminInteraction(['cost_usd' => 0.60, 'cost_unknown' => false]);

        $this->artisan('ai:check-budgets')->assertSuccessful();
        Notification::assertSentTimes(AiBudgetExceeded::class, 1);
    }

    /**
     * 10 bis — la commande signale explicitement qu'un total est partiel.
     *
     * C'est la contrepartie de `cost_unknown` : sans ce signal, des appels non
     * mesurés se liraient comme gratuits dans un budget.
     */
    public function test_check_ai_budgets_reports_unmeasurable_interactions(): void
    {
        Notification::fake();
        User::factory()->create(['is_admin' => true]);

        config(['ai.budget_alerts' => ['supervision_content' => 100.00]]);

        $this->makeAdminInteraction(['cost_usd' => null, 'cost_unknown' => true]);
        $this->makeAdminInteraction(['cost_usd' => null, 'cost_unknown' => true]);

        $this->artisan('ai:check-budgets')
            ->expectsOutputToContain('2 interaction(s) with an unmeasurable cost')
            ->assertSuccessful();
    }

    /**
     * 10 ter — les pages admin de coût restent affichables avec des coûts NULL.
     *
     * Elles faisaient auparavant `number_format($cost)` sur une colonne NOT NULL.
     */
    public function test_admin_cost_pages_render_with_unmeasurable_rows(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'organization_id' => $this->organization->id,
        ]);

        $this->makeAdminInteraction(['cost_usd' => null, 'cost_unknown' => true]);
        $this->makeAdminInteraction(['cost_usd' => 0.12345678, 'cost_unknown' => false]);

        $this->actingAs($admin)->get('/admin/ai-benchmark')->assertOk();
        $this->actingAs($admin)->get('/admin/ai-interactions')->assertOk();
    }

    private function makeAdminInteraction(array $overrides = []): AdminAiInteraction
    {
        return AdminAiInteraction::create(array_merge([
            'organization_id' => $this->organization->id,
            'scenario_id' => 'supervision_content',
            'provider' => 'openai',
            'model' => 'gpt-catalogued',
            'status' => 'success',
            'input_excerpt' => 'Extrait',
            'input_length' => 7,
            'result_summary' => 'Resume',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'latency_ms' => 900,
        ], $overrides));
    }
}
