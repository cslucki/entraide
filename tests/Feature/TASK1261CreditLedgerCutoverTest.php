<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\OrganizationAiEconomicUsage;
use App\Services\Ai\OrganizationDoctrineSandbox;
use App\Support\Ai\AiEconomicGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1261 (G11-c) — bascule du CREDIT membre (`userCreditUses()`,
 * `creditUsesByUser()`, `creditUsesByOrganizationAndUser()`) vers le ledger
 * canonique `ai_provider_invocations`, cutover GLOBAL fige
 * `OrganizationAiEconomicUsage::CREDIT_LEDGER_AUTHORITY_SINCE`
 * (2026-09-01T00:00:00Z).
 *
 * Les 10 familles du plan Phase 1 (TASK file, section 8), plus le test de
 * changement d'Organization en cours de mois explicitement demande par M1.
 * La 10e famille (suite existante gardee verte) n'est pas dupliquee ici :
 * TASK1229/1228/1257/1258/1262/934 sont rejouees telles quelles en
 * validation finale.
 */
class TASK1261CreditLedgerCutoverTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $memberA;

    private User $memberB;

    private CarbonImmutable $septemberStart;

    private CarbonImmutable $septemberEnd;

    private CarbonImmutable $augustStart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['name' => 'Org Alpha 1261']);
        $this->orgB = Organization::factory()->create(['name' => 'Org Beta 1261']);
        $this->memberA = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Membre Alpha 1261']);
        $this->memberB = User::factory()->create(['organization_id' => $this->orgB->id, 'name' => 'Membre Beta 1261']);

        $this->septemberStart = CarbonImmutable::parse(OrganizationAiEconomicUsage::CREDIT_LEDGER_AUTHORITY_SINCE);
        $this->septemberEnd = $this->septemberStart->addMonth();
        $this->augustStart = $this->septemberStart->subMonth();
    }

    // =====================================================================
    // 0. La liste elle-meme
    // =====================================================================

    public function test_creditable_processes_list_has_fourteen_entries_independent_from_guard_authority_list(): void
    {
        $this->assertCount(14, OrganizationAiEconomicUsage::CREDITABLE_PROCESSES);
        $this->assertContains('member_profile.agent_setup', OrganizationAiEconomicUsage::CREDITABLE_PROCESSES);
        $this->assertContains('member_profile.loop_agent_reply', OrganizationAiEconomicUsage::CREDITABLE_PROCESSES);
        $this->assertContains('member_profile.agent_visitor_chat', OrganizationAiEconomicUsage::CREDITABLE_PROCESSES);
        $this->assertContains('service_offer.master', OrganizationAiEconomicUsage::CREDITABLE_PROCESSES);

        // Independance des deux listes (delta M1 point A) : la garde G11-b
        // (10 process) et le credit G11-c (14 process) ne sont PAS le meme
        // ensemble, meme si elles se recoupent.
        $guardProcesses = AiEconomicGuard::ledgerAuthorityProcesses();
        $this->assertCount(10, $guardProcesses);
        $this->assertNotContains('member_profile.agent_setup', $guardProcesses);
        $this->assertNotContains('member_profile.loop_agent_reply', $guardProcesses);
        $this->assertNotContains('member_profile.agent_visitor_chat', $guardProcesses);
        $this->assertNotContains('service_offer.master', $guardProcesses);
    }

    // =====================================================================
    // 1. Echec provider ne consomme JAMAIS, sur les deux seaux
    // =====================================================================

    public function test_provider_failure_never_counts_on_either_bucket(): void
    {
        $this->ledgerRow([
            'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'process' => 'chatloop.answer',
            'status' => AiProviderInvocation::STATUS_FAILED,
            'created_at' => $this->septemberStart->addDay(),
        ]);
        $this->ledgerRow([
            'operation' => AiProviderInvocation::OPERATION_EMBEDDING,
            'embedding_operation' => AiProviderInvocation::EMBEDDING_OPERATION_QUERY,
            'process' => 'dossier.embeddings_search',
            'status' => AiProviderInvocation::STATUS_FAILED,
            'created_at' => $this->septemberStart->addDay(),
        ]);

        $this->assertSame(0, $this->usage()->userCreditUses(
            $this->orgA->id, $this->septemberStart, $this->septemberEnd, $this->memberA->id,
        ));
    }

    // =====================================================================
    // 2. Retry meme correlation_id = 1 ; sans correlation = sa propre operation
    // =====================================================================

    public function test_retry_same_correlation_counts_once_and_null_correlation_counts_as_its_own_operation(): void
    {
        $correlationId = (string) Str::uuid();

        $this->ledgerRow(['process' => 'chatloop.answer', 'correlation_id' => $correlationId, 'created_at' => $this->septemberStart->addDay()]);
        $this->ledgerRow(['process' => 'chatloop.answer', 'correlation_id' => $correlationId, 'created_at' => $this->septemberStart->addDay()->addSecond()]);

        $this->ledgerRow(['process' => 'chatloop.ask', 'correlation_id' => null, 'created_at' => $this->septemberStart->addDays(2)]);
        $this->ledgerRow(['process' => 'chatloop.ask', 'correlation_id' => null, 'created_at' => $this->septemberStart->addDays(3)]);

        $this->assertSame(3, $this->usage()->userCreditUses(
            $this->orgA->id, $this->septemberStart, $this->septemberEnd, $this->memberA->id,
        ));
    }

    // =====================================================================
    // 3. DEUX SEAUX JAMAIS FUSIONNES (anti-DISTINCT-global)
    // =====================================================================

    public function test_generation_and_embedding_query_never_merge_even_under_the_same_correlation(): void
    {
        $correlationId = (string) Str::uuid();

        $this->ledgerRow([
            'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'process' => 'loop_knowledge.answer',
            'correlation_id' => $correlationId,
            'created_at' => $this->septemberStart->addDay(),
        ]);
        $this->ledgerRow([
            'operation' => AiProviderInvocation::OPERATION_EMBEDDING,
            'embedding_operation' => AiProviderInvocation::EMBEDDING_OPERATION_QUERY,
            'process' => 'dossier.embeddings_search',
            'correlation_id' => $correlationId,
            'created_at' => $this->septemberStart->addDay(),
        ]);

        $this->assertSame(2, $this->usage()->userCreditUses(
            $this->orgA->id, $this->septemberStart, $this->septemberEnd, $this->memberA->id,
        ));
    }

    // =====================================================================
    // 4. Sandbox hors credit, sur les deux seaux
    // =====================================================================

    public function test_sandbox_is_excluded_from_credit_on_both_buckets(): void
    {
        $this->ledgerRow(['process' => 'chatloop.answer', 'created_at' => $this->septemberStart->addDay()]);
        $this->ledgerRow([
            'operation' => AiProviderInvocation::OPERATION_EMBEDDING,
            'embedding_operation' => AiProviderInvocation::EMBEDDING_OPERATION_QUERY,
            'process' => 'dossier.embeddings_search',
            'created_at' => $this->septemberStart->addDay(),
        ]);

        $baseline = $this->usage()->userCreditUses(
            $this->orgA->id, $this->septemberStart, $this->septemberEnd, $this->memberA->id,
        );
        $this->assertSame(2, $baseline);

        $this->ledgerRow([
            'process' => 'chatloop.answer',
            'feature' => OrganizationDoctrineSandbox::FEATURE,
            'created_at' => $this->septemberStart->addDays(2),
        ]);
        $this->ledgerRow([
            'operation' => AiProviderInvocation::OPERATION_EMBEDDING,
            'embedding_operation' => AiProviderInvocation::EMBEDDING_OPERATION_QUERY,
            'process' => 'dossier.embeddings_search',
            'feature' => OrganizationDoctrineSandbox::FEATURE,
            'created_at' => $this->septemberStart->addDays(2),
        ]);

        $this->assertSame($baseline, $this->usage()->userCreditUses(
            $this->orgA->id, $this->septemberStart, $this->septemberEnd, $this->memberA->id,
        ));
    }

    // =====================================================================
    // 5. Ingestion jamais comptee ; query comptee
    // =====================================================================

    public function test_embedding_ingestion_never_counts_query_counts(): void
    {
        $this->ledgerRow([
            'operation' => AiProviderInvocation::OPERATION_EMBEDDING,
            'embedding_operation' => AiProviderInvocation::EMBEDDING_OPERATION_INGESTION,
            'process' => 'dossier.embeddings_index',
            'created_at' => $this->septemberStart->addDay(),
        ]);

        $this->assertSame(0, $this->usage()->userCreditUses(
            $this->orgA->id, $this->septemberStart, $this->septemberEnd, $this->memberA->id,
        ));

        $this->ledgerRow([
            'operation' => AiProviderInvocation::OPERATION_EMBEDDING,
            'embedding_operation' => AiProviderInvocation::EMBEDDING_OPERATION_QUERY,
            'process' => 'dossier.embeddings_search',
            'created_at' => $this->septemberStart->addDay(),
        ]);

        $this->assertSame(1, $this->usage()->userCreditUses(
            $this->orgA->id, $this->septemberStart, $this->septemberEnd, $this->memberA->id,
        ));
    }

    // =====================================================================
    // 6. Visiteur cross-Organization : compte par la garde du tenant servi,
    //    absent de la console de restitution du meme tenant
    // =====================================================================

    public function test_cross_organization_visitor_is_counted_by_the_tenant_served_but_absent_from_its_console(): void
    {
        // memberB (Organization B) visite/consomme reellement dans le tenant A.
        $this->ledgerRow([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->memberB->id,
            'process' => 'member_profile.agent_visitor_chat',
            'created_at' => $this->septemberStart->addDay(),
        ]);

        $this->assertSame(1, $this->usage()->userCreditUses(
            $this->orgA->id, $this->septemberStart, $this->septemberEnd, $this->memberB->id,
        ));

        $byUser = $this->usage()->creditUsesByUser($this->orgA->id, $this->septemberStart, $this->septemberEnd);
        $this->assertArrayNotHasKey((string) $this->memberB->id, $byUser);

        $byOrgAndUser = $this->usage()->creditUsesByOrganizationAndUser($this->septemberStart, $this->septemberEnd);
        $this->assertArrayNotHasKey((string) $this->memberB->id, $byOrgAndUser[(string) $this->orgA->id] ?? []);
    }

    // =====================================================================
    // 7. Process hors CREDITABLE_PROCESSES : jamais compte, dans les 3 methodes
    // =====================================================================

    public function test_process_outside_creditable_list_is_never_counted_across_all_three_methods(): void
    {
        $this->ledgerRow([
            'process' => 'supervision.content',
            'created_at' => $this->septemberStart->addDay(),
        ]);

        $this->assertSame(0, $this->usage()->userCreditUses(
            $this->orgA->id, $this->septemberStart, $this->septemberEnd, $this->memberA->id,
        ));

        $byUser = $this->usage()->creditUsesByUser($this->orgA->id, $this->septemberStart, $this->septemberEnd);
        $this->assertArrayNotHasKey((string) $this->memberA->id, $byUser);

        $byOrgAndUser = $this->usage()->creditUsesByOrganizationAndUser($this->septemberStart, $this->septemberEnd);
        $this->assertArrayNotHasKey((string) $this->memberA->id, $byOrgAndUser[(string) $this->orgA->id] ?? []);
    }

    // =====================================================================
    // 8. CUTOVER
    // =====================================================================

    public function test_august_window_keeps_legacy_semantics_including_a_counted_failure(): void
    {
        // Doctrine 2.c : un echec ChatLoop d'aout ECRIT ai_interactions et
        // COMPTE encore — comportement historique fige, pas corrige
        // retroactivement.
        AiInteraction::create([
            'user_id' => $this->memberA->id,
            'organization_id' => $this->orgA->id,
            'process' => 'chatloop.answer',
            'feature' => 'chatloop_ai_answer',
            'model' => 'openrouter/openai/gpt-4o-mini',
            'prompt' => 'p',
            'response' => '',
            'cost_usd' => null,
            'cost_unknown' => true,
            'metadata' => ['status' => 'failed'],
        ])->forceFill(['created_at' => $this->augustStart->addDay()])->saveQuietly();

        // Une ligne ledger la MEME journee ne doit PAS etre lue avant le
        // cutover (fenetre aout = legacy exclusivement).
        $this->ledgerRow(['process' => 'chatloop.answer', 'created_at' => $this->augustStart->addDay()]);

        $this->assertSame(1, $this->usage()->userCreditUses(
            $this->orgA->id, $this->augustStart, $this->septemberStart, $this->memberA->id,
        ));
    }

    public function test_september_window_reads_the_ledger_exclusively_ignoring_the_legacy_table(): void
    {
        // Cinq lignes historiques en septembre (ecriture continuee par les
        // writers, doctrine G11-b) : ne doivent JAMAIS etre lues ici.
        for ($i = 0; $i < 5; $i++) {
            AiInteraction::create([
                'user_id' => $this->memberA->id,
                'organization_id' => $this->orgA->id,
                'process' => 'chatloop.answer',
                'feature' => 'chatloop_ai_answer',
                'model' => 'openrouter/openai/gpt-4o-mini',
                'prompt' => 'p',
                'response' => 'r',
                'cost_usd' => 0.001,
                'cost_unknown' => false,
                'metadata' => ['status' => 'success'],
            ])->forceFill(['created_at' => $this->septemberStart->addDay()])->saveQuietly();
        }

        $this->ledgerRow(['process' => 'chatloop.answer', 'created_at' => $this->septemberStart->addDay()]);

        $this->assertSame(1, $this->usage()->userCreditUses(
            $this->orgA->id, $this->septemberStart, $this->septemberEnd, $this->memberA->id,
        ));
    }

    public function test_a_line_exactly_at_cutover_belongs_to_september_and_counts_once(): void
    {
        $this->ledgerRow(['process' => 'chatloop.answer', 'created_at' => $this->septemberStart]);

        $this->assertSame(1, $this->usage()->userCreditUses(
            $this->orgA->id, $this->septemberStart, $this->septemberEnd, $this->memberA->id,
        ));

        // La meme ligne n'est jamais vue par la fenetre d'aout (routee vers
        // le registre legacy, et `created_at < to` exclut la borne).
        $this->assertSame(0, $this->usage()->userCreditUses(
            $this->orgA->id, $this->augustStart, $this->septemberStart, $this->memberA->id,
        ));
    }

    // =====================================================================
    // 9. Coherence garde/affichage
    // =====================================================================

    public function test_guard_status_reflects_the_new_ledger_reading_in_september(): void
    {
        $this->ledgerRow(['process' => 'chatloop.answer', 'created_at' => $this->septemberStart->addDay()]);
        $this->ledgerRow(['process' => 'chatloop.ask', 'created_at' => $this->septemberStart->addDays(2)]);

        $status = app(AiEconomicGuard::class)->userCreditStatus(
            $this->orgA,
            $this->memberA,
            Carbon::instance($this->septemberStart),
            Carbon::instance($this->septemberEnd),
        );

        $this->assertSame(2, $status->used);
    }

    // =====================================================================
    // Changement d'Organization en cours de mois (demande explicite M1)
    // =====================================================================

    public function test_member_who_changed_organization_mid_month_is_scoped_by_ledger_organization_not_current_membership(): void
    {
        // Ligne ecrite QUAND memberA appartenait encore a orgA.
        $this->ledgerRow([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->memberA->id,
            'process' => 'chatloop.answer',
            'created_at' => $this->septemberStart->addDay(),
        ]);

        // memberA change d'Organization en cours de mois.
        $this->memberA->update(['organization_id' => $this->orgB->id]);

        // Ligne ecrite APRES le changement, sous le nouveau tenant.
        $this->ledgerRow([
            'organization_id' => $this->orgB->id,
            'user_id' => $this->memberA->id,
            'process' => 'chatloop.ask',
            'created_at' => $this->septemberStart->addDays(2),
        ]);

        // Lecture DIRECTE (garde) : chaque Organization ne voit que SA ligne,
        // au tenant de record de l'ecriture — jamais la current membership.
        $this->assertSame(1, $this->usage()->userCreditUses(
            $this->orgA->id, $this->septemberStart, $this->septemberEnd, $this->memberA->id,
        ));
        $this->assertSame(1, $this->usage()->userCreditUses(
            $this->orgB->id, $this->septemberStart, $this->septemberEnd, $this->memberA->id,
        ));

        // Restitution (jointure `users.organization_id`) : memberA n'apparait
        // QUE dans la console de son Organization ACTUELLE (orgB) — l'usage
        // qu'il a genere dans orgA reste compte par la garde d'orgA mais
        // n'est plus attribuable dans sa console (ecart garde/restitution,
        // Point B, assume et documente).
        $byUserA = $this->usage()->creditUsesByUser($this->orgA->id, $this->septemberStart, $this->septemberEnd);
        $this->assertArrayNotHasKey((string) $this->memberA->id, $byUserA);

        $byUserB = $this->usage()->creditUsesByUser($this->orgB->id, $this->septemberStart, $this->septemberEnd);
        $this->assertSame(1, $byUserB[(string) $this->memberA->id] ?? null);
    }

    // =====================================================================
    // Defense en profondeur : garde Str::isUuid sur la lecture directe
    // =====================================================================

    public function test_malformed_user_id_returns_zero_without_crashing_on_the_ledger_branch(): void
    {
        $this->assertSame(0, $this->usage()->userCreditUses(
            $this->orgA->id, $this->septemberStart, $this->septemberEnd, 'not-a-uuid',
        ));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function usage(): OrganizationAiEconomicUsage
    {
        return app(OrganizationAiEconomicUsage::class);
    }

    private function ledgerRow(array $overrides = []): AiProviderInvocation
    {
        $createdAt = $overrides['created_at'] ?? CarbonImmutable::now();
        unset($overrides['created_at']);

        $row = AiProviderInvocation::create(array_merge([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->memberA->id,
            'process' => 'chatloop.answer',
            'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            'status' => AiProviderInvocation::STATUS_SUCCESS,
            'cost_status' => AiProviderInvocation::COST_UNKNOWN,
            'cost_source' => AiProviderInvocation::COST_UNKNOWN,
        ], $overrides));

        $row->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $row->refresh();
    }
}
