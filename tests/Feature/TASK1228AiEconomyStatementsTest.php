<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\DTO\AiConsumptionFilters;
use App\Services\Ai\OrganizationAiEconomicUsage;
use App\Services\Ai\OrganizationDoctrineSandbox;
use App\Support\Ai\AiCost;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1228 — Economie visible : releve User / Organization / plateforme.
 *
 *  A. AUTORITE & COHERENCE — la ligne d'une Organization au cockpit plateforme
 *     EST son `summary()` ; la somme des Organizations (+ non attribuable) EST
 *     le total plateforme ; la somme des utilisateurs (+ non attribuable) EST
 *     le total de l'Organization ; l'utilisateur voit exactement sa part.
 *  B. VERITE — inconnu jamais rendu 0, inconnus comptes jamais sommes, aucune
 *     attribution au prorata, pas de double comptage generation/embeddings,
 *     lignes `ai_doctrine_sandbox` distinguees et comptees.
 *  C. PERIODE — une seule fenetre (UTC, celle de la garde) aux trois niveaux ;
 *     un appel a la frontiere tombe du bon cote, une seule fois.
 *  D. TENANT / PERMISSIONS — rien de B chez A ; membre -> ses usages seuls,
 *     403 sur le releve Organization ; autre admin -> 403 ; non SuperAdmin ->
 *     403 plateforme ; aucun contenu prive dans l'agregat.
 */
