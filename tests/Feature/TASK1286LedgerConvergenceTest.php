<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiEconomicVerdict;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1286 (NIGHT ENDGAME V2, ECONOMIE) — convergence economique des
 * chemins agent de profil DEJA sous ledger vers l'autorite de generation
 * canonique de la garde :
 *
 *  - `member_profile.loop_agent_reply` (ledger depuis T1251, PR #255,
 *    merge 19/08 10:38 UTC) et `member_profile.agent_visitor_chat` (ledger
 *    depuis T1252, PR #256, merge 19/08 11:36 UTC) entrent au mapping
 *    `LEDGER_AUTHORITY_SINCE_BY_PROCESS` avec le cutover 20/08 00:00Z —
 *    premier minuit UTC entierement couvert par leurs ecritures ledger,
 *    decision produit FIGEE, jamais un MIN(created_at) runtime ;
 *  - fenetres DISJOINTES par process : une generation presente dans les
 *    deux registres autour du cutover ne compte qu'UNE fois ;
 *  - les process NON converges restent sur l'autorite historique
 *    `ai_interactions`, ou leurs lignes ledger ne comptent JAMAIS. Depuis
 *    TASK-1291 (HARD GATE tenant leve : garde d'appartenance fail-closed,
 *    tenant de l'ACTEUR), `member_profile.agent_setup` et
 *    `service_offer.master` ont converge (cutover 25/08 00:00Z) — il ne
 *    reste dehors que les bancs SuperAdmin : `supervision.content` (tenant
 *    = resolution par defaut, exclu d'office) et
 *    `member_profile.admin_llm_test` (decision produit en attente, Review
 *    Notes T1286) ;
 *  - la liste des process migres reste DERIVEE du mapping par
 *    `array_keys()`, jamais une seconde constante.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1286LedgerConvergenceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    private AiEconomicGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->guard = new AiEconomicGuard;
    }

    // =====================================================================
    // 0. Le mapping lui-meme : decision figee + liste derivee
    // =====================================================================

    public function test_converged_agent_processes_carry_their_frozen_cutover_instant(): void
    {
        // L'instant est une DECISION PRODUIT figee dans le code — le test la
        // fige une seconde fois : la changer exige de changer les deux.
        $this->assertSame(
            '2026-08-20T00:00:00+00:00',
            AiEconomicGuard::LEDGER_AUTHORITY_SINCE_BY_PROCESS['member_profile.loop_agent_reply'],
        );
        $this->assertSame(
            '2026-08-20T00:00:00+00:00',
            AiEconomicGuard::LEDGER_AUTHORITY_SINCE_BY_PROCESS['member_profile.agent_visitor_chat'],
        );
    }

    public function test_converged_short_surface_processes_carry_their_frozen_cutover_instant(): void
    {
        // TASK-1291 : HARD GATE tenant leve (garde d'appartenance
        // fail-closed, tenant de l'ACTEUR sur les deux surfaces) — cutover
        // VALIDE 25/08 00:00Z, premier minuit UTC entierement couvert par le
        // comportement corrige. Meme regle : decision produit figee deux
        // fois, la changer exige de changer code ET test.
        $this->assertSame(
            '2026-08-25T00:00:00+00:00',
            AiEconomicGuard::LEDGER_AUTHORITY_SINCE_BY_PROCESS['member_profile.agent_setup'],
        );
        $this->assertSame(
            '2026-08-25T00:00:00+00:00',
            AiEconomicGuard::LEDGER_AUTHORITY_SINCE_BY_PROCESS['service_offer.master'],
        );
    }

    public function test_process_list_stays_derived_from_the_mapping_by_array_keys(): void
    {
        $this->assertSame(
            array_keys(AiEconomicGuard::LEDGER_AUTHORITY_SINCE_BY_PROCESS),
            AiEconomicGuard::ledgerAuthorityProcesses(),
        );

        // 12 (T1286) + les 2 de la surface courte (T1291) = 14.
        $this->assertCount(14, AiEconomicGuard::ledgerAuthorityProcesses());
    }

    // =====================================================================
    // 1. La depense d'un process converge COMPTE desormais pour la garde
    // =====================================================================

    public function test_converged_agent_spend_now_raises_the_organization_ceiling(): void
    {
        Carbon::setTestNow('2026-09-10 12:00:00');

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'api_key' => 'sk-task1286',
            'monthly_budget_usd' => 2.00,
        ]);

        // Avant T1286, ces deux lignes n'entraient dans AUCUN plafond
        // d'Organization (le trou : depense reelle, ligne ledger presente,
        // budget aveugle). Desormais elles portent le plafond.
        $this->ledgerGeneration('member_profile.loop_agent_reply', 1.50, '2026-09-05 10:00:00');
        $this->ledgerGeneration('member_profile.agent_visitor_chat', 0.60, '2026-09-06 10:00:00');

        $refused = $this->authorize('chatloop.summarize', budget: 100.0);
        $this->assertSame(AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED, $refused->reason);
        $this->assertSame(2.10, round($refused->knownMonthlyCostUsd, 6));

        OrganizationAiSetting::query()->update(['monthly_budget_usd' => 2.11]);
        $this->assertTrue($this->authorize('chatloop.summarize', budget: 100.0)->allowed);
    }

    public function test_converged_process_budget_reads_the_ledger_post_cutover(): void
    {
        Carbon::setTestNow('2026-09-10 12:00:00');

        $this->ledgerGeneration('member_profile.agent_visitor_chat', 2.00, '2026-09-05 10:00:00');

        // Une trace registre post-cutover du meme process ne compte plus :
        // l'autorite du mois entier est le ledger (cutover clampe au debut
        // du mois — aucune perte, aucun double comptage).
        $this->interaction('member_profile.agent_visitor_chat', 5.00, false, '2026-09-05 10:00:00');

        $refused = $this->authorize('member_profile.agent_visitor_chat');
        $this->assertSame(AiEconomicGuard::REASON_MONTHLY_BUDGET_REACHED, $refused->reason);
        $this->assertSame(2.00, round($refused->knownMonthlyCostUsd, 6));
    }

    // =====================================================================
    // 2. Mois de transition : fenetres disjointes, une generation = une fois
    // =====================================================================

    public function test_twin_written_generation_around_the_cutover_counts_exactly_once(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        // AVANT le cutover (19/08) : l'autorite est le registre. La jumelle
        // ledger de la MEME generation (T1252 ecrivait deja les deux) doit
        // etre ignoree — sans quoi elle compterait deux fois.
        $this->interaction('member_profile.agent_visitor_chat', 0.10, false, '2026-08-19 10:00:00');
        $this->ledgerGeneration('member_profile.agent_visitor_chat', 5.00, '2026-08-19 10:00:00');

        // APRES le cutover (21/08) : l'autorite est le ledger. La trace
        // registre jumelle doit etre ignoree, symetriquement.
        $this->ledgerGeneration('member_profile.agent_visitor_chat', 0.20, '2026-08-21 10:00:00');
        $this->interaction('member_profile.agent_visitor_chat', 5.00, false, '2026-08-21 10:00:00');

        // PILE au cutover : la ligne appartient au ledger (`>=`), jamais au
        // registre (`<` strict) — comptee une fois, jamais deux, jamais zero.
        $this->ledgerGeneration('member_profile.loop_agent_reply', 0.05, '2026-08-20 00:00:00');
        $this->interaction('member_profile.loop_agent_reply', 5.00, false, '2026-08-20 00:00:00');

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'api_key' => 'sk-task1286',
            'monthly_budget_usd' => 0.35,
        ]);

        // 0.10 + 0.20 + 0.05 = 0.35 exactement : chaque generation une fois,
        // aucun 5.00 de jumelle n'est entre.
        $refused = $this->authorize('chatloop.summarize', budget: 100.0);
        $this->assertSame(AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED, $refused->reason);
        $this->assertSame(0.35, round($refused->knownMonthlyCostUsd, 6));

        OrganizationAiSetting::query()->update(['monthly_budget_usd' => 0.36]);
        $this->assertTrue($this->authorize('chatloop.summarize', budget: 100.0)->allowed);
    }

    // =====================================================================
    // 3. Les process NON converges restent exclus, dans les deux sens
    // =====================================================================

    public function test_non_converged_processes_stay_excluded_from_ledger_authority(): void
    {
        Carbon::setTestNow('2026-09-10 12:00:00');

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'api_key' => 'sk-task1286',
            'monthly_budget_usd' => 2.00,
        ]);

        // Apres TASK-1291, seuls les deux bancs SuperAdmin restent dehors.
        // Leurs lignes ledger existent (c'est le role du ledger) mais ne
        // portent NI le plafond Organization NI leur verrou par process.
        foreach ([
            'supervision.content',
            'member_profile.admin_llm_test',
        ] as $process) {
            $this->ledgerGeneration($process, 100.00, '2026-09-05 10:00:00');
        }

        $verdict = $this->authorize('chatloop.summarize');
        $this->assertTrue($verdict->allowed);
        $this->assertSame(0.0, $verdict->knownMonthlyCostUsd);

        foreach ([
            'supervision.content',
            'member_profile.admin_llm_test',
        ] as $process) {
            $verdict = $this->authorize($process);
            $this->assertTrue($verdict->allowed, $process);
            $this->assertSame(0.0, $verdict->knownMonthlyCostUsd, $process);
        }
    }

    public function test_non_converged_processes_keep_the_historical_registry_authority(): void
    {
        Carbon::setTestNow('2026-09-10 12:00:00');

        // Leur voie historique (`ai_interactions`, mois entier) est INTACTE :
        // une trace registre les bloque toujours, cutover ou pas.
        $this->interaction('supervision.content', 2.00, false, '2026-09-05 10:00:00');

        $this->assertSame(
            AiEconomicGuard::REASON_MONTHLY_BUDGET_REACHED,
            $this->authorize('supervision.content')->reason,
        );
    }

    // =====================================================================
    // 4. TASK-1291 : les deux process de la surface courte ONT converge
    // =====================================================================

    public function test_converged_short_surface_spend_now_raises_the_organization_ceiling(): void
    {
        Carbon::setTestNow('2026-09-10 12:00:00');

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'api_key' => 'sk-task1286',
            'monthly_budget_usd' => 2.00,
        ]);

        // Avant T1291, ces lignes ledger n'entraient dans AUCUN plafond
        // (exclusion HARD GATE : tenant possiblement par defaut). Le tenant
        // est desormais celui de l'ACTEUR : elles portent le plafond.
        $this->ledgerGeneration('member_profile.agent_setup', 1.50, '2026-09-05 10:00:00');
        $this->ledgerGeneration('service_offer.master', 0.60, '2026-09-06 10:00:00');

        $refused = $this->authorize('chatloop.summarize', budget: 100.0);
        $this->assertSame(AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED, $refused->reason);
        $this->assertSame(2.10, round($refused->knownMonthlyCostUsd, 6));

        OrganizationAiSetting::query()->update(['monthly_budget_usd' => 2.11]);
        $this->assertTrue($this->authorize('chatloop.summarize', budget: 100.0)->allowed);
    }

    public function test_converged_short_surface_process_budget_reads_the_ledger_post_cutover(): void
    {
        Carbon::setTestNow('2026-09-10 12:00:00');

        $this->ledgerGeneration('member_profile.agent_setup', 2.00, '2026-09-05 10:00:00');

        // La trace registre jumelle post-cutover ne compte plus : meme
        // regle de fenetres disjointes que les autres process converges.
        $this->interaction('member_profile.agent_setup', 5.00, false, '2026-09-05 10:00:00');

        $refused = $this->authorize('member_profile.agent_setup');
        $this->assertSame(AiEconomicGuard::REASON_MONTHLY_BUDGET_REACHED, $refused->reason);
        $this->assertSame(2.00, round($refused->knownMonthlyCostUsd, 6));
    }

    // =====================================================================
    // Helpers (patron T1260, a l'identique)
    // =====================================================================

    private function authorize(string $process, float $budget = 2.00, int $unknownLimit = 10): AiEconomicVerdict
    {
        return $this->guard->authorize(
            $this->organization->fresh(),
            $process,
            'openrouter',
            'unpriced',
            $budget,
            $unknownLimit,
        );
    }

    private function interaction(string $process, ?float $cost, ?bool $unknown, string $createdAt): void
    {
        $interaction = AiInteraction::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'process' => $process,
            'feature' => 'task1286_fixture',
            'model' => 'provider/model',
            'prompt' => 'prompt',
            'response' => 'response',
            'input_tokens' => 1,
            'output_tokens' => 1,
            'cost_usd' => $cost,
            'cost_unknown' => $unknown,
        ]);

        // `created_at` n'est pas fillable : passe a create(), il serait
        // ignore en silence et la ligne serait datee d'aujourd'hui.
        $interaction->forceFill(['created_at' => CarbonImmutable::parse($createdAt)])->saveQuietly();
    }

    private function ledgerGeneration(string $process, ?float $knownCost, string $createdAt): void
    {
        $invocation = AiProviderInvocation::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'process' => $process,
            'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'provider' => 'openrouter',
            'model' => 'unpriced',
            'credential_source' => AiProviderInvocation::CREDENTIAL_PLATFORM,
            'provider_cost' => $knownCost,
            'currency' => $knownCost !== null ? 'USD' : null,
            'cost_status' => $knownCost !== null ? AiProviderInvocation::COST_KNOWN : AiProviderInvocation::COST_UNKNOWN,
            'cost_source' => $knownCost !== null ? 'catalog_estimated' : 'unknown',
            'status' => AiProviderInvocation::STATUS_SUCCESS,
            'correlation_id' => null,
        ]);

        $invocation->forceFill(['created_at' => CarbonImmutable::parse($createdAt)])->saveQuietly();
    }
}
