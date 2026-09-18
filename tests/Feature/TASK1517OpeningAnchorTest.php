<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\DossierSemanticSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * TASK-1517 — ancrer l'ouverture du document, replier les quasi-doublons.
 *
 * ## Le cas rouge, mesure sur le corpus ARIA reel avant d'ecrire une ligne
 *
 * « Que signifie ARIA ? » repondait « ARIA signifie "Artist-Professional
 * Interplay and Responsible Research and Innovation" ». Cette chaine
 * n'apparait dans AUCUN des 265 chunks du Dossier ; « ARtistic Intelligence
 * Alliance » en occupe douze, dont les quatre extraits d'OUVERTURE. Et la
 * reponse se declarait grounded, parce que le modele citait `[Sn]` ailleurs.
 *
 * ## Ce que la mesure a REFUTE
 *
 * Le CDC recommandait `content_hash`. Compte reel : 265 chunks, 265 hashes
 * DISTINCTS, zero doublon exact — les versions different d'un octet, les
 * frontieres de chunk se decalent. Le hash exact ne replie rien.
 *
 * Une cle NORMALISEE replie 5 chunks sur 265. Modeste — sauf pour la famille
 * qui compte : les quatre ouvertures identiques. La deduplication existe donc
 * PARCE QUE l'ancrage la rend necessaire, pas pour elle-meme.
 */
