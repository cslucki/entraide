<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierChunk;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\OrganizationRagOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Console RAG Organization V1 (TASK-1217), read-only.
 *
 * Ce que ces tests protegent en priorite : l'isolation tenant (aucun compteur,
 * aucune source, aucun diagnostic d'une autre Organization) et la separation
 * « portee != sujet » (un admin voit l'ETAT d'un Dossier prive sans heriter du
 * droit d'en ouvrir le contenu). Ils verifient aussi qu'aucun statut n'est
 * invente : une source sans chunk est « non indexee », jamais « en erreur ».
 */
class TASK1217RagConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function dimensions(): int
    {
        return config('database.default') === 'pgsql' ? 1536 : 8;
    }

    // ---- Tenant ----

    public function test_counters_and_sources_are_strictly_scoped_to_the_organization(): void
    {
        [$orgA, $ownerA] = $this->organizationWithAdmin();
        [$orgB, $ownerB] = $this->organizationWithAdmin();

        $dossierA = $this->dossier($orgA, $ownerA, 'Dossier A');
        $postA = $this->attachedArticle($orgA, $dossierA, $ownerA, 'Article A');
        $this->chunkForArticle($orgA, $dossierA, $postA);

        $dossierB = $this->dossier($orgB, $ownerB, 'Dossier B');
        $postB = $this->attachedArticle($orgB, $dossierB, $ownerB, 'Article B');
        $this->chunkForArticle($orgB, $dossierB, $postB);
        $this->chunkForArticle($orgB, $dossierB, $postB, chunkIndex: 1);

        $overview = app(OrganizationRagOverview::class);

        $summaryA = $overview->summary($orgA->id);
        $this->assertSame(1, $summaryA['dossiers']);
        $this->assertSame(1, $summaryA['articles']);
        $this->assertSame(1, $summaryA['chunks'], 'les 2 chunks de orgB ne doivent jamais entrer dans le compte de orgA');
        $this->assertSame(1, $summaryA['indexed_sources']);

        $titles = array_column($overview->sources($orgA->id), 'title');
        $this->assertSame(['Article A'], $titles);
        $this->assertNotContains('Article B', $titles);

        $this->assertSame(1, $overview->diagnostics($orgA->id)['chunks']);
    }

    public function test_the_console_page_never_leaks_another_organization_source(): void
    {
        [$orgA, $adminA] = $this->organizationWithAdmin();
        [$orgB, $ownerB] = $this->organizationWithAdmin();

        $dossierB = $this->dossier($orgB, $ownerB, 'Dossier secret B');
        $postB = $this->attachedArticle($orgB, $dossierB, $ownerB, 'Article confidentiel B');
        $this->chunkForArticle($orgB, $dossierB, $postB);

        $response = $this->actingAs($adminA)->get($this->consoleUrl($orgA));

        $response->assertOk();
        $response->assertDontSee('Article confidentiel B');
        $response->assertDontSee('Dossier secret B');
    }

    // ---- Permissions ----

    public function test_a_non_admin_member_cannot_reach_the_console(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $member = User::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($member)->get($this->consoleUrl($organization))->assertForbidden();
        $this->actingAs($admin)->get($this->consoleUrl($organization))->assertOk();
    }

    public function test_an_admin_of_another_organization_cannot_reach_this_console(): void
    {
        [$orgA] = $this->organizationWithAdmin();
        [, $adminB] = $this->organizationWithAdmin();

        $this->actingAs($adminB)->get($this->consoleUrl($orgA))->assertForbidden();
    }

    /**
     * Doctrine « portee != sujet » : etre admin d'Organization ne donne aucun
     * privilege sur DossierPolicy. L'etat de l'index est visible, le lien
     * d'ouverture ne l'est pas si la policy refuse.
     */
    public function test_an_admin_sees_the_state_of_a_private_dossier_without_being_able_to_open_it(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $someoneElse = User::factory()->create(['organization_id' => $organization->id]);

        // Dossier prive appartenant a quelqu'un d'autre : l'admin n'y a pas acces.
        $private = $this->dossier($organization, $someoneElse, 'Notes privées', Dossier::VISIBILITY_PRIVATE);
        $post = $this->attachedArticle($organization, $private, $someoneElse, 'Article privé');
        $this->chunkForArticle($organization, $private, $post);

        $this->assertFalse($admin->can('view', $private), 'préalable du test : l’admin ne doit pas voir ce Dossier');

        $sources = $this->consoleSources($organization, $admin);

        $this->assertCount(1, $sources);
        $this->assertSame('Article privé', $sources[0]['title']);
        $this->assertTrue($sources[0]['indexed'], 'l’état de l’index reste visible');
        $this->assertFalse($sources[0]['can_open'], 'le contenu, lui, ne doit pas être ouvrable');
    }

    public function test_an_admin_can_open_a_source_of_an_organization_visible_dossier(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();

        $shared = $this->dossier($organization, $admin, 'Dossier partagé', Dossier::VISIBILITY_ORGANIZATION);
        $post = $this->attachedArticle($organization, $shared, $admin, 'Article partagé');
        $this->chunkForArticle($organization, $shared, $post);

        $sources = $this->consoleSources($organization, $admin);

        $this->assertCount(1, $sources);
        $this->assertTrue($sources[0]['can_open']);
    }

    // ---- Sources / etats ----

    public function test_an_eligible_source_without_chunk_is_reported_as_not_indexed_not_as_an_error(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $dossier = $this->dossier($organization, $admin, 'Dossier', Dossier::VISIBILITY_ORGANIZATION);
        $this->attachedArticle($organization, $dossier, $admin, 'Jamais indexé');

        $sources = app(OrganizationRagOverview::class)->sources($organization->id);

        $this->assertCount(1, $sources);
        $this->assertFalse($sources[0]['indexed']);
        $this->assertSame(0, $sources[0]['chunks']);
        // Aucune valeur inventee quand l'information n'existe pas.
        $this->assertNull($sources[0]['embedding_provider']);
        $this->assertNull($sources[0]['embedding_model']);
        $this->assertNull($sources[0]['indexed_at']);
    }

    public function test_txt_and_markdown_files_are_listed_as_sources_with_their_index_state(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $dossier = $this->dossier($organization, $admin, 'Dossier', Dossier::VISIBILITY_ORGANIZATION);

        $txt = $this->file($organization, $dossier, $admin, 'notes.txt', 'text/plain');
        $markdown = $this->file($organization, $dossier, $admin, 'lyra.md', 'text/markdown');
        $this->chunkForFile($organization, $dossier, $txt);

        $sources = collect(app(OrganizationRagOverview::class)->sources($organization->id))->keyBy('title');

        $this->assertTrue($sources['notes.txt']['indexed']);
        $this->assertSame(1, $sources['notes.txt']['chunks']);
        $this->assertFalse($sources['lyra.md']['indexed']);
        $this->assertSame('file', $sources['lyra.md']['type']);
    }

    public function test_a_non_ingestible_file_is_not_presented_as_a_rag_source(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $dossier = $this->dossier($organization, $admin, 'Dossier', Dossier::VISIBILITY_ORGANIZATION);
        // TASK-1272 : le PDF est devenu ingerable ; l'image reste l'exemple
        // du fichier hors contrat.
        $this->file($organization, $dossier, $admin, 'mockup.png', 'image/png');

        // Un PNG n'est pas « en erreur » : il n'est simplement pas une source.
        $this->assertSame([], app(OrganizationRagOverview::class)->sources($organization->id));
        $this->assertSame(0, app(OrganizationRagOverview::class)->summary($organization->id)['files']);
    }

    public function test_a_draft_article_is_not_presented_as_an_eligible_source(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $dossier = $this->dossier($organization, $admin, 'Dossier', Dossier::VISIBILITY_ORGANIZATION);
        $this->attachedArticle($organization, $dossier, $admin, 'Brouillon', published: false);

        $this->assertSame([], app(OrganizationRagOverview::class)->sources($organization->id));
        $this->assertSame(0, app(OrganizationRagOverview::class)->summary($organization->id)['articles']);
    }

    // ---- Provenance / secrets ----

    public function test_the_page_never_exposes_a_filesystem_path_or_a_credential(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $dossier = $this->dossier($organization, $admin, 'Dossier', Dossier::VISIBILITY_ORGANIZATION);
        $file = $this->file($organization, $dossier, $admin, 'notes.txt', 'text/plain');
        $this->chunkForFile($organization, $dossier, $file);

        $html = $this->actingAs($admin)->get($this->consoleUrl($organization))->assertOk()->getContent();

        $this->assertStringNotContainsString($file->path, $html);
        $this->assertStringNotContainsString('dossier-files/', $html);
        $this->assertStringNotContainsString('sk-', $html);
        // Le nom lisible, lui, doit bien etre la.
        $this->assertStringContainsString('notes.txt', $html);
    }

    // ---- Diagnostics ----

    public function test_diagnostics_report_a_coherent_index_as_healthy(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $this->configureIndex('openai', 'text-embedding-3-small');

        $dossier = $this->dossier($organization, $admin, 'Dossier', Dossier::VISIBILITY_ORGANIZATION);
        $post = $this->attachedArticle($organization, $dossier, $admin, 'Article');
        $this->chunkForArticle($organization, $dossier, $post, provider: 'openai', model: 'text-embedding-3-small');

        $diagnostics = app(OrganizationRagOverview::class)->diagnostics($organization->id);

        $this->assertSame(['openai'], $diagnostics['providers']);
        $this->assertSame(['text-embedding-3-small'], $diagnostics['models']);
        $this->assertFalse($diagnostics['provider_mismatch']);
        $this->assertFalse($diagnostics['model_mismatch']);
        $this->assertFalse($diagnostics['index_mismatch']);
    }

    public function test_two_providers_in_the_same_index_are_reported_as_a_provider_mismatch(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $this->configureIndex('openai', 'text-embedding-3-small');

        $dossier = $this->dossier($organization, $admin, 'Dossier', Dossier::VISIBILITY_ORGANIZATION);
        $post = $this->attachedArticle($organization, $dossier, $admin, 'Article');
        $this->chunkForArticle($organization, $dossier, $post, provider: 'openai', model: 'text-embedding-3-small');
        $this->chunkForArticle($organization, $dossier, $post, chunkIndex: 1, provider: 'openrouter', model: 'text-embedding-3-small');

        $diagnostics = app(OrganizationRagOverview::class)->diagnostics($organization->id);

        $this->assertTrue($diagnostics['provider_mismatch']);
        $this->assertFalse($diagnostics['model_mismatch'], 'le modele, lui, est bien homogene');
        $this->assertTrue($diagnostics['index_mismatch']);
        $this->assertEqualsCanonicalizing(['openai', 'openrouter'], $diagnostics['providers']);
    }

    /**
     * Deux modeles d'une MEME famille produisent des espaces vectoriels
     * differents : c'est une incoherence au meme titre qu'un changement de
     * famille, et aucune compatibilite ne doit etre supposee entre eux.
     */
    public function test_two_models_of_the_same_provider_are_reported_as_a_model_mismatch(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $this->configureIndex('openai', 'text-embedding-3-small');

        $dossier = $this->dossier($organization, $admin, 'Dossier', Dossier::VISIBILITY_ORGANIZATION);
        $post = $this->attachedArticle($organization, $dossier, $admin, 'Article');
        $this->chunkForArticle($organization, $dossier, $post, provider: 'openai', model: 'text-embedding-3-small');
        $this->chunkForArticle($organization, $dossier, $post, chunkIndex: 1, provider: 'openai', model: 'text-embedding-3-large');

        $diagnostics = app(OrganizationRagOverview::class)->diagnostics($organization->id);

        $this->assertFalse($diagnostics['provider_mismatch'], 'le fournisseur, lui, est bien homogene');
        $this->assertTrue($diagnostics['model_mismatch']);
        $this->assertTrue($diagnostics['index_mismatch']);
        $this->assertEqualsCanonicalizing(['text-embedding-3-small', 'text-embedding-3-large'], $diagnostics['models']);
    }

    public function test_a_stored_model_differing_from_the_configured_one_is_a_mismatch(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        // L'index a ete produit avec un modele, la configuration en designe
        // un autre aujourd'hui : les vecteurs stockes ne repondent plus a la
        // configuration courante.
        $this->configureIndex('openai', 'text-embedding-3-large');

        $dossier = $this->dossier($organization, $admin, 'Dossier', Dossier::VISIBILITY_ORGANIZATION);
        $post = $this->attachedArticle($organization, $dossier, $admin, 'Article');
        $this->chunkForArticle($organization, $dossier, $post, provider: 'openai', model: 'text-embedding-3-small');

        $diagnostics = app(OrganizationRagOverview::class)->diagnostics($organization->id);

        $this->assertFalse($diagnostics['provider_mismatch']);
        $this->assertTrue($diagnostics['model_mismatch']);
        $this->assertTrue($diagnostics['index_mismatch']);
        $this->assertSame('text-embedding-3-large', $diagnostics['index_model']);
        $this->assertSame(['text-embedding-3-small'], $diagnostics['models']);
    }

    public function test_an_empty_index_is_never_reported_as_a_mismatch(): void
    {
        [$organization] = $this->organizationWithAdmin();
        $this->configureIndex('openai', 'text-embedding-3-small');

        $diagnostics = app(OrganizationRagOverview::class)->diagnostics($organization->id);

        // Rien de stocke : il n'y a rien a comparer, donc rien a affirmer.
        $this->assertSame([], $diagnostics['providers']);
        $this->assertSame([], $diagnostics['models']);
        $this->assertFalse($diagnostics['index_mismatch']);
    }

    // ---- Dossier supprime ----

    /**
     * Un Dossier supprime emporte ses sources hors du perimetre courant :
     * la liste ET les compteurs doivent le refleter, sinon l'ecran se
     * contredit lui-meme.
     */
    public function test_a_soft_deleted_dossier_removes_its_sources_from_the_list_and_the_counters(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $dossier = $this->dossier($organization, $admin, 'Dossier vivant', Dossier::VISIBILITY_ORGANIZATION);
        $this->attachedArticle($organization, $dossier, $admin, 'Article du dossier');
        $this->file($organization, $dossier, $admin, 'notes.txt', 'text/plain');

        $overview = app(OrganizationRagOverview::class);

        $before = $overview->summary($organization->id);
        $this->assertSame(1, $before['articles']);
        $this->assertSame(1, $before['files']);
        $this->assertCount(2, $overview->sources($organization->id));

        $dossier->delete();

        $after = $overview->summary($organization->id);
        $this->assertSame(0, $after['articles'], 'un Article dont le seul Dossier est supprime n’est plus une source actuelle');
        $this->assertSame(0, $after['files'], 'un fichier d’un Dossier supprime n’est plus une source actuelle');
        $this->assertSame(0, $after['dossiers']);
        $this->assertSame([], $overview->sources($organization->id));
    }

    // ---- helpers ----

    /** @return array{0: Organization, 1: User} */
    private function organizationWithAdmin(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create(['organization_id' => $organization->id]);
        $organization->update(['admin_id' => $admin->id]);

        return [$organization->fresh(), $admin];
    }

    private function consoleUrl(Organization $organization): string
    {
        return route('organization.admin.ai-knowledge', ['organization' => $organization->slug]);
    }

    /**
     * Les sources telles que la PAGE les calcule (policy `can_open` incluse),
     * pas seulement le read model.
     *
     * @return array<int, array<string, mixed>>
     */
    private function consoleSources(Organization $organization, User $admin): array
    {
        return $this->actingAs($admin)
            ->get($this->consoleUrl($organization))
            ->assertOk()
            ->viewData('sources');
    }

    private function dossier(Organization $organization, User $owner, string $name, string $visibility = Dossier::VISIBILITY_PRIVATE): Dossier
    {
        return Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'name' => $name,
            'visibility' => $visibility,
        ]);
    }

    private function attachedArticle(Organization $organization, Dossier $dossier, User $author, string $title, bool $published = true): BlogPost
    {
        $post = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $author->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::uuid(),
            'content' => '<p>'.$title.'</p>',
            'status' => $published ? 'published' : 'draft',
            'published_at' => $published ? now()->subMinute() : null,
            'listed_in_blog' => true,
        ]);

        DossierBlogPost::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $author->id,
            'position' => 1,
        ]);

        return $post;
    }

    private function file(Organization $organization, Dossier $dossier, User $uploader, string $name, string $mime): DossierFile
    {
        return DossierFile::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $uploader->id,
            'disk' => 'dossier_files',
            'path' => 'dossier-files/'.$dossier->id.'/'.$name,
            'original_name' => $name,
            'display_name' => $name,
            'mime_type' => $mime,
            'size_bytes' => 42,
            'checksum_sha256' => hash('sha256', $name.Str::uuid()),
            'source' => 'upload',
        ]);
    }

    private function configureIndex(string $family, string $model): void
    {
        config()->set('ai.default_for_embeddings', $family);
        config()->set("ai.providers.{$family}.models.embeddings.default", $model);
    }

    private function chunkForArticle(Organization $organization, Dossier $dossier, BlogPost $post, int $chunkIndex = 0, string $provider = 'openai', string $model = 'text-embedding-3-small'): DossierChunk
    {
        return DossierChunk::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'dossier_file_id' => null,
            'chunk_index' => $chunkIndex,
            'content' => 'contenu '.$chunkIndex,
            'content_hash' => hash('sha256', $post->id.$chunkIndex.$provider.$model),
            'token_count' => 3,
            'embedding' => array_fill(0, $this->dimensions(), 0.1),
            'embedding_provider' => $provider,
            'embedding_model' => $model,
            'indexed_at' => now(),
        ]);
    }

    private function chunkForFile(Organization $organization, Dossier $dossier, DossierFile $file, int $chunkIndex = 0): DossierChunk
    {
        return DossierChunk::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => null,
            'dossier_file_id' => $file->id,
            'chunk_index' => $chunkIndex,
            'content' => 'contenu fichier '.$chunkIndex,
            'content_hash' => hash('sha256', $file->id.$chunkIndex),
            'token_count' => 3,
            'embedding' => array_fill(0, $this->dimensions(), 0.1),
            'embedding_provider' => 'openai',
            'embedding_model' => 'text-embedding-3-small',
            'indexed_at' => now(),
        ]);
    }
}
