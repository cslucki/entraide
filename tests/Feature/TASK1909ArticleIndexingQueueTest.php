<?php

namespace Tests\Feature;

use App\Jobs\ExtractBlogPostFileText;
use App\Jobs\IndexDossierArticleChunks;
use App\Models\BlogPostFile;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1909 — l'indexation IA d'un article quitte la requete de l'auteur.
 *
 * En PROD `QUEUE_CONNECTION=sync` : sans connexion imposee, le job tourne
 * pendant l'enregistrement. `queue.article_indexing_connection` (defaut
 * `database`) l'envoie dans `jobs`, consomme par le worker planifie ;
 * `sync` ramene le comportement d'avant, vide laisse `QUEUE_CONNECTION` decider.
 */
class TASK1909ArticleIndexingQueueTest extends TestCase
{
    use RefreshDatabase;

    private function dispatchOne(): void
    {
        app(DossierArticleIndexingDispatcher::class)->dispatch(
            (string) Str::uuid(),
            (string) Str::uuid(),
            (string) Str::uuid(),
        );
    }

    public function test_par_defaut_le_job_part_sur_la_connexion_database_et_sa_file_dediee(): void
    {
        // La valeur livree par config/queue.php, sans la surcharge `sync` des suites de tests.
        config(['queue.article_indexing_connection' => 'database']);
        Queue::fake();

        $this->dispatchOne();

        Queue::assertPushed(IndexDossierArticleChunks::class, fn ($job) => $job->connection === 'database'
            && $job->queue === DossierArticleIndexingDispatcher::DEDICATED_QUEUE);
    }

    public function test_avec_database_le_job_est_ecrit_dans_jobs_et_ne_tourne_pas_dans_la_requete(): void
    {
        config(['queue.default' => 'sync', 'queue.article_indexing_connection' => 'database']);

        $this->dispatchOne();

        $this->assertSame(1, DB::table('jobs')->where('queue', DossierArticleIndexingDispatcher::DEDICATED_QUEUE)->count());
        $this->assertStringContainsString('IndexDossierArticleChunks', (string) DB::table('jobs')->value('payload'));
    }

    public function test_sync_ramene_le_comportement_d_avant(): void
    {
        config(['queue.article_indexing_connection' => 'sync']);
        Queue::fake();

        $this->dispatchOne();

        Queue::assertPushed(IndexDossierArticleChunks::class, fn ($job) => $job->connection === 'sync');
    }

    public function test_vide_laisse_queue_connection_decider(): void
    {
        config(['queue.article_indexing_connection' => '']);
        Queue::fake();

        $this->dispatchOne();

        Queue::assertPushed(IndexDossierArticleChunks::class, fn ($job) => $job->connection === null
            && $job->queue === DossierArticleIndexingDispatcher::DEDICATED_QUEUE);
    }

    public function test_la_valeur_livree_par_defaut_est_database(): void
    {
        // Lu dans le fichier de config lui-meme : la surcharge des suites de
        // tests (`sync`) ne doit pas masquer ce que la PROD recevra.
        $source = file_get_contents(config_path('queue.php'));

        $this->assertStringContainsString("env('ARTICLE_INDEXING_QUEUE_CONNECTION', 'database')", $source);
    }

    // ---- Etape 3 : extraction du texte des PDF au depot ----

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    private function deposerPdf(): string
    {
        Storage::fake(BlogPostFile::DISK);
        $org = Organization::factory()->create(['is_public' => true, 'slug' => 't1909-org', 'is_active' => true]);
        $auteur = User::factory()->create(['organization_id' => $org->id, 'is_admin' => false]);

        return (string) $this->actingAs($auteur)->postJson(
            route('organization.blog.files.store', [$org]),
            ['file' => UploadedFile::fake()->createWithContent('Guide.pdf', self::PDF)],
        )->assertCreated()->json('id');
    }

    public function test_le_depot_d_un_pdf_met_l_extraction_en_file_database_sans_l_executer(): void
    {
        config(['queue.default' => 'sync', 'queue.article_indexing_connection' => 'database']);

        $id = $this->deposerPdf();

        $this->assertSame(1, DB::table('jobs')->where('queue', ExtractBlogPostFileText::QUEUE)->count());
        $this->assertNull(BlogPostFile::withoutGlobalScopes()->find($id)->extracted_at, 'rien n est extrait pendant la requete d envoi');
    }

    public function test_en_sync_le_depot_ne_lance_rien_et_la_commande_reste_le_chemin_principal(): void
    {
        config(['queue.default' => 'sync', 'queue.article_indexing_connection' => 'sync']);

        $id = $this->deposerPdf();

        // Rien en file, et surtout rien d'execute pendant la requete d'envoi.
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertNull(BlogPostFile::withoutGlobalScopes()->find($id)->extracted_at);
        $this->artisan('blog:extract-file-texts')->assertSuccessful();
        $this->assertNotNull(BlogPostFile::withoutGlobalScopes()->find($id)->extracted_at, 'en sync, la commande traite aussitot');
    }

    public function test_la_commande_filet_laisse_un_pdf_recent_a_son_job_de_depot(): void
    {
        config(['queue.default' => 'sync', 'queue.article_indexing_connection' => 'database']);

        $id = $this->deposerPdf();

        $this->artisan('blog:extract-file-texts')->assertSuccessful();
        $this->assertNull(BlogPostFile::withoutGlobalScopes()->find($id)->extracted_at, 'pas de doublon avec le job du depot');

        $this->travel(ExtractBlogPostFileText::FILET_APRES_MINUTES + 1)->minutes();
        $this->artisan('blog:extract-file-texts')->assertSuccessful();
        $this->assertNotNull(BlogPostFile::withoutGlobalScopes()->find($id)->extracted_at, 'au-dela du delai, le filet rattrape');
    }

    public function test_le_job_d_extraction_tient_sous_le_retry_after_de_database(): void
    {
        $this->assertLessThan(
            (int) config('queue.connections.database.retry_after'),
            (new ExtractBlogPostFileText('x'))->timeout,
        );
    }
}
