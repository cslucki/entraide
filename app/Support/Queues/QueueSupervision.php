<?php

namespace App\Support\Queues;

use App\Jobs\ExtractBlogPostFileText;
use App\Jobs\ProcessImageVariants;
use App\Models\BlogPost;
use App\Models\BlogPostFile;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use App\Services\Dossiers\DossierFileIndexingDispatcher;
use App\Services\Knowledge\LoopConversationKnowledgeDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TASK-1909 — lecture, pour la console SuperAdmin « Files d'attente », de
 * l'etat du worker planifie `queue:work database` (AGENTS.md section 16).
 *
 * Lecture seule. Ne renvoie JAMAIS le contenu d'un job (`payload`) : seuls
 * le nom de la classe, la file, les dates et un resume d'erreur sortent.
 */
final class QueueSupervision
{
    /**
     * Les files du worker planifie, dans l'ordre de `routes/console.php`.
     * Le litteral de la planification reste la source ;
     * `TASK1909QueueConsoleTest` rougit si les deux divergent.
     */
    public const WORKER_QUEUES = [
        DossierFileIndexingDispatcher::DEDICATED_QUEUE,
        DossierArticleIndexingDispatcher::DEDICATED_QUEUE,
        LoopConversationKnowledgeDispatcher::DEDICATED_QUEUE,
        ProcessImageVariants::QUEUE,
        ExtractBlogPostFileText::QUEUE,
    ];

    /** Au-dela, un job en attente signale un worker arrete. */
    public const ALERT_AFTER_SECONDS = 300;

    public const CONNECTION = 'database';

    public function __construct(private readonly QueueWorkerHeartbeat $heartbeat) {}

    /**
     * La connexion EFFECTIVE de chaque traitement. `sync` = execute pendant
     * la requete de la personne ; tout autre pilote = hors requete.
     *
     * @return list<array{key: string, connection: string, driver: string, async: bool}>
     */
    public function flows(): array
    {
        $default = (string) config('queue.default');

        $flows = [
            'articles' => (string) (config('queue.article_indexing_connection') ?: $default),
            'pdf' => ExtractBlogPostFileText::uploadConnection() ?? $default,
            'images' => (string) (config('image_variants.queue_connection') ?: $default),
            'notifications' => (string) (config('queue.notifications_managed_queue_connection') ?: $default),
            'other' => $default,
        ];

        return collect($flows)->map(function (string $connection, string $key) {
            $driver = (string) config("queue.connections.{$connection}.driver", '?');

            return ['key' => $key, 'connection' => $connection, 'driver' => $driver, 'async' => $driver !== 'sync'];
        })->values()->all();
    }

    /**
     * @return list<array{queue: string, pending: int, running: int, oldest_seconds: int|null}>
     */
    public function queues(): array
    {
        $now = now()->getTimestamp();
        $rows = DB::table('jobs')
            ->whereIn('queue', self::WORKER_QUEUES)
            ->selectRaw('queue, count(*) as total, sum(case when reserved_at is null then 0 else 1 end) as running, min(case when reserved_at is null then available_at end) as oldest')
            ->groupBy('queue')
            ->get()
            ->keyBy('queue');

        return array_map(function (string $queue) use ($rows, $now) {
            $row = $rows->get($queue);
            $running = (int) ($row->running ?? 0);
            $oldest = $row?->oldest !== null ? max(0, $now - (int) $row->oldest) : null;

            return [
                'queue' => $queue,
                'pending' => (int) ($row->total ?? 0) - $running,
                'running' => $running,
                'oldest_seconds' => $oldest,
            ];
        }, self::WORKER_QUEUES);
    }

    public function pendingTotal(): int
    {
        return (int) DB::table('jobs')->whereIn('queue', self::WORKER_QUEUES)->count();
    }

    /**
     * Rouge : un job attend depuis plus de 5 minutes, ou des jobs attendent
     * et le worker n'a pas ete vu.
     *
     * @return array{level: string, reason: string|null}
     */
    public function alert(): array
    {
        $oldest = collect($this->queues())->max('oldest_seconds');

        if ($oldest !== null && $oldest > self::ALERT_AFTER_SECONDS) {
            return ['level' => 'red', 'reason' => 'stale'];
        }

        if ($this->pendingTotal() > 0 && ! $this->heartbeat->isAlive()) {
            return ['level' => 'red', 'reason' => 'worker_down'];
        }

        return ['level' => 'green', 'reason' => null];
    }

    /**
     * @return list<array{uuid: string, queue: string, job: string, failed_at: string, error: string}>
     */
    public function failedJobs(int $limit = 20): array
    {
        return DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit($limit)
            ->get(['uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(fn ($row) => [
                'uuid' => (string) $row->uuid,
                'queue' => (string) $row->queue,
                // Le NOM de la classe seulement : le reste du payload peut porter
                // des donnees privees et ne sort jamais.
                'job' => class_basename((string) (json_decode((string) $row->payload, true)['displayName'] ?? '?')),
                'failed_at' => (string) $row->failed_at,
                'error' => Str::limit(strtok((string) $row->exception, "\n") ?: '', 180),
            ])
            ->all();
    }

    public function failedTotal(): int
    {
        return (int) DB::table('failed_jobs')->count();
    }

    /**
     * PDF d'article dont le texte n'est pas encore extrait.
     *
     * @return list<array{id: string, name: string, article: string|null, age_seconds: int}>
     */
    public function pendingPdfs(int $limit = 10): array
    {
        return BlogPostFile::query()
            ->withoutGlobalScopes()
            ->with(['blogPost' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'title', 'organization_id')])
            ->where('kind', BlogPostFile::KIND_PDF)
            ->whereNull('extracted_at')
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (BlogPostFile $file) => [
                'id' => (string) $file->id,
                'name' => (string) $file->original_name,
                'article' => $file->blogPost?->title,
                'age_seconds' => (int) max(0, now()->getTimestamp() - $file->created_at->getTimestamp()),
            ])
            ->all();
    }

    public function pendingPdfTotal(): int
    {
        return BlogPostFile::query()->withoutGlobalScopes()
            ->where('kind', BlogPostFile::KIND_PDF)->whereNull('extracted_at')->count();
    }

    /**
     * Articles qui attendent leur indexation (jobs de la file des articles),
     * avec leur titre. Les identifiants sont lus dans la commande serialisee,
     * qui ne porte que trois UUID ; rien d'autre n'en sort.
     *
     * @return list<array{title: string, age_seconds: int, running: bool}>
     */
    public function pendingArticles(int $limit = 10): array
    {
        $rows = DB::table('jobs')
            ->where('queue', DossierArticleIndexingDispatcher::DEDICATED_QUEUE)
            ->orderBy('id')
            ->limit($limit)
            ->get(['payload', 'created_at', 'reserved_at']);

        $ids = $rows->map(fn ($row) => $this->blogPostIdFrom((string) $row->payload))->filter()->unique()->values();
        $titles = BlogPost::query()->withoutGlobalScopes()->whereIn('id', $ids)->pluck('title', 'id');

        return $rows->map(fn ($row) => [
            'title' => (string) ($titles[$this->blogPostIdFrom((string) $row->payload)] ?? '—'),
            'age_seconds' => max(0, now()->getTimestamp() - (int) $row->created_at),
            'running' => $row->reserved_at !== null,
        ])->all();
    }

    private function blogPostIdFrom(string $payload): ?string
    {
        $command = (string) (json_decode($payload, true)['data']['command'] ?? '');

        return preg_match('/blogPostId";s:36:"([0-9a-f-]{36})"/', $command, $m) ? $m[1] : null;
    }
}
