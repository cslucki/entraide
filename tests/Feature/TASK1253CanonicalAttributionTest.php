<?php

namespace Tests\Feature;

use App\Ai\CapabilityRegistry;
use App\Ai\NervousSystemCoverage;
use App\Ai\ResolvedModel;
use App\Http\Controllers\Admin\AdminAiSupervisionController;
use App\Http\Controllers\Admin\AdminMemberAiProfileController;
use App\Http\Controllers\BlogExplorerController;
use App\Jobs\GenerateAiAgentResponse;
use App\Livewire\AiAgentChat;
use App\Models\AiProviderInvocation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiProviderInvocationLedger;
use App\Services\Ai\OrganizationDoctrineSandbox;
use App\Services\Ai\SupervisionEconomicScope;
use App\Support\Ai\AiUsage;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * TASK-1253 — attribution canonique User / Organization / Capability des
 * chemins IA sous autorite economique (fiche roadmap « T1249 », glissee).
 *
 * Ces tests pinnent la REGLE (posee dans `SupervisionEconomicScope` et tenue
 * par `AiProviderInvocationLedger`), pas un chemin en particulier — les
 * valeurs chemin par chemin sont deja prouvees par T1247/T1248 (Blog,
 * Explorer), T1250 (#13/#17/#18), T1251 (#14, credit = expediteur), T1252
 * (#15, credit = visiteur, proprietaire = son propre visiteur) et les tests
 * canoniques (T1220, T1229, T1233) :
 *
 *  A. le credit est porte par l'ACTEUR ou par personne (le ledger n'a qu'un
 *     `user_id` : il dit qui a agi ET qui a paye son credit) ;
 *  B. `capability` = canonique (registre) ou NULL — jamais une etiquette
 *     inventee ;
 *  C. aucune capability canonique n'existe pour les chemins herites (leur
 *     NULL est la verite) ; l'inventaire des fonctions heritees nomme
 *     l'Explorer (G16).
 */