class TASK1517OpeningAnchorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);

        $this->dossier = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->owner->id,
            'name' => 'Dossier ARIA',
            'visibility' => 'private',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1517',
        ]);

        config([
            // Posee, jamais empruntee au `.env` de la machine : sans elle, le
            // resolveur cherche un credential dans la famille par defaut et
            // refuse. Le test passerait en local et rougirait en CI.
            'ai.default_for_embeddings' => 'openrouter',
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
    }

    private function mockSearch(): MockInterface
    {
        return $this->mock(DossierSemanticSearchService::class);
    }

    private function fakeAgent(string $text): void
    {
        LoopKnowledgeAgent::fake([
            new TextResponse($text, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }

    /**
     * Une ligne de retrieval. `$fileId` porte l'identite du DOCUMENT : deux
     * versions d'un meme rapport sont deux documents distincts, et c'est
     * precisement ce qui trompait `diversify()`.
     */
    private function row(string $fileId, string $content, int $chunkIndex = 4, ?float $distance = 0.2): array
    {
        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $this->dossier->id,
            'dossier_name' => $this->dossier->name,
            'source_type' => 'file',
            'blog_post_id' => null,
            'title' => null,
            'slug' => null,
            'dossier_file_id' => $fileId,
            'filename' => 'version-'.substr($fileId, 0, 4).'.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'chunk_index' => $chunkIndex,
            'content' => $content,
            'distance' => $distance,
        ];
    }

    private function file(string $name): DossierFile
    {
        return DossierFile::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'uploaded_by' => $this->owner->id,
            'disk' => 'dossier_files',
            'path' => 'dossier-files/'.Str::uuid().'.docx',
            'original_name' => $name,
            'display_name' => $name,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size_bytes' => 2048,
            'checksum_sha256' => hash('sha256', $name.Str::uuid()),
            'source' => 'upload',
        ]);
    }

    private function ask(string $question = 'Que signifie ARIA ?'): array
    {
        return $this->actingAs($this->owner)
            ->postJson(route('organization.dossiers.answer', [
                'organization' => $this->organization,
                'dossier' => $this->dossier,
            ]), ['question' => $question])
            ->assertOk()
            ->json('data');
    }

    // ── L'ancrage ───────────────────────────────────────────────────────────

    /**
     * LE test de cette TASK : la definition vit au chunk 0, le retrieval ne la
     * remonte pas, elle doit arriver quand meme.
     */
    public function test_the_opening_of_the_best_ranked_document_reaches_the_model(): void
    {
        $best = (string) Str::uuid();
        $ouverture = 'ARIA ARtistic Intelligence Alliance: multiplying creative interactions.';

        $search = $this->mockSearch();
        $search->shouldReceive('searchAcrossDossiers')->once()->andReturn([
            $this->row($best, 'Le projet prevoit des reunions de trio entre un artiste et un professionnel.', 12, 0.18),
            $this->row($best, 'Les formats sont flexibles mais structures.', 20, 0.26),
        ]);
        $search->shouldReceive('representativeChunksAcrossDossiers')->once()->andReturn([
            $this->row($best, $ouverture, 0, null),
        ]);

        $this->fakeAgent('ARIA signifie ARtistic Intelligence Alliance. [S3]');

        $data = $this->ask();

        $titres = array_column($data['consulted'], 'ref');
        $this->assertSame(['S1', 'S2', 'S3'], $titres, 'l ouverture est AJOUTEE, jamais substituee');

        $extraits = array_column($data['consulted'], 'excerpt');
        $this->assertStringContainsString('ARtistic Intelligence Alliance', $extraits[2]);

        // L'ancrage arrive EN FIN : [S1] doit rester l'extrait le plus proche
        // de la question, sinon le rang cesserait de dire la pertinence.
        $this->assertStringNotContainsString('ARtistic Intelligence Alliance', $extraits[0]);
    }

    public function test_the_opening_is_not_added_twice_when_the_retrieval_already_found_it(): void
    {
        $best = (string) Str::uuid();
        $ouverture = 'ARIA ARtistic Intelligence Alliance: multiplying creative interactions.';

        $search = $this->mockSearch();
        $search->shouldReceive('searchAcrossDossiers')->once()->andReturn([
            $this->row($best, $ouverture, 0, 0.11),
        ]);
        $search->shouldReceive('representativeChunksAcrossDossiers')->once()->andReturn([
            $this->row($best, $ouverture, 0, null),
        ]);

        $this->fakeAgent('ARIA signifie ARtistic Intelligence Alliance. [S1]');

        $this->assertCount(1, $this->ask()['consulted'], 'un extrait deja present ne doit pas etre redit');
    }

    /**
     * Une version differente porte la MEME ouverture. Sans le repli, l'ancrage
     * ajouterait une phrase que le modele a deja sous les yeux.
     */
    public function test_an_opening_already_brought_by_another_version_is_not_repeated(): void
    {
        $v1 = (string) Str::uuid();
        $v2 = (string) Str::uuid();
        $ouverture = 'ARIA ARtistic Intelligence Alliance: multiplying creative interactions.';

        $search = $this->mockSearch();
        $search->shouldReceive('searchAcrossDossiers')->once()->andReturn([
            // La version 2 a ete retrouvee, et son ouverture est la meme a la
            // ponctuation pres.
            $this->row($v2, 'ARIA — ARtistic Intelligence Alliance : multiplying creative interactions !', 0, 0.14),
        ]);
        $search->shouldReceive('representativeChunksAcrossDossiers')->once()->andReturn([
            $this->row($v2, $ouverture, 0, null),
        ]);

        $this->fakeAgent('ARIA signifie ARtistic Intelligence Alliance. [S1]');

        $this->assertCount(1, $this->ask()['consulted']);
    }

    /**
     * Une question restreinte a un fichier ne recoit que le contenu de CE
     * fichier.
     *
     * Ce test ne garde PAS un `if` dedie, et c'est deliberé : un sabotage a
     * montre qu'un tel `if` restait vert quoi qu'il arrive. La raison est
     * structurelle — la recherche est deja bornee au fichier EN SQL, donc le
     * document le mieux classe EST ce fichier, donc l'ouverture ancree en
     * vient. Le `if` a ete retire ; ce test garde la propriete, pas la ligne.
     */
    public function test_a_file_scoped_question_never_gets_the_opening_of_another_document(): void
    {
        $cible = $this->file('rapport-cible.docx');
        $autre = (string) Str::uuid();

        $search = $this->mockSearch();
        $search->shouldReceive('searchAcrossDossiers')->once()->andReturn([
            $this->row((string) $cible->id, 'Le rapport cible parle de gouvernance.', 3, 0.2),
        ]);
        $search->shouldReceive('representativeChunksAcrossDossiers')->once()->andReturn([
            $this->row($autre, 'Ouverture d un AUTRE document, hors perimetre demande.', 0, null),
        ]);

        $this->fakeAgent('Le rapport parle de gouvernance. [S1]');

        $data = $this->ask('Cherche uniquement dans rapport-cible.docx : de quoi parle-t-il ?');

        $this->assertCount(1, $data['consulted']);
        $this->assertStringNotContainsString('AUTRE document', $data['consulted'][0]['excerpt']);
    }

    // ── Le repli des quasi-doublons ─────────────────────────────────────────

    /**
     * Le coeur du §8 du CDC, mais sur le bon signal : quatre VERSIONS quasi
     * identiques ne doivent pas occuper quatre places.
     */
    public function test_four_near_identical_versions_do_not_occupy_four_places(): void
    {
        $phrase = 'Le consortium reunit cinq partenaires europeens autour de la mediation artistique.';

        $search = $this->mockSearch();
        $search->shouldReceive('searchAcrossDossiers')->once()->andReturn([
            $this->row((string) Str::uuid(), $phrase, 7, 0.10),
            $this->row((string) Str::uuid(), $phrase.' ', 7, 0.11),
            $this->row((string) Str::uuid(), strtoupper($phrase), 7, 0.12),
            $this->row((string) Str::uuid(), 'Le consortium reunit cinq partenaires europeens, autour de la mediation artistique !', 7, 0.13),
            // La source complementaire, qui n'entrait pas avant.
            $this->row((string) Str::uuid(), 'Le budget total est de deux millions d euros.', 40, 0.31),
        ]);
        $search->shouldReceive('representativeChunksAcrossDossiers')->andReturn([]);

        $this->fakeAgent('Cinq partenaires. [S1] Deux millions d euros. [S2]');

        $extraits = array_column($this->ask()['consulted'], 'excerpt');

        $this->assertCount(2, $extraits, 'quatre quasi-doublons doivent se replier en un seul');
        $this->assertStringContainsString('cinq partenaires', $extraits[0]);
        $this->assertStringContainsString('budget total', $extraits[1], 'la source complementaire prend la place liberee');
    }

    /**
     * Le repli ne doit PAS confondre deux extraits qui parlent de la meme
     * chose autrement : c'est la ligne entre dedupliquer et appauvrir.
     */
    public function test_two_genuinely_different_excerpts_are_both_kept(): void
    {
        $search = $this->mockSearch();
        $search->shouldReceive('searchAcrossDossiers')->once()->andReturn([
            $this->row((string) Str::uuid(), 'Le consortium reunit cinq partenaires europeens.', 7, 0.10),
            $this->row((string) Str::uuid(), 'Chaque partenaire designe un referent scientifique pour la duree du projet.', 8, 0.15),
        ]);
        $search->shouldReceive('representativeChunksAcrossDossiers')->andReturn([]);

        $this->fakeAgent('Cinq partenaires. [S1] Chacun a un referent. [S2]');

        $this->assertCount(2, $this->ask()['consulted']);
    }

    /**
     * Le bassin est plus large que la selection : sans cela, replier des
     * doublons retrecirait le corpus au lieu de le diversifier.
     */
    public function test_the_candidate_pool_is_wider_than_the_citation_budget(): void
    {
        $search = $this->mockSearch();
        $search->shouldReceive('searchAcrossDossiers')
            ->once()
            ->withArgs(function (...$args): bool {
                [$limit, $candidateLimit] = [$args[4] ?? null, $args[6] ?? null];

                return $limit === 5 && $candidateLimit === 12;
            })
            ->andReturn([$this->row((string) Str::uuid(), 'Un extrait.', 1, 0.2)]);
        $search->shouldReceive('representativeChunksAcrossDossiers')->andReturn([]);

        $this->fakeAgent('Une reponse. [S1]');

        $this->ask();
    }
}
