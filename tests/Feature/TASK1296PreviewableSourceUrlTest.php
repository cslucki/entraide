<?php

namespace Tests\Feature;

use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\DossierRetrievalSource;
use App\Ai\ContexteIa;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1296 — URL honnete pour les sources documentaires citees.
 *
 * Une source RAG dont le fichier est previewable (image, PDF, text/plain,
 * text/markdown — la MEME allowlist que `ouvrirFichier()` cote drive) doit
 * porter une URL d'apercu (`files.preview`, Content-Disposition inline), pas
 * la route de telechargement (`files.show`). Les fichiers non previewables
 * et les Articles gardent leur URL actuelle.
 *
 * Le moteur pgvector est double (cf. TASK1213KnowledgeAnswerTest) : seul le
 * choix de la route par la provenance est en jeu ici.
 */
class TASK1296PreviewableSourceUrlTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private Loop $loop;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->loop = (new LoopService)->createLoop($this->member, 'Boucle apercu');

        app()->instance('current_organization', $this->organization);

        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'Dossier partagé',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loop->id,
        ]);

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

        Http::preventStrayRequests();
    }

    public function test_provenance_url_of_a_previewable_file_targets_the_preview_route(): void
    {
        // Le cas exact du symptome : sur le dataset reel, 57 `.md` sont
        // stockes en text/plain et 2 en text/markdown — previewables tous
        // les deux dans la modale existante du drive.
        $plain = $this->fileRow('notes.md', 'text/plain');
        $markdown = $this->fileRow('protocole.md', 'text/markdown');
        $this->mockSearch([$plain, $markdown]);

        $provenance = $this->provenance();

        $this->assertCount(2, $provenance);
        $this->assertSame($this->previewUrl($plain['dossier_file_id']), $provenance[0]['url']);
        $this->assertSame($this->previewUrl($markdown['dossier_file_id']), $provenance[1]['url']);
    }

    public function test_provenance_url_of_a_non_previewable_file_keeps_the_download_route(): void
    {
        $docx = $this->fileRow(
            'rapport.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );
        $this->mockSearch([$docx]);

        $provenance = $this->provenance();

        $this->assertCount(1, $provenance);
        $this->assertSame($this->downloadUrl($docx['dossier_file_id']), $provenance[0]['url']);
    }

    public function test_article_provenance_url_is_unchanged(): void
    {
        $this->mockSearch([$this->articleRow('article-cite')]);

        $provenance = $this->provenance();

        $this->assertCount(1, $provenance);
        $this->assertSame(
            route('organization.blog.show', [
                'organization' => $this->organization->slug,
                'post' => 'article-cite',
            ]),
            $provenance[0]['url'],
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function mockSearch(array $rows): void
    {
        $this->mock(DossierSemanticSearchService::class)
            ->shouldReceive('searchAcrossDossiers')
            ->once()
            ->andReturn($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function provenance(): array
    {
        $contexte = new ContexteIa(
            organizationId: $this->organization->id,
            userId: $this->member->id,
            loopId: $this->loop->id,
            locale: 'fr',
            capability: CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            correlationId: (string) Str::uuid(),
            source: CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL,
            query: 'Que dit le protocole ?',
        );

        $borne = app(ContextBuilder::class)->build(
            $contexte,
            app(CapabilityRegistry::class)->get(CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER),
        );

        return $borne->provenanceFor(DossierRetrievalSource::NAME);
    }

    /**
     * Ligne `file` telle que rendue par searchAcrossDossiers() (chunk_id,
     * dossier_id, dossier_name + mapSourceRow()).
     *
     * @return array<string, mixed>
     */
    private function fileRow(string $filename, string $mimeType): array
    {
        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $this->dossier->id,
            'dossier_name' => $this->dossier->name,
            'source_type' => 'file',
            'blog_post_id' => null,
            'title' => null,
            'slug' => null,
            'dossier_file_id' => (string) Str::uuid(),
            'filename' => $filename,
            'mime_type' => $mimeType,
            'chunk_index' => 0,
            'content' => 'Contenu previewable du fichier '.$filename.' pour la citation.',
            'distance' => 0.2,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function articleRow(string $slug): array
    {
        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $this->dossier->id,
            'dossier_name' => $this->dossier->name,
            'source_type' => 'article',
            'blog_post_id' => (string) Str::uuid(),
            'title' => 'Article cité',
            'slug' => $slug,
            'dossier_file_id' => null,
            'filename' => null,
            'mime_type' => null,
            'chunk_index' => 0,
            'content' => 'Contenu de l\'Article cité pour la citation.',
            'distance' => 0.2,
        ];
    }

    private function previewUrl(string $fileId): string
    {
        return route('organization.dossiers.files.preview', [
            'organization' => $this->organization->slug,
            'dossier' => $this->dossier->id,
            'file' => $fileId,
        ]);
    }

    private function downloadUrl(string $fileId): string
    {
        return route('organization.dossiers.files.show', [
            'organization' => $this->organization->slug,
            'dossier' => $this->dossier->id,
            'file' => $fileId,
        ]);
    }
}