class TASK1253CanonicalAttributionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $actor;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true, 'slug' => 'tenant-1253']);
        $this->actor = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->other = User::factory()->create(['organization_id' => $this->organization->id]);
    }

    // =====================================================================
    // A. Scope economique : credit = acteur ou personne
    // =====================================================================

    public function test_the_scope_accepts_the_actor_as_credit_user_even_through_another_instance(): void
    {
        $sameActorOtherInstance = User::query()->findOrFail($this->actor->id);

        $scope = new SupervisionEconomicScope(
            organization: $this->organization,
            actor: $this->actor,
            creditUser: $sameActorOtherInstance,
            feature: 'member_profile_agent_visitor_chat',
        );

        $this->assertSame($this->actor->id, $scope->creditUser?->id);
    }

    public function test_the_scope_accepts_an_actor_without_credit_for_administration_benches(): void
    {
        $scope = new SupervisionEconomicScope(
            organization: $this->organization,
            actor: $this->actor,
            creditUser: null,
            feature: AdminAiSupervisionController::BENCH_FEATURE,
        );

        $this->assertNull($scope->creditUser);
        $this->assertSame($this->actor->id, $scope->actor?->id);
    }

    public function test_the_scope_refuses_a_credit_user_who_is_not_the_actor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('creditUser must be NULL or the actor itself');

        // Le cas « le proprietaire du profil paierait pour le visiteur » :
        // l'unique forme ou acteur != credit, refusee par construction.
        new SupervisionEconomicScope(
            organization: $this->organization,
            actor: $this->actor,
            creditUser: $this->other,
            feature: GenerateAiAgentResponse::FEATURE,
        );
    }

    public function test_the_scope_refuses_a_credit_user_without_actor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SupervisionEconomicScope(
            organization: $this->organization,
            actor: null,
            creditUser: $this->other,
            feature: AiAgentChat::FEATURE,
        );
    }

    // =====================================================================
    // B. Ledger : capability canonique ou NULL
    // =====================================================================

    public function test_the_ledger_refuses_an_invented_capability_on_generation(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('blog_ai_generation');

        $this->recordGeneration(capability: 'blog_ai_generation', feature: 'blog_generate');
    }

    public function test_the_ledger_refuses_an_invented_capability_on_embedding(): void
    {
        $this->expectException(DomainException::class);

        app(AiProviderInvocationLedger::class)->recordEmbedding(
            organizationId: (string) $this->organization->id,
            userId: (string) $this->actor->id,
            capability: 'member_profile_agent',
            process: 'dossier.embeddings_search',
            embeddingOperation: AiProviderInvocation::EMBEDDING_OPERATION_QUERY,
            provider: 'openai',
            model: 'text-embedding-3-small',
            credentialSource: AiProviderInvocation::CREDENTIAL_UNKNOWN,
            totalTokens: 12,
            embeddingCount: 1,
            embeddingDimensions: 1536,
            cost: null,
            status: AiProviderInvocation::STATUS_SUCCESS,
            correlationId: null,
            sdkInvocationId: null,
            startedAtMicrotime: null,
        );
    }

    public function test_the_ledger_accepts_a_canonical_capability_and_null_for_inherited_paths(): void
    {
        $canonical = $this->recordGeneration(capability: CapabilityRegistry::LOOP_SUMMARY, feature: null);
        $inherited = $this->recordGeneration(capability: null, feature: AiAgentChat::FEATURE);
        $sandbox = $this->recordGeneration(capability: CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER, feature: OrganizationDoctrineSandbox::FEATURE);

        $this->assertSame(3, AiProviderInvocation::query()->count());

        // Fonction produit = COALESCE(feature, capability) : lisible sur les trois formes.
        $this->assertSame(CapabilityRegistry::LOOP_SUMMARY, $canonical->feature ?? $canonical->capability);
        $this->assertSame(AiAgentChat::FEATURE, $inherited->feature ?? $inherited->capability);
        $this->assertNull($inherited->capability);
        $this->assertSame(OrganizationDoctrineSandbox::FEATURE, $sandbox->feature ?? $sandbox->capability);

        // user_id = l'acteur, sur les trois.
        foreach ([$canonical, $inherited, $sandbox] as $row) {
            $this->assertSame($this->actor->id, $row->user_id);
            $this->assertSame($this->organization->id, $row->organization_id);
        }
    }

    // =====================================================================
    // C. Axe Capability : ce qui est canonique, ce qui ne l'est pas
    // =====================================================================

    /**
     * Les features ecrites par les chemins herites sous autorite (Blog T1247,
     * Explorer T1248, famille C T1250/T1251/T1252) et les cles de l'inventaire
     * herite ne sont PAS des capabilities canoniques : leur `capability = NULL`
     * au ledger est la verite, pas un branchement oublie. Si l'une d'elles
     * entre un jour au registre, ce test le dit — et le writer concerne doit
     * alors la porter en `capability`.
     */
    public function test_no_canonical_capability_exists_for_the_inherited_paths(): void
    {
        $registry = app(CapabilityRegistry::class);

        // TASK-1284 : blog_generate / blog_correct sont sortis de cette liste
        // — devenus canoniques, leur writer les porte en `capability`.
        // TASK-1285 : GenerateAiAgentResponse::FEATURE et AiAgentChat::FEATURE
        // en sont sortis pour la meme raison — capabilities canoniques
        // `member_profile_agent_loop_reply` / `member_profile_agent_visitor_chat`
        // (ids = les features historiques), leur writer (`SupervisionEconomic-
        // Authority::attempt()`) les porte en `capability`.
        $inheritedFeatures = [
            'blog_method_selection_explorer_fr',
            'blog_explorer', 'blog_explorer_note',
            'service_offer_formulation',
            AdminMemberAiProfileController::LLM_TEST_SCENARIO,
            AdminAiSupervisionController::BENCH_FEATURE,
            ...array_keys(NervousSystemCoverage::INHERITED),
        ];

        foreach ($inheritedFeatures as $feature) {
            $this->assertFalse($registry->has($feature), "[$feature] n'est pas une capability canonique : capability NULL au ledger.");
        }

        $this->assertSame(
            [
                CapabilityRegistry::LOOP_SUMMARY,
                CapabilityRegistry::CLARIFY_HELP_REQUEST,
                CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
                // TASK-1309 : le mode « IA + Dossiers » est une capability
                // CANONIQUE de plus — declaree juste apres sa soeur
                // documentaire, dont elle partage sources et process.
                CapabilityRegistry::LOOP_HYBRID_ANSWER,
                CapabilityRegistry::LOOP_ANSWER,
                CapabilityRegistry::LOOP_ASK,
                CapabilityRegistry::BLOG_GENERATE,
                CapabilityRegistry::BLOG_CORRECT,
                CapabilityRegistry::MEMBER_PROFILE_AGENT_LOOP_REPLY,
                CapabilityRegistry::MEMBER_PROFILE_AGENT_VISITOR_CHAT,
                // TASK-1327 : Decision Memory IA — capability canonique de
                // plus, sur le process du resume dont elle partage l'acte
                // economique (meme geste que TASK-1309).
                CapabilityRegistry::LOOP_DECISION_SUGGESTION,
            ],
            array_map(static fn ($definition): string => $definition->id, $registry->all()),
            'Les onze capabilities canoniques (TASK-1285 : + les deux reponses de l\'agent de profil ; TASK-1309 : + IA + Dossiers ; TASK-1327 : + la suggestion de Decision) — aucune pour la suggestion sur selection, la configuration conversationnelle du profil, l\'Explorer, l\'offre, les bancs.',
        );
    }

    public function test_the_explorer_is_declared_inherited_with_labels_in_both_locales(): void
    {
        $coverage = app(NervousSystemCoverage::class);

        $this->assertContains('blog_explorer', $coverage->inherited());
        $this->assertSame(BlogExplorerController::class, NervousSystemCoverage::INHERITED['blog_explorer']);
        $this->assertNotSame('ai.inherited_label.blog_explorer', __('ai.inherited_label.blog_explorer', [], 'fr'));
        $this->assertNotSame('ai.inherited_label.blog_explorer', __('ai.inherited_label.blog_explorer', [], 'en'));
        $this->assertSame(11 + 4, $coverage->totalCount(), 'Onze canoniques (TASK-1285 : + les deux reponses de l\'agent de profil ; TASK-1309 : + IA + Dossiers ; TASK-1327 : + la suggestion de Decision) + quatre heritees (configuration conversationnelle du profil, suggestion sur selection Blog, Explorer, offre de service).');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function recordGeneration(?string $capability, ?string $feature): AiProviderInvocation
    {
        return app(AiProviderInvocationLedger::class)->recordGeneration(
            organizationId: (string) $this->organization->id,
            userId: (string) $this->actor->id,
            capability: $capability,
            process: 'test.process',
            resolved: new ResolvedModel('openai', 'gpt-4o-mini'),
            usage: AiUsage::of(10, 5),
            cost: null,
            status: AiProviderInvocation::STATUS_SUCCESS,
            correlationId: null,
            sdkInvocationId: null,
            failureReason: null,
            startedAtMicrotime: null,
            feature: $feature,
        );
    }
}
