<?php

namespace Tests\Feature;

use App\Ai\ProviderResolver;
use App\Models\AiProviderInvocation;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiProviderInvocationConsole;
use App\Services\Ai\OrganizationAiEconomicUsage;
use App\Services\Dossiers\DossierRerankGate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1562 — rendre le rerank PILOTABLE et OBSERVABLE, sans l'allumer.
 *
 * Deux volets, et une meme exigence : qu'on puisse ouvrir le pilote a UNE
 * Organization, et voir ce qu'elle consomme — sans que rien de ce qui existait
 * ne se mette a dire autre chose qu'avant.
 */
class TASK1562CoherePilotControlTest extends TestCase
{
    use RefreshDatabase;

    // ───────────────────────────────────── VOLET A — activation

    /** REQ 1 — drapeau maitre faux : la porte est fermee, allowlist ou pas. */
    public function test_the_master_flag_closes_the_door_even_for_an_allowlisted_organization(): void
    {
        $organization = Organization::factory()->create();

        $this->autoriser($organization);
        config(['ai.knowledge.rerank.enabled' => false]);

        $this->assertFalse(app(DossierRerankGate::class)->isEnabledFor($organization->id));
    }

    /** REQ 2 — drapeau maitre vrai + Organization nommee : la porte s'ouvre. */
    public function test_an_allowlisted_organization_is_enabled(): void
    {
        $organization = Organization::factory()->create();

        $this->autoriser($organization);
        config(['ai.knowledge.rerank.enabled' => true]);

        $this->assertTrue(app(DossierRerankGate::class)->isEnabledFor($organization->id));
    }

    /** REQ 3 — drapeau maitre vrai, mais Organization non nommee : ferme. */
    public function test_a_non_allowlisted_organization_stays_disabled(): void
    {
        $autorisee = Organization::factory()->create();
        $autre = Organization::factory()->create();

        $this->autoriser($autorisee);
        config(['ai.knowledge.rerank.enabled' => true]);

        $this->assertFalse(app(DossierRerankGate::class)->isEnabledFor($autre->id));
    }

    /**
     * REQ 4 — autoriser A n'autorise pas B.
     *
     * C'est LA propriete qui fait d'un pilote un pilote : sans elle, ouvrir a
     * une Organization ouvrirait a toutes celles du meme environnement.
     */
    public function test_enabling_one_organization_never_enables_another(): void
    {
        $a = Organization::factory()->create();
        $b = Organization::factory()->create();

        $this->autoriser($a);
        config(['ai.knowledge.rerank.enabled' => true]);

        $gate = app(DossierRerankGate::class);

        $this->assertTrue($gate->isEnabledFor($a->id));
        $this->assertFalse($gate->isEnabledFor($b->id));
    }

    /**
     * REQ 6 — autoriser une Organization n'en autorise aucune autre.
     *
     * TASK-1563 a RETIRE l'allowlist d'environnement (par id et par slug) : le
     * drapeau vit desormais sur la ligne de reglages de l'Organization
     * elle-meme. Les deux tests qui eprouvaient le mecanisme de slug ont donc
     * disparu avec lui — mais leur GARANTIE, elle, reste, et c'est celle-ci :
     * une autorisation ne deborde jamais sur une voisine.
     *
     * Elle est meme devenue structurelle : le drapeau est une colonne de la
     * ligne de CETTE Organization. Il n'existe plus de liste centrale ou une
     * erreur de saisie pourrait designer la mauvaise.
     */
    public function test_authorizing_one_organization_writes_nothing_on_another(): void
    {
        $portee = Organization::factory()->create(['slug' => 'pilote-cohere']);
        $autre = Organization::factory()->create(['slug' => 'pas-le-pilote']);

        $this->autoriser($portee);
        config(['ai.knowledge.rerank.enabled' => true]);

        $gate = app(DossierRerankGate::class);

        $this->assertTrue($gate->isEnabledFor($portee->id));
        $this->assertFalse($gate->isEnabledFor($autre->id));

        // Et rien n'a ete ecrit sur la voisine — pas meme une ligne de reglages.
        $this->assertDatabaseMissing('organization_ai_settings', ['organization_id' => $autre->id]);
    }

