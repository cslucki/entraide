<?php

namespace App\Livewire\Admin;

use App\Jobs\ExtractBlogPostFileText;
use App\Models\BlogPost;
use App\Models\BlogPostFile;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use App\Support\Queues\QueueSupervision;
use App\Support\Queues\QueueWorkerHeartbeat;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * TASK-1909 — console SuperAdmin « Files d'attente », `/admin/outils/files-attente`.
 *
 * Supervise le worker planifie `queue:work database` (cron local, scheduler
 * PROD ; AGENTS.md section 16) : etat et alerte, jobs en echec, articles en
 * attente, et un MODE MANUEL (un job par requete, enchaine par le navigateur)
 * pour le cas ou le worker ne tourne pas. Modele : `/admin/images`.
 *
 * Aucune action ne lit ni n'affiche le contenu d'un job.
 */
#[Layout('layouts.admin')]
class QueueConsole extends Component
{
    public string $articleRef = '';

    public ?string $notice = null;

    public ?string $error = null;

    /**
     * Defense en profondeur, comme `ImageOptimizer` : `AdminMiddleware` est
     * deja persistant pour Livewire ; cette garde couvre chaque requete.
     */
    public function boot(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }

    /**
     * Mode manuel : traite UN job des files du worker, dans cette requete.
     * Retourne le nombre de jobs restants ; le navigateur rappelle tant qu'il
     * en reste et que le SuperAdmin n'a pas arrete.
     */
    public function processOne(): int
    {
        Artisan::call('queue:work', [
            'connection' => QueueSupervision::CONNECTION,
            '--queue' => implode(',', QueueSupervision::WORKER_QUEUES),
            '--once' => true,
            '--stop-when-empty' => true,
            '--tries' => 3,
        ]);

        return app(QueueSupervision::class)->pendingTotal();
    }

    public function retryFailed(string $uuid): void
    {
        $this->resetMessages();

        if (! Str::isUuid($uuid) || ! DB::table('failed_jobs')->where('uuid', $uuid)->exists()) {
            $this->error = __('admin.queue_console.failed_unknown');

            return;
        }

        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $this->log('retry', ['uuid' => $uuid]);
        $this->notice = __('admin.queue_console.failed_retried');
    }

    public function forgetFailed(string $uuid): void
    {
        $this->resetMessages();

        if (! Str::isUuid($uuid) || ! DB::table('failed_jobs')->where('uuid', $uuid)->exists()) {
            $this->error = __('admin.queue_console.failed_unknown');

            return;
        }

        Artisan::call('queue:forget', ['id' => $uuid]);
        $this->log('forget', ['uuid' => $uuid]);
        $this->notice = __('admin.queue_console.failed_forgotten');
    }

    /** Extrait tout de suite le texte d'UN PDF, dans cette requete (choix explicite). */
    public function extractNow(string $fileId): void
    {
        $this->resetMessages();

        $file = Str::isUuid($fileId) ? BlogPostFile::query()->withoutGlobalScopes()->find($fileId) : null;

        if (! $file || $file->kind !== BlogPostFile::KIND_PDF || $file->extracted_at !== null) {
            $this->error = __('admin.queue_console.pdf_unknown');

            return;
        }

        dispatch_sync(new ExtractBlogPostFileText($file->id));
        $this->log('extract', ['blog_post_file_id' => $file->id]);
        $this->notice = __('admin.queue_console.pdf_extracted', ['name' => $file->original_name]);
    }

    /** Remet un article dans la file d'indexation (par identifiant ou slug). */
    public function reindexArticle(): void
    {
        $this->resetMessages();
        $ref = trim($this->articleRef);

        $posts = BlogPost::query()->withoutGlobalScopes()
            ->when(Str::isUuid($ref), fn ($q) => $q->whereKey($ref), fn ($q) => $q->where('slug', $ref))
            ->limit(2)
            ->get();

        if ($ref === '' || $posts->count() !== 1) {
            $this->error = $posts->count() > 1
                ? __('admin.queue_console.reindex_ambiguous')
                : __('admin.queue_console.reindex_unknown');

            return;
        }

        $post = $posts->first();
        $count = app(DossierArticleIndexingDispatcher::class)->dispatchForBlogPost($post);
        $this->log('reindex', ['blog_post_id' => $post->id, 'jobs' => $count]);

        $this->notice = $count > 0
            ? __('admin.queue_console.reindex_queued', ['title' => $post->title, 'count' => $count])
            : __('admin.queue_console.reindex_not_in_dossier', ['title' => $post->title]);
        $this->articleRef = '';
    }

    public function render(QueueSupervision $supervision, QueueWorkerHeartbeat $heartbeat): View
    {
        return view('livewire.admin.queue-console', [
            'flows' => $supervision->flows(),
            'queues' => $supervision->queues(),
            'pendingTotal' => $supervision->pendingTotal(),
            'alert' => $supervision->alert(),
            'workerAlive' => $heartbeat->isAlive(),
            'workerSeen' => $heartbeat->lastSeen(),
            'failed' => $supervision->failedJobs(),
            'failedTotal' => $supervision->failedTotal(),
            'pdfs' => $supervision->pendingPdfs(),
            'pdfTotal' => $supervision->pendingPdfTotal(),
            'articles' => $supervision->pendingArticles(),
            'workerQueues' => QueueSupervision::WORKER_QUEUES,
        ]);
    }

    private function resetMessages(): void
    {
        $this->notice = null;
        $this->error = null;
    }

    /** Trace des actions SuperAdmin (qui, quoi) : jamais de contenu de job. */
    private function log(string $action, array $context): void
    {
        Log::info('TASK-1909 console files d\'attente : '.$action, $context + ['admin_id' => auth()->id()]);
    }
}
