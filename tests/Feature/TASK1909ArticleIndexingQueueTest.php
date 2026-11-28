<?php

namespace Tests\Feature;

use App\Jobs\IndexDossierArticleChunks;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
}