    /**
     * REQ 7 — aucune autorisation, ou etat illisible : comportement SECURISE.
     *
     * Le defaut est ferme, et une valeur qu'on ne sait pas lire ne « degrade »
     * pas vers l'ouverture : elle ferme.
     */
    public function test_an_absent_or_unreadable_state_opens_nothing(): void
    {
        $organization = Organization::factory()->create();
        $gate = app(DossierRerankGate::class);

        config(['ai.knowledge.rerank.enabled' => true]);

        // Aucune ligne de reglages du tout.
        $this->assertFalse($gate->isEnabledFor($organization->id), 'Une Organization sans reglages ne doit pas reranker.');

        // Une ligne, mais l'autorisation a son defaut ferme.
        OrganizationAiSetting::factory()->create(['organization_id' => $organization->id]);
        $this->assertFalse($gate->isEnabledFor($organization->id), 'Le defaut de la colonne doit fermer.');

        // Identifiant vide cote appelant.
        $this->autoriser($organization);
        $this->assertFalse($gate->isEnabledFor(''), 'Un identifiant vide ne doit rien ouvrir.');
    }

    /**
     * REQ 5 + REQ 8 — l'instance resolue est celle du TENANT, et la porte
     * commande vraiment le resolver.
     *
     * Le Gate pourrait etre juste et inutile si `resolveRerankingInstance()`
     * ne le consultait pas. Ce test mesure le resolver, pas le Gate.
     */
    public function test_the_resolver_obeys_the_gate_and_never_falls_back_to_a_platform_key(): void
    {
        $organization = Organization::factory()->create();
        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openrouter',
            'api_key' => 'sk-tenant',
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'PLATEFORME_NE_DOIT_PAS_SERVIR',
            'ai.knowledge.rerank.enabled' => true,
        ]);

        $resolver = app(ProviderResolver::class);

        // Non autorisee : aucune instance, et surtout aucun repli plateforme.
        $this->assertNull($resolver->resolveRerankingInstance($organization->id));

        // Autorisee : l'instance est celle du tenant, nommement.
        $this->autoriser($organization);

        $this->assertSame(
            "org:{$organization->id}:rerank",
            $resolver->resolveRerankingInstance($organization->id),
        );
    }

    // ───────────────────────────────────── VOLET B — observabilite

    /**
     * REQ 9 — un rerank apparait dans le releve economique.
     *
     * C'est le manque que cette TASK ferme : TASK-1560 l'ecrivait au ledger, et
     * AUCUNE surface ne le lisait.
     */
    public function test_a_rerank_appears_in_the_economic_statement(): void
    {
        [$organization, $user] = $this->tenant();
        $this->rerank($organization, $user);
        $this->rerank($organization, $user, AiProviderInvocation::STATUS_FAILED);

        $releve = app(OrganizationAiEconomicUsage::class)->summary(
            $organization->id,
            CarbonImmutable::now()->startOfMonth(),
            CarbonImmutable::now()->startOfMonth()->addMonth(),
        );

        $this->assertArrayHasKey('rerank', $releve);
        $this->assertSame(2, $releve['rerank']['invocation_count']);
        $this->assertSame(1, $releve['rerank']['failed_count']);
    }

    /**
     * REQ 10 + REQ 13 — generation et embedding restent INCHANGES, et les
     * totaux ne gagnent pas un seul rerank.
     *
     * Le test compare le MEME releve avant et apres l'ecriture de reranks. Si
     * un total bougeait, tout releve anterieur au merge deviendrait faux en
     * silence — et personne ne pourrait s'en apercevoir.
     */
    public function test_reranks_never_move_a_single_pre_existing_total(): void
    {
        [$organization, $user] = $this->tenant();
        $this->embedding($organization, $user);

        $usage = app(OrganizationAiEconomicUsage::class);
        $from = CarbonImmutable::now()->startOfMonth();
        $to = $from->addMonth();

        $avant = $usage->summary($organization->id, $from, $to);
        $avantParUtilisateur = collect($usage->byUser($organization->id, $from, $to))->firstWhere('user_id', $user->id);

        $this->rerank($organization, $user);
        $this->rerank($organization, $user);
        $this->rerank($organization, $user, AiProviderInvocation::STATUS_FAILED);

        $apres = $usage->summary($organization->id, $from, $to);
        $apresParUtilisateur = collect($usage->byUser($organization->id, $from, $to))->firstWhere('user_id', $user->id);

        // Les DEUX chemins de totaux du depot : `summary()` en porte trois,
        // `byUser()` passe par `withOrganizationTotals()` et en porte quatre,
        // dont `total_count`. Les deux doivent rester immobiles.
        foreach (['total_known_cost_usd', 'total_unknown_count', 'total_unevaluated_count'] as $total) {
            $this->assertSame($avant[$total], $apres[$total], "Le total « {$total} » de summary() a bouge a cause d'un rerank.");
        }

        foreach (['total_known_cost_usd', 'total_unknown_count', 'total_unevaluated_count', 'total_count'] as $total) {
            $this->assertSame(
                $avantParUtilisateur[$total],
                $apresParUtilisateur[$total],
                "Le total « {$total} » de byUser() a bouge a cause d'un rerank.",
            );
        }

        foreach (['generation', 'embedding_query', 'embedding_ingestion', 'embedding_undeclared'] as $tranche) {
            $this->assertSame($avant[$tranche], $apres[$tranche], "La tranche « {$tranche} » a bouge a cause d'un rerank.");
        }

        // Et pourtant le rerank EST la : additif, pas invisible.
        $this->assertSame(0, $avant['rerank']['invocation_count']);
        $this->assertSame(3, $apres['rerank']['invocation_count']);
        $this->assertSame(3, $apresParUtilisateur['rerank']['invocation_count']);
    }

    /**
     * REQ 11 + REQ 12 — NULL reste NULL, unknown reste unknown.
     *
     * Le SDK ne rend aucun usage sur un rerank. Un cout affiche a 0 le ferait
     * passer pour gratuit ; c'est le mensonge que ce test interdit.
     */
    public function test_an_unknown_rerank_cost_never_becomes_zero(): void
    {
        [$organization, $user] = $this->tenant();
        $this->rerank($organization, $user);

        $releve = app(OrganizationAiEconomicUsage::class)->summary(
            $organization->id,
            CarbonImmutable::now()->startOfMonth(),
            CarbonImmutable::now()->startOfMonth()->addMonth(),
        );

        $this->assertNull($releve['rerank']['known_cost_usd'], 'Un cout inconnu ne doit JAMAIS se rendre en 0.');
        $this->assertSame(0, $releve['rerank']['measured_count']);
        $this->assertSame(1, $releve['rerank']['unknown_count']);

        $ligne = AiProviderInvocation::query()->where('operation', AiProviderInvocation::OPERATION_RERANK)->firstOrFail();
        $this->assertNull($ligne->provider_cost);
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $ligne->cost_status);
    }

    /** REQ 14 — un rerank en echec se voit COMME un echec, pas comme un inconnu. */
    public function test_a_failed_rerank_is_visible_as_failed_and_not_as_unknown(): void
    {
        [$organization, $user] = $this->tenant();
        $this->rerank($organization, $user, AiProviderInvocation::STATUS_FAILED);

        $releve = app(OrganizationAiEconomicUsage::class)->summary(
            $organization->id,
            CarbonImmutable::now()->startOfMonth(),
            CarbonImmutable::now()->startOfMonth()->addMonth(),
        );

        $this->assertSame(1, $releve['rerank']['failed_count']);
        // L'inconnu economique ne compte que les appels REUSSIS non mesurables :
        // un echec ne se deguise pas en « cout inconnu ».
        $this->assertSame(0, $releve['rerank']['unknown_count']);
    }

    /** REQ 15 — isolation Organization dans les lectures economiques. */
    public function test_a_rerank_of_another_organization_never_leaks_into_this_statement(): void
    {
        [$mienne, $monUtilisateur] = $this->tenant();
        [$autre, $sonUtilisateur] = $this->tenant();

        $this->rerank($autre, $sonUtilisateur);
        $this->rerank($autre, $sonUtilisateur);

        $usage = app(OrganizationAiEconomicUsage::class);
        $from = CarbonImmutable::now()->startOfMonth();
        $to = $from->addMonth();

        $this->assertSame(0, $usage->summary($mienne->id, $from, $to)['rerank']['invocation_count']);
        $this->assertSame(2, $usage->summary($autre->id, $from, $to)['rerank']['invocation_count']);

        // Ventilation par utilisateur : rien de l'autre tenant ne doit y entrer.
        foreach ($usage->byUser($mienne->id, $from, $to) as $ligne) {
            $this->assertSame(0, $ligne['rerank']['invocation_count']);
        }

        // Et l'utilisateur de l'autre Organization n'est pas lisible depuis la mienne.
        $this->assertSame(0, $usage->summary($mienne->id, $from, $to, $sonUtilisateur->id)['rerank']['invocation_count']);
    }

    /** Le rerank est VISIBLE a l'operateur : ventilation par utilisateur et activite recente. */
    public function test_the_operator_can_actually_see_the_rerank(): void
    {
        [$organization, $user] = $this->tenant();
        $this->rerank($organization, $user);
        $this->rerank($organization, $user);

        $usage = app(OrganizationAiEconomicUsage::class);
        $from = CarbonImmutable::now()->startOfMonth();
        $to = $from->addMonth();

        $lignes = collect($usage->byUser($organization->id, $from, $to))->firstWhere('user_id', $user->id);
        $this->assertNotNull($lignes, 'L\'utilisateur doit apparaitre dans la ventilation.');
        $this->assertSame(2, $lignes['rerank']['invocation_count']);

        $activite = app(AiProviderInvocationConsole::class)->recentActivityForUser($organization->id, $user->id, 20);
        $reranks = array_values(array_filter($activite, static fn (array $l): bool => $l['kind'] === 'rerank'));

        $this->assertCount(2, $reranks, 'Le rerank doit apparaitre dans l\'activite recente.');
        $this->assertSame('openrouter', $reranks[0]['provider']);
        $this->assertSame('cohere/rerank-v3.5', $reranks[0]['model']);
        $this->assertSame('unknown', $reranks[0]['cost_state']);
        $this->assertNull($reranks[0]['cost_usd']);
    }

    // ───────────────────────────────────────────────────── harnais

    /**
     * TASK-1563 — autorise une Organization par le chemin qui fait DESORMAIS
     * autorite.
     *
     * TASK-1562 la designait par une allowlist d'environnement ; celle-ci a ete
     * retiree au profit d'un interrupteur d'administration, et le drapeau vit
     * sur la ligne de reglages de l'Organization. Les tests gardent leurs
     * garanties, seul le mecanisme d'activation change.
     */
    private function autoriser(Organization $organization, bool $autorisee = true): void
    {
        // La factory fournit `provider` et `model`, qui sont NOT NULL : une
        // Organization sans configuration IA ne peut pas porter ce drapeau, et
        // ce n'est pas un contournement — sans credential tenant elle ne
        // rerankerait pas de toute facon.
        $setting = OrganizationAiSetting::query()->where('organization_id', $organization->id)->first()
            ?? OrganizationAiSetting::factory()->create(['organization_id' => $organization->id]);

        $setting->rerank_enabled = $autorisee;
        $setting->save();
    }

    /** @return array{0: Organization, 1: User} */
    private function tenant(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);

        return [$organization, $user];
    }

    private function rerank(Organization $organization, User $user, string $status = AiProviderInvocation::STATUS_SUCCESS): void
    {
        AiProviderInvocation::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'capability' => 'loop_knowledge_answer',
            'process' => 'dossier.retrieval',
            'operation' => AiProviderInvocation::OPERATION_RERANK,
            'provider' => 'openrouter',
            'model' => 'cohere/rerank-v3.5',
            'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            // Le SDK ne rend aucun usage sur un rerank : NULL reste NULL.
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'provider_cost' => null,
            'currency' => null,
            'cost_status' => AiProviderInvocation::COST_UNKNOWN,
            'cost_source' => AiProviderInvocation::COST_UNKNOWN,
            'status' => $status,
        ]);
    }

    private function embedding(Organization $organization, User $user): void
    {
        AiProviderInvocation::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'capability' => 'loop_knowledge_answer',
            'process' => 'dossier.embeddings_search',
            'operation' => AiProviderInvocation::OPERATION_EMBEDDING,
            'embedding_operation' => AiProviderInvocation::EMBEDDING_OPERATION_QUERY,
            'provider' => 'openrouter',
            'model' => 'openai/text-embedding-3-small',
            'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            'provider_cost' => null,
            'cost_status' => AiProviderInvocation::COST_UNKNOWN,
            'cost_source' => AiProviderInvocation::COST_UNKNOWN,
            'status' => AiProviderInvocation::STATUS_SUCCESS,
        ]);
    }
}
