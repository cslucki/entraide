<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiProviderInvocationConsole;
use App\Services\Ai\AiUserCreditSettings;
use App\Services\Ai\DTO\AiConsumptionFilters;
use App\Services\Ai\OrganizationAiEconomicUsage;
use App\Support\Ai\AiCost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1257 — Releve utilisateur IA V2 (« Mes usages IA »), ecarts G1..G4 de
 * la gap analysis, sur les MEMES autorites (1219/1222 via
 * `OrganizationAiEconomicUsage`, console, garde) :
 *
 *  G1. CATEGORIES D'USAGE — les generations du mois ventilees par fonction en
 *      langage produit ; les sous-lignes SOMMENT la ligne « Generations » ;
 *      jamais une categorie d'un autre membre ni d'une autre Organization.
 *  G2. COUT FOURNISSEUR, PAS PRIX CLIENT — le $ est nomme cout fournisseur
 *      mesure, accompagne de « ce n'est pas un prix qui vous est facture » ;
 *      la carte credit ne porte aucun $ (utilisations seulement) ; le montant
 *      lui-meme reste affiche (arbitrage MASTER T1228).
 *  G3. STATUT PAR LIGNE — une generation sans parole de l'ecrivain prend le
 *      statut de la ligne ledger `generation` de sa correlation (meme
 *      Organization, meme utilisateur) ; sinon « — » ; la parole de
 *      l'ecrivain prime ; aucune ligne ni aucun compte ne vient du ledger.
 *  G4. EXCLUSIONS DU CREDIT CHIFFREES — « Ce mois N » et « M sur Q » se
 *      lisent l'un par l'autre : indexations et traitements non declares
 *      hors credit, comptes sous la carte.
 */
