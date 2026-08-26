<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\DocumentaryQuestionShape;
use App\Ai\Context\DossierManifestSource;
use App\Ai\Context\DossierRetrievalSource;
use App\Ai\ContexteIa;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierChunk;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiCorrelation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1309 — sous-objectif A : des Dossiers reellement intelligents.
 *
 * ## Le bug reproduit, et pourquoi il n'etait pas qu'une phrase de prompt
 *
 * Sur le corpus reel (`test20260822` / Boucle `01-COMMUNICATION`, 26 chunks
 * indexes sur 5 documents), la question « Que contiennent les dossiers ? »
 * produisait une reponse auto-contradictoire : « Je n'ai pas trouve cette
 * information [...] Voici la liste des elements du dossier : [M1]...[M6] ».
 * L'interaction correspondante porte 6 references [Mn] et ZERO [Sn].
 *
 * La cause profonde n'est donc PAS seulement que le prompt v2 autorisait le
 * refus et l'inventaire a coexister — c'est que le moteur ne fournissait
 * AUCUN extrait de contenu a une question panoramique. Une question de ce
 * genre n'a par construction aucun excellent voisin vectoriel : le filtre
 * `max_distance` ecarte tout, et il ne reste que des metadonnees. Corriger la
 * phrase sans corriger l'apport aurait rendu la reponse coherente ET vide.
 *
 * ## Ce que ces tests prouvent
 *
 *  - une question panoramique sans aucun voisin vectoriel recoit desormais
 *    des extraits [Sn] representatifs de PLUSIEURS documents ;
 *  - un marqueur de largeur elargit une selection concentree sur un seul
 *    document ;
 *  - une question PRECISE qui trouve des extraits n'est jamais diluee ni
 *    tronquee — « question precise non degradee » ;
 *  - une question d'inventaire pur continue de repondre depuis le manifest ;
 *  - la vue d'ensemble reste strictement Organization- ET Loop-scoped ;
 *  - elle ne declenche AUCUN appel provider supplementaire ;
 *  - « Sources utilisees » = sources REELLEMENT citees.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1309DocumentaryOverviewTest extends TestCase
{
    use RefreshDatabase;

    /** Marqueur place a la fin d'un contenu long : present = extrait entier. */
    private const TAIL_MARKER = 'FIN-DU-CONTENU-ALPHA';

    private Organization $organization;

    private Organization $otherOrganization;

    private User $member;

    private User $stranger;

    private Loop $loop;

    private Dossier $dossier;

    private FakeOverviewSearch $search;

    /** @var array<string, DossierFile> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'ArtSciLab', 'slug' => 'artscilab']);
        $this->otherOrganization = Organization::factory()->create(['name' => 'Autre Org', 'slug' => 'autre-org']);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->stranger = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        app()->instance('current_organization', $this->organization);
        $this->loop = (new LoopService)->createLoop($this->member, '01-COMMUNICATION');

        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'Dossier de la Boucle',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loop->id,
        ]);

        // Quatre documents indexes, deux chunks chacun — la forme du corpus
        // reel (un manifeste, une architecture, un benchmark, un cadre).
        $this->indexDocument('alpha', 'Manifeste.md', 'ALPHA parle du role de l IA dans les Boucles');
        $this->indexDocument('beta', 'Architecture.md', 'BETA parle de l architecture multi-communautes');
        $this->indexDocument('gamma', 'Benchmark.md', 'GAMMA parle des innovations comparees');
        $this->indexDocument('delta', 'Gouvernance.md', 'DELTA parle de la gouvernance et des roles');

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-or-tenant',
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$this->organization->id],
            'ai_pricing.overrides' => [],
        ]);

        $this->search = new FakeOverviewSearch;
        $this->app->instance(DossierSemanticSearchService::class, $this->search);

        Http::preventStrayRequests();
    }

    // =====================================================================
    // A. Le cas reproduit : question panoramique, zero voisin vectoriel.
    // =====================================================================

    /**
     * Avant TASK-1309 : `dossier.retrieval` rendait un fragment VIDE (tout
     * ecarte par `max_distance`) et la reponse ne pouvait s'appuyer que sur
     * des metadonnees. Apres : plusieurs documents, avec leur contenu.
     */
    public function test_a_panoramic_question_without_any_vector_neighbour_still_gets_multi_document_extracts(): void
    {
        // Exactement l'etat reel : des candidats existent, mais AUCUN ne
        // passe `max_distance` — la selection semantique sort vide.
        $this->search->rows = [$this->semanticRow('alpha', distance: 0.93)];

        $provenance = $this->retrievalProvenance('Que contiennent les dossiers ?');

        $this->assertGreaterThanOrEqual(3, count($provenance), 'une vue d\'ensemble doit apporter plusieurs extraits');
        $this->assertGreaterThanOrEqual(3, count($this->documentTitles($provenance)), 'les extraits doivent venir de documents DIFFERENTS');
        $this->assertSame(
            array_fill(0, count($provenance), 'overview'),
            array_column($provenance, 'selection'),
            'aucun de ces extraits n\'a ete choisi par proximite semantique : ils ne doivent pas le pretendre',
        );

        foreach ($provenance as $source) {
            $this->assertNull($source['distance'], 'un extrait de vue d\'ensemble n\'a pas de distance');
            $this->assertSame(DossierRetrievalSource::NAME, $source['source']);
            $this->assertStringStartsWith('S', (string) $source['ref']);
        }
    }

    /**
     * La contradiction n'est plus possible cote APPORT : le modele recoit,
     * dans le meme prompt, l'inventaire [Mn] ET du contenu [Sn]. La regle de
     * refus corrigee (prompt v3) porte l'autre moitie du correctif.
     */
    public function test_the_reproduced_question_now_reaches_the_model_with_both_families_of_references(): void
    {
        $this->search->rows = [$this->semanticRow('alpha', distance: 0.93)];
        $this->fakeAgent('Les documents traitent du role de l IA [S1], de l architecture [S2] et de la gouvernance [S3].');

        $answer = app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'Que contiennent les dossiers ?');

        LoopKnowledgeAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $this->assertStringContainsString('ELEMENTS DU DOSSIER', $prompt->prompt);
            $this->assertStringContainsString('SOURCES DOCUMENTAIRES', $prompt->prompt);
            $this->assertStringContainsString('[M1]', $prompt->prompt);
            $this->assertStringContainsString('[S3]', $prompt->prompt);

            return true;
        });

        $this->assertTrue($answer->grounded);
        $this->assertSame(['S1', 'S2', 'S3'], array_column($answer->sources, 'ref'));
    }

    // =====================================================================
    // B. Une question PRECISE n'est jamais diluee ni tronquee.
    // =====================================================================

    public function test_a_precise_question_with_real_hits_is_never_broadened_nor_truncated(): void
    {
        $this->search->rows = [
            $this->semanticRow('alpha', distance: 0.18, long: true),
            $this->semanticRow('alpha', distance: 0.21),
        ];

        $borne = $this->retrievalBorne('Que dit précisément Manifeste.md sur le rôle de l\'IA ?');
        $provenance = $this->retrievalOnly($borne);

        $this->assertCount(2, $provenance, 'la selection semantique doit rester intacte');
        $this->assertSame(['semantic', 'semantic'], array_column($provenance, 'selection'));
        $this->assertSame(['Manifeste.md'], array_values($this->documentTitles($provenance)));
        $this->assertStringContainsString(
            self::TAIL_MARKER,
            $borne->text,
            'hors vue d\'ensemble, un extrait pertinent garde sa longueur : aucun plafond ne s\'applique',
        );
    }

    /**
     * Le pendant du test precedent : la MEME selection concentree sur un seul
     * document s'elargit des que la question demande de la largeur — et
     * chaque extrait est alors borne, pour que plusieurs documents tiennent.
     */
    public function test_a_breadth_marker_broadens_a_selection_concentrated_on_a_single_document(): void
    {
        $this->search->rows = [
            $this->semanticRow('alpha', distance: 0.18, long: true),
            $this->semanticRow('alpha', distance: 0.21),
        ];

        $borne = $this->retrievalBorne('Résume les principaux sujets de cette Boucle.');
        $provenance = $this->retrievalOnly($borne);

        $titles = $this->documentTitles($provenance);
        $this->assertGreaterThanOrEqual(3, count($titles));
        $this->assertContains('Manifeste.md', $titles, 'le meilleur extrait semantique reste dans la selection');
        $this->assertSame('semantic', $provenance[0]['selection'], 'le document le plus pertinent reste [S1]');
        $this->assertCount(
            count($titles),
            $provenance,
            'en vue d\'ensemble, un seul extrait par document : la largeur prime sur la profondeur',
        );
        $this->assertStringNotContainsString(
            self::TAIL_MARKER,
            $borne->text,
            'en vue d\'ensemble, chaque extrait est borne pour laisser de la place aux autres documents',
        );
    }

    // =====================================================================
    // C. Inventaire pur : le manifest reste l'autorite, inchange.
    // =====================================================================

    public function test_a_pure_inventory_question_is_still_answered_from_the_manifest(): void
    {
        $this->search->rows = [];
        $this->fakeAgent('Voici les documents : Manifeste.md [M2], Architecture.md [M3].');

        $answer = app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'Liste les fichiers.');

        $this->assertTrue($answer->grounded);
        $this->assertSame(['M2', 'M3'], array_column($answer->sources, 'ref'));

        // Un [Mn] n'expose JAMAIS de contenu — invariant TASK-1307, que la
        // vue d'ensemble ne doit pas eroder.
        foreach ($answer->sources as $source) {
            $this->assertSame(DossierManifestSource::NAME, $source['source']);
            $this->assertArrayNotHasKey('extrait', $source);
        }
    }

    // =====================================================================
    // D. Provenance honnete : « Sources utilisees » = reellement citees.
    // =====================================================================

    public function test_a_refusal_never_displays_consulted_documents_as_sources_used(): void
    {
        $this->search->rows = [$this->semanticRow('alpha', distance: 0.2)];
        // Une reponse qui ne cite RIEN — le cas reel du 2026-08-26 : 10
        // elements consultes s'affichaient sous « Sources utilisées ».
        $this->fakeAgent('Je n\'ai pas trouvé cette information dans les sources auxquelles j\'ai accès.');

        $answer = app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'Que savons-nous du budget 2027 ?');

        $this->assertFalse($answer->grounded);
        $this->assertSame([], $answer->sources, 'aucune source n\'a soutenu d\'affirmation : aucune n\'est presentee comme utilisee');
        $this->assertNotEmpty($answer->consulted, 'ce qui a ete consulte reste disponible, sous son vrai nom');

        $message = \App\Models\LoopMessage::query()->where('type', 'ai')->sole();
        $this->assertSame([], $message->metadata['sources']);
    }

    /**
     * Decouvert EN RECETTE REELLE (banc ai-validation, run 2b66b90e) : un
     * modele peut produire une reponse parfaitement fondee sur un extrait
     * fourni SANS ecrire son marqueur `[Sn]`. La regle « sources utilisees =
     * sources citees » est juste, mais appliquee seule elle faisait alors
     * disparaitre TOUTE provenance — le membre n'avait plus rien a verifier.
     */
    public function test_without_any_citation_the_read_documents_appear_under_their_own_title(): void
    {
        $this->search->rows = [$this->semanticRow('alpha', distance: 0.2)];
        // Reponse fondee sur l'extrait, mais sans marqueur.
        $this->fakeAgent('Le manifeste decrit le role de l IA dans les Boucles.');

        app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'Que dit le manifeste sur le role de l IA ?');

        $message = \App\Models\LoopMessage::query()->where('type', 'ai')->sole();
        $this->assertSame([], $message->metadata['sources'], 'rien n\'est cite : rien n\'est « utilise »');
        $this->assertNotEmpty($message->metadata['consulted'], 'ce qui a ete LU reste verifiable');

        // Le manifest en est exclu : « j'ai regarde la liste des fichiers »
        // n'est pas une provenance a offrir a la verification.
        foreach ($message->metadata['consulted'] as $source) {
            $this->assertStringStartsWith('S', (string) $source['ref']);
        }

        // Et la bulle le dit sous SON titre, jamais sous « Sources utilisées ».
        $this->actingAs($this->member)
            ->get(route('organization.loops.show', ['organization' => $this->organization->slug, 'loop' => $this->loop]))
            ->assertOk()
            ->assertSee('data-sources-kind="consulted"', false)
            ->assertSee(__('loops.knowledge_consulted_title'))
            // Les deux libelles vivent aussi, en dur, dans le panneau modal
            // de `loops/show` (Alpine, `x-text`) : c'est le DISCRIMINANT de
            // la bulle qu'on assert, jamais la simple presence d'un texte.
            ->assertDontSee('data-sources-kind="used"', false);
    }

    /**
     * L'autre moitie de l'exclusion mutuelle : des qu'une citation valide
     * existe, la cle `consulted` est ABSENTE — la metadata reste exactement
     * ce qu'elle etait, et la bulle ne montre qu'un seul bloc.
     */
    public function test_with_a_valid_citation_only_the_used_sources_are_kept(): void
    {
        $this->search->rows = [$this->semanticRow('alpha', distance: 0.2)];
        $this->fakeAgent('Le manifeste decrit le role de l IA [S1].');

        app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'Que dit le manifeste sur le role de l IA ?');

        $message = \App\Models\LoopMessage::query()->where('type', 'ai')->sole();
        $this->assertSame(['S1'], array_column($message->metadata['sources'], 'ref'));
        $this->assertArrayNotHasKey('consulted', $message->metadata);

        $this->actingAs($this->member)
            ->get(route('organization.loops.show', ['organization' => $this->organization->slug, 'loop' => $this->loop]))
            ->assertOk()
            ->assertSee('data-sources-kind="used"', false)
            ->assertSee(__('loops.knowledge_sources_title'))
            ->assertDontSee('data-sources-kind="consulted"', false);
    }

    // =====================================================================
    // E. Tenant et Boucle : la vue d'ensemble ne franchit aucune frontiere.
    // =====================================================================

    public function test_the_overview_never_reaches_another_organization_nor_another_loop(): void
    {
        // Sentinelle d'une AUTRE Organization.
        $foreignDossier = Dossier::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'owner_id' => $this->stranger->id,
            'name' => 'Dossier étranger',
            'visibility' => Dossier::VISIBILITY_ORGANIZATION,
        ]);
        $this->indexDocument('secret-org', 'SECRET-T1309-OTHER-ORG.md', 'SECRET-T1309-OTHER-ORG', $foreignDossier, $this->otherOrganization, $this->stranger);

        // Sentinelle d'une AUTRE Boucle de la MEME Organization. La creation
        // d'une Boucle indexe son document racine (job synchrone en test) :
        // on referme la porte de l'indexation le temps de la fixture, sinon
        // c'est un vrai embedding provider qui partirait — sans rapport avec
        // ce que ce test prouve.
        $otherLoop = $this->withoutIndexing(fn (): Loop => (new LoopService)->createLoop($this->member, 'Autre Boucle'));
        $otherLoopDossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'Dossier de l\'autre Boucle',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $otherLoop->id,
        ]);
        $this->indexDocument('secret-loop', 'SECRET-T1309-OTHER-LOOP.md', 'SECRET-T1309-OTHER-LOOP', $otherLoopDossier);

        $this->search->rows = [];
        $borne = $this->retrievalBorne('Que contiennent les dossiers ?');

        $this->assertStringNotContainsString('SECRET-T1309-OTHER-ORG', $borne->text);
        $this->assertStringNotContainsString('SECRET-T1309-OTHER-LOOP', $borne->text);

        foreach ($this->documentTitles($this->retrievalOnly($borne)) as $title) {
            $this->assertStringNotContainsString('SECRET-T1309', $title);
        }
    }

    // =====================================================================
    // F. Cout : la vue d'ensemble n'appelle aucun provider de plus.
    // =====================================================================

    public function test_the_overview_adds_no_provider_call_at_all(): void
    {
        $this->search->rows = [];
        $this->fakeAgent('Vue d\'ensemble [S1][S2].');

        app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'Que contiennent les dossiers ?');

        $this->assertSame(1, $this->search->calls, 'un seul embedding de requete, comme avant');
        // Une seule generation : le complement est une lecture SQL, pas un
        // second tour de modele ni un appel de routage.
        $this->assertDatabaseCount('ai_provider_invocations', 1);
    }

    // =====================================================================
    // G. L'extrait representatif : l'ouverture du document, deterministe.
    // =====================================================================

    public function test_representative_chunks_are_the_opening_of_each_document_and_stay_deterministic(): void
    {
        $service = new DossierSemanticSearchService(
            app(\App\Services\Dossiers\DossierSemanticSearchGate::class),
            app(\App\Services\Dossiers\DossierChunkEmbeddingService::class),
            app(\App\Support\Ai\AiEconomicGuard::class),
        );

        $first = $service->representativeChunksAcrossDossiers($this->organization->id, [$this->dossier->id], 6);
        $second = $service->representativeChunksAcrossDossiers($this->organization->id, [$this->dossier->id], 6);

        $this->assertCount(4, $first, 'un extrait par document indexe, jamais deux du meme');
        $this->assertSame(array_column($first, 'chunk_id'), array_column($second, 'chunk_id'), 'ordre deterministe');
        $this->assertSame([0, 0, 0, 0], array_column($first, 'chunk_index'), 'toujours l\'ouverture du document');
        $this->assertSame([null, null, null, null], array_column($first, 'distance'));
    }

    public function test_representative_chunks_of_another_organization_are_never_returned(): void
    {
        $foreignDossier = Dossier::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'owner_id' => $this->stranger->id,
            'name' => 'Dossier étranger',
            'visibility' => Dossier::VISIBILITY_ORGANIZATION,
        ]);
        $this->indexDocument('secret-org', 'SECRET-T1309-OTHER-ORG.md', 'SECRET-T1309-OTHER-ORG', $foreignDossier, $this->otherOrganization, $this->stranger);

        $service = new DossierSemanticSearchService(
            app(\App\Services\Dossiers\DossierSemanticSearchGate::class),
            app(\App\Services\Dossiers\DossierChunkEmbeddingService::class),
            app(\App\Support\Ai\AiEconomicGuard::class),
        );

        // Meme en PASSANT l'identifiant du Dossier etranger, le tenant de la
        // requete borne le resultat : la portee est intrinseque au SQL, elle
        // n'est jamais un filtre applique apres coup.
        $rows = $service->representativeChunksAcrossDossiers(
            $this->organization->id,
            [$this->dossier->id, $foreignDossier->id],
            12,
        );

        foreach ($rows as $row) {
            $this->assertStringNotContainsString('SECRET-T1309-OTHER-ORG', (string) $row['content']);
            $this->assertStringNotContainsString('SECRET-T1309-OTHER-ORG', (string) $row['filename']);
        }
    }

    // =====================================================================
    // H. L'indice de forme : local, deterministe, sans LLM.
    // =====================================================================

    public function test_breadth_markers_recognise_panoramic_questions_in_both_languages(): void
    {
        foreach ([
            'Que contiennent les dossiers ?',
            'De quoi parlent les documents ?',
            'Fais-moi une vue d\'ensemble.',
            'Quels sont les principaux sujets de cette Boucle ?',
            'Résume les connaissances disponibles ici.',
            'Give me an overview of these folders.',
            'What are the main topics here?',
        ] as $question) {
            $this->assertTrue(DocumentaryQuestionShape::wantsCorpusOverview($question), $question);
        }
    }

    public function test_a_precise_question_is_never_taken_for_a_panoramic_one(): void
    {
        foreach ([
            'Que dit précisément 02-ManifesteV2.md sur les Boucles ?',
            'Quel est le tarif d\'adhésion ?',
            'Qui est responsable du budget ?',
            'What does the architecture document say about tenants?',
            '',
            null,
        ] as $question) {
            $this->assertFalse(DocumentaryQuestionShape::wantsCorpusOverview($question), var_export($question, true));
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function contexte(string $question): ContexteIa
    {
        return new ContexteIa(
            organizationId: (string) $this->organization->id,
            userId: (string) $this->member->id,
            loopId: (string) $this->loop->id,
            locale: 'fr',
            capability: CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            correlationId: AiCorrelation::id(),
            source: CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL,
            query: $question,
        );
    }

    private function retrievalBorne(string $question): \App\Ai\Context\ContexteBorne
    {
        return app(ContextBuilder::class)->build(
            $this->contexte($question),
            app(CapabilityRegistry::class)->get(CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER),
        );
    }

    /** @return list<array<string, mixed>> */
    private function retrievalProvenance(string $question): array
    {
        return $this->retrievalOnly($this->retrievalBorne($question));
    }

    /** @return list<array<string, mixed>> */
    private function retrievalOnly(\App\Ai\Context\ContexteBorne $borne): array
    {
        return $borne->provenanceFor(DossierRetrievalSource::NAME);
    }

    /**
     * @param  list<array<string, mixed>>  $provenance
     * @return list<string>
     */
    private function documentTitles(array $provenance): array
    {
        return array_values(array_unique(array_map(
            static fn (array $source): string => (string) $source['title'],
            $provenance,
        )));
    }

    /**
     * Un document REELLEMENT indexe : un fichier et ses deux chunks. Le
     * complement de vue d'ensemble lit la base, pas un double — c'est ce SQL
     * la (sous-requete correlee, PostgreSQL comme SQLite) que ces tests
     * exercent.
     */
    private function indexDocument(
        string $key,
        string $filename,
        string $topic,
        ?Dossier $dossier = null,
        ?Organization $organization = null,
        ?User $uploader = null,
    ): void {
        $dossier ??= $this->dossier;
        $organization ??= $this->organization;
        $uploader ??= $this->member;

        $file = DossierFile::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $uploader->id,
            'disk' => 'dossier_files',
            'path' => 'dossier-files/'.$dossier->id.'/'.Str::uuid().'-'.$filename,
            'original_name' => $filename,
            'display_name' => $filename,
            'mime_type' => 'text/markdown',
            'size_bytes' => 4096,
            'checksum_sha256' => hash('sha256', $filename),
            'source' => 'upload',
        ]);

        $this->files[$key] = $file;

        foreach ([0, 1] as $index) {
            DossierChunk::create([
                'organization_id' => $organization->id,
                'dossier_id' => $dossier->id,
                'blog_post_id' => null,
                'dossier_file_id' => $file->id,
                'chunk_index' => $index,
                'content' => $index === 0
                    ? "OUVERTURE {$filename} — {$topic}."
                    : "SUITE {$filename} — détails complémentaires sur {$topic}.",
                'content_hash' => hash('sha256', $filename.$index),
                'token_count' => 40,
                'embedding' => array_fill(0, 4, 0.1),
                'embedding_provider' => 'openrouter',
                'embedding_model' => 'text-embedding-3-small',
                'indexed_at' => now(),
            ]);
        }
    }

    /**
     * Une ligne rendue par le moteur pgvector (double). `long` produit un
     * contenu volontairement plus long que le plafond de vue d'ensemble, avec
     * un marqueur en fin : sa presence prouve qu'aucun plafond ne s'est
     * applique.
     *
     * @return array<string, mixed>
     */
    private function semanticRow(string $key, float $distance, bool $long = false): array
    {
        $file = $this->files[$key];
        $name = (string) $file->display_name;

        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $this->dossier->id,
            'dossier_name' => $this->dossier->name,
            'source_type' => 'file',
            'blog_post_id' => null,
            'title' => null,
            'slug' => null,
            'dossier_file_id' => $file->id,
            'filename' => $name,
            'mime_type' => 'text/markdown',
            'chunk_index' => 0,
            'content' => $long
                ? 'DEBUT '.str_repeat('contenu documentaire pertinent. ', 30).self::TAIL_MARKER
                : "Extrait semantique de {$name}.",
            'distance' => $distance,
        ];
    }

    private function fakeAgent(string $text): void
    {
        LoopKnowledgeAgent::fake([
            new TextResponse($text, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }

    private function draftRootDocument(Loop $loop): void
    {
        BlogPost::whereKey(Dossier::where('loop_id', $loop->id)->value('root_blog_post_id'))
            ->update(['status' => 'draft']);
    }

    /**
     * Execute `$callback` avec la porte de recherche semantique fermee — la
     * MEME porte que consulte `DossierArticleIndexer` avant d'embarquer quoi
     * que ce soit.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withoutIndexing(callable $callback)
    {
        $enabled = config('ai.dossiers.semantic_search.organization_ids');
        config(['ai.dossiers.semantic_search.organization_ids' => []]);

        try {
            return $callback();
        } finally {
            config(['ai.dossiers.semantic_search.organization_ids' => $enabled]);
        }
    }
}

/**
 * Double du moteur pgvector (contrat TASK-1213/1307). `representativeChunks…`
 * n'est VOLONTAIREMENT pas double : la lecture SQL du complement de vue
 * d'ensemble doit s'executer reellement, sur les deux moteurs de la CI.
 */
class FakeOverviewSearch extends DossierSemanticSearchService
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public int $calls = 0;

    public function __construct() {}

    public function searchAcrossDossiers(string $organizationId, array $dossierIds, string $query, string $embeddingInstance, int $limit = 5, array $traceMetadata = [], ?int $candidateLimit = null): array
    {
        $this->calls++;

        return array_slice($this->rows, 0, $candidateLimit ?? $limit);
    }
}
