<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Context\DossierRetrievalTraceRecorder;
use App\Listeners\RecordSdkEmbeddingsInvocation;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\Loops\LoopRootDocumentService;
use App\Support\Ai\AiTurnState;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1566 / CDC-01 V0-A — le PRODUCTEUR PILOTE ecrit son bloc `turn`.
 *
 * Meme harnais que `TASK1565RetrievalTraceTest` : un vrai tour `loop_chat.dossiers`
 * execute par `ai:inspect-turn`, avec recherche mockee et agent fake. On observe
 * le PRODUIT, on ne reconstruit rien a cote.
 *
 * Ce que ces tests gardent, par ordre de degats :
 *
 *  1. **I8 — HARD.** La SEULE cle nouvelle au premier niveau de la metadata est
 *     `turn`. C'est le garde le plus important de la TASK : au premier niveau,
 *     `sources_denied` allume un bandeau VISIBLE PAR LE MEMBRE
 *     (`AiResponseExplanationService::ragPanel()` -> `loops.why_denied`). Une
 *     TASK d'observabilite qui allumerait une surface au passage aurait change
 *     le produit sans le dire ;
 *  2. **aucune cle legacy deplacee, renommee ou supprimee** — des lecteurs les
 *     consomment ;
 *  3. **les sous-blocs non produits sont ABSENTS**, pas vides. Une cle a moitie
 *     alimentee se lit comme une mesure et ment ; absente, elle se lit
 *     `UNAVAILABLE`, ce qui est la verite ;
 *  4. **une seule mesure de latence** pour deux cles ;
 *  5. **la collecte coupee ne change rien au produit** — garde de
 *     non-dependance ;
 *  6. **aucun texte de document ni de prompt dans le bloc** (I9).
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1566TurnBlockPilotTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les cles de premier niveau que ce writer ecrivait DEJA avant TASK-1566.
     *
     * Cette liste est le temoin du test I8 : toute cle observee hors d'elle et
     * hors de `turn` est une cle nouvelle non declaree, donc un risque de
     * lecteur reveille sans l'avoir voulu.
     */
    private const CLES_LEGACY = [
        'loop_id',
        'requested_by',
        'latency_ms',
        'provider',
        'capability',
        'status',
        'sdk_invocation_id',
        'turn_id',
        RecordSdkEmbeddingsInvocation::TURN_METADATA_KEY,
        'failure',
        'retrieval',
        'sources_used',
        DossierRetrievalTraceRecorder::TURN_METADATA_KEY,
        'doctrine_version',
    ];

    private Organization $organization;

    private User $membre;

    private Loop $loop;

    private Dossier $dossier;

    /** Le manifeste ne se pose qu'une fois : deux fichiers changeraient le corpus entre deux tours. */
    private bool $manifestePose = false;

    protected function setUp(): void
    {
        parent::setUp();

        DossierRetrievalTraceRecorder::forgetJournal();
        AiTurnTrace::forgetJournal();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr']);

        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->membre->id,
            'name' => 'BoucleAria',
            'visibility' => 'private',
        ]);

        LoopMember::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'user_id' => $this->membre->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        app(LoopRootDocumentService::class)->ensureRootDossier($this->loop->fresh());

        $this->dossier = Dossier::query()->where('loop_id', $this->loop->id)->firstOrFail();

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1566',
        ]);

        config([
            'ai.default_for_embeddings' => 'openrouter',
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai_pricing.overrides' => [],
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $this->organization->id],
            'ai.knowledge.max_distance' => 0.60,
            'ai.knowledge.retrieval_trace.enabled' => true,
        ]);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        AiTurnTrace::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────────── 1. I8 — le garde HARD

    public function test_i8_la_seule_cle_nouvelle_au_premier_niveau_est_turn(): void
    {
        $metadata = $this->metadataDuTour();

        $nouvelles = array_values(array_diff(array_keys($metadata), self::CLES_LEGACY));

        $this->assertSame(
            [AiTurnTrace::TURN_METADATA_KEY],
            $nouvelles,
            'V0-A ne doit introduire AUCUNE cle de premier niveau hors `turn`.',
        );
    }

    public function test_i8_sources_denied_n_est_jamais_ecrit_au_premier_niveau(): void
    {
        $metadata = $this->metadataDuTour();

        // La cle qui allume `loops.why_denied` chez le membre. Son absence ici
        // est un contrat produit, pas un detail de rangement.
        $this->assertArrayNotHasKey('sources_denied', $metadata);
    }

    public function test_i8_execution_path_n_est_jamais_ecrit_au_premier_niveau(): void
    {
        $metadata = $this->metadataDuTour();

        // `execution_path` vit DANS le bloc `turn.identity`. Au premier niveau,
        // il deviendrait une cle publique que V0-G devrait ensuite deplacer.
        $this->assertArrayNotHasKey('execution_path', $metadata);
        $this->assertArrayHasKey('execution_path', $metadata[AiTurnTrace::TURN_METADATA_KEY]['identity']);
    }

    public function test_i8_aucune_cle_legacy_n_est_perdue_ni_renommee(): void
    {
        $metadata = $this->metadataDuTour();

        // `failure` est filtree quand elle est nulle (tour nominal) : c'est le
        // comportement HISTORIQUE d'`array_filter`, pas une perte introduite ici.
        foreach (['loop_id', 'requested_by', 'latency_ms', 'provider', 'capability', 'status', 'turn_id', 'retrieval', 'sources_used', 'doctrine_version'] as $cle) {
            $this->assertArrayHasKey($cle, $metadata, "la cle legacy `{$cle}` doit survivre a TASK-1566");
        }

        $this->assertArrayHasKey(DossierRetrievalTraceRecorder::TURN_METADATA_KEY, $metadata);
        $this->assertArrayHasKey(RecordSdkEmbeddingsInvocation::TURN_METADATA_KEY, $metadata);
    }

    public function test_l_ancien_turn_id_de_premier_niveau_est_conserve(): void
    {
        $metadata = $this->metadataDuTour();

        // Compatibilite avant elegance : la cle historique reste, et porte la
        // MEME valeur que l'identite canonique du bloc.
        $this->assertSame(
            $metadata['turn_id'],
            $metadata[AiTurnTrace::TURN_METADATA_KEY]['id'],
        );
    }

    // ────────────────────────────── 2. le bloc du pilote

    public function test_le_pilote_ecrit_un_bloc_de_schema_1_identifie_par_le_turn_id(): void
    {
        $turn = $this->blocDuTour();

        $this->assertSame(1, $turn['schema']);
        $this->assertTrue(Str::isUuid($turn['id']));
    }

    public function test_le_bloc_nomme_le_chemin_reellement_execute(): void
    {
        $identity = $this->blocDuTour()['identity'];

        $this->assertSame('loop_chat', $identity['surface']);
        $this->assertSame('dossiers', $identity['mode']);
        $this->assertSame('loop_chat.dossiers', $identity['execution_path']);
        $this->assertSame('loop_knowledge_answer', $identity['capability']);
    }

    public function test_le_bloc_distingue_le_provider_effectif_et_n_invente_pas_le_demande(): void
    {
        $identity = $this->blocDuTour()['identity'];

        $this->assertSame('openrouter', $identity['provider_effective']);
        $this->assertFalse($identity['fallback_used']);

        // `ResolvedModel` ne porte QUE le resolu : recopier l'effectif dans le
        // demande fabriquerait une mesure. Absent = UNAVAILABLE.
        $this->assertArrayNotHasKey('provider_requested', $identity);
    }

    public function test_le_bloc_dit_l_issue_du_tour_et_qui_l_a_decidee(): void
    {
        $turn = $this->blocDuTour();

        $this->assertSame(AiTurnState::TURN_ANSWERED, $turn['status']);
        $this->assertSame('LoopKnowledgeAnswerService', $turn['decided_by']);

        // Un tour nominal n'a pas d'etage terminal ni de raison : les ecrire
        // supposerait un echec qui n'a pas eu lieu.
        $this->assertArrayNotHasKey('stage', $turn);
        $this->assertArrayNotHasKey('reason_code', $turn);
    }

    public function test_les_etapes_traversees_sont_dans_l_ordre_d_execution(): void
    {
        $steps = $this->blocDuTour()['steps'];

        // TASK-1567 / V0-L a insere `conversation_history` entre la
        // construction du contexte et l'appel provider ; TASK-1574 / V0-F fait
        // deposer `retrieval` et `rerank` par `DossierRetrievalSource` PENDANT
        // le ContextBuilder (donc avant l'etape `context_builder`, que le
        // moteur ecrit quand le builder a rendu), et `grounding` apres l'appel.
        // C'est l'ordre reel d'execution, pas un rangement d'affichage.
        $this->assertSame(
            ['economic_check', 'retrieval', 'rerank', 'context_builder', 'conversation_history', 'provider_call', 'grounding'],
            array_column($steps, 'name'),
        );
        $statuts = array_column($steps, 'status', 'name');
        foreach (['economic_check', 'retrieval', 'context_builder', 'conversation_history', 'provider_call', 'grounding'] as $nom) {
            $this->assertSame('executed', $statuts[$nom], "`{$nom}`");
        }
        // Le rerank n'est pas configure sur ce banc : `skipped`, avec sa raison
        // (famille 4) — un gate interne non franchi, pas un echec.
        $this->assertSame('skipped', $statuts['rerank']);
    }

    public function test_la_latence_est_mesuree_une_seule_fois_pour_les_deux_cles(): void
    {
        $metadata = $this->metadataDuTour();

        $this->assertSame(
            $metadata['latency_ms'],
            $metadata[AiTurnTrace::TURN_METADATA_KEY]['latency_ms'],
        );
    }

    // ────────────────────────────── 3. ce que V0-A ne produit PAS encore

    public function test_les_sous_blocs_des_taches_suivantes_sont_absents_et_non_vides(): void
    {
        $turn = $this->blocDuTour();

        // `history` (V0-L), `sources` (V0-E) puis `state` (V0-F) appartenaient a
        // la liste des sous-blocs « a venir » jusqu'a leur TASK. La garde ne
        // disparait pas pour autant : elle CHANGE DE SENS et exige desormais la
        // presence. C'est la difference entre mettre un test a jour et
        // l'affaiblir. Le schema v1 est ainsi COMPLET sur le pilote.
        $this->assertArrayHasKey('history', $turn, '`history` est livre par V0-L : il doit etre present.');
        $this->assertArrayHasKey('sources', $turn, '`sources` est livre par V0-E : il doit etre present.');
        $this->assertSame(['retrieved', 'reranked', 'used', 'denied'], array_keys($turn['sources']));
        $this->assertArrayHasKey('state', $turn, '`state` est livre par V0-F : il doit etre present.');
        $this->assertSame(['verification_status', 'degraded_reason'], array_keys($turn['state']));
        $this->assertSame('supported', $turn['state']['verification_status'], 'grounded (syntaxique) → supported');
    }

    public function test_l_etape_grounding_est_observee_et_generation_jamais_fabriquee(): void
    {
        $steps = $this->blocDuTour()['steps'];
        $noms = array_column($steps, 'name');

        // `generation` n'est pas un etage de ce moteur : `provider_call` est
        // l'appel, `grounding` la verification. Rien n'est depose « pour faire
        // complet » — la chronologie ne ment pas.
        $this->assertNotContains('generation', $noms);

        // TASK-1574 / V0-F : `grounding` est OBSERVE — avec sa methode, dite
        // telle qu'elle est : syntaxique.
        $grounding = array_values(array_filter($steps, static fn (array $e): bool => $e['name'] === 'grounding'))[0];
        $this->assertSame('executed', $grounding['status']);
        $this->assertSame('syntactic_citations', $grounding['metrics']['method']);
        $this->assertSame(1, $grounding['metrics']['cited']);
    }

    // ────────────────────────────── 4. non-dependance et secret

    public function test_collecte_coupee_le_produit_est_identique(): void
    {
        // Deux tours successifs dans le MEME test : le second avec la collecte
        // coupee. Les comparer dans deux tests distincts ne prouverait rien —
        // il faut que ce soit la meme fixture, la meme question, le meme corpus.
        $avec = $this->metadataDuTour();

        AiTurnTrace::pauseCollectionForTesting();

        $sans = $this->metadataDuTour();

        // La reponse, les citations et la provenance ne bougent pas d'un octet.
        $this->assertSame($avec['retrieval'], $sans['retrieval']);
        $this->assertSame($avec['sources_used'], $sans['sources_used']);
        $this->assertSame($avec['status'], $sans['status']);

        // Le bloc reste ecrit — le writer connait son propre verdict — mais il
        // ne porte plus AUCUNE observation de composant.
        $this->assertArrayNotHasKey('identity', $sans[AiTurnTrace::TURN_METADATA_KEY]);
        $this->assertArrayNotHasKey('steps', $sans[AiTurnTrace::TURN_METADATA_KEY]);
        $this->assertSame(1, $sans[AiTurnTrace::TURN_METADATA_KEY]['schema']);
    }

    public function test_le_bloc_ne_porte_aucun_texte_de_document_ni_de_prompt(): void
    {
        $serialise = json_encode($this->blocDuTour(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Enrica', $serialise);
        $this->assertStringNotContainsString('ARIA Part B', $serialise);
        $this->assertStringNotContainsString('sk-test-1566', $serialise);
        $this->assertStringNotContainsString('platform-key', $serialise);
    }

    // ────────────────────────────── harnais

    /**
     * Execute UN tour reel et rend la metadata de l'interaction que CE tour
     * vient d'ecrire.
     *
     * L'identification se fait par DIFFERENCE d'identifiants et non par
     * `latest('created_at')` : deux tours du meme test partagent la meme
     * seconde, et le tri rendrait alors l'un pour l'autre — le genre de faux
     * exactement assez subtil pour faire passer un test qui ne mesure plus rien.
     *
     * @return array<string, mixed>
     */
    private function metadataDuTour(): array
    {
        $deja = AiInteraction::query()->pluck('id')->all();

        if (! $this->manifestePose) {
            $this->manifesteNonVide();
            $this->manifestePose = true;
        }

        $this->rechercheRendant([$this->ligne('Enrica coordonne le lot B.', 0.42)]);
        $this->agentRepond('Enrica coordonne le lot B [S1].');

        $this->artisan('ai:inspect-turn', [
            '--organization' => $this->organization->slug,
            '--user' => $this->membre->email,
            '--surface' => 'loop',
            '--loop' => (string) $this->loop->id,
            '--mode' => 'dossiers',
            '--question' => 'Qui est Enrica ?',
        ])->assertSuccessful();

        $interaction = AiInteraction::query()->whereNotIn('id', $deja)->get();

        $this->assertCount(1, $interaction, 'un tour doit ecrire EXACTEMENT une interaction');

        return $interaction->first()->metadata;
    }

    /** @return array<string, mixed> */
    private function blocDuTour(): array
    {
        $metadata = $this->metadataDuTour();

        $this->assertArrayHasKey(AiTurnTrace::TURN_METADATA_KEY, $metadata);

        return $metadata[AiTurnTrace::TURN_METADATA_KEY];
    }

    private function manifesteNonVide(): void
    {
        DossierFile::factory()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'uploaded_by' => $this->membre->id,
            'display_name' => 'ARIA Part B.docx',
            'original_name' => 'ARIA Part B.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /** @param  list<array<string, mixed>>  $lignes */
    private function rechercheRendant(array $lignes): MockInterface
    {
        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $mock->shouldReceive('searchAcrossDossiers')->andReturn($lignes);

        return $mock;
    }

    private function agentRepond(string $texte): void
    {
        LoopKnowledgeAgent::fake([
            new TextResponse($texte, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }

    /**
     * Le corpus est FIGE, identifiants compris.
     *
     * Des uuid tires au hasard a chaque appel rendraient impossible la seule
     * comparaison qui compte ici — le meme tour avec et sans collecte —
     * puisque la provenance differerait pour une raison qui n'a rien a voir
     * avec ce que le test mesure.
     *
     * @return array<string, mixed>
     */
    private function ligne(string $contenu, float $distance): array
    {
        return [
            'chunk_id' => '11111111-1111-4111-8111-111111111111',
            'dossier_id' => (string) $this->dossier->id,
            'dossier_name' => $this->dossier->name,
            'source_type' => 'file',
            'blog_post_id' => null,
            'title' => null,
            'slug' => null,
            'dossier_file_id' => '22222222-2222-4222-8222-222222222222',
            'filename' => 'ARIA Part B.docx',
            'mime_type' => 'application/pdf',
            'chunk_index' => 0,
            'content' => $contenu,
            'distance' => $distance,
        ];
    }
}
