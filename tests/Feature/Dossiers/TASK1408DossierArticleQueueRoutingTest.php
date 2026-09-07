<?php

namespace Tests\Feature\Dossiers;

use App\Jobs\IndexDossierArticleChunks;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1408 — toute indexation d'ARTICLE de Dossier part sur une queue dediee
 * et DISTINCTE de celle des fichiers.
 *
 * Le defaut, jumeau de celui corrige par TASK-1407 cote fichiers :
 * `DossierArticleIndexingDispatcher::dispatch()` n'appelait `onQueue()` que si
 * une queue explicite lui etait fournie. Sur 17 sites d'appel, **15 n'en
 * passaient aucune** — les deux Observers, les deux controleurs,
 * `LoopRootDocumentService`, `LoopAnswerCapitalizationService`. Tous
 * alimentaient `default`.
 *
 * Deux differences avec TASK-1407, et elles comptent :
 *
 * 1. La constante change de VALEUR : `dossier-files-indexing` devient
 *    `dossier-articles-indexing`. Les deux dispatchers portaient jusqu'ici la
 *    meme chaine litterale, si bien qu'un worker ne pouvait pas consommer
 *    l'indexation des fichiers sans consommer aussi celle des Articles.
 *
 * 2. Le relais explicite a ici un appelant REEL. `dispatchForEntries()`
 *    retransmet son propre `$queue` en 4e argument de `dispatch()`, y compris
 *    quand il vaut `null`, et `AiValidationIndexArtSciLabCommand` l'appelle
 *    sans queue. Une valeur par defaut de PARAMETRE ne s'applique qu'a un
 *    argument OMIS : elle aurait donc laisse ce chemin sur `default` tout en
 *    AFFICHANT le bon defaut dans la signature. D'ou le defaut pose dans le
 *    corps, et le test `..._entries_without_a_queue_...` qui le prouve.
 *
 * Ces gardes mesurent la QUEUE REELLE du job pousse — `Queue::fake()`
 * intercepte au niveau du QueueManager et expose la file de destination, ce que
 * `Bus::fake()` ne fait pas.
 *
 * HORS SCOPE : les jobs historiques de `default` ne sont ni reveilles, ni
 * purges, ni draines. Ils portent deja leur colonne `queue` et rien ne la
 * reecrit. Cette TASK change un ROUTAGE, elle ne met aucun worker en service.
 */
