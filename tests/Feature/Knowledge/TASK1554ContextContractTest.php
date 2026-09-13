<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Context\DossierRetrievalSource;
use App\Ai\Context\SourceDenied;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\DossierChunk;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\DTO\KnowledgeAnswer;
use App\Services\ChatLoop\AiResponseExplanationService;
use App\Services\Dossiers\DossierInsightsService;
use App\Services\Dossiers\DossierSemanticSearchService;
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
 * TASK-1554 — W3A Context Architecture Gate : le contrat commun a la frontiere
 * de `DossierInsightsService`.
 *
 * Decision Cockpit `W3A_OPTION = B` : ce service RESTE un moteur specialise,
 * distinct de `ContextBuilder`. Ce que W3A impose n'est pas une absorption,
 * c'est que sa frontiere parle la MEME semantique que l'autre mecanisme —
 * `used`, `denied`, `provenance` — avec les memes types et le meme vocabulaire.
 *
 * Ce que ces tests gardent, dans l'ordre de ce qui ferait le plus de degats :
 *
 *  1. la DISTINCTION refus / absence. Un refus deterministe connu du serveur
 *     doit se dire comme un refus ; un zero hit doit rester un zero hit. Les
 *     confondre, dans un sens comme dans l'autre, est la seule faute que ce
 *     contrat puisse commettre — et `sources_denied` est lu par le
 *     « Pourquoi ? » que T1549/T1551 ont livre ;
 *  2. la COMPATIBILITE de la forme historique. `metadata['sources']`,
 *     `retrieval.consulted/cited`, `ai_interaction_id`, l'ordre et l'identite
 *     des citations : la chaine T1551 doit continuer a les apparier POSITION
 *     par POSITION ;
 *  3. le MESSAGE montre a l'humain, inchange au caractere pres malgre le
 *     passage a un refus typed ;
 *  4. l'ABSENCE de nouvelle strategie de retrieval : le meme nombre d'appels,
 *     avec les memes arguments, y compris l'autorite de la connaissance
 *     derivee.
 *
 * Harnais repris TEL QUEL de TASK-1516 (recherche mockee, agent fake,
 * `Http::preventStrayRequests()`) : aucun appel reseau, aucun besoin de
 * pgvector.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1554ContextContractTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $autreOrganization;

    private User $proprietaire;

    private User $etranger;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['locale' => 'fr']);
        $this->autreOrganization = Organization::factory()->create();
        $this->proprietaire = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->etranger = User::factory()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);

        $this->dossier = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->proprietaire->id,
            'name' => 'Dossier ARIA',
            'visibility' => 'private',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1554',
        ]);

        config([
            'ai.default_for_embeddings' => 'openrouter',
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
    }

    // ─────────────────────────── 1. le contrat commun, a la frontiere

    public function test_une_reponse_documentaire_declare_la_source_qui_l_a_fondee(): void
    {
        $this->rechercheRendant([$this->ligne('A')]);
        $this->agentRepond('ARIA signifie ARtistic Intelligence Alliance [S1].');

        $reponse = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'C\'est quoi ARIA ?');

        $this->assertSame([DossierInsightsService::SOURCE_NAME], $reponse->sourcesUsed);
        $this->assertSame([], $reponse->sourcesDenied,
            'des sources fournies et autorisees ne peuvent pas etre refusees');
    }

    /**
     * La trace et le resultat sortent de la MEME expression, au meme moment.
     *
     * Deux calculs separes auraient diverge au premier correctif applique d'un
     * seul cote — c'est exactement ce que la remediation R2 de T1549 a paye une
     * fois, sur trois lectures independantes de la meme liste.
     */
    public function test_la_trace_dit_exactement_ce_que_le_resultat_dit(): void
    {
        $this->rechercheRendant([$this->ligne('A')]);
        $this->agentRepond('Reponse fondee [S1].');

        $reponse = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question ?');

        $interaction = AiInteraction::query()->findOrFail($reponse->interactionId);

        $this->assertSame($reponse->sourcesUsed, $interaction->metadata['sources_used']);
        $this->assertSame($reponse->sourcesDenied, $interaction->metadata['sources_denied']);
    }

    /**
     * La cle doit EXISTER, pas seulement valoir zero.
     *
     * `AiResponseExplanationService` calcule `denied_count` par
     * `count($imeta['sources_denied'] ?? [])` : une cle absente et une cle vide
     * rendent le meme 0 a l'ecran, mais seule la seconde est une MESURE. Sans
     * ce test, retirer l'ecriture ne ferait rougir aucune assertion de comptage.
     */
    public function test_la_trace_porte_les_deux_cles_du_contrat_meme_quand_rien_n_est_refuse(): void
    {
        $this->rechercheRendant([$this->ligne('A')]);
        $this->agentRepond('Reponse fondee [S1].');

        $reponse = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question ?');
        $metadata = AiInteraction::query()->findOrFail($reponse->interactionId)->metadata;

        $this->assertArrayHasKey('sources_used', $metadata);
        $this->assertArrayHasKey('sources_denied', $metadata);
    }

    /**
     * La provenance porte la cle `id` de la semantique commune — la SEULE
     * difference de forme qui restait avec `DossierRetrievalSource`.
     */
    public function test_la_provenance_porte_l_identifiant_de_la_semantique_commune(): void
    {
        $this->rechercheRendant([$this->ligne('A')]);
        $this->agentRepond('Reponse fondee [S1].');

        $reponse = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question ?');

        $this->assertNotSame([], $reponse->consulted);

        foreach ($reponse->consulted as $entree) {
            $this->assertArrayHasKey('id', $entree);
            $this->assertSame($entree['chunk_id'], $entree['id'],
                'l identite commune est celle du chunk, pas un second identifiant');
            $this->assertSame(DossierInsightsService::SOURCE_NAME, $entree['source']);
        }
    }

    // ───────────────────── 2. un vrai refus deterministe devient `denied`

    public function test_un_dossier_refuse_par_l_acl_leve_un_refus_typed(): void
    {
        $this->rechercheMuette();

        try {
            $this->service()->answer($this->organization, $this->dossier, $this->etranger, 'Question ?');
            $this->fail('un Dossier non autorise doit etre refuse');
        } catch (SourceDenied $refus) {
            $this->assertSame(DossierInsightsService::SOURCE_NAME, $refus->source);
            $this->assertSame(DossierInsightsService::REASON_DOSSIER_NOT_AUTHORIZED, $refus->reason);
        }
    }

    public function test_un_dossier_d_un_autre_tenant_leve_un_refus_typed(): void
    {
        $etranger = Dossier::create([
            'organization_id' => $this->autreOrganization->id,
            'owner_id' => User::factory()->create(['organization_id' => $this->autreOrganization->id])->id,
            'name' => 'Dossier etranger',
            'visibility' => 'organization',
        ]);

        $this->rechercheMuette();

        try {
            $this->service()->answer($this->organization, $etranger, $this->proprietaire, 'Question ?');
            $this->fail('un Dossier d un autre tenant doit etre refuse');
        } catch (SourceDenied $refus) {
            $this->assertContains($refus->reason, [
                DossierInsightsService::REASON_DOSSIER_OUTSIDE_ORGANIZATION,
                DossierInsightsService::REASON_DOSSIER_NOT_AUTHORIZED,
            ], 'les deux barrieres sont legitimes ; aucune ne doit laisser passer');
        }
    }

    /**
     * Typer un refus ne doit RIEN changer de ce qu'un humain lit.
     *
     * `DossierAnswerController` renvoie `$exception->getMessage()` dans le corps
     * de sa 503. Un refus typed dont le message serait devenu
     * « AI context source [...] denied: ... » aurait remplace une phrase
     * traduite par une trace technique — une difference produit, involontaire
     * et invisible en revue de diff.
     */
    public function test_le_refus_typed_ne_change_pas_un_caractere_du_message_montre(): void
    {
        $this->rechercheMuette();

        try {
            $this->service()->answer($this->organization, $this->dossier, $this->etranger, 'Question ?');
            $this->fail('un Dossier non autorise doit etre refuse');
        } catch (SourceDenied $refus) {
            $this->assertSame(__('dossiers.insights_not_authorized'), $refus->getMessage());
        }
    }

    /** Un refus typed reste un `RuntimeException` : aucun `catch` existant ne change. */
    public function test_un_refus_typed_reste_rattrape_par_les_appelants_historiques(): void
    {
        $this->rechercheMuette();

        $this->expectException(\RuntimeException::class);

        $this->service()->answer($this->organization, $this->dossier, $this->etranger, 'Question ?');
    }

    /**
     * Le refus qui se DEGUISAIT en zero resultat.
     *
     * `searchAcrossDossiers()` lit le gate de recherche semantique et rend `[]`
     * sans rien chercher quand il est ferme. A la frontiere, ce vide etait
     * rigoureusement indiscernable de « rien ne correspond » : le produit
     * repondait « je n'ai rien trouve » a propos d'un corpus qu'il n'avait
     * jamais ouvert.
     */
    public function test_une_recherche_desactivee_pour_le_tenant_cesse_de_se_deguiser_en_zero_resultat(): void
    {
        config(['ai.dossiers.semantic_search.enabled' => false]);

        $this->rechercheRendant([]);

        $reponse = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question ?');

        $this->assertSame(
            [DossierInsightsService::SOURCE_NAME => DossierRetrievalSource::REASON_SEMANTIC_SEARCH_DISABLED],
            $reponse->sourcesDenied,
        );
        $this->assertSame([], $reponse->sourcesUsed);
    }

    /**
     * Et ce que la personne LIT n'a pas bouge.
     *
     * W3A porte ce que le serveur sait ; il ne redessine aucune surface. Une
     * non-reponse honnete reste une reponse — jamais une panne affichee en
     * rouge (CDC §10).
     */
    public function test_le_refus_de_configuration_ne_change_ni_le_texte_ni_la_nature_de_la_reponse(): void
    {
        config(['ai.dossiers.semantic_search.enabled' => false]);

        $this->rechercheRendant([]);

        $reponse = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question ?');

        $this->assertSame(__('dossiers.answer_no_source', [], 'fr'), $reponse->answer);
        $this->assertFalse($reponse->grounded);
        $this->assertNull($reponse->interactionId);
        $this->assertSame([], $reponse->sources);
    }

    // ─────────────────── 3. un zero resultat ne devient JAMAIS un refus

    public function test_un_zero_resultat_sur_une_recherche_reelle_n_est_jamais_un_refus(): void
    {
        // Le gate est OUVERT : la recherche a reellement eu lieu, et n'a rien
        // rapporte. C'est une absence, pas une porte fermee.
        config([
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $this->organization->id],
        ]);

        $this->rechercheRendant([]);

        $reponse = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question ?');

        $this->assertSame([], $reponse->sourcesDenied,
            'zero hit, preuve insuffisante et document absent ne sont pas des refus');
        $this->assertSame([], $reponse->sourcesUsed);
    }

    /**
     * Et l'inverse : une reponse REUSSIE ne declare pas un refus parce que le
     * gate serait ferme quelque part. Le refus ne se dit que la ou il a
     * reellement produit le vide.
     */
    public function test_une_reponse_fondee_ne_declare_aucun_refus_meme_gate_ferme(): void
    {
        config(['ai.dossiers.semantic_search.enabled' => false]);

        $this->rechercheRendant([$this->ligne('A')]);
        $this->agentRepond('Reponse fondee [S1].');

        $reponse = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question ?');

        $this->assertSame([], $reponse->sourcesDenied);
        $this->assertSame([DossierInsightsService::SOURCE_NAME], $reponse->sourcesUsed);
    }

    // ──────────────── 4. la forme historique, et la chaine T1551

    public function test_la_forme_publique_d_une_source_est_inchangee(): void
    {
        $this->rechercheRendant([$this->ligne('A')]);
        $this->agentRepond('Reponse fondee [S1].');

        $reponse = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question ?');

        $this->assertCount(1, $reponse->sources);

        $publique = KnowledgeAnswer::publicSource($reponse->sources[0]);

        $this->assertSame(['ref', 'title', 'dossier_name', 'excerpt', 'url', 'type'], array_keys($publique));
        $this->assertArrayNotHasKey('id', $publique,
            'l identite commune est une cle INTERNE : elle ne fuit ni au JSON ni a la metadata');
        $this->assertArrayNotHasKey('chunk_id', $publique);
    }

    public function test_la_trace_de_retrieval_reste_appariable_position_par_position(): void
    {
        $this->rechercheRendant([$this->ligne('A'), $this->ligne('B')]);
        $this->agentRepond('Deux appuis [S1] et [S2].');

        $reponse = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question ?');

        $retrieval = AiInteraction::query()->findOrFail($reponse->interactionId)->metadata['retrieval'];

        $this->assertSame(['consulted', 'cited'], array_keys($retrieval));
        $this->assertCount(count($reponse->sources), $retrieval['cited'],
            'T1551 apparie les titres publics et la trace POSITION par POSITION');

        foreach ($retrieval['cited'] as $index => $trace) {
            $this->assertSame(['chunk_id', 'dossier_id'], array_keys($trace),
                'la trace ne porte que des identifiants, jamais un contenu');
            $this->assertSame($reponse->sources[$index]['chunk_id'], $trace['chunk_id']);
        }
    }

    /**
     * Le « Pourquoi ? » de T1551 relit REELLEMENT cette reponse.
     *
     * Ce n'est pas une assertion de forme : c'est l'autorite d'explication
     * elle-meme (`citedProvenance()`, celle que le ChatLoop et le Shell
     * partagent depuis T1551) rebranchee sur la sortie de ce moteur.
     */
    public function test_le_pourquoi_de_t1551_continue_de_lire_cette_reponse(): void
    {
        // Un chunk REEL : la chaine d'explication resout `retrieval.cited`
        // contre la base. Des identifiants inventes rendraient « source
        // injoignable » — le troisieme etat de T1549 — et ne prouveraient rien
        // de la compatibilite.
        $this->rechercheRendant([$this->ligneDeFichierReel()]);
        $this->agentRepond('Reponse fondee [S1].');

        $reponse = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question ?');

        $retrieval = AiInteraction::query()->findOrFail($reponse->interactionId)->metadata['retrieval'];
        $publiques = array_map(KnowledgeAnswer::publicSource(...), $reponse->sources);

        $panneau = app(AiResponseExplanationService::class)->citedProvenance(
            $this->organization,
            null,
            $this->proprietaire,
            $publiques,
            $retrieval,
        );

        $this->assertSame(['documents', 'memory', 'unreachable_count'], array_keys($panneau));
        $this->assertCount(1, $panneau['documents']['entries'],
            'la source citee doit rester retrouvable par la chaine d explication');
        $this->assertSame(0, $panneau['unreachable_count'],
            'une source fraichement citee n est jamais injoignable');
    }

    // ───────── 5. aucune nouvelle strategie de retrieval, aucune fuite

    /**
     * Le contrat est une LECTURE de ce qui etait deja su. Il n'ajoute aucune
     * requete, et surtout aucune requete qui elargirait le perimetre.
     *
     * Le gate est un booleen de configuration : le lire ne touche ni la base,
     * ni le classement, ni le bassin de candidats.
     */
    public function test_le_contrat_n_ajoute_aucune_recherche(): void
    {
        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $mock->shouldReceive('searchAcrossDossiers')->once()->andReturn([$this->ligne('A')]);

        $this->agentRepond('Reponse fondee [S1].');

        $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question ?');
    }

    /**
     * L'autorite de la connaissance derivee traverse la frontiere inchangee.
     *
     * `answer()` transmet les Boucles que CE lecteur peut lire (T1535). W3A ne
     * touche ni l'argument, ni sa position, ni sa valeur — sans quoi une note
     * derivee d'une Boucle privee deviendrait lisible par la porte documentaire.
     */
    public function test_l_autorite_de_la_connaissance_derivee_est_toujours_transmise(): void
    {
        $capture = null;

        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $mock->shouldReceive('searchAcrossDossiers')
            ->andReturnUsing(function (...$arguments) use (&$capture) {
                $capture = $arguments;

                return [];
            });

        $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question ?');

        $this->assertIsArray($capture);
        $this->assertCount(9, $capture, 'la signature du retrieval n a pas bouge');
        $this->assertIsArray($capture[8], 'les Boucles autorisees restent transmises, jamais null');
        $this->assertSame([(string) $this->dossier->id], $capture[1],
            'le perimetre reste le seul Dossier deja autorise');
    }

    /**
     * Le refus ne dit RIEN de la ressource refusee.
     *
     * `SourceDenied` existe pour le diagnostic ; il ne doit rien laisser fuir de
     * ce qu'il n'a pas eu le droit de lire — ni nom de Dossier, ni identifiant,
     * ni contenu.
     */
    public function test_un_refus_ne_divulgue_ni_le_nom_ni_l_identifiant_du_dossier(): void
    {
        $secret = Dossier::create([
            'organization_id' => $this->autreOrganization->id,
            'owner_id' => User::factory()->create(['organization_id' => $this->autreOrganization->id])->id,
            'name' => 'CONFIDENTIEL FUSION ACQUISITION',
            'visibility' => 'organization',
        ]);

        $this->rechercheMuette();

        try {
            $this->service()->answer($this->organization, $secret, $this->proprietaire, 'Question ?');
            $this->fail('un Dossier d un autre tenant doit etre refuse');
        } catch (SourceDenied $refus) {
            $this->assertStringNotContainsString('CONFIDENTIEL', $refus->getMessage());
            $this->assertStringNotContainsString((string) $secret->id, $refus->getMessage());
            $this->assertStringNotContainsString((string) $secret->id, $refus->reason);
        }
    }

    // ───────────────────────────────────────────────────────── fixtures

    private function service(): DossierInsightsService
    {
        return app(DossierInsightsService::class);
    }

    /** @param  list<array<string, mixed>>  $lignes */
    private function rechercheRendant(array $lignes): MockInterface
    {
        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $mock->shouldReceive('searchAcrossDossiers')->andReturn($lignes);

        return $mock;
    }

    /** Le retrieval ne doit meme pas etre atteint : la garde refuse avant. */
    private function rechercheMuette(): MockInterface
    {
        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldNotReceive('searchAcrossDossiers');

        return $mock;
    }

    private function agentRepond(string $texte): void
    {
        LoopKnowledgeAgent::fake([
            new TextResponse($texte, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }

    /**
     * Une ligne de retrieval adossee a un chunk REELLEMENT en base — la seule
     * forme que la chaine d'explication de T1551 sait resoudre.
     *
     * @return array<string, mixed>
     */
    private function ligneDeFichierReel(): array
    {
        $fichier = DossierFile::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'uploaded_by' => $this->proprietaire->id,
            'disk' => 'dossier_files',
            'path' => 'dossier-files/'.Str::uuid().'.pdf',
            'original_name' => 'Devis charpente.pdf',
            'display_name' => 'Devis charpente.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'checksum_sha256' => hash('sha256', (string) Str::uuid()),
            'source' => 'upload',
        ]);

        $contenu = 'Le devis original de la charpente, revision 2.';

        $chunk = DossierChunk::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'dossier_file_id' => $fichier->id,
            'chunk_index' => 0,
            'content' => $contenu,
            'content_hash' => hash('sha256', $contenu),
            'embedding' => array_fill(0, 1536, 0.01),
            'embedding_provider' => 'openrouter',
            'embedding_model' => 'openai/text-embedding-3-small',
            'indexed_at' => now(),
        ]);

        return [
            'chunk_id' => (string) $chunk->id,
            'dossier_id' => $this->dossier->id,
            'dossier_name' => $this->dossier->name,
            'source_type' => 'file',
            'blog_post_id' => null,
            'title' => null,
            'slug' => null,
            'dossier_file_id' => (string) $fichier->id,
            'filename' => $fichier->display_name,
            'mime_type' => $fichier->mime_type,
            'chunk_index' => 0,
            'content' => $contenu,
            'distance' => 0.12,
        ];
    }

    /** @return array<string, mixed> Forme rendue par `searchAcrossDossiers()`. */
    private function ligne(string $etiquette): array
    {
        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $this->dossier->id,
            'dossier_name' => $this->dossier->name,
            'source_type' => 'article',
            'blog_post_id' => (string) Str::uuid(),
            'title' => 'Document '.$etiquette,
            'slug' => 'document-'.strtolower($etiquette),
            'dossier_file_id' => null,
            'filename' => null,
            'mime_type' => null,
            'chunk_index' => 0,
            'content' => "Contenu {$etiquette} : ARIA signifie ARtistic Intelligence Alliance.",
            'distance' => 0.12,
        ];
    }
}
