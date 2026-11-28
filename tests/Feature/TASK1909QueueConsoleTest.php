<?php

namespace Tests\Feature;

use App\Livewire\Admin\QueueConsole;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use App\Support\Queues\QueueSupervision;
use App\Support\Queues\QueueWorkerHeartbeat;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1909 — console SuperAdmin « Files d'attente » (`/admin/outils/files-attente`).
 */
class TASK1909QueueConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        QueueWorkerHeartbeat::resetThrottle();
        config(['queue.article_indexing_connection' => 'database']);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function queueOneArticleJob(?string $blogPostId = null): void
    {
        app(DossierArticleIndexingDispatcher::class)->dispatch((string) Str::uuid(), (string) Str::uuid(), $blogPostId ?? (string) Str::uuid());
    }

    public function test_seul_le_superadmin_ouvre_la_console(): void
    {
        $this->get(route('admin.outils.files-attente'))->assertRedirect();
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get(route('admin.outils.files-attente'))->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('admin.outils.files-attente'))
            ->assertOk()
            ->assertSee(__('admin.queue_console.title'))
            ->assertSee('data-marqueur="note-wsl"', false)
            ->assertSee('crontab -l | grep test.laravel');
    }

    public function test_la_console_et_la_planification_listent_les_memes_files(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => str_contains((string) $e->command, 'queue:work'));

        preg_match("/--queue='([^']+)'/", (string) $event->command, $m);

        $this->assertSame(QueueSupervision::WORKER_QUEUES, explode(',', $m[1]));
    }

    public function test_le_signe_de_vie_ne_compte_que_le_worker_database_des_articles(): void
    {
        $heartbeat = app(QueueWorkerHeartbeat::class);

        event(new Looping('sync', implode(',', QueueSupervision::WORKER_QUEUES)));
        $this->assertNull($heartbeat->lastSeen(), 'un worker sync ne consomme rien');

        event(new Looping('database', 'images'));
        $this->assertNull($heartbeat->lastSeen(), 'le worker images seul ne compte pas');

        event(new Looping('database', implode(',', QueueSupervision::WORKER_QUEUES)));
        $this->assertTrue($heartbeat->isAlive());

        $this->travel(QueueWorkerHeartbeat::ALIVE_WITHIN_SECONDS + 1)->seconds();
        $this->assertFalse($heartbeat->isAlive());
    }

    public function test_l_alerte_passe_au_rouge_quand_un_job_attend_plus_de_cinq_minutes(): void
    {
        $this->queueOneArticleJob();
        event(new Looping('database', implode(',', QueueSupervision::WORKER_QUEUES)));

        $this->assertSame('green', app(QueueSupervision::class)->alert()['level']);

        $this->travel(QueueSupervision::ALERT_AFTER_SECONDS + 10)->seconds();
        QueueWorkerHeartbeat::resetThrottle();
        event(new Looping('database', implode(',', QueueSupervision::WORKER_QUEUES)));

        $this->assertSame(['level' => 'red', 'reason' => 'stale'], app(QueueSupervision::class)->alert());
    }

    public function test_le_mode_manuel_traite_un_job_par_appel(): void
    {
        $this->queueOneArticleJob();
        $this->queueOneArticleJob();
        $this->assertSame(2, DB::table('jobs')->count());

        $component = Livewire::actingAs($this->admin())->test(QueueConsole::class);

        $this->assertSame(1, $component->instance()->processOne());
        $this->assertSame(0, $component->instance()->processOne());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_un_job_en_echec_s_affiche_sans_son_contenu_et_se_relance_ou_s_oublie(): void
    {
        // Un vrai payload (pris d'un vrai job), auquel on ajoute un contenu prive.
        $this->queueOneArticleJob();
        $job = DB::table('jobs')->first();
        DB::table('jobs')->delete();
        $decoded = json_decode((string) $job->payload, true);
        $uuid = (string) $decoded['uuid'];
        $decoded['note'] = 'SECRET-CONTENU-PRIVE';
        $payload = json_encode($decoded);
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid, 'connection' => 'database', 'queue' => DossierArticleIndexingDispatcher::DEDICATED_QUEUE,
            'payload' => $payload, 'exception' => "RuntimeException: panne fournisseur\n#0 trace", 'failed_at' => now(),
        ]);

        $this->actingAs($admin = $this->admin())
            ->get(route('admin.outils.files-attente'))
            ->assertSee('IndexDossierArticleChunks')
            ->assertSee('RuntimeException: panne fournisseur')
            ->assertDontSee('SECRET-CONTENU-PRIVE')
            ->assertDontSee('#0 trace');

        Livewire::actingAs($admin)->test(QueueConsole::class)->call('retryFailed', $uuid);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', DossierArticleIndexingDispatcher::DEDICATED_QUEUE)->count());

        DB::table('failed_jobs')->insert([
            'uuid' => $autre = (string) Str::uuid(), 'connection' => 'database', 'queue' => 'images',
            'payload' => '{}', 'exception' => 'Exception: x', 'failed_at' => now(),
        ]);
        Livewire::actingAs($admin)->test(QueueConsole::class)->call('forgetFailed', $autre);
        $this->assertFalse(DB::table('failed_jobs')->where('uuid', $autre)->exists());

        Livewire::actingAs($admin)->test(QueueConsole::class)
            ->call('forgetFailed', 'pas-un-uuid')
            ->assertSet('error', __('admin.queue_console.failed_unknown'));
    }

    public function test_les_articles_en_attente_sont_nommes_et_la_reindexation_par_slug_est_sure(): void
    {
        $org = Organization::factory()->create();
        $post = BlogPost::create([
            'user_id' => User::factory()->create(['organization_id' => $org->id])->id,
            'organization_id' => $org->id,
            'category_id' => Category::factory()->create(['organization_id' => $org->id])->id,
            'title' => 'Article T1909 en attente',
            'slug' => 't1909-unique',
            'content' => '<p>Texte</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
        $this->queueOneArticleJob($post->id);

        $this->actingAs($admin = $this->admin())
            ->get(route('admin.outils.files-attente'))
            ->assertSee('Article T1909 en attente');

        Livewire::actingAs($admin)->test(QueueConsole::class)
            ->set('articleRef', 'slug-inexistant')
            ->call('reindexArticle')
            ->assertSet('error', __('admin.queue_console.reindex_unknown'));

        Livewire::actingAs($admin)->test(QueueConsole::class)
            ->set('articleRef', 't1909-unique')
            ->call('reindexArticle')
            ->assertSet('error', null)
            ->assertSet('notice', __('admin.queue_console.reindex_not_in_dossier', ['title' => 'Article T1909 en attente']));
    }
}
