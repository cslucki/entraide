<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Context\DossierRerankOutcome;
use App\Ai\Context\DossierRetrievalTraceRecorder;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\RerankingPrompt;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1565 — TRACE FOUNDATION : rendre les etages du retrieval OBSERVABLES,
 * sans toucher a ce que le produit fait.
 *
 * ## Le defaut mesure, et pourquoi il comptait
 *
 * Sur la vraie Boucle ARIA, « Qui est Enrica ? » :
 *
 *     GOLD_DENSE_RANK       = 1        le MEILLEUR candidat du corpus
 *     GOLD_DISTANCE         = 0.6849
 *     AFTER_DISTANCE_FILTER = 0        le filtre ne choisit pas mal : il VIDE
 *     RERANK_ATTEMPTED      = NO       Cohere n'a rien a reordonner
 *
 * Ce tour ne laissait AUCUNE trace, nulle part. Ni `Log::info('ai.rerank')`
 * (court-circuite par le retour a vide, ~85 lignes plus haut), ni
 * `sourcesDenied` (rien n'a ete REFUSE — une recherche infructueuse n'est pas
 * une porte fermee), ni `sourcesUsed` (le fragment vide est purge par
 * `ContextBuilder`). Trois autorites aveugles au meme instant, sur le seul cas
 * qu'il fallait voir.
 *
 * ## Ce que ces tests gardent, par ordre de degats
 *
 *  1. **le cas critique est visible** (S2) — sans quoi la mesure du seuil dense
 *     resterait impossible et il faudrait reconstruire le pipeline a cote du
 *     produit, ce qui cesserait de mesurer le produit ;
 *  2. **le produit n'a pas bouge** — aucune reponse, aucune citation, aucun
 *     compte de sources ne change, et surtout `sources_denied` n'est PAS ecrit
 *     au premier niveau de la metadata : la, il allumerait un bandeau visible
 *     par le membre (`AiResponseExplanationService` -> `loops.why_denied`) ;
 *  3. **la trace ne ment pas** — `candidates_sent_to_rerank_count` vaut ce qui
 *     est REELLEMENT parti chez le provider, et `rerank_rank` n'existe que si
 *     le rerank a reussi : en repli, l'ordre dense ne se deguise pas en
 *     classement Cohere ;
 *  4. **l'observabilite n'est pas une dependance** — coupee, la reponse est
 *     identique au caractere pres ;
 *  5. **rien de sensible n'est persiste** — des compteurs et des identifiants,
 *     jamais un texte de chunk.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1565RetrievalTraceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Loop $loop;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        DossierRetrievalTraceRecorder::forgetJournal();

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
            'api_key' => 'sk-test-1565',
        ]);

        config([
            'ai.default_for_embeddings' => 'openrouter',
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai_pricing.overrides' => [],
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $this->organization->id],
            // Le seuil REEL de production : c'est lui qui vide le bassin sur le
            // cas ENRICA, et le figer ici empeche un `.env` de banc de
            // transformer ce test en mesure d'autre chose.
            'ai.knowledge.max_distance' => 0.60,
            'ai.knowledge.retrieval_trace.enabled' => true,
        ]);

        Http::preventStrayRequests();
    }

    // ────────── S1 : un bassin dense non vide est COMPTE

    public function test_s1_le_bassin_dense_est_compte_avant_et_apres_le_filtre(): void
    {
        $this->rechercheRendant([
            $this->ligne('Prof. Enrica De Cian — Team Lead UNIVE.', 0.12),
            $this->ligne('Le consortium ARIA reunit huit partenaires.', 0.30),
            $this->ligne('Le work package 3 demarre en mars.', 0.45),
        ]);
        $this->agentRepond('UNIVE coordonne le projet [S1].');

        $trace = $this->traceDuTour();

        $this->assertSame(3, $trace['dense_candidates_count']);
        $this->assertSame(3, $trace['after_distance_filter_count']);
        $this->assertSame(3, $trace['final_context_count']);
        $this->assertTrue($trace['candidates'][0]['passed_distance_filter']);
        $this->assertSame(1, $trace['candidates'][0]['dense_rank']);
        $this->assertSame(0.12, $trace['candidates'][0]['dense_distance']);
        $this->assertTrue($trace['candidates'][0]['selected_final']);
    }

    /**
     * `candidates_found` etait `null` en v0 parce que le bassin vivait a
     * l'interieur de la source et que rien ne l'exposait. T1565 ne change pas
     * la regle « NULL reste NULL » : il rend la valeur OBSERVABLE, par le
     * pipeline lui-meme.
     */
    public function test_le_bassin_dense_remonte_jusqu_a_candidates_found(): void
    {
        $this->rechercheRendant([
            $this->ligne('Prof. Enrica De Cian — Team Lead UNIVE.', 0.12),
            $this->ligne('Le consortium ARIA reunit huit partenaires.', 0.30),
        ]);
        $this->agentRepond('UNIVE coordonne [S1].');

        $sortie = $this->sortieJson();

        $this->assertSame(2, $sortie['retrieval']['candidates_found']);
    }

    // ────────── S2 : LE cas critique — le filtre VIDE le bassin

    /**
     * Le cas ENRICA, reproduit aux distances reellement mesurees.
     *
     * Le meilleur passage du corpus (0.6849) n'atteint jamais Cohere : le
     * filtre absolu l'ecarte AVANT. Avant T1565, ce tour etait un trou noir —
     * il faut donc que chacun des quatre chiffres du mandat soit lisible.
     */
    public function test_s2_le_bassin_vide_apres_le_filtre_de_distance_devient_visible(): void
    {
        $this->manifesteNonVide();

        $this->rechercheRendant([
            $this->ligne('Prof. Enrica De Cian — Team Lead UNIVE.', 0.6849),
            $this->ligne('Le consortium ARIA reunit huit partenaires.', 0.7985),
        ]);
        $this->agentRepond('Je ne trouve pas cette information dans les Dossiers.');

        $trace = $this->traceDuTour();

        $this->assertSame(2, $trace['dense_candidates_count'], 'le bassin dense N EST PAS vide');
        $this->assertSame(0, $trace['after_distance_filter_count'], 'c est le FILTRE qui vide');
        $this->assertFalse($trace['rerank_attempted']);
        $this->assertSame(
            DossierRerankOutcome::REASON_EMPTY_AFTER_DISTANCE_FILTER,
            $trace['reason_not_attempted'],
            'un bassin vide ne se confond jamais avec un pilote eteint',
        );
        $this->assertSame(0, $trace['final_context_count']);
    }

    /** Le GOLD est decrit assez precisement pour que le seuil soit mesurable. */
    public function test_s2_le_meilleur_candidat_ecarte_reste_decrit(): void
    {
        $this->manifesteNonVide();

        $this->rechercheRendant([
            $this->ligne('Prof. Enrica De Cian — Team Lead UNIVE.', 0.6849),
            $this->ligne('Le consortium ARIA reunit huit partenaires.', 0.7985),
        ]);
        $this->agentRepond('Je ne trouve pas cette information.');

        $gold = $this->traceDuTour()['candidates'][0];

        $this->assertSame(1, $gold['dense_rank'], 'le GOLD est bien le meilleur candidat du corpus');
        $this->assertSame(0.6849, $gold['dense_distance']);
        $this->assertFalse($gold['passed_distance_filter'], 'et il est ECARTE par le seuil absolu');
        $this->assertNull($gold['rerank_rank'], 'il n a jamais atteint le reranker');
        $this->assertFalse($gold['selected_final']);
    }

    // ────────── S3 : le rerank s'execute REELLEMENT

    public function test_s3_ce_qui_part_chez_le_reranker_est_compte_exactement(): void
    {
        $this->rerankOuvert();
        $soumis = $this->rerankCapture();

        $this->rechercheRendant([
            $this->ligne('Prof. Enrica De Cian — Team Lead UNIVE.', 0.12),
            $this->ligne('Le consortium ARIA reunit huit partenaires.', 0.30),
            $this->ligne('Le work package 3 demarre en mars.', 0.45),
        ]);
        $this->agentRepond('UNIVE coordonne le projet [S1].');

        $trace = $this->traceDuTour();

        $this->assertTrue($trace['rerank_attempted']);
        $this->assertTrue($trace['rerank_succeeded']);
        $this->assertCount(3, $soumis->documents, 'garde-fou : le VRAI appel a bien recu trois documents');
        $this->assertSame(
            count($soumis->documents),
            $trace['candidates_sent_to_rerank_count'],
            'le compte trace est celui du VRAI appel, pas une estimation',
        );
        $this->assertSame(3, $trace['rerank_result_count']);
        $this->assertSame('openrouter', $trace['rerank_provider']);
    }

    /** Le classement trace est celui qui a REELLEMENT servi, pas l'ordre dense. */
    public function test_s3_le_rang_de_rerank_est_celui_du_classement_rendu(): void
    {
        $this->rerankOuvert();

        // Un reranker qui INVERSE : si la trace rendait l'ordre dense, elle
        // afficherait 1,2,3 et ce test resterait vert a tort.
        Reranking::fake(fn (RerankingPrompt $prompt): array => array_map(
            fn (int $index): RankedDocument => new RankedDocument(
                index: count($prompt->documents) - 1 - $index,
                document: $prompt->documents[count($prompt->documents) - 1 - $index],
                score: 1.0 - ($index / 100),
            ),
            array_keys($prompt->documents),
        ))->preventStrayRerankings();

        $this->rechercheRendant([
            $this->ligne('Premier en dense.', 0.12),
            $this->ligne('Deuxieme en dense.', 0.30),
            $this->ligne('Troisieme en dense.', 0.45),
        ]);
        $this->agentRepond('Reponse [S1].');

        $candidats = $this->traceDuTour()['candidates'];

        $this->assertSame(1, $candidats[0]['dense_rank']);
        $this->assertSame(3, $candidats[0]['rerank_rank'], 'le meilleur dense est DERNIER apres rerank');
        $this->assertSame(1, $candidats[2]['rerank_rank'], 'et le dernier dense est passe premier');
    }

    /**
     * En REPLI, l'ordre dense ne se deguise pas en classement Cohere.
     *
     * `DossierRerankOutcome::$rows` rend le tableau d'entree TEL QUEL quand le
     * rerank n'a pas eu lieu : lire sa position sous le nom `rerank_rank`
     * ferait croire a un classement qui n'a jamais ete calcule.
     */
    public function test_sans_rerank_aucun_rang_de_rerank_n_est_fabrique(): void
    {
        $this->rechercheRendant([
            $this->ligne('Prof. Enrica De Cian — Team Lead UNIVE.', 0.12),
            $this->ligne('Le consortium ARIA reunit huit partenaires.', 0.30),
        ]);
        $this->agentRepond('UNIVE coordonne [S1].');

        $trace = $this->traceDuTour();

        $this->assertFalse($trace['rerank_attempted']);
        $this->assertSame(0, $trace['candidates_sent_to_rerank_count'],
            'rien n a ete soumis : annoncer le bassin ferait etat d un envoi qui n a pas eu lieu');

        foreach ($trace['candidates'] as $candidat) {
            $this->assertNull($candidat['rerank_rank']);
        }
    }

    // ────────── le vocabulaire des raisons

    public function test_un_pilote_eteint_se_distingue_d_un_bassin_vide(): void
    {
        $this->rechercheRendant([
            $this->ligne('Prof. Enrica De Cian — Team Lead UNIVE.', 0.12),
            $this->ligne('Le consortium ARIA reunit huit partenaires.', 0.30),
        ]);
        $this->agentRepond('UNIVE coordonne [S1].');

        // La porte est fermee par defaut (DEFAULT_OFF_BY_DESIGN, T1560/T1562).
        $this->assertSame(
            DossierRerankOutcome::REASON_GATE_CLOSED,
            $this->traceDuTour()['reason_not_attempted'],
        );
    }

    public function test_un_candidat_unique_est_nomme_not_applicable_et_pas_un_bassin_vide(): void
    {
        $this->rechercheRendant([$this->ligne('Prof. Enrica De Cian — Team Lead UNIVE.', 0.12)]);
        $this->agentRepond('UNIVE coordonne [S1].');

        $trace = $this->traceDuTour();

        $this->assertSame(1, $trace['after_distance_filter_count']);
        $this->assertSame(DossierRerankOutcome::REASON_NOT_APPLICABLE, $trace['reason_not_attempted']);
    }

    // ────────── la garde de NON-DEPENDANCE

    /**
     * Coupee, la collecte ne doit rien changer — c'est ce qui prouve que
     * l'observabilite n'est pas devenue une dependance fonctionnelle.
     */
    public function test_la_collecte_coupee_ne_change_ni_la_reponse_ni_la_provenance(): void
    {
        $lignes = [
            $this->ligne('Prof. Enrica De Cian — Team Lead UNIVE.', 0.12),
            $this->ligne('Le consortium ARIA reunit huit partenaires.', 0.30),
        ];

        $this->rechercheRendant($lignes);
        $this->agentRepond('UNIVE coordonne le projet [S1].');
        $avec = $this->sortieJson();

        AiInteraction::query()->delete();
        DossierRetrievalTraceRecorder::forgetJournal();
        config(['ai.knowledge.retrieval_trace.enabled' => false]);

        $this->rechercheRendant($lignes);
        $this->agentRepond('UNIVE coordonne le projet [S1].');
        $sans = $this->sortieJson();

        $this->assertSame($avec['output']['answer'], $sans['output']['answer']);
        $this->assertSame($avec['output']['grounded'], $sans['output']['grounded']);
        $this->assertSame($avec['output']['citations'], $sans['output']['citations']);
        $this->assertSame($avec['llm_input']['chunks_sent'], $sans['llm_input']['chunks_sent']);
        $this->assertSame($avec['selection'], $sans['selection']);

        $this->assertNotNull($avec['retrieval_trace']['dense_candidates_count']);
        $this->assertNull($sans['retrieval_trace']['dense_candidates_count'],
            'coupee, la trace ne rend pas 0 : elle ne rend RIEN');
        $this->assertNull($sans['retrieval']['candidates_found']);
    }

    // ────────── le produit n'a pas bouge

    /**
     * LA garde produit de cette TASK.
     *
     * `sources_denied` au PREMIER NIVEAU de la metadata est lu par
     * `AiResponseExplanationService::ragPanel()` et allume un bandeau ambre
     * VISIBLE PAR LE MEMBRE (« N sources de contexte ont ete refusees »). Le
     * docblock de `KnowledgeAnswer` l'avait annonce : « une difference PRODUIT,
     * qui demande sa propre mesure et pas un branchement de commodite ».
     *
     * T1565 est une TASK d'observabilite : elle ne l'allume pas.
     */
    public function test_le_relai_w3a_n_allume_aucun_bandeau_chez_le_membre(): void
    {
        $this->rechercheRendant([$this->ligne('Prof. Enrica De Cian — Team Lead UNIVE.', 0.12)]);
        $this->agentRepond('UNIVE coordonne [S1].');

        $this->executer();

        $metadata = AiInteraction::query()->firstOrFail()->metadata;

        $this->assertArrayNotHasKey('sources_denied', $metadata,
            'au premier niveau, cette cle allumerait `loops.why_denied` dans la bulle du membre');
        $this->assertArrayHasKey('sources_used', $metadata, 'la dette W3A, elle, est bien payee');
        $this->assertContains('dossier.retrieval', $metadata['sources_used']);
    }

    /** Et l'inspection, elle, les voit quand meme — c'est tout l'objet du relai. */
    public function test_les_refus_restent_lisibles_par_l_inspection(): void
    {
        $this->rechercheRendant([$this->ligne('Prof. Enrica De Cian — Team Lead UNIVE.', 0.12)]);
        $this->agentRepond('UNIVE coordonne [S1].');

        $sortie = $this->sortieJson();

        $this->assertIsArray($sortie['retrieval_trace']['sources_denied']);
        $this->assertIsArray($sortie['provider']['sources_denied'],
            'l inspection rend UNE reponse, quel que soit l emplacement d ecriture');
    }

    /** Aucun texte de document ne doit survivre dans la trace persistee. */
    public function test_la_trace_persistee_ne_porte_aucun_texte_de_document(): void
    {
        $this->rechercheRendant([$this->ligne('SECRET INDUSTRIEL Enrica De Cian.', 0.12)]);
        $this->agentRepond('UNIVE coordonne [S1].');

        $this->executer();

        $trace = AiInteraction::query()->firstOrFail()
            ->metadata[DossierRetrievalTraceRecorder::TURN_METADATA_KEY];

        $this->assertStringNotContainsString('SECRET INDUSTRIEL',
            (string) json_encode($trace, JSON_UNESCAPED_UNICODE));
    }

    /** Une trace n'est reclamee qu'UNE fois : jamais recyclee par le tour suivant. */
    public function test_une_trace_n_est_reclamee_qu_une_fois(): void
    {
        DossierRetrievalTraceRecorder::record('org', 'tour', ['dense_candidates_count' => 7]);

        $this->assertSame(['dense_candidates_count' => 7],
            DossierRetrievalTraceRecorder::claim('org', 'tour'));
        $this->assertNull(DossierRetrievalTraceRecorder::claim('org', 'tour'));
    }

    /** Un autre tenant, ou un autre tour, ne reclame jamais la trace d'autrui. */
    public function test_une_trace_n_est_jamais_reclamee_par_un_autre_tour(): void
    {
        DossierRetrievalTraceRecorder::record('org-a', 'tour-1', ['dense_candidates_count' => 7]);

        $this->assertNull(DossierRetrievalTraceRecorder::claim('org-b', 'tour-1'));
        $this->assertNull(DossierRetrievalTraceRecorder::claim('org-a', 'tour-2'));
        $this->assertNotNull(DossierRetrievalTraceRecorder::claim('org-a', 'tour-1'));
    }

    // ─────────────────────────────────────────────── fixtures

    /** La trace de retrieval REELLEMENT persistee par le tour. */
    private function traceDuTour(): array
    {
        $this->executer();

        $metadata = AiInteraction::query()->firstOrFail()->metadata;

        $this->assertArrayHasKey(DossierRetrievalTraceRecorder::TURN_METADATA_KEY, $metadata);

        $trace = $metadata[DossierRetrievalTraceRecorder::TURN_METADATA_KEY]['dossier_retrieval'] ?? null;

        $this->assertIsArray($trace, 'le tour doit avoir depose sa trace de retrieval');

        return $trace;
    }

    private function executer(): void
    {
        $this->artisan('ai:inspect-turn', $this->invocation())->assertSuccessful();
    }

    /** @return array<string, mixed> */
    private function sortieJson(): array
    {
        $code = Artisan::call('ai:inspect-turn', $this->invocation(['--json' => true]));

        $this->assertSame(0, $code, 'la commande doit reussir pour que sa trace soit lisible');

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, string>  $remplace */
    private function invocation(array $remplace = []): array
    {
        return array_merge([
            '--organization' => $this->organization->slug,
            '--user' => $this->membre->email,
            '--surface' => 'loop',
            '--loop' => (string) $this->loop->id,
            '--mode' => 'dossiers',
            '--question' => 'Qui est Enrica ?',
        ], $remplace);
    }

    /**
     * Le manifest doit rendre quelque chose pour que le tour aille jusqu'a
     * l'ecriture de son interaction.
     *
     * Ce n'est pas un artifice de test : c'est EXACTEMENT la situation ARIA
     * mesuree — « FINAL_CONTEXT = 2 manifestes, ZERO extrait semantique ». Sans
     * aucune provenance, le mode Dossiers refuse AVANT tout appel et n'ecrit
     * aucune `AiInteraction` : il n'y a alors nulle part ou persister la trace,
     * et c'est une limite assumee de ce v0.
     */
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

    /** Ouvre les DEUX verrous du pilote Cohere (T1562/T1563). */
    private function rerankOuvert(): void
    {
        OrganizationAiSetting::query()
            ->where('organization_id', $this->organization->id)
            ->update(['rerank_enabled' => true]);

        config([
            'ai.knowledge.rerank.enabled' => true,
            'ai.knowledge.rerank.model' => 'cohere/rerank-v3.5',
            'ai.knowledge.rerank.url' => 'https://openrouter.ai/api/v1',
        ]);
    }

    /** Capture ce qui part REELLEMENT chez le reranker, sans changer l'ordre. */
    private function rerankCapture(): object
    {
        $vu = new class
        {
            /** @var list<string> */
            public array $documents = [];
        };

        Reranking::fake(function (RerankingPrompt $prompt) use ($vu): array {
            $vu->documents = $prompt->documents;

            return array_map(
                fn (int $index): RankedDocument => new RankedDocument(
                    index: $index,
                    document: $prompt->documents[$index],
                    score: 1.0 - ($index / 100),
                ),
                array_keys($prompt->documents),
            );
        })->preventStrayRerankings();

        return $vu;
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

    /** @return array<string, mixed> */
    private function ligne(string $contenu, float $distance): array
    {
        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => (string) $this->dossier->id,
            'dossier_name' => $this->dossier->name,
            'source_type' => 'file',
            'blog_post_id' => null,
            'title' => null,
            'slug' => null,
            'dossier_file_id' => (string) Str::uuid(),
            'filename' => 'ARIA Part B.docx',
            'mime_type' => 'application/pdf',
            'chunk_index' => 0,
            'content' => $contenu,
            'distance' => $distance,
        ];
    }
}