class TASK1257UserAiStatementV2Test extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'sk-task1257-never-rendered';

    private Organization $orgA;

    private Organization $orgB;

    private User $memberA;

    private User $memberA2;

    private User $memberB;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['name' => 'Org Alpha 1257']);
        $this->orgB = Organization::factory()->create(['name' => 'Org Beta 1257']);

        foreach ([$this->orgA, $this->orgB] as $organization) {
            OrganizationAiSetting::factory()->create([
                'organization_id' => $organization->id,
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'api_key' => self::API_KEY,
                'monthly_budget_usd' => 5.00,
            ]);
        }

        $this->memberA = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Membre Alpha Un']);
        $this->memberA2 = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Membre Alpha Deux']);
        $this->memberB = User::factory()->create(['organization_id' => $this->orgB->id, 'name' => 'Membre Beta']);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Super Admin', 'is_admin' => true]);
    }

    // =====================================================================
    // G1. Categories d'usage
    // =====================================================================

    public function test_the_month_is_broken_down_by_usage_category_in_product_language_and_the_categories_sum_the_generations_line(): void
    {
        $this->generation($this->memberA, cost: 0.10, feature: 'loop_knowledge_answer', process: 'loop_knowledge.answer');
        $this->generation($this->memberA, cost: 0.20, feature: 'loop_knowledge_answer', process: 'loop_knowledge.answer');
        $this->generation($this->memberA, cost: null, feature: 'chatloop_ai_summarize', process: 'chatloop.summarize');
        $this->generation($this->memberA, cost: 0.05, feature: 'blog_explorer', process: 'blog.explorer_dialogue');
        // Une recherche documentaire : une NATURE, pas une categorie de generation.
        $this->embedding($this->memberA, 'query', cost: 0.001);

        $page = $this->actingAs($this->memberA)->get(route('profile.ai-usage'));
        $page->assertOk()
            ->assertSee(__('ai.my_ai_usage_categories_title'))
            ->assertSee('data-my-ai-usage-categories-count="3"', false)
            ->assertSee('data-my-ai-usage-category="loop_knowledge.answer" data-my-ai-usage-category-count="2"', false)
            ->assertSee('data-my-ai-usage-category="chatloop.summarize" data-my-ai-usage-category-count="1"', false)
            ->assertSee('data-my-ai-usage-category="blog.explorer_dialogue" data-my-ai-usage-category-count="1"', false)
            // Langage produit, jamais l'identifiant technique en clair comme libelle.
            ->assertSee(__('ai.process_label.loop_knowledge_answer'))
            ->assertSee(__('ai.process_label.chatloop_summarize'))
            ->assertSee(__('ai.process_label.blog_explorer_dialogue'))
            // La ligne « Generations » et « Ce mois » restent ce qu'elles etaient.
            ->assertSee('data-my-ai-usage-nature="generation" data-my-ai-usage-nature-count="4"', false)
            ->assertSee('data-my-ai-usage-month-count="5"', false);

        // L'inconnu reste un COMPTE sur sa categorie, jamais un 0 somme.
        $content = $page->getContent();
        $summarize = substr($content, strpos($content, 'data-my-ai-usage-category="chatloop.summarize"'));
        $summarize = substr($summarize, 0, strpos($summarize, '</li>'));
        $this->assertStringContainsString('—', $summarize);
        $this->assertStringContainsString(trans_choice('ai.economy_unknown_count', 1, ['count' => 1]), $summarize);

        // AUTORITE : les lignes par categorie SOMMENT exactement la tranche generation de summary().
        $usage = app(OrganizationAiEconomicUsage::class);
        $period = AiConsumptionFilters::currentMonth();
        $summary = $usage->summary((string) $this->orgA->id, $period->from, $period->to, (string) $this->memberA->id);
        $categories = $usage->userGenerationByProcess((string) $this->orgA->id, $period->from, $period->to, (string) $this->memberA->id);

        $this->assertCount(3, $categories);
        $this->assertSame($summary['generation']['trace_count'], array_sum(array_column($categories, 'trace_count')));
        $this->assertSame($summary['generation']['unknown_count'], array_sum(array_column($categories, 'unknown_count')));
        $this->assertSame($summary['generation']['measured_count'], array_sum(array_column($categories, 'measured_count')));
        $this->assertEqualsWithDelta($summary['generation']['known_cost_usd'], array_sum(array_filter(array_column($categories, 'known_cost_usd'), static fn ($v) => $v !== null)), 0.0000001);
        // Tri : la categorie la plus utilisee d'abord.
        $this->assertSame('loop_knowledge.answer', $categories[0]['key']);
    }

    public function test_a_member_with_no_generation_sees_no_category_block_and_a_dash_is_never_a_zero(): void
    {
        $this->embedding($this->memberA, 'query', cost: null);

        $page = $this->actingAs($this->memberA)->get(route('profile.ai-usage'));
        $page->assertOk()
            ->assertDontSee('data-my-ai-usage-categories', false)
            ->assertSee('data-my-ai-usage-nature="generation" data-my-ai-usage-nature-count="0"', false)
            ->assertDontSee('$0.000000');
    }

    public function test_categories_never_include_another_member_or_another_organization(): void
    {
        $this->generation($this->memberA, cost: 0.10, feature: 'loop_knowledge_answer', process: 'loop_knowledge.answer');
        // Un autre membre de la MEME Organization, sur une autre fonction.
        $this->generation($this->memberA2, cost: 0.90, feature: 'chatloop_ai_summarize', process: 'chatloop.summarize');
        // Un membre d'une AUTRE Organization.
        $this->generation($this->memberB, cost: 0.50, feature: 'blog_explorer', process: 'blog.explorer_dialogue', organization: $this->orgB);

        $page = $this->actingAs($this->memberA)->get(route('profile.ai-usage'));
        $page->assertOk()
            ->assertSee('data-my-ai-usage-categories-count="1"', false)
            ->assertSee('data-my-ai-usage-category="loop_knowledge.answer" data-my-ai-usage-category-count="1"', false)
            ->assertDontSee('data-my-ai-usage-category="chatloop.summarize"', false)
            ->assertDontSee('data-my-ai-usage-category="blog.explorer_dialogue"', false)
            ->assertDontSee(__('ai.process_label.chatloop_summarize'))
            ->assertDontSee(__('ai.process_label.blog_explorer_dialogue'))
            ->assertDontSee('Membre Alpha Deux')
            ->assertDontSee('$0.900000')
            ->assertDontSee('$1.000000');

        // Defense tenant de l'autorite : l'identifiant d'un membre d'une autre
        // Organization ne selectionne RIEN, meme sur une Organization qui a des lignes.
        $usage = app(OrganizationAiEconomicUsage::class);
        $period = AiConsumptionFilters::currentMonth();
        $this->assertSame([], $usage->userGenerationByProcess((string) $this->orgA->id, $period->from, $period->to, (string) $this->memberB->id));
        $this->assertSame([], $usage->userGenerationByProcess((string) $this->orgB->id, $period->from, $period->to, (string) $this->memberA->id));
    }

    // =====================================================================
    // G2. Cout fournisseur, jamais prix client ; credit en utilisations
    // =====================================================================

    public function test_the_provider_cost_is_named_as_such_with_an_explicit_not_a_price_note_and_the_credit_card_carries_no_dollar(): void
    {
        app(AiUserCreditSettings::class)->updatePlatform([
            'free_enabled' => true, 'monthly_uses' => 10, 'alert_percent' => 80, 'offer_subscription' => true,
        ], $this->superAdmin);
        $this->generation($this->memberA, cost: 0.10);

        $page = $this->actingAs($this->memberA)->get(route('profile.ai-usage'));
        $page->assertOk()
            ->assertSee(__('ai.my_ai_usage_known_cost'))
            ->assertSee(__('ai.usage_col_cost'))
            ->assertSee(__('ai.my_ai_usage_provider_cost_note'))
            ->assertSee('data-my-ai-usage-provider-cost-note', false)
            // Le montant fournisseur reste affiche (arbitrage MASTER T1228).
            ->assertSee('$0.100000')
            // Le credit : en utilisations.
            ->assertSee(__('ai.credit_used_of_quota', ['used' => 1, 'quota' => 10]))
            ->assertSee(trans_choice('ai.credit_remaining', 9, ['count' => 9]));

        // La carte credit ne contient AUCUN montant en dollars.
        $content = $page->getContent();
        $card = substr($content, strpos($content, 'data-my-ai-credit '));
        $card = substr($card, 0, strpos($card, '</section>'));
        $this->assertStringNotContainsString('$', $card);
        // Les libelles du cout ne se lisent jamais comme un prix.
        $this->assertStringContainsStringIgnoringCase('fournisseur', __('ai.my_ai_usage_known_cost', [], 'fr'));
        $this->assertStringContainsStringIgnoringCase('provider', __('ai.my_ai_usage_known_cost', [], 'en'));
        $this->assertStringContainsStringIgnoringCase('fournisseur', __('ai.usage_col_cost', [], 'fr'));
        $this->assertStringContainsStringIgnoringCase('provider', __('ai.usage_col_cost', [], 'en'));
    }

    // =====================================================================
    // G3. Statut par ligne
    // =====================================================================

    public function test_a_generation_without_writer_status_takes_the_ledger_status_of_its_correlation_and_stays_a_dash_otherwise(): void
    {
        // (a) Sans parole de l'ecrivain (Blog IA), ledger « success » sur la meme correlation.
        $success = $this->rawInteraction($this->memberA, correlation: (string) Str::uuid(), metadata: ['provider' => 'openrouter'], feature: 'blog_explorer_a', process: 'blog.explorer_dialogue');
        $this->ledgerGeneration($this->memberA, $success->correlation_id, AiProviderInvocation::STATUS_SUCCESS);

        // (b) Sans parole de l'ecrivain, ledger « failed » puis « success » (nouvelle tentative) : la plus recente parle.
        $retried = $this->rawInteraction($this->memberA, correlation: (string) Str::uuid(), metadata: ['provider' => 'openrouter'], feature: 'blog_generate', process: 'blog.article_generate');
        $this->ledgerGeneration($this->memberA, $retried->correlation_id, AiProviderInvocation::STATUS_FAILED, at: now()->subMinutes(2));
        $this->ledgerGeneration($this->memberA, $retried->correlation_id, AiProviderInvocation::STATUS_SUCCESS, at: now()->subMinute());

        // (c) Sans correlation : rien a emprunter -> « — ».
        $orphan = $this->rawInteraction($this->memberA, correlation: null, metadata: ['provider' => 'openrouter'], feature: 'blog_explorer_c', process: 'blog.explorer_dialogue');

        // (d) Correlation presente, mais la ligne ledger appartient a une AUTRE
        //     Organization / un AUTRE utilisateur : jamais empruntee -> « — ».
        $foreign = $this->rawInteraction($this->memberA, correlation: (string) Str::uuid(), metadata: ['provider' => 'openrouter'], feature: 'blog_explorer_d', process: 'blog.explorer_dialogue');
        $this->ledgerGeneration($this->memberB, $foreign->correlation_id, AiProviderInvocation::STATUS_SUCCESS, organization: $this->orgB);
        $this->ledgerGeneration($this->memberA2, $foreign->correlation_id, AiProviderInvocation::STATUS_SUCCESS);

        // (e) La parole de l'ecrivain PRIME : « failed » ecrit, ledger « success ».
        $spoken = $this->rawInteraction($this->memberA, correlation: (string) Str::uuid(), metadata: ['provider' => 'openai', 'status' => 'failed'], feature: 'loop_knowledge_answer', process: 'loop_knowledge.answer');
        $this->ledgerGeneration($this->memberA, $spoken->correlation_id, AiProviderInvocation::STATUS_SUCCESS);

        $rows = app(AiProviderInvocationConsole::class)->recentActivityForUser((string) $this->orgA->id, (string) $this->memberA->id, 20);

        // Aucune ligne ne vient du ledger : exactement les 5 interactions, rien de plus.
        $this->assertCount(5, $rows);
        $this->assertSame(['generation'], array_values(array_unique(array_column($rows, 'kind'))));
        foreach ($rows as $row) {
            // `correlation_id` ne sort pas de la classe.
            $this->assertArrayNotHasKey('correlation_id', $row);
        }

        $statusOf = static function (array $rows, AiInteraction $interaction): ?string {
            foreach ($rows as $row) {
                if ($row['feature'] === $interaction->feature) {
                    return $row['status'];
                }
            }

            return 'not-found';
        };

        $this->assertSame('success', $statusOf($rows, $success));
        $this->assertSame('success', $statusOf($rows, $retried));
        $this->assertNull($statusOf($rows, $orphan));
        $this->assertNull($statusOf($rows, $foreign));
        $this->assertSame('failed', $statusOf($rows, $spoken));

        // Rendu : « Reussi » / « Echec » / « — », et toujours 5 lignes.
        $page = $this->actingAs($this->memberA)->get(route('profile.ai-usage'));
        $page->assertOk()
            ->assertSee(__('ai.usage_status_success'))
            ->assertSee(__('ai.usage_status_failed'));
        $this->assertSame(5, substr_count($page->getContent(), 'data-my-ai-usage-row'));
        $this->assertSame(2, substr_count($page->getContent(), '>'.__('ai.usage_status_success').'<'));
        $this->assertSame(1, substr_count($page->getContent(), '>'.__('ai.usage_status_failed').'<'));
    }

    public function test_the_ledger_status_lookup_costs_one_query_and_none_when_every_writer_spoke(): void
    {
        $this->generation($this->memberA, cost: 0.10);
        $this->generation($this->memberA, cost: 0.20);
        $console = app(AiProviderInvocationConsole::class);

        // Deux registres lus (generation + embeddings), rien d'autre quand chaque ecrivain a parle.
        $this->assertSame(2, $this->countQueries(fn () => $console->recentActivityForUser((string) $this->orgA->id, (string) $this->memberA->id, 20)));

        $mute = $this->rawInteraction($this->memberA, correlation: (string) Str::uuid(), metadata: ['provider' => 'openrouter'], feature: 'blog_explorer', process: 'blog.explorer_dialogue');
        $this->ledgerGeneration($this->memberA, $mute->correlation_id, AiProviderInvocation::STATUS_SUCCESS);
        $mute2 = $this->rawInteraction($this->memberA, correlation: (string) Str::uuid(), metadata: ['provider' => 'openrouter'], feature: 'blog_explorer', process: 'blog.explorer_dialogue');
        $this->ledgerGeneration($this->memberA, $mute2->correlation_id, AiProviderInvocation::STATUS_SUCCESS);

        // Une seule requete groupee de plus, quel que soit le nombre de lignes muettes.
        $this->assertSame(3, $this->countQueries(fn () => $console->recentActivityForUser((string) $this->orgA->id, (string) $this->memberA->id, 20)));
    }

    // =====================================================================
    // G4. Exclusions du credit chiffrees
    // =====================================================================

    public function test_the_credit_exclusions_are_counted_under_the_credit_card_so_this_month_and_the_credit_read_each_other(): void
    {
        app(AiUserCreditSettings::class)->updatePlatform([
            'free_enabled' => true, 'monthly_uses' => 10, 'alert_percent' => 80, 'offer_subscription' => true,
        ], $this->superAdmin);

        $this->generation($this->memberA, cost: 0.10);
        $this->generation($this->memberA, cost: 0.10);
        $this->embedding($this->memberA, 'query', cost: 0.001);
        $this->embedding($this->memberA, 'ingestion', cost: 0.002);
        $this->embedding($this->memberA, 'ingestion', cost: 0.002);
        $this->embedding($this->memberA, null, cost: 0.003);

        $page = $this->actingAs($this->memberA)->get(route('profile.ai-usage'));
        $page->assertOk()
            // Ce mois : 6 ; credit : 3 (2 generations + 1 recherche) ; 3 exclusions chiffrees.
            ->assertSee('data-my-ai-usage-month-count="6"', false)
            ->assertSee('data-my-ai-credit-used="3"', false)
            ->assertSee(__('ai.credit_used_of_quota', ['used' => 3, 'quota' => 10]))
            ->assertSee('data-my-ai-credit-ingestion-excluded="2"', false)
            ->assertSee(trans_choice('ai.credit_out_of_scope_ingestion_count', 2, ['count' => 2]))
            ->assertSee('data-my-ai-credit-undeclared-excluded="1"', false)
            ->assertSee(trans_choice('ai.credit_out_of_scope_undeclared_count', 1, ['count' => 1]))
            // Aucun essai de doctrine : pas de ligne fabriquee.
            ->assertDontSee('data-my-ai-credit-sandbox-excluded', false);

        // Sans exclusion : aucune ligne (pas de « dont 0 »).
        $other = $this->actingAs($this->memberA2)->get(route('profile.ai-usage'));
        $other->assertOk()
            ->assertDontSee('data-my-ai-credit-ingestion-excluded', false)
            ->assertDontSee('data-my-ai-credit-undeclared-excluded', false);
    }

    // =====================================================================
    // Invariants inchanges
    // =====================================================================

    public function test_the_page_still_carries_no_prompt_response_or_credential_and_nothing_from_another_member(): void
    {
        $this->generation($this->memberA, cost: 0.10);
        $this->generation($this->memberA2, cost: 0.90, feature: 'chatloop_ai_summarize', process: 'chatloop.summarize');

        $page = $this->actingAs($this->memberA)->get(route('profile.ai-usage'));
        $page->assertOk()
            ->assertDontSee(self::API_KEY)
            ->assertDontSee('prompt-secret-1257')
            ->assertDontSee('response-secret-1257')
            ->assertDontSee('Membre Alpha Deux')
            ->assertDontSee(__('ai.consumption_budget_title'))
            ->assertDontSee('$5.00');
        $this->assertSame(1, substr_count($page->getContent(), 'data-my-ai-usage-row'));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function generation(
        User $user,
        ?float $cost,
        string $feature = 'loop_knowledge_answer',
        string $process = 'loop_knowledge.answer',
        ?Organization $organization = null,
    ): AiInteraction {
        $organization ??= $this->orgA;
        $correlation = (string) Str::uuid();
        $this->ledgerGeneration($user, $correlation, AiProviderInvocation::STATUS_SUCCESS, organization: $organization, cost: $cost, process: $process, capability: $feature);

        return $this->rawInteraction($user, $correlation, ['provider' => 'openai', 'status' => 'success', 'capability' => $feature], $feature, $process, $organization, $cost);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function rawInteraction(
        User $user,
        ?string $correlation,
        array $metadata,
        string $feature,
        string $process,
        ?Organization $organization = null,
        ?float $cost = 0.01,
    ): AiInteraction {
        return AiInteraction::create([
            'user_id' => $user->id,
            'organization_id' => ($organization ?? $this->orgA)->id,
            'correlation_id' => $correlation,
            'process' => $process,
            'feature' => $feature,
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'prompt-secret-1257',
            'response' => 'response-secret-1257',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cost_usd' => $cost,
            'cost_unknown' => $cost === null,
            'metadata' => $metadata,
        ]);
    }

    private function ledgerGeneration(
        User $user,
        string $correlation,
        string $status,
        ?\DateTimeInterface $at = null,
        ?Organization $organization = null,
        ?float $cost = 0.01,
        string $process = 'blog.explorer_dialogue',
        string $capability = 'blog_explorer',
    ): AiProviderInvocation {
        $row = AiProviderInvocation::create([
            'organization_id' => ($organization ?? $this->orgA)->id,
            'user_id' => $user->id,
            'correlation_id' => $correlation,
            'capability' => $capability,
            'process' => $process,
            'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'credential_source' => AiProviderInvocation::CREDENTIAL_PLATFORM,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'provider_cost' => $cost,
            'currency' => $cost !== null ? 'USD' : null,
            'cost_status' => $cost !== null ? AiProviderInvocation::COST_KNOWN : AiProviderInvocation::COST_UNKNOWN,
            'cost_source' => $cost !== null ? AiCost::SOURCE_CATALOG_ESTIMATED : AiProviderInvocation::COST_UNKNOWN,
            'status' => $status,
        ]);

        if ($at !== null) {
            // `created_at` n'est pas fillable : dater la ligne apres coup.
            $row->forceFill(['created_at' => $at])->saveQuietly();
        }

        return $row;
    }

    private function embedding(User $user, ?string $operation, ?float $cost): AiProviderInvocation
    {
        return AiProviderInvocation::create([
            'organization_id' => $this->orgA->id,
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
            'status' => AiProviderInvocation::STATUS_SUCCESS,
        ]);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
