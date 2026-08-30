<?php

namespace Tests\Feature\Dossiers;

use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContexteBorne;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\DossierManifestSource;
use App\Ai\Context\SourceDenied;
use App\Ai\ContexteIa;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1307 — source `dossier.manifest` : inventaire deterministe
 * (metadonnees, sans recherche ni contenu) des elements accessibles du
 * Dossier d'une Boucle. Repond aux questions structurelles (« quels
 * fichiers ? ») qu'une recherche semantique sur des chunks ne peut pas
 * atteindre.
 *
 * Meme perimetre que `DossierRetrievalSource` (`DossierAccessScope`
 * partagee) : Organization = Tenant, permission-safe (`DossierPolicy::view`),
 * loop-scoped. Zero embedding, zero appel provider.
 */
class DossierManifestSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_articles_and_files_of_the_loop_dossier_as_metadata_only(): void
    {
        [$organization, $owner, $loop, $dossier] = $this->fixture();
        $this->draftRootDocument($loop);

        $this->attachArticle($organization, $owner, $dossier, 'Cadre du dialogue', '<p>Contenu prive qui ne doit pas fuiter.</p>');
        $this->attachFile($organization, $owner, $dossier, 'Manifeste.pdf', 'application/pdf');
        $this->attachFile($organization, $owner, $dossier, 'Notes.md', 'text/markdown');

        $borne = $this->build($organization, $owner, $loop);
        $text = $this->manifestText($borne);

        $this->assertStringContainsString('[M1] Article : Cadre du dialogue', $text);
        $this->assertStringContainsString('Fichier PDF : Manifeste.pdf', $text);
        $this->assertStringContainsString('Fichier MD : Notes.md', $text);
        // Metadonnees seulement : jamais le contenu de l'article.
        $this->assertStringNotContainsString('Contenu prive', $text);
    }

    /**
     * TASK-1307 (revue) : la provenance du manifest est desormais CITABLE
     * ([M1]..[Mn], distincte de [S1]..[Sn]) et propagee jusqu'a
     * `LoopKnowledgeAnswerService`. Chaque entree porte le strict minimum
     * de metadonnees autorisees — jamais de contenu de document.
     */
    public function test_manifest_entries_are_citable_provenance_without_document_content(): void
    {
        [$organization, $owner, $loop, $dossier] = $this->fixture();
        $this->draftRootDocument($loop);

        $post = $this->attachArticle($organization, $owner, $dossier, 'Cadre du dialogue', '<p>Contenu prive.</p>');
        $file = $this->attachFile($organization, $owner, $dossier, 'Manifeste.pdf', 'application/pdf');

        $borne = $this->build($organization, $owner, $loop);
        $provenance = $borne->provenanceFor(DossierManifestSource::NAME);

        $this->assertCount(2, $provenance);

        $article = $provenance[0];
        $this->assertSame('M1', $article['ref']);
        $this->assertSame(DossierManifestSource::NAME, $article['source']);
        $this->assertSame('manifest', $article['type']);
        $this->assertSame('article', $article['source_type']);
        $this->assertSame((string) $post->id, $article['blog_post_id']);
        $this->assertNull($article['dossier_file_id']);
        $this->assertSame((string) $dossier->id, $article['dossier_id']);
        $this->assertSame($dossier->name, $article['dossier_name']);
        $this->assertSame('Cadre du dialogue', $article['title']);
        // Aucune cle `extrait` : le manifest n'a jamais lu le contenu.
        $this->assertArrayNotHasKey('extrait', $article);
        $this->assertArrayNotHasKey('chunk_id', $article);

        $fileEntry = $provenance[1];
        $this->assertSame('M2', $fileEntry['ref']);
        $this->assertSame('file', $fileEntry['source_type']);
        $this->assertSame((string) $file->id, $fileEntry['dossier_file_id']);
        $this->assertNull($fileEntry['blog_post_id']);
        $this->assertSame('Manifeste.pdf', $fileEntry['title']);
        $this->assertArrayNotHasKey('extrait', $fileEntry);
    }

    public function test_a_dossier_of_another_organization_never_appears(): void
    {
        [$organization, $owner, $loop, $dossier] = $this->fixture();
        $otherOrganization = Organization::factory()->create();
        $otherOwner = User::factory()->create(['organization_id' => $otherOrganization->id]);
        $otherLoop = (new LoopService)->createLoop($otherOwner, 'Boucle etrangere');
        $foreignDossier = Dossier::query()->where('loop_id', $otherLoop->id)->firstOrFail();

        $this->attachFile($organization, $owner, $dossier, 'A-nous.md', 'text/markdown');
        $this->attachFile($otherOrganization, $otherOwner, $foreignDossier, 'SECRET-AUTRE-ORG.md', 'text/markdown');

        $borne = $this->build($organization, $owner, $loop);
        $text = $this->manifestText($borne);

        $this->assertStringContainsString('A-nous.md', $text);
        $this->assertStringNotContainsString('SECRET-AUTRE-ORG', $text);
    }

    public function test_a_loop_of_another_organization_is_denied(): void
    {
        [$organization, $owner] = $this->fixture();
        $otherOrganization = Organization::factory()->create();
        $otherOwner = User::factory()->create(['organization_id' => $otherOrganization->id]);
        $otherLoop = (new LoopService)->createLoop($otherOwner, 'Boucle hors tenant');

        $source = app(DossierManifestSource::class);

        $this->expectException(SourceDenied::class);

        $source->collect(new ContexteIa(
            organizationId: (string) $organization->id,
            userId: (string) $owner->id,
            loopId: (string) $otherLoop->id,
            locale: 'fr',
            capability: CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            correlationId: (string) Str::uuid(),
        ), 6000);
    }

    public function test_a_private_dossier_not_shared_with_the_loop_is_excluded(): void
    {
        [$organization, $owner, $loop, $dossier] = $this->fixture();
        $other = User::factory()->create(['organization_id' => $organization->id]);

        // Dossier prive d'un AUTRE membre, jamais partage avec cette Boucle.
        $privateDossier = Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $other->id,
            'name' => 'Prive',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $this->attachFile($organization, $other, $privateDossier, 'CONFIDENTIEL.md', 'text/markdown');
        $this->attachFile($organization, $owner, $dossier, 'Public-a-la-boucle.md', 'text/markdown');

        $borne = $this->build($organization, $owner, $loop);
        $text = $this->manifestText($borne);

        $this->assertStringContainsString('Public-a-la-boucle.md', $text);
        $this->assertStringNotContainsString('CONFIDENTIEL', $text);
    }

    public function test_without_a_loop_in_context_the_source_produces_nothing(): void
    {
        [$organization, $owner, , $dossier] = $this->fixture();
        $this->attachFile($organization, $owner, $dossier, 'Peu-importe.md', 'text/markdown');

        $source = app(DossierManifestSource::class);
        $fragment = $source->collect(new ContexteIa(
            organizationId: (string) $organization->id,
            userId: (string) $owner->id,
            loopId: null,
            locale: 'fr',
            capability: CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            correlationId: (string) Str::uuid(),
        ), 6000);

        $this->assertTrue($fragment->isEmpty());
    }

    public function test_a_draft_article_is_not_listed(): void
    {
        [$organization, $owner, $loop, $dossier] = $this->fixture();

        $draft = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'title' => 'Brouillon jamais publie',
            'slug' => 'brouillon-'.Str::uuid(),
            'content' => '<p>x</p>',
            'status' => 'draft',
        ]);
        DossierBlogPost::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $draft->id,
            'added_by' => $owner->id,
            'position' => 1,
        ]);
        $this->attachFile($organization, $owner, $dossier, 'Temoin.md', 'text/markdown');

        $borne = $this->build($organization, $owner, $loop);
        $text = $this->manifestText($borne);

        // Temoin : l'instrument fonctionne (sinon ce test passerait meme sur
        // un manifest vide) — le fichier publie apparait, le brouillon non.
        $this->assertStringContainsString('Temoin.md', $text);
        $this->assertStringNotContainsString('Brouillon jamais publie', $text);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * @return array{0: Organization, 1: User, 2: Loop, 3: Dossier}
     */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        app()->instance('current_organization', $organization);

        $loop = (new LoopService)->createLoop($owner, 'Boucle manifest '.uniqid());
        $dossier = Dossier::query()->where('loop_id', $loop->id)->firstOrFail();

        return [$organization, $owner, $loop, $dossier];
    }

    private function attachArticle(Organization $organization, User $owner, Dossier $dossier, string $title, string $content): BlogPost
    {
        $post = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::uuid(),
            'content' => $content,
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
        DossierBlogPost::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $owner->id,
            'position' => 1,
        ]);

        return $post;
    }

    private function attachFile(Organization $organization, User $owner, Dossier $dossier, string $name, string $mimeType): DossierFile
    {
        return DossierFile::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $owner->id,
            'disk' => 'dossier_files',
            'path' => 'dossier-files/'.$dossier->id.'/'.Str::uuid().'-'.$name,
            'original_name' => $name,
            'display_name' => $name,
            'mime_type' => $mimeType,
            'size_bytes' => 10,
            'checksum_sha256' => hash('sha256', $name),
            'source' => 'upload',
        ]);
    }

    private function build(Organization $organization, User $owner, Loop $loop): ContexteBorne
    {
        return app(ContextBuilder::class)->build(new ContexteIa(
            organizationId: (string) $organization->id,
            userId: (string) $owner->id,
            loopId: (string) $loop->id,
            locale: 'fr',
            capability: CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            correlationId: (string) Str::uuid(),
            query: 'quels fichiers dans cette boucle ?',
        ), app(CapabilityRegistry::class)->get(CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER));
    }

    private function manifestText(ContexteBorne $borne): string
    {
        // TASK-1307 (revue) : le manifest porte desormais sa PROPRE
        // provenance citable ([M1]..[Mn], voir `test_manifest_entries_are_
        // citable_provenance_without_document_content`) — ce helper reste
        // pratique pour verifier le texte compose sans en decoder la
        // provenance structuree.
        return $borne->text;
    }

    /**
     * TASK-1307 (revue) : depublie le document racine auto-cree
     * (LoopRootDocumentService) pour isoler un fixture "un seul element
     * connu" sans dependre de l'ordre d'insertion entre Dossiers.
     */
    private function draftRootDocument(Loop $loop): void
    {
        BlogPost::whereKey(Dossier::where('loop_id', $loop->id)->value('root_blog_post_id'))
            ->update(['status' => 'draft']);
    }
}