#[Group('sqlite-only')]
class TASK1408DossierArticleQueueRoutingTest extends TestCase
{
    public function refreshDatabase()
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            $this->markTestSkipped('TASK1408DossierArticleQueueRoutingTest requires safe-test sqlite :memory:.');
        }

        $this->artisan('migrate:fresh');
        $this->app[Kernel::class]->setArtisan(null);
    }

    // ── La queue dediee est DISTINCTE de celle des fichiers ─────────────────

    public function test_the_article_queue_is_distinct_from_the_file_queue(): void
    {
        $this->assertSame('dossier-articles-indexing', DossierArticleIndexingDispatcher::DEDICATED_QUEUE);

        $this->assertNotSame(
            \App\Services\Dossiers\DossierFileIndexingDispatcher::DEDICATED_QUEUE,
            DossierArticleIndexingDispatcher::DEDICATED_QUEUE,
            'Un worker doit pouvoir consommer les fichiers sans consommer les Articles.'
        );
    }

    // ── Aucune queue explicite ──────────────────────────────────────────────

    public function test_a_dispatch_without_an_explicit_queue_goes_to_the_dedicated_queue(): void
    {
        [$organization, , $dossier, $post] = $this->fixture(attached: false);
        Queue::fake();

        app(DossierArticleIndexingDispatcher::class)->dispatch($organization->id, $dossier->id, $post->id);

        Queue::assertPushedOn(DossierArticleIndexingDispatcher::DEDICATED_QUEUE, IndexDossierArticleChunks::class);
        $this->assertNoArticleJobOnDefault();
    }

    /**
     * LE test de cette TASK : `dispatchForEntries()` retransmet `$queue`
     * EXPLICITEMENT. Une valeur par defaut de parametre sur `dispatch()`
     * laisserait ce chemin sur `default`, et la signature afficherait pourtant
     * le bon defaut. Ce chemin a un appelant reel
     * (`AiValidationIndexArtSciLabCommand`).
     */
    public function test_dispatch_for_entries_without_a_queue_also_goes_to_the_dedicated_queue(): void
    {
        [$organization, , $dossier, $post] = $this->fixture();
        Queue::fake();

        $count = app(DossierArticleIndexingDispatcher::class)->dispatchForEntries([[
            'organization_id' => (string) $organization->id,
            'dossier_id' => (string) $dossier->id,
            'blog_post_id' => (string) $post->id,
        ]]);

        $this->assertSame(1, $count);
        Queue::assertPushedOn(DossierArticleIndexingDispatcher::DEDICATED_QUEUE, IndexDossierArticleChunks::class);
        $this->assertNoArticleJobOnDefault();
    }

    // ── Queue explicite preservee ───────────────────────────────────────────

    public function test_an_explicit_queue_is_preserved_untouched(): void
    {
        [$organization, , $dossier, $post] = $this->fixture(attached: false);
        Queue::fake();

        app(DossierArticleIndexingDispatcher::class)->dispatch(
            $organization->id,
            $dossier->id,
            $post->id,
            'file-d-appoint',
        );

        Queue::assertPushedOn('file-d-appoint', IndexDossierArticleChunks::class);
        Queue::assertNotPushed(
            IndexDossierArticleChunks::class,
            fn (IndexDossierArticleChunks $job, ?string $queue): bool => $queue !== 'file-d-appoint',
        );
    }

    public function test_dispatch_for_entries_with_an_explicit_queue_preserves_it(): void
    {
        [$organization, , $dossier, $post] = $this->fixture();
        Queue::fake();

        app(DossierArticleIndexingDispatcher::class)->dispatchForEntries([[
            'organization_id' => (string) $organization->id,
            'dossier_id' => (string) $dossier->id,
            'blog_post_id' => (string) $post->id,
        ]], 'file-d-appoint');

        Queue::assertPushedOn('file-d-appoint', IndexDossierArticleChunks::class);
    }

    // ── Les chemins producteurs reels ───────────────────────────────────────

    public function test_updating_an_article_content_pushes_on_the_dedicated_queue(): void
    {
        [, , , $post] = $this->fixture();
        Queue::fake();

        $post->update(['content' => '<p>contenu modifie</p>']);

        Queue::assertPushed(IndexDossierArticleChunks::class, 1);
        Queue::assertPushedOn(DossierArticleIndexingDispatcher::DEDICATED_QUEUE, IndexDossierArticleChunks::class);
        $this->assertNoArticleJobOnDefault();
    }

    public function test_deleting_and_restoring_an_article_push_on_the_dedicated_queue(): void
    {
        [, , , $post] = $this->fixture();

        Queue::fake();
        $post->delete();
        Queue::assertPushed(IndexDossierArticleChunks::class, 1);
        $this->assertNoArticleJobOnDefault();

        Queue::fake();
        $post->restore();
        Queue::assertPushed(IndexDossierArticleChunks::class, 1);
        Queue::assertPushedOn(DossierArticleIndexingDispatcher::DEDICATED_QUEUE, IndexDossierArticleChunks::class);
        $this->assertNoArticleJobOnDefault();
    }

    // ── Temoin d'instrument ─────────────────────────────────────────────────

    /**
     * Sans lui, un Observer debranche ou un dispatcher muet feraient passer
     * TOUTES les gardes « aucun job sur default » : zero job pousse, zero job
     * sur default. Ce temoin exige qu'un job existe REELLEMENT et que
     * l'assertion de queue sache distinguer deux files.
     */
    public function test_the_probe_really_pushes_a_job_and_can_tell_two_queues_apart(): void
    {
        [$organization, , $dossier, $post] = $this->fixture(attached: false);
        Queue::fake();

        app(DossierArticleIndexingDispatcher::class)->dispatch($organization->id, $dossier->id, $post->id);

        Queue::assertPushed(IndexDossierArticleChunks::class, 1);
        Queue::assertNotPushed(
            IndexDossierArticleChunks::class,
            fn (IndexDossierArticleChunks $job, ?string $queue): bool => $queue === 'une-file-qui-n-existe-pas',
        );
        Queue::assertPushedOn(DossierArticleIndexingDispatcher::DEDICATED_QUEUE, IndexDossierArticleChunks::class);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * `Queue::fake()` presente au callback la queue `null` pour un job pousse
     * SANS `onQueue()`, et jamais la chaine `'default'` — mesure faite en
     * sabotant le routage en TASK-1407, ou une closure typee `string $queue`
     * levait un TypeError au lieu de rendre un verdict. D'ou `?string`, et la
     * couverture des trois formes.
     */
    private function assertNoArticleJobOnDefault(): void
    {
        Queue::assertNotPushed(
            IndexDossierArticleChunks::class,
            fn (IndexDossierArticleChunks $job, ?string $queue): bool => $queue === 'default'
                || $queue === null
                || $queue === '',
        );
    }

    /** @return array{0: Organization, 1: User, 2: Dossier, 3: BlogPost} */
    private function fixture(bool $attached = true): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $dossier = Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $user->id,
            'name' => 'Dossier TASK-1408',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $post = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'title' => 'Article TASK-1408',
            'slug' => 'article-task-1408-'.Str::uuid(),
            'content' => '<p>contenu indexable</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);

        if ($attached) {
            DossierBlogPost::create([
                'organization_id' => $organization->id,
                'dossier_id' => $dossier->id,
                'blog_post_id' => $post->id,
                'added_by' => $user->id,
                'position' => 1,
            ]);
        }

        return [$organization, $user, $dossier, $post];
    }
}
