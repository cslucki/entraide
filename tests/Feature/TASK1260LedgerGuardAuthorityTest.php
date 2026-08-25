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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1260 (G11-b) — bascule de l'autorite GARDE generation vers le ledger
 * canonique `ai_provider_invocations`, scope strict :
 *
 *  - `LEDGER_AUTHORITY_SINCE_BY_PROCESS` (mapping ferme, parite prouvee
 *    T1259) : chaque process lu au ledger A PARTIR de SON cutover
 *    (canoniques 18/08, Blog/Explorer 20/08 — correctif signal S2), au
 *    registre historique `ai_interactions` AVANT — fenetres disjointes PAR
 *    PROCESS, jamais de chevauchement ;
 *  - les process HORS liste (familles Supervision / bancs SuperAdmin) gardent
 *    la lecture historique EXACTE, cutover ou pas ;
 *  - une ligne ledger d'un process hors liste ne change JAMAIS un verdict ;
 *  - quota d'inconnus = operations metier distinctes
 *    (`COUNT(DISTINCT COALESCE(correlation_id, id))`), succes uniquement ;
 *  - le credit (G11-c) et les releves visibles (G11-d) ne bougent pas ici.
 */
class TASK1260LedgerGuardAuthorityTest extends TestCase
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
    // Correction 3 — fenetres de cutover (jamais de remplacement total)
    // =====================================================================

    public function test_pre_cutover_month_reads_the_legacy_registry_only(): void
    {
        Carbon::setTestNow('2026-07-15 12:00:00');

        $this->ledgerGeneration('chatloop.summarize', 50.00, '2026-07-10 10:00:00');

        $verdict = $this->authorize('chatloop.summarize');
        $this->assertTrue($verdict->allowed);
        $this->assertSame(0.0, $verdict->knownMonthlyCostUsd);

        $this->interaction('chatloop.summarize', 2.00, false, '2026-07-10 10:00:00');

        $this->assertSame(
            AiEconomicGuard::REASON_MONTHLY_BUDGET_REACHED,
            $this->authorize('chatloop.summarize')->reason,
        );
    }

    public function test_post_cutover_month_reads_the_ledger_only_for_migrated_processes(): void
    {
        Carbon::setTestNow('2026-09-10 12:00:00');

        // Une ligne registre sans jumelle ledger ne peut venir que d'une
        // fixture : post-cutover elle n'est plus l'autorite du perimetre.
        $this->interaction('chatloop.summarize', 50.00, false, '2026-09-05 10:00:00');

        $verdict = $this->authorize('chatloop.summarize');
        $this->assertTrue($verdict->allowed);
        $this->assertSame(0.0, $verdict->knownMonthlyCostUsd);

        $this->ledgerGeneration('chatloop.summarize', 2.00, '2026-09-05 11:00:00');

        $this->assertSame(
            AiEconomicGuard::REASON_MONTHLY_BUDGET_REACHED,
            $this->authorize('chatloop.summarize')->reason,
        );
    }

    public function test_transition_month_sums_disjoint_windows_exactly(): void
    {
        Carbon::setTestNow('2026-08-20 12:00:00');

        // Comptees : registre AVANT le cutover, ledger A PARTIR du cutover.
        $this->interaction('chatloop.summarize', 1.00, false, '2026-08-10 10:00:00');
        $this->ledgerGeneration('chatloop.summarize', 1.00, '2026-08-19 10:00:00');
        // Ignorees : registre post-cutover (la jumelle de la ligne ledger
        // ci-dessus dans le monde reel) et ledger pre-cutover (periode ou le
        // registre est l'autorite) — sans quoi transition = double comptage.
        $this->interaction('chatloop.summarize', 5.00, false, '2026-08-19 10:00:00');
        $this->ledgerGeneration('chatloop.summarize', 5.00, '2026-08-15 10:00:00');

        $verdict = $this->authorize('chatloop.summarize', budget: 2.50);
        $this->assertTrue($verdict->allowed);
        $this->assertSame(2.00, round($verdict->knownMonthlyCostUsd, 6));

        $this->assertSame(
            AiEconomicGuard::REASON_MONTHLY_BUDGET_REACHED,
            $this->authorize('chatloop.summarize', budget: 2.00)->reason,
        );
    }

    public function test_cutover_boundary_line_counts_exactly_once(): void
    {
        Carbon::setTestNow('2026-08-20 12:00:00');

        // Jumelles ecrites PILE a l'instant du cutover : la ligne appartient
        // au ledger (`>=`), jamais au registre (`<` strict) — comptee une
        // fois (1.50), jamais deux (3.00), jamais zero.
        $this->interaction('chatloop.summarize', 1.50, false, '2026-08-18 00:00:00');
        $this->ledgerGeneration('chatloop.summarize', 1.50, '2026-08-18 00:00:00');

        $verdict = $this->authorize('chatloop.summarize', budget: 10.00);
        $this->assertTrue($verdict->allowed);
        $this->assertSame(1.50, round($verdict->knownMonthlyCostUsd, 6));
    }

    // =====================================================================
    // Correction 2 — les familles hors liste ne bougent pas, dans les deux sens
    // =====================================================================

    public function test_out_of_scope_ledger_lines_never_change_any_verdict(): void
    {
        Carbon::setTestNow('2026-09-10 12:00:00');

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'api_key' => 'sk-task1260',
            'monthly_budget_usd' => 2.00,
        ]);

        // Grosse depense ledger d'une famille restee hors mapping (le banc
        // test LLM — T1286 a migre les deux process agent de profil, le banc
        // reste dehors) : le ledger la porte — c'est son role — mais
        // l'autorite de la garde G11-b ne la lit pas.
        $this->ledgerGeneration('member_profile.admin_llm_test', 100.00, '2026-09-05 10:00:00');

        // Ni sur le plafond Organization ni sur le verrou du process migre.
        $verdict = $this->authorize('chatloop.summarize');
        $this->assertTrue($verdict->allowed);
        $this->assertSame(0.0, $verdict->knownMonthlyCostUsd);

        // Ni sur le verrou par process de la famille elle-meme (sa voie
        // historique lit `ai_interactions`, ou elle n'ecrit jamais).
        $verdict = $this->authorize('member_profile.admin_llm_test');
        $this->assertTrue($verdict->allowed);
        $this->assertSame(0.0, $verdict->knownMonthlyCostUsd);
    }

    public function test_out_of_scope_processes_keep_the_historical_registry_authority(): void
    {
        Carbon::setTestNow('2026-09-10 12:00:00');

        // Post-cutover, une trace registre d'un process HORS liste compte
        // toujours : le cutover ne s'applique qu'au perimetre migre.
        // (T1286 : l'exemple etait `member_profile.loop_agent_reply`,
        // desormais migre — le banc test LLM reste, lui, hors mapping.)
        $this->interaction('member_profile.admin_llm_test', 2.00, false, '2026-09-05 10:00:00');

        $this->assertSame(
            AiEconomicGuard::REASON_MONTHLY_BUDGET_REACHED,
            $this->authorize('member_profile.admin_llm_test')->reason,
        );
    }

    // =====================================================================
    // Quota d'inconnus migre — statut et operations (3.3 / 3.4 du plan)
    // =====================================================================

    public function test_failed_ledger_attempts_do_not_consume_the_unknown_quota(): void
    {
        Carbon::setTestNow('2026-09-10 12:00:00');

        // Au registre, un echec portait `cost_unknown = NULL` et n'entrait
        // nulle part ; au ledger il porte `cost_status = unknown` — sans le
        // filtre de statut, une panne (multipliee par les retries de job)
        // fermerait le process pour le mois.
        foreach (range(1, 10) as $unused) {
            $this->ledgerGeneration(
                'chatloop.summarize',
                null,
                '2026-09-05 10:00:00',
                status: AiProviderInvocation::STATUS_FAILED,
                correlationId: (string) Str::uuid(),
            );
        }

        $verdict = $this->authorize('chatloop.summarize');
        $this->assertTrue($verdict->allowed);
        $this->assertSame(0, $verdict->successfulUnknownCount);

        foreach (range(1, 10) as $unused) {
            $this->ledgerGeneration(
                'chatloop.summarize',
                null,
                '2026-09-05 11:00:00',
                correlationId: (string) Str::uuid(),
            );
        }

        $this->assertSame(
            AiEconomicGuard::REASON_UNKNOWN_QUOTA_REACHED,
            $this->authorize('chatloop.summarize')->reason,
        );
    }

    public function test_a_retried_operation_consumes_the_unknown_quota_once(): void
    {
        Carbon::setTestNow('2026-09-10 12:00:00');

        // Le ledger ecrit une ligne PAR TENTATIVE : deux tentatives d'une
        // MEME operation metier (meme correlation_id) = une seule operation.
        $correlationId = (string) Str::uuid();
        $this->ledgerGeneration('chatloop.summarize', null, '2026-09-05 10:00:00', correlationId: $correlationId);
        $this->ledgerGeneration('chatloop.summarize', null, '2026-09-05 10:01:00', correlationId: $correlationId);

        $verdict = $this->authorize('chatloop.summarize', unknownLimit: 2);
        $this->assertTrue($verdict->allowed);
        $this->assertSame(1, $verdict->successfulUnknownCount);

        // Une ligne SANS correlation est sa propre operation (COALESCE sur
        // l'id) : jamais ignoree, jamais fusionnee avec une autre orpheline.
        $this->ledgerGeneration('chatloop.summarize', null, '2026-09-05 10:02:00');

        $refused = $this->authorize('chatloop.summarize', unknownLimit: 2);
        $this->assertSame(AiEconomicGuard::REASON_UNKNOWN_QUOTA_REACHED, $refused->reason);
        $this->assertSame(2, $refused->successfulUnknownCount);
    }

    // =====================================================================
    // Parite de verdict — la preuve read-only de G11-a rendue executable
    // =====================================================================

    public function test_verdict_parity_with_twin_writes_across_the_whole_scope(): void
    {
        // Mois de transition : le scenario le plus exigeant (deux fenetres
        // actives). Jumelles fideles au monde reel : chaque operation ecrit
        // LES DEUX tables dans la meme methode.
        Carbon::setTestNow('2026-08-20 12:00:00');

        foreach (AiEconomicGuard::ledgerAuthorityProcesses() as $process) {
            $this->interaction($process, 0.10, false, '2026-08-05 10:00:00');
            $this->ledgerGeneration($process, 0.10, '2026-08-05 10:00:00', correlationId: (string) Str::uuid());

            $this->interaction($process, 0.10, false, '2026-08-19 10:00:00');
            $this->ledgerGeneration($process, 0.10, '2026-08-19 10:00:00', correlationId: (string) Str::uuid());
        }

        foreach (AiEconomicGuard::ledgerAuthorityProcesses() as $process) {
            // L'ancienne autorite : la somme registre du mois entier.
            $legacyWholeMonth = (float) DB::table('ai_interactions')
                ->where('organization_id', $this->organization->id)
                ->where('process', $process)
                ->where('cost_unknown', false)
                ->sum('cost_usd');

            $verdict = $this->authorize($process, budget: 1000.0);
            $this->assertTrue($verdict->allowed);
            $this->assertSame(
                round($legacyWholeMonth, 6),
                round($verdict->knownMonthlyCostUsd, 6),
                "Verdict parity broken for [{$process}].",
            );
            $this->assertSame(0.20, round($verdict->knownMonthlyCostUsd, 6));

            $this->assertSame(
                AiEconomicGuard::REASON_MONTHLY_BUDGET_REACHED,
                $this->authorize($process, budget: 0.20)->reason,
            );
            $this->assertTrue($this->authorize($process, budget: 0.21)->allowed);
        }
    }

    // =====================================================================
    // Plafond Organization — fenetres partagees, embeddings sans cutover
    // =====================================================================

    public function test_org_wide_ceiling_splits_generation_windows_and_keeps_embeddings_full_month(): void
    {
        Carbon::setTestNow('2026-08-20 12:00:00');

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'api_key' => 'sk-task1260',
            'monthly_budget_usd' => 2.00,
        ]);

        // Comptes : generation registre pre-cutover + generation ledger
        // post-cutover + embedding connu PRE-cutover (les embeddings etaient
        // deja lus au ledger sur tout le mois, le cutover ne les touche pas).
        $this->interaction('chatloop.summarize', 0.80, false, '2026-08-10 10:00:00');
        $this->ledgerGeneration('chatloop.summarize', 0.70, '2026-08-19 10:00:00');
        $this->ledgerEmbedding(0.50, '2026-08-10 09:00:00');
        // Ignoree : generation registre post-cutover (jumelle test).
        $this->interaction('chatloop.summarize', 5.00, false, '2026-08-19 10:00:00');

        $verdict = $this->authorize('chatloop.summarize', budget: 100.0);
        $this->assertFalse($verdict->allowed);
        $this->assertSame(AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED, $verdict->reason);
        $this->assertSame(2.00, round($verdict->knownMonthlyCostUsd, 6));
    }

    // =====================================================================
    // Correctif S2 — cutover PAR PROCESS (Blog 20/08, canoniques 18/08)
    // =====================================================================

    public function test_blog_registry_lines_between_the_two_cutovers_still_count(): void
    {
        Carbon::setTestNow('2026-08-20 12:00:00');

        // Le scenario reel du 18-19/08 pour Blog : `ai_interactions` ecrite,
        // AUCUNE jumelle ledger (T1247/T1248, qui ont commence le ledger
        // Blog, n'etaient pas mergees). Un cutover Blog au 18/08 ferait
        // disparaitre ce cout — la perte silencieuse visee par le signal S2.
        $this->interaction('blog.explorer_dialogue', 2.00, false, '2026-08-19 10:00:00');

        // (a) le verrou par process la compte toujours.
        $refused = $this->authorize('blog.explorer_dialogue', budget: 2.00);
        $this->assertSame(AiEconomicGuard::REASON_MONTHLY_BUDGET_REACHED, $refused->reason);
        $this->assertSame(2.00, round($refused->knownMonthlyCostUsd, 6));

        // (b) le plafond Organization la compte toujours.
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'api_key' => 'sk-task1260',
            'monthly_budget_usd' => 2.00,
        ]);

        $orgRefused = $this->authorize('blog.explorer_dialogue', budget: 100.0);
        $this->assertSame(AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED, $orgRefused->reason);
        $this->assertSame(2.00, round($orgRefused->knownMonthlyCostUsd, 6));
    }

    public function test_blog_line_at_the_blog_cutover_goes_to_the_ledger_only(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        // Jumelles ecrites PILE a l'instant du cutover BLOG (20/08, pas
        // 18/08) : la ligne appartient au ledger (`>=`), jamais au registre
        // (`<` strict) — comptee une fois (1.50), jamais deux, jamais zero.
        $this->interaction('blog.explorer_note', 1.50, false, '2026-08-20 00:00:00');
        $this->ledgerGeneration('blog.explorer_note', 1.50, '2026-08-20 00:00:00');

        $verdict = $this->authorize('blog.explorer_note', budget: 10.00);
        $this->assertTrue($verdict->allowed);
        $this->assertSame(1.50, round($verdict->knownMonthlyCostUsd, 6));
    }

    public function test_mixed_cutovers_in_the_same_month_lose_nothing_and_double_count_nothing(): void
    {
        // Aout 2026, le cas le plus exigeant : canonique (cutover 18/08) et
        // Blog (cutover 20/08) dans le MEME mois calendaire, chaque groupe
        // avec ses deux fenetres propres a l'interieur de la somme org-wide.
        Carbon::setTestNow('2026-08-25 12:00:00');

        // Canonique : registre pre-18/08 compte…
        $this->interaction('chatloop.summarize', 0.10, false, '2026-08-10 10:00:00');
        // …jumelles post-18/08 : ledger compte, registre ignore.
        $this->interaction('chatloop.summarize', 0.20, false, '2026-08-19 10:00:00');
        $this->ledgerGeneration('chatloop.summarize', 0.20, '2026-08-19 10:00:00');

        // Blog : registre SEUL du 19/08 (entre les deux cutovers) compte —
        // c'est la ligne que le cutover unique du 18/08 aurait perdue…
        $this->interaction('blog.explorer_note', 0.30, false, '2026-08-19 11:00:00');
        // …jumelles post-20/08 : ledger compte, registre ignore.
        $this->interaction('blog.explorer_note', 0.40, false, '2026-08-21 10:00:00');
        $this->ledgerGeneration('blog.explorer_note', 0.40, '2026-08-21 10:00:00');

        // Bruit qui ne doit jamais compter : ledger canonique pre-cutover
        // (periode ou le registre est l'autorite du groupe canonique).
        $this->ledgerGeneration('chatloop.summarize', 5.00, '2026-08-16 10:00:00');
        // Ledger Blog du 19/08 : pre-cutover BLOG, ignore lui aussi.
        $this->ledgerGeneration('blog.explorer_note', 5.00, '2026-08-19 11:30:00');

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'api_key' => 'sk-task1260',
            'monthly_budget_usd' => 1.00,
        ]);

        // 0.10 + 0.20 + 0.30 + 0.40 = 1.00 exactement : aucune perte (le
        // 0.30 Blog inter-cutovers est la), aucun double comptage (chaque
        // jumelle une fois), aucun bruit (les 5.00 absents).
        $refused = $this->authorize('chatloop.summarize', budget: 100.0);
        $this->assertSame(AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED, $refused->reason);
        $this->assertSame(1.00, round($refused->knownMonthlyCostUsd, 6));

        OrganizationAiSetting::query()->update(['monthly_budget_usd' => 1.01]);
        $this->assertTrue($this->authorize('chatloop.summarize', budget: 100.0)->allowed);
    }

    // =====================================================================
    // Helpers
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
            'feature' => 'task1260_fixture',
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

    private function ledgerGeneration(
        string $process,
        ?float $knownCost,
        string $createdAt,
        string $status = AiProviderInvocation::STATUS_SUCCESS,
        ?string $correlationId = null,
    ): void {
        $invocation = AiProviderInvocation::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'process' => $process,
            'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'provider' => 'openrouter',
            'model' => 'unpriced',
            'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            'provider_cost' => $knownCost,
            'currency' => $knownCost !== null ? 'USD' : null,
            'cost_status' => $knownCost !== null ? AiProviderInvocation::COST_KNOWN : AiProviderInvocation::COST_UNKNOWN,
            'cost_source' => $knownCost !== null ? 'catalog_estimated' : 'unknown',
            'status' => $status,
            'correlation_id' => $correlationId,
        ]);

        $invocation->forceFill(['created_at' => CarbonImmutable::parse($createdAt)])->saveQuietly();
    }

    private function ledgerEmbedding(float $knownCost, string $createdAt): void
    {
        $invocation = AiProviderInvocation::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'process' => 'dossier.embeddings_index',
            'operation' => AiProviderInvocation::OPERATION_EMBEDDING,
            'embedding_operation' => AiProviderInvocation::EMBEDDING_OPERATION_INGESTION,
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            'provider_cost' => $knownCost,
            'currency' => 'USD',
            'cost_status' => AiProviderInvocation::COST_KNOWN,
            'cost_source' => 'catalog_estimated',
            'status' => AiProviderInvocation::STATUS_SUCCESS,
        ]);

        $invocation->forceFill(['created_at' => CarbonImmutable::parse($createdAt)])->saveQuietly();
    }
}