class TASK1228AiEconomyStatementsTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'sk-task1228-never-rendered';

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $memberA;

    private User $memberA2;

    private User $adminB;

    private User $memberB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['name' => 'Org Alpha 1228']);
        $this->orgB = Organization::factory()->create(['name' => 'Org Beta 1228']);

        foreach ([[$this->orgA, 5.00], [$this->orgB, null]] as [$organization, $budget]) {
            OrganizationAiSetting::factory()->create([
                'organization_id' => $organization->id,
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'api_key' => self::API_KEY,
                'monthly_budget_usd' => $budget,
            ]);
        }

        $this->adminA = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Admin Alpha']);
        $this->orgA->update(['admin_id' => $this->adminA->id]);
        $this->memberA = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Membre Alpha Un']);
        $this->memberA2 = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Membre Alpha Deux']);
        $this->adminB = User::factory()->create(['organization_id' => $this->orgB->id, 'name' => 'Admin Beta']);
        $this->orgB->update(['admin_id' => $this->adminB->id]);
        $this->memberB = User::factory()->create(['organization_id' => $this->orgB->id, 'name' => 'Membre Beta']);
    }

    // =====================================================================
    // A. Autorite et coherence entre niveaux
    // =====================================================================

    public function test_the_three_levels_are_coherent_and_come_from_the_same_authority(): void
    {
        $this->seedRealisticMonth();
        $usage = app(OrganizationAiEconomicUsage::class);
        $period = AiConsumptionFilters::currentMonth();

        $platform = $usage->perOrganization($period->from, $period->to);

        // 1. La ligne de chaque Organization au cockpit plateforme EST son releve.
        foreach ([$this->orgA, $this->orgB] as $organization) {
            $summary = $usage->summary((string) $organization->id, $period->from, $period->to);
            $row = $platform['organizations'][(string) $organization->id];

            foreach (['generation', 'generation_sandbox', 'embedding_ingestion', 'embedding_query', 'embedding_undeclared'] as $bucket) {
                $this->assertSame($summary[$bucket], $row[$bucket], "{$organization->name} / {$bucket}");
            }
            $this->assertSame($summary['total_known_cost_usd'], $row['total_known_cost_usd']);
            $this->assertSame($summary['total_unknown_count'], $row['total_unknown_count']);
            $this->assertSame($summary['total_unevaluated_count'], $row['total_unevaluated_count']);
        }

        // 2. La somme des Organizations + non attribuable EST le total plateforme.
        $rows = [...array_values($platform['organizations']), $platform['unattributed']];
        $this->assertEqualsWithDelta(
            array_sum(array_map(static fn (array $r): float => (float) ($r['total_known_cost_usd'] ?? 0), $rows)),
            (float) $platform['totals']['known_cost_usd'],
            0.0000001,
        );
        $this->assertSame(array_sum(array_column($rows, 'total_unknown_count')), $platform['totals']['unknown_count']);
        $this->assertSame(array_sum(array_column($rows, 'total_unevaluated_count')), $platform['totals']['unevaluated_count']);
        foreach ([
            'generation_count' => ['generation', 'trace_count'],
            'generation_sandbox_count' => ['generation_sandbox', 'trace_count'],
            'embedding_query_count' => ['embedding_query', 'invocation_count'],
            'embedding_ingestion_count' => ['embedding_ingestion', 'invocation_count'],
            'embedding_undeclared_count' => ['embedding_undeclared', 'invocation_count'],
        ] as $total => [$bucket, $field]) {
            $this->assertSame(array_sum(array_map(static fn (array $r): int => $r[$bucket][$field], $rows)), $platform['totals'][$total], $total);
        }
        // Les valeurs attendues du jeu : tous les seaux sont exerces.
        $rowA = $platform['organizations'][(string) $this->orgA->id];
        $this->assertSame(1, $rowA['generation_sandbox']['trace_count']);
        $this->assertSame(1, $rowA['embedding_ingestion']['invocation_count']);
        $this->assertSame(2, $rowA['embedding_undeclared']['invocation_count']);
        $this->assertSame(1, $rowA['embedding_undeclared']['unknown_count']);
        $this->assertSame(1, $rowA['total_unevaluated_count']);
        // Utilisateurs IA de A : memberA, memberA2, adminA — jamais l'utilisateur
        // de B auquel une trace de A est attribuee.
        $this->assertSame(3, $rowA['ai_users_count']);
        // La trace historique sans Organization est comptee A PART, jamais repartie.
        $this->assertSame(1, $platform['unattributed']['generation']['trace_count']);
        $this->assertSame(0.7, (float) $platform['unattributed']['total_known_cost_usd']);

        // 3. La somme des utilisateurs (+ non attribuable) EST le total de l'Organization.
        $summaryA = $usage->summary((string) $this->orgA->id, $period->from, $period->to);
        $byUser = $usage->byUser((string) $this->orgA->id, $period->from, $period->to);
        $this->assertEqualsWithDelta(
            array_sum(array_map(static fn (array $r): float => (float) ($r['total_known_cost_usd'] ?? 0), $byUser)),
            (float) $summaryA['total_known_cost_usd'],
            0.0000001,
        );
        $this->assertSame(
            array_sum(array_map(static fn (array $r): int => $r['generation']['trace_count'], $byUser)),
            $summaryA['generation']['trace_count'],
        );
        $this->assertSame(
            array_sum(array_map(static fn (array $r): int => $r['embedding_query']['invocation_count'], $byUser)),
            $summaryA['embedding_query']['invocation_count'],
        );
        $this->assertSame(array_sum(array_column($byUser, 'total_unknown_count')), $summaryA['total_unknown_count']);
        $this->assertSame(array_sum(array_column($byUser, 'total_unevaluated_count')), $summaryA['total_unevaluated_count']);
        foreach (['embedding_ingestion', 'embedding_undeclared'] as $bucket) {
            $this->assertSame(
                array_sum(array_map(static fn (array $r): int => $r[$bucket]['invocation_count'], $byUser)),
                $summaryA[$bucket]['invocation_count'],
                $bucket,
            );
        }
        // La ligne non attribuable existe (generation ET embedding attribues a un
        // utilisateur de B) : comptee, jamais nommee.
        $unattributed = array_values(array_filter($byUser, static fn (array $r): bool => $r['user_id'] === null));
        $this->assertCount(1, $unattributed);
        $this->assertNull($unattributed[0]['name']);
        $this->assertSame(1, $unattributed[0]['generation']['trace_count']);
        $this->assertSame(1, $unattributed[0]['embedding_query']['invocation_count']);
        $this->assertNotContains('Membre Beta', array_column($byUser, 'name'));

        // 4. L'utilisateur voit exactement SA part de la MEME autorite.
        $mine = $usage->summary((string) $this->orgA->id, $period->from, $period->to, (string) $this->memberA->id);
        $line = array_values(array_filter($byUser, fn (array $r): bool => $r['user_id'] === $this->memberA->id))[0];
        $this->assertSame($line['generation'], $mine['generation']);
        $this->assertSame($line['embedding_query'], $mine['embedding_query']);
        $this->assertSame($line['total_known_cost_usd'], $mine['total_known_cost_usd']);
        $this->assertSame($line['total_unknown_count'], $mine['total_unknown_count']);
    }

    public function test_the_platform_row_matches_the_organization_page_and_the_org_page_matches_the_user_page(): void
    {
        $this->seedRealisticMonth();
        $superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgA->id]);
        $period = AiConsumptionFilters::currentMonth();
        $summaryA = app(OrganizationAiEconomicUsage::class)->summary((string) $this->orgA->id, $period->from, $period->to);

        $platform = $this->actingAs($superAdmin)->get(route('admin.ai-organizations'));
        $platform->assertOk();
        $platform->assertSee('data-platform-org="'.$this->orgA->id.'" data-platform-org-known-cost="'.$summaryA['total_known_cost_usd'].'" data-platform-org-unknown="'.$summaryA['total_unknown_count'].'"', false);

        $organization = $this->actingAs($this->adminA)->get($this->orgUrl());
        $organization->assertOk();
        $organization->assertSee('$'.number_format($summaryA['total_known_cost_usd'], 6));
        $organization->assertSee('data-consumption-economics-unknown="'.$summaryA['total_unknown_count'].'"', false);
        // L'utilisateur de B auquel une trace de A est attribuee n'est JAMAIS nomme.
        $organization->assertDontSee('Membre Beta');
        $organization->assertSee(__('ai.economy_unattributed'));
    }

    public function test_traces_of_a_deleted_organization_stay_in_the_platform_total_on_a_dedicated_line(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgA->id]);
        $this->generation($this->orgA, $this->memberA, cost: 0.10);
        $ghost = Organization::factory()->create(['name' => 'Org Fantome 1228']);
        $ghostMember = User::factory()->create(['organization_id' => $ghost->id]);
        $this->generation($ghost, $ghostMember, cost: 0.25);
        $ghost->delete();

        $page = $this->actingAs($superAdmin)->get(route('admin.ai-organizations'));
        $page->assertOk();
        // Le total plateforme contient la depense (elle est reelle)…
        $page->assertSee('data-platform-card="known-cost" data-platform-card-value="0.35"', false);
        // …sur une ligne dediee, pas dans une Organization vivante, ni « active ».
        $page->assertSee('data-platform-deleted="1"', false);
        $page->assertSee('$0.250000');
        $page->assertSee('data-platform-card="active-organizations" data-platform-card-value="1"', false);
        $page->assertDontSee('data-platform-org="'.$ghost->id.'"', false);
    }

    public function test_the_user_statement_is_the_users_organization_even_on_the_non_prefixed_route(): void
    {
        // memberB n'est pas dans l'Organization par defaut de la plateforme
        // (orgA, premiere creee) : la route non prefixee doit quand meme
        // rendre SON releve dans SON Organization.
        $this->generation($this->orgB, $this->memberB, cost: 0.42);

        $page = $this->actingAs($this->memberB)->get(route('profile.ai-usage'));
        $page->assertOk();
        $page->assertSee('data-my-ai-usage-month-count="1"', false);
        $page->assertSee('$0.420000');
    }

    // =====================================================================
    // B. Verite des chiffres
    // =====================================================================

    public function test_an_unknown_cost_is_never_rendered_as_zero_and_unknowns_are_counted_not_summed(): void
    {
        // Un seul appel, au cout inconnu : total connu NULL, inconnu = 1.
        $this->generation($this->orgA, $this->memberA, cost: null);
        $period = AiConsumptionFilters::currentMonth();
        $summary = app(OrganizationAiEconomicUsage::class)->summary((string) $this->orgA->id, $period->from, $period->to);

        $this->assertNull($summary['total_known_cost_usd']);
        $this->assertSame(1, $summary['total_unknown_count']);

        // Utilisateur : « — » et « 1 appel au cout non mesurable », jamais $0.
        $page = $this->actingAs($this->memberA)->get(route('profile.ai-usage'));
        $page->assertOk();
        $page->assertSee('data-my-ai-usage-unknown="1"', false);
        $page->assertSee(trans_choice('ai.economy_unknown_count', 1, ['count' => 1]));
        $page->assertDontSee('$0.000000');

        // Organization : consomme « — », reste = budget entier, inconnu compte.
        $org = $this->actingAs($this->adminA)->get($this->orgUrl());
        $org->assertOk();
        $this->assertMatchesRegularExpression('/data-consumption-budget-consumed[^>]*>\s*—\s*</u', $org->getContent());
        $org->assertSee('data-consumption-economics-unknown="1"', false);

        // Plateforme : cout fournisseur « — », carte inconnue = 1.
        $superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgA->id]);
        $platform = $this->actingAs($superAdmin)->get(route('admin.ai-organizations'));
        $platform->assertOk();
        $platform->assertSee('data-platform-card="known-cost" data-platform-card-value=""', false);
        $platform->assertSee('data-platform-card="unknown" data-platform-card-value="1"', false);
    }

    public function test_a_measured_zero_is_a_real_zero_and_differs_from_unknown(): void
    {
        $this->generation($this->orgA, $this->memberA, cost: 0.0);
        $this->generation($this->orgA, $this->memberA, cost: null);

        $period = AiConsumptionFilters::currentMonth();
        $summary = app(OrganizationAiEconomicUsage::class)->summary((string) $this->orgA->id, $period->from, $period->to);
        $this->assertSame(0.0, $summary['total_known_cost_usd']);
        $this->assertSame(1, $summary['total_unknown_count']);
        $this->assertSame(1, $summary['generation']['measured_count']);
    }

    public function test_generation_and_embeddings_are_never_counted_twice(): void
    {
        // Une operation reelle : la generation ecrit ai_interactions ET le
        // ledger ; l'embedding n'ecrit que le ledger. L'autorite lit la
        // generation UNE fois (ai_interactions) et l'embedding UNE fois.
        $this->generation($this->orgA, $this->memberA, cost: 0.10);
        $this->embedding($this->orgA, $this->memberA, 'query', cost: 0.01);

        $period = AiConsumptionFilters::currentMonth();
        $summary = app(OrganizationAiEconomicUsage::class)->summary((string) $this->orgA->id, $period->from, $period->to);

        $this->assertSame(1, $summary['generation']['trace_count']);
        $this->assertSame(1, $summary['embedding_query']['invocation_count']);
        $this->assertEqualsWithDelta(0.11, $summary['total_known_cost_usd'], 0.0000001);
        // Le ledger porte 2 lignes, l'autorite en compte 2 — pas 3.
        $this->assertSame(2, AiProviderInvocation::query()->count());
    }

    public function test_sandbox_lines_are_identified_and_distinguished_but_still_counted(): void
    {
        $this->generation($this->orgA, $this->adminA, cost: 0.20, feature: OrganizationDoctrineSandbox::FEATURE, process: 'help_request.clarify');
        $this->generation($this->orgA, $this->memberA, cost: 0.30);

        $period = AiConsumptionFilters::currentMonth();
        $summary = app(OrganizationAiEconomicUsage::class)->summary((string) $this->orgA->id, $period->from, $period->to);

        // Dans le budget (la depense est reelle)…
        $this->assertSame(2, $summary['generation']['trace_count']);
        $this->assertEqualsWithDelta(0.50, $summary['total_known_cost_usd'], 0.0000001);
        // …et distinguee comme sous-ensemble.
        $this->assertSame(1, $summary['generation_sandbox']['trace_count']);
        $this->assertEqualsWithDelta(0.20, $summary['generation_sandbox']['known_cost_usd'], 0.0000001);

        $org = $this->actingAs($this->adminA)->get($this->orgUrl());
        $org->assertSee('data-consumption-nature="sandbox" data-consumption-nature-count="1"', false);
        $org->assertSee(__('ai.economy_nature_sandbox'));

        $mine = $this->actingAs($this->adminA)->get(route('profile.ai-usage'));
        $mine->assertSee('data-my-ai-usage-nature="sandbox" data-my-ai-usage-nature-count="1"', false);
        $mine->assertSee(__('ai.activity_sandbox_label'));
        $mine->assertSee('data-my-ai-usage-feature="'.OrganizationDoctrineSandbox::FEATURE.'"', false);
    }

    public function test_the_user_history_speaks_product_language_and_never_a_technical_invocation(): void
    {
        $this->generation($this->orgA, $this->memberA, cost: 0.10, feature: 'loop_knowledge_answer', process: 'loop_knowledge.answer');
        $this->embedding($this->orgA, $this->memberA, 'query', cost: 0.001);

        $page = $this->actingAs($this->memberA)->get(route('profile.ai-usage'));
        $page->assertOk();
        $page->assertSee(__('ai.process_label.loop_knowledge_answer'));
        $page->assertSee(__('ai.usage_type_embedding_query'));
        $this->assertSame(2, substr_count($page->getContent(), 'data-my-ai-usage-row'));
        $page->assertSee('data-my-ai-usage-month-count="2"', false);
        $page->assertDontSee('invocation provider');
        $page->assertDontSee(self::API_KEY);
    }

    // =====================================================================
    // C. Periode
    // =====================================================================

    public function test_the_period_is_the_guard_window_in_utc_and_boundary_calls_fall_once_on_the_right_side(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-18 10:00:00', 'UTC'));
        $period = AiConsumptionFilters::currentMonth();
        $this->assertSame('2026-08-01 00:00:00', $period->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-01 00:00:00', $period->to->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $period->from->timezoneName);
        // Meme fenetre que la garde economique.
        $this->assertTrue($period->from->equalTo(now()->startOfMonth()));

        // Une trace a 23:59:59.5 UTC le 31/07 -> mois precedent ; a 00:00:00 le 01/08 -> ce mois.
        $before = $this->generation($this->orgA, $this->memberA, cost: 1.0);
        $before->forceFill(['created_at' => CarbonImmutable::parse('2026-07-31 23:59:59', 'UTC')])->saveQuietly();
        $onEdge = $this->generation($this->orgA, $this->memberA, cost: 2.0);
        $onEdge->forceFill(['created_at' => CarbonImmutable::parse('2026-08-01 00:00:00', 'UTC')])->saveQuietly();
        $atEnd = $this->generation($this->orgA, $this->memberA, cost: 4.0);
        $atEnd->forceFill(['created_at' => CarbonImmutable::parse('2026-08-31 23:59:59', 'UTC')])->saveQuietly();
        $next = $this->generation($this->orgA, $this->memberA, cost: 8.0);
        $next->forceFill(['created_at' => CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC')])->saveQuietly();

        $usage = app(OrganizationAiEconomicUsage::class);
        $summary = $usage->summary((string) $this->orgA->id, $period->from, $period->to);
        $this->assertSame(2, $summary['generation']['trace_count']);
        $this->assertEqualsWithDelta(6.0, $summary['total_known_cost_usd'], 0.0000001);

        // Meme decoupage aux trois niveaux.
        $platform = $usage->perOrganization($period->from, $period->to);
        $this->assertEqualsWithDelta(6.0, $platform['organizations'][(string) $this->orgA->id]['total_known_cost_usd'], 0.0000001);
        $mine = $usage->summary((string) $this->orgA->id, $period->from, $period->to, (string) $this->memberA->id);
        $this->assertEqualsWithDelta(6.0, $mine['total_known_cost_usd'], 0.0000001);

        // Et l'ecran l'annonce.
        $page = $this->actingAs($this->memberA)->get(route('profile.ai-usage'));
        $page->assertSee(__('ai.economy_period_label', ['from' => '01/08/2026', 'to' => '31/08/2026']));
    }

    // =====================================================================
    // D. Tenant / permissions
    // =====================================================================

    public function test_nothing_from_organization_b_appears_in_organization_a_figures(): void
    {
        $this->generation($this->orgA, $this->memberA, cost: 0.10);
        $this->generation($this->orgB, $this->memberB, cost: 9.99, feature: 'clarify_help_request');
        $this->embedding($this->orgB, $this->memberB, 'query', cost: 3.33);

        $period = AiConsumptionFilters::currentMonth();
        $summary = app(OrganizationAiEconomicUsage::class)->summary((string) $this->orgA->id, $period->from, $period->to);
        $this->assertEqualsWithDelta(0.10, $summary['total_known_cost_usd'], 0.0000001);
        $this->assertSame(0, $summary['embedding_query']['invocation_count']);

        $byUser = app(OrganizationAiEconomicUsage::class)->byUser((string) $this->orgA->id, $period->from, $period->to);
        $this->assertNotContains('Membre Beta', array_column($byUser, 'name'));

        $org = $this->actingAs($this->adminA)->get($this->orgUrl());
        $org->assertOk();
        $org->assertDontSee('Membre Beta');
        $org->assertDontSee('9.99');
        $org->assertDontSee('3.33');
    }

    public function test_a_member_sees_only_their_own_usage_and_no_organization_figure(): void
    {
        $this->generation($this->orgA, $this->memberA, cost: 0.10);
        $this->generation($this->orgA, $this->memberA2, cost: 0.90, feature: 'chatloop_ai_summarize', process: 'chatloop.summarize');

        $page = $this->actingAs($this->memberA)->get(route('profile.ai-usage'));
        $page->assertOk();
        $this->assertSame(1, substr_count($page->getContent(), 'data-my-ai-usage-row'));
        $page->assertSee('data-my-ai-usage-month-count="1"', false);
        $page->assertSee('$0.100000');
        // Ni le total de l'Organization (1.00), ni le budget (5.00), ni un autre membre.
        $page->assertDontSee('$1.000000');
        $page->assertDontSee('$5.00');
        $page->assertDontSee('Membre Alpha Deux');
        $page->assertDontSee(__('ai.consumption_budget_title'));
    }

    public function test_permissions_on_the_three_statements(): void
    {
        // Membre : 403 sur le releve Organization ; admin d'une autre Organization : 403.
        $this->actingAs($this->memberA)->get($this->orgUrl())->assertForbidden();
        $this->actingAs($this->adminB)->get($this->orgUrl())->assertForbidden();
        $this->actingAs($this->adminA)->get($this->orgUrl())->assertOk();

        // Non SuperAdmin : 403 sur l'agregat plateforme (meme un admin d'Organization).
        $this->actingAs($this->adminA)->get(route('admin.ai-organizations'))->assertForbidden();
        $this->actingAs($this->memberA)->get(route('admin.ai-organizations'))->assertForbidden();
        $superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgA->id]);
        $this->actingAs($superAdmin)->get(route('admin.ai-organizations'))->assertOk();
    }

    public function test_the_platform_aggregate_carries_no_private_content(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgA->id]);
        // Une generation dont le prompt/la reponse sont des sentinelles.
        AiInteraction::create([
            'user_id' => $this->memberA->id,
            'organization_id' => $this->orgA->id,
            'correlation_id' => (string) Str::uuid(),
            'process' => 'loop_knowledge.answer',
            'feature' => 'loop_knowledge_answer',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'QUESTION-PRIVEE-SENTINELLE-1228',
            'response' => 'REPONSE-PRIVEE-SENTINELLE-1228',
            'input_tokens' => 10,
            'output_tokens' => 5,
            'cost_usd' => 0.01,
            'cost_unknown' => false,
            'metadata' => ['provider' => 'openai', 'status' => 'success', 'dossier_title' => 'DOSSIER-TITRE-SENTINELLE-1228'],
        ]);

        $page = $this->actingAs($superAdmin)->get(route('admin.ai-organizations'));
        $page->assertOk();
        $page->assertDontSee('QUESTION-PRIVEE-SENTINELLE-1228');
        $page->assertDontSee('REPONSE-PRIVEE-SENTINELLE-1228');
        $page->assertDontSee('DOSSIER-TITRE-SENTINELLE-1228');
        $page->assertDontSee(self::API_KEY);
        $page->assertSee('Org Alpha 1228');
        $page->assertSee('$0.010000');
    }

    public function test_the_organization_page_shows_budget_consumed_remaining_and_percent(): void
    {
        $this->generation($this->orgA, $this->memberA, cost: 1.25);
        $this->embedding($this->orgA, $this->memberA, 'ingestion', cost: 0.25);

        $page = $this->actingAs($this->adminA)->get($this->orgUrl());
        $page->assertOk();
        $page->assertSee('data-consumption-budget-block', false);
        $this->assertMatchesRegularExpression('/data-consumption-budget-monthly[^>]*>\s*\$5\.00\s*</u', $page->getContent());
        $this->assertMatchesRegularExpression('/data-consumption-budget-consumed[^>]*>\s*\$1\.500000\s*</u', $page->getContent());
        $this->assertMatchesRegularExpression('/data-consumption-budget-remaining[^>]*>\s*\$3\.500000\s*</u', $page->getContent());
        $this->assertMatchesRegularExpression('/data-consumption-budget-percent[^>]*>\s*30\.0 %\s*</u', $page->getContent());
        $page->assertSee('data-consumption-nature="embedding_ingestion" data-consumption-nature-count="1"', false);
        $page->assertSee('data-consumption-top-user="'.$this->memberA->id.'"', false);

        // Sans aucune mesure mais avec budget : consomme « — », reste = budget, 0.0 % (regle de la garde).
        $fresh = Organization::factory()->create();
        OrganizationAiSetting::factory()->create(['organization_id' => $fresh->id, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'k', 'monthly_budget_usd' => 2.00]);
        $freshAdmin = User::factory()->create(['organization_id' => $fresh->id]);
        $fresh->update(['admin_id' => $freshAdmin->id]);
        $this->generation($fresh, $freshAdmin, cost: null);
        $freshPage = $this->actingAs($freshAdmin)->get($this->orgUrl($fresh));
        $this->assertMatchesRegularExpression('/data-consumption-budget-consumed[^>]*>\s*—\s*</u', $freshPage->getContent());
        $this->assertMatchesRegularExpression('/data-consumption-budget-remaining[^>]*>\s*\$2\.000000\s*</u', $freshPage->getContent());
        $this->assertMatchesRegularExpression('/data-consumption-budget-percent[^>]*>\s*0\.0 %\s*</u', $freshPage->getContent());

        // Sans budget (Organization B) : « — » et la note, jamais 0.
        $this->generation($this->orgB, $this->memberB, cost: 0.10);
        $pageB = $this->actingAs($this->adminB)->get($this->orgUrl($this->orgB));
        $pageB->assertOk();
        $this->assertMatchesRegularExpression('/data-consumption-budget-monthly[^>]*>\s*—\s*</u', $pageB->getContent());
        $pageB->assertSee('data-consumption-budget-none', false);

        // Periode personnalisee : consomme oui, reste non.
        $custom = $this->actingAs($this->adminA)->get($this->orgUrl().'?from='.now()->subDays(3)->toDateString().'&to='.now()->toDateString());
        $custom->assertOk();
        $custom->assertSee('data-consumption-budget-custom', false);
        $this->assertMatchesRegularExpression('/data-consumption-budget-remaining[^>]*>\s*—\s*</u', $custom->getContent());
    }

    public function test_the_platform_page_counts_ai_users_and_active_organizations(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgA->id]);
        $this->generation($this->orgA, $this->memberA, cost: 0.10);
        $this->generation($this->orgA, $this->memberA, cost: 0.10);
        $this->embedding($this->orgA, $this->memberA2, 'query', cost: 0.01);

        $page = $this->actingAs($superAdmin)->get(route('admin.ai-organizations'));
        $page->assertOk();
        // 2 utilisateurs IA distincts (memberA compte une fois), 1 Organization active sur 2.
        $page->assertSee('data-platform-card="ai-users" data-platform-card-value="2"', false);
        $page->assertSee('data-platform-card="active-organizations" data-platform-card-value="1"', false);
        $page->assertSee('data-platform-card="generation" data-platform-card-value="2"', false);
        $page->assertSee('data-platform-card="search" data-platform-card-value="1"', false);
    }

    public function test_the_platform_query_count_does_not_grow_with_organizations(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgA->id]);
        $this->generation($this->orgA, $this->memberA, cost: 0.10);

        $count = fn (): int => $this->countQueries(fn () => $this->actingAs($superAdmin)->get(route('admin.ai-organizations'))->assertOk());
        $baseline = $count();

        for ($i = 0; $i < 12; $i++) {
            $organization = Organization::factory()->create();
            $member = User::factory()->create(['organization_id' => $organization->id]);
            $this->generation($organization, $member, cost: 0.01);
            $this->embedding($organization, $member, 'query', cost: null);
        }

        $this->assertLessThanOrEqual($baseline + 2, $count());
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Un mois « realiste » : deux membres de A, un essai de doctrine, une
     * trace attribuee a un utilisateur de B (attribution non nommable), des
     * embeddings de deux natures dont un inconnu, une trace sans
     * Organization, et l'activite de B.
     */
    private function seedRealisticMonth(): void
    {
        $this->generation($this->orgA, $this->memberA, cost: 0.10);
        $this->generation($this->orgA, $this->memberA, cost: null);
        $this->generation($this->orgA, $this->memberA2, cost: 0.05, feature: 'chatloop_ai_summarize', process: 'chatloop.summarize');
        $this->generation($this->orgA, $this->adminA, cost: 0.02, feature: OrganizationDoctrineSandbox::FEATURE, process: 'help_request.clarify');
        // Incoherence historique : trace de A attribuee a un utilisateur de B.
        $this->generation($this->orgA, $this->memberB, cost: 0.03);
        $this->embedding($this->orgA, $this->memberA, 'query', cost: 0.001);
        $this->embedding($this->orgA, $this->memberA, 'query', cost: null);
        $this->embedding($this->orgA, $this->memberA2, 'ingestion', cost: 0.002);
        // Embedding de A attribue a un utilisateur de B : compte, jamais nomme.
        $this->embedding($this->orgA, $this->memberB, 'query', cost: 0.005);
        // Embedding sans operation declaree, et un autre hors catalogue : meme
        // seau « non declaree », additif.
        $this->embedding($this->orgA, $this->memberA, null, cost: 0.006);
        $this->embedding($this->orgA, $this->memberA, 'legacy_op', cost: null);
        // Trace historique jamais evaluee (cost_unknown NULL).
        $this->generation($this->orgA, $this->memberA2, cost: null)->forceFill(['cost_unknown' => null])->saveQuietly();
        // Trace historique sans Organization.
        $legacy = $this->generation($this->orgA, $this->memberA, cost: 0.70);
        $legacy->forceFill(['organization_id' => null])->saveQuietly();

        $this->generation($this->orgB, $this->memberB, cost: 0.40);
        $this->embedding($this->orgB, $this->memberB, 'ingestion', cost: 0.004);
    }

    private function generation(
        Organization $organization,
        User $user,
        ?float $cost,
        string $feature = 'clarify_help_request',
        string $process = 'help_request.clarify',
    ): AiInteraction {
        // Comme les ecrivains canoniques : trace P1 + ligne de ledger.
        AiProviderInvocation::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
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
            'correlation_id' => (string) Str::uuid(),
            'process' => $process,
            'feature' => $feature,
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'p',
            'response' => 'r',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cost_usd' => $cost,
            'cost_unknown' => $cost === null,
            'metadata' => ['provider' => 'openai', 'status' => 'success', 'capability' => $feature],
        ]);
    }

    private function embedding(Organization $organization, User $user, ?string $operation, ?float $cost): AiProviderInvocation
    {
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
            'status' => AiProviderInvocation::STATUS_SUCCESS,
        ]);
    }

    private function orgUrl(?Organization $organization = null): string
    {
        return route('organization.admin.ai-consumption', ['organization' => ($organization ?? $this->orgA)->slug]);
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
