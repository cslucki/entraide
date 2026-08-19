<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\DTO\AiConsumptionFilters;
use App\Services\Ai\OrganizationAiEconomicUsage;
use App\Support\Ai\AiCost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1258 — Releve Organization IA V2 (console Admin Organization
 * « Consommation IA »), Option B, ecarts G1..G4 de la gap analysis, sur les
 * MEMES autorites (1222 `OrganizationAiEconomicUsage` -> 1219 `byProcess`) :
 *
 *  G1. FONCTIONS PRODUIT a l'echelle Organization — « Fonctions les plus
 *      consommatrices » en langage produit (jamais la cle technique dans le
 *      texte), org-wide (les filtres de dimension ne s'y appliquent pas), la
 *      somme des lignes EST la ligne « Generations » ; filtre et table 1219
 *      « Par fonction » en langage produit, cle conservee comme valeur / attribut.
 *  G2. ECHECS visibles — `failed_count` des natures documentaires (calcule par
 *      l'autorite 1222 depuis T1222, jamais rendu) dans la ventilation et par
 *      utilisateur ; jamais « 0 echec » fabrique.
 *  G3. VOCABULAIRE « cout fournisseur » partout ou un montant est nomme ; le
 *      montant $ reste (surface Admin).
 *  G4. La phrase « l'origine des identifiants n'est pas tracable » est remplacee
 *      par le vrai (tracee par appel au registre canonique, non ventilee ici).
 *  Tenant : rien d'une autre Organization ; methode = [] sur un tenant etranger.
 */
class TASK1258OrganizationAiStatementV2Test extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'sk-task1258-never-rendered';

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $memberA;

    private User $memberA2;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['name' => 'Org Alpha 1258']);
        $this->orgB = Organization::factory()->create(['name' => 'Org Beta 1258']);

        foreach ($this->orgs() as $organization) {
            OrganizationAiSetting::factory()->create([
                'organization_id' => $organization->id,
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'api_key' => self::API_KEY,
                'monthly_budget_usd' => 5.00,
            ]);
        }

        $this->adminA = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Admin Alpha']);
        $this->orgA->update(['admin_id' => $this->adminA->id]);
        $this->memberA = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Membre Alpha Un']);
        $this->memberA2 = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Membre Alpha Deux']);
        $this->adminB = User::factory()->create(['organization_id' => $this->orgB->id, 'name' => 'Admin Beta']);
        $this->orgB->update(['admin_id' => $this->adminB->id]);
    }

    // =====================================================================
    // G1. Fonctions produit
    // =====================================================================

    public function test_the_organization_sees_its_most_used_functions_in_product_language_and_they_sum_the_generations_line(): void
    {
        $this->generation($this->orgA, $this->memberA, cost: 0.10, feature: 'loop_knowledge_answer', process: 'loop_knowledge.answer');
        $this->generation($this->orgA, $this->memberA2, cost: 0.20, feature: 'loop_knowledge_answer', process: 'loop_knowledge.answer');
        $this->generation($this->orgA, $this->memberA, cost: null, feature: 'chatloop_ai_summarize', process: 'chatloop.summarize');
        $this->generation($this->orgA, $this->adminA, cost: 0.05, feature: 'blog_explorer', process: 'blog.explorer_dialogue');
        $this->embedding($this->orgA, $this->memberA, 'query', cost: 0.001);
        // Une autre Organization, une autre fonction : jamais ici.
        $this->generation($this->orgB, $this->adminB, cost: 0.90, feature: 'blog_generate', process: 'blog.article_generate');

        $page = $this->actingAs($this->adminA)->get($this->orgUrl());
        $page->assertOk()
            ->assertSee(__('ai.consumption_top_processes_title'))
            ->assertSee('data-consumption-top-processes-count="3"', false)
            ->assertSee('data-consumption-top-process="loop_knowledge.answer" data-consumption-top-process-count="2"', false)
            ->assertSee('data-consumption-top-process="chatloop.summarize" data-consumption-top-process-count="1"', false)
            ->assertSee('data-consumption-top-process="blog.explorer_dialogue" data-consumption-top-process-count="1"', false)
            ->assertSee(__('ai.process_label.loop_knowledge_answer'))
            ->assertSee(__('ai.process_label.chatloop_summarize'))
            ->assertSee(__('ai.process_label.blog_explorer_dialogue'))
            ->assertDontSee('data-consumption-top-process="blog.article_generate"', false)
            ->assertDontSee(__('ai.process_label.blog_article_generate'))
            ->assertDontSee('$0.900000')
            ->assertSee('data-consumption-nature="generation" data-consumption-nature-count="4"', false);

        // Langage produit : la cle technique n'est pas un TEXTE visible de la table
        // des fonctions (seulement un attribut de donnees).
        $content = $page->getContent();
        $table = substr($content, strpos($content, 'data-consumption-top-processes'));
        $table = substr($table, 0, strpos($table, '</table>'));
        $this->assertStringNotContainsString('>loop_knowledge.answer<', $table);
        $this->assertStringNotContainsString('>chatloop.summarize<', $table);

        // AUTORITE : la somme des fonctions EST la tranche generation de summary().
        $usage = app(OrganizationAiEconomicUsage::class);
        $period = AiConsumptionFilters::currentMonth();
        $summary = $usage->summary((string) $this->orgA->id, $period->from, $period->to);
        $functions = $usage->generationByProcess((string) $this->orgA->id, $period->from, $period->to);

        $this->assertCount(3, $functions);
        $this->assertSame($summary['generation']['trace_count'], array_sum(array_column($functions, 'trace_count')));
        $this->assertSame($summary['generation']['unknown_count'], array_sum(array_column($functions, 'unknown_count')));
        $this->assertSame($summary['generation']['measured_count'], array_sum(array_column($functions, 'measured_count')));
        $this->assertEqualsWithDelta($summary['generation']['known_cost_usd'], array_sum(array_filter(array_column($functions, 'known_cost_usd'), static fn ($v) => $v !== null)), 0.0000001);
        $this->assertSame('loop_knowledge.answer', $functions[0]['key']);

        // La version par utilisateur (T1257) EST la meme lecture restreinte.
        $this->assertSame(
            $usage->generationByProcess((string) $this->orgA->id, $period->from, $period->to, (string) $this->memberA->id),
            $usage->userGenerationByProcess((string) $this->orgA->id, $period->from, $period->to, (string) $this->memberA->id),
        );
        // Tenant : un identifiant etranger ne selectionne rien.
        $this->assertSame([], $usage->generationByProcess((string) $this->orgB->id, $period->from, $period->to, (string) $this->memberA->id));
    }

    public function test_the_functions_table_is_organization_wide_even_when_a_dimension_filter_is_set(): void
    {
        $this->generation($this->orgA, $this->memberA, cost: 0.10, feature: 'loop_knowledge_answer', process: 'loop_knowledge.answer');
        $this->generation($this->orgA, $this->memberA2, cost: 0.20, feature: 'chatloop_ai_summarize', process: 'chatloop.summarize');

        // Filtre utilisateur pose : le detail 1219 se restreint, le bloc d'autorite
        // (budget, natures, utilisateurs, FONCTIONS) reste toute l'Organization.
        $page = $this->actingAs($this->adminA)->get($this->orgUrl().'?user_id='.$this->memberA->id);
        $page->assertOk()
            ->assertSee('data-consumption-economics-org-wide', false)
            ->assertSee('data-consumption-top-processes-count="2"', false)
            ->assertSee('data-consumption-top-process="chatloop.summarize" data-consumption-top-process-count="1"', false);
    }

    public function test_the_process_filter_and_the_by_function_table_speak_product_language_and_keep_the_key_as_value(): void
    {
        $this->generation($this->orgA, $this->memberA, cost: 0.10, feature: 'loop_knowledge_answer', process: 'loop_knowledge.answer');

        $page = $this->actingAs($this->adminA)->get($this->orgUrl());
        $page->assertOk()
            // Option du filtre : valeur = cle (contrat d'URL), texte = libelle produit.
            ->assertSee('<option value="loop_knowledge.answer" >'.__('ai.process_label.loop_knowledge_answer').'</option>', false)
            ->assertDontSee('<option value="loop_knowledge.answer" >loop_knowledge.answer</option>', false)
            // Table 1219 « Par fonction » : libelle visible, cle en attribut / title seulement.
            ->assertSee(__('ai.consumption_console_by_process'))
            ->assertSee('data-consumption-process-key="loop_knowledge.answer"', false)
            ->assertDontSee('<span class="text-xs text-gray-400 font-mono">loop_knowledge.answer</span>', false);

        // Le filtre par cle fonctionne toujours exactement comme avant.
        $filtered = $this->actingAs($this->adminA)->get($this->orgUrl().'?process=loop_knowledge.answer');
        $filtered->assertOk()->assertSee('<option value="loop_knowledge.answer" selected>'.__('ai.process_label.loop_knowledge_answer').'</option>', false);
    }

    // =====================================================================
    // G2. Echecs visibles
    // =====================================================================

    public function test_failed_document_calls_are_shown_per_nature_and_per_user_and_never_fabricated(): void
    {
        $this->embedding($this->orgA, $this->memberA, 'query', cost: 0.001);
        $this->embedding($this->orgA, $this->memberA, 'query', cost: null, status: AiProviderInvocation::STATUS_FAILED);
        $this->embedding($this->orgA, $this->memberA, 'query', cost: null, status: AiProviderInvocation::STATUS_FAILED);
        $this->embedding($this->orgA, $this->memberA2, 'ingestion', cost: null, status: AiProviderInvocation::STATUS_FAILED);
        $this->embedding($this->orgA, $this->memberA2, 'ingestion', cost: 0.002);

        $page = $this->actingAs($this->adminA)->get($this->orgUrl());
        $page->assertOk()
            ->assertSee('data-consumption-nature="embedding_query" data-consumption-nature-count="3" data-consumption-nature-failed="2"', false)
            ->assertSee(trans_choice('ai.economy_failed_count', 2, ['count' => 2]))
            ->assertSee('data-consumption-nature="embedding_ingestion" data-consumption-nature-count="2" data-consumption-nature-failed="1"', false)
            ->assertSee(trans_choice('ai.economy_failed_count', 1, ['count' => 1]))
            // Par utilisateur : la somme des echecs documentaires de chacun.
            ->assertSee(__('ai.consumption_col_failed'))
            ->assertSee('data-consumption-top-user-failed="2"', false)
            ->assertSee('data-consumption-top-user-failed="1"', false)
            // Un echec n'est pas un « inconnu » : le seau inconnu ne compte que les succes non mesures.
            ->assertSee('data-consumption-economics-unknown="0"', false)
            // La generation n'a pas d'attribut d'echec (l'autorite ne le compte pas) et la page le dit.
            ->assertDontSee('data-consumption-nature="generation" data-consumption-nature-count="0" data-consumption-nature-failed', false)
            ->assertSee(__('ai.consumption_console_limit_generation_failures'));

        // Aucun echec : aucune mention « 0 echec » fabriquee.
        $other = $this->actingAs($this->adminB)->get($this->orgUrl($this->orgB));
        $other->assertOk()
            ->assertDontSee(trans_choice('ai.economy_failed_count', 0, ['count' => 0]))
            ->assertSee('data-consumption-nature="embedding_query" data-consumption-nature-count="0" data-consumption-nature-failed="0"', false);

        // Autorite : les valeurs rendues SONT celles de summary().
        $period = AiConsumptionFilters::currentMonth();
        $summary = app(OrganizationAiEconomicUsage::class)->summary((string) $this->orgA->id, $period->from, $period->to);
        $this->assertSame(2, $summary['embedding_query']['failed_count']);
        $this->assertSame(1, $summary['embedding_ingestion']['failed_count']);
        $this->assertSame(0, $summary['total_unknown_count']);
    }

    // =====================================================================
    // G3. Vocabulaire « cout fournisseur »
    // =====================================================================

    public function test_every_named_amount_is_a_provider_cost_and_the_amount_itself_stays_on_the_admin_surface(): void
    {
        $this->generation($this->orgA, $this->memberA, cost: 0.10);

        $page = $this->actingAs($this->adminA)->get($this->orgUrl());
        $page->assertOk()
            ->assertSee(__('ai.consumption_budget_title'))
            ->assertSee(__('ai.consumption_budget_consumed'))
            ->assertSee(__('ai.consumption_console_known_cost'))
            ->assertSee(__('ai.consumption_col_known_cost'))
            // Le montant RESTE affiche cote Admin (seul le vocabulaire change).
            ->assertSee('$0.100000')
            ->assertSee('$5.00');

        foreach (['fr' => 'fournisseur', 'en' => 'provider'] as $locale => $word) {
            foreach (['consumption_budget_title', 'consumption_budget_consumed', 'consumption_console_known_cost', 'consumption_console_col_known_cost', 'consumption_col_known_cost', 'consumption_budget_none'] as $key) {
                $this->assertStringContainsStringIgnoringCase($word, __('ai.'.$key, [], $locale), "{$locale}: {$key}");
            }
        }
    }

    // =====================================================================
    // G4. La phrase fausse sur credential_source
    // =====================================================================

    public function test_the_limits_block_no_longer_claims_that_the_credential_origin_is_not_traceable(): void
    {
        $page = $this->actingAs($this->adminA)->get($this->orgUrl());
        $page->assertOk()
            ->assertSee(__('ai.consumption_console_limits_title'))
            ->assertSee(__('ai.consumption_console_limit_credential'))
            ->assertDontSee("n'est pas traçable")
            ->assertDontSee('is not traceable');

        foreach (['fr', 'en'] as $locale) {
            $sentence = __('ai.consumption_console_limit_credential', [], $locale);
            $this->assertDoesNotMatchRegularExpression('/pas tra[cç]able|not traceable/iu', $sentence);
            $this->assertMatchesRegularExpression('/tracée|traced/iu', $sentence);
            // Elle ne promet pas une ventilation par payeur (hors scope M1).
            $this->assertMatchesRegularExpression('/ne la ventile pas|does not break it down/i', $sentence);
        }

        // La preuve que la phrase etait fausse : le ledger porte la source par appel.
        $this->embedding($this->orgA, $this->memberA, 'query', cost: 0.001);
        $this->assertSame(
            AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            AiProviderInvocation::query()->where('organization_id', $this->orgA->id)->value('credential_source'),
        );
    }

    // =====================================================================
    // Invariants : tenant, contenu
    // =====================================================================

    public function test_the_page_still_carries_nothing_from_another_organization_nor_any_content_or_credential(): void
    {
        $this->generation($this->orgA, $this->memberA, cost: 0.10);
        $this->generation($this->orgB, $this->adminB, cost: 0.90, feature: 'blog_generate', process: 'blog.article_generate');

        $page = $this->actingAs($this->adminA)->get($this->orgUrl());
        $page->assertOk()
            ->assertDontSee(self::API_KEY)
            ->assertDontSee('prompt-secret-1258')
            ->assertDontSee('response-secret-1258')
            ->assertDontSee('Admin Beta')
            ->assertDontSee('$0.900000')
            ->assertDontSee('data-consumption-top-process="blog.article_generate"', false);

        // Un admin d'une autre Organization ne voit pas cette console (inchange, T1219).
        $this->actingAs($this->adminB)->get($this->orgUrl())->assertForbidden();
        $this->actingAs($this->memberA)->get($this->orgUrl())->assertForbidden();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** @return list<Organization> */
    private function orgs(): array
    {
        return [$this->orgA, $this->orgB];
    }

    private function orgUrl(?Organization $organization = null): string
    {
        return route('organization.admin.ai-consumption', ['organization' => ($organization ?? $this->orgA)->slug]);
    }

    private function generation(
        Organization $organization,
        User $user,
        ?float $cost,
        string $feature = 'loop_knowledge_answer',
        string $process = 'loop_knowledge.answer',
    ): AiInteraction {
        $correlation = (string) Str::uuid();

        AiProviderInvocation::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'correlation_id' => $correlation,
            'capability' => $feature,
            'process' => $process,
            'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'provider_cost' => $cost,
            'currency' => $cost !== null ? 'USD' : null,
            'cost_status' => $cost !== null ? AiProviderInvocation::COST_KNOWN : AiProviderInvocation::COST_UNKNOWN,
            'cost_source' => $cost !== null ? AiCost::SOURCE_CATALOG_ESTIMATED : AiProviderInvocation::COST_UNKNOWN,
            'status' => AiProviderInvocation::STATUS_SUCCESS,
        ]);

        return AiInteraction::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'correlation_id' => $correlation,
            'process' => $process,
            'feature' => $feature,
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'prompt-secret-1258',
            'response' => 'response-secret-1258',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cost_usd' => $cost,
            'cost_unknown' => $cost === null,
            'metadata' => ['provider' => 'openai', 'status' => 'success', 'capability' => $feature],
        ]);
    }

    private function embedding(
        Organization $organization,
        User $user,
        ?string $operation,
        ?float $cost,
        string $status = AiProviderInvocation::STATUS_SUCCESS,
    ): AiProviderInvocation {
        return AiProviderInvocation::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'capability' => 'loop_knowledge_answer',
            'process' => $operation === 'query' ? 'dossier.embeddings_search' : 'dossier.embeddings_index',
            'operation' => AiProviderInvocation::OPERATION_EMBEDDING,
            'embedding_operation' => $operation,
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            'total_tokens' => 30,
            'provider_cost' => $cost,
            'currency' => $cost !== null ? 'USD' : null,
            'cost_status' => $cost !== null ? AiProviderInvocation::COST_KNOWN : AiProviderInvocation::COST_UNKNOWN,
            'cost_source' => $cost !== null ? AiCost::SOURCE_CATALOG_ESTIMATED : AiProviderInvocation::COST_UNKNOWN,
            'status' => $status,
        ]);
    }
}
