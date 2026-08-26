<?php

namespace App\Console\Commands;

use App\Models\DossierBlogPost;
use App\Models\Organization;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * TASK-1307 : catch-up EXPLICITE et borne des Articles de Dossier d'UNE
 * Organization deja attaches mais jamais indexes — clone structurel de
 * `dossiers:index-files` (TASK-1268) pour les Articles.
 *
 * Pourquoi ce rattrapage est necessaire : `LoopRootDocumentService::designate()`
 * ne dispatchait aucune indexation avant TASK-1307 (corrige), et
 * `DossierArticleIndexer` rejetait tout document racine avant TASK-1307
 * (`listed_in_blog = false`, corrige). Les entrees deja attachees AVANT ces
 * deux correctifs sont donc restees jamais indexees : cette commande les
 * rattrape sans toucher au flux normal (qui, desormais, indexe correctement
 * a l'attache).
 *
 * Rien n'est extrait, chunke ni embarque ici : la commande SELECTIONNE et
 * DISPATCHE, c'est tout. L'idempotence est celle de `DossierArticleIndexer`
 * (`content_hash` par chunk + `WithoutOverlapping` sur le job) ; la gate
 * (`DossierSemanticSearchGate`) et le credential tenant (`ProviderResolver`)
 * restent juges dans le job, jamais contournes ici.
 *
 * Les jobs partent sur une queue DEDIEE, jamais `default` — meme regle que
 * `dossiers:index-files`.
 */
class DossiersIndexArticlesCommand extends Command
{
    protected $signature = 'dossiers:index-articles
        {organization : Slug ou id de l\'Organization (obligatoire)}
        {--dry-run : Compte et liste les Articles sans rien dispatcher}
        {--limit= : Nombre maximum d\'Articles retenus}
        {--queue='.DossierArticleIndexingDispatcher::DEDICATED_QUEUE.' : Queue des jobs (jamais `default`)}';

    protected $description = 'Planifie l’indexation IA des Articles de Dossier deja attaches d’une Organization sur une queue dédiée (TASK-1307)';

    public function handle(DossierArticleIndexingDispatcher $dispatcher): int
    {
        $organization = $this->resolveOrganization((string) $this->argument('organization'));

        if ($organization === null) {
            $this->error('Organization inconnue : '.(string) $this->argument('organization'));

            return self::FAILURE;
        }

        $queue = trim((string) $this->option('queue'));

        if ($queue === '' || $queue === 'default') {
            $this->error('Queue refusée : les jobs d’indexation ne partent jamais sur `default` (TASK-1307). Utiliser une queue dédiée.');

            return self::FAILURE;
        }

        $limit = $this->resolveLimit();

        if ($limit === false) {
            $this->error('--limit doit être un entier strictement positif.');

            return self::FAILURE;
        }

        $query = $this->eligibleEntries($organization);
        $eligibleTotal = (clone $query)->count();

        if ($limit !== null) {
            $query->limit($limit);
        }

        $entries = $query->get(['dossier_blog_posts.organization_id', 'dossier_blog_posts.dossier_id', 'dossier_blog_posts.blog_post_id', 'blog_posts.title']);
        $dryRun = (bool) $this->option('dry-run');

        $this->line('Base : '.$this->connectionLabel());
        $this->line("Organization : {$organization->slug} ({$organization->id})");
        $this->line("Articles éligibles (attachés, publiés, lisibles) : {$eligibleTotal}".($limit !== null ? " — retenus (--limit={$limit}) : {$entries->count()}" : ''));
        $this->line("Queue : {$queue}");
        $this->line('Mode : '.($dryRun ? 'DRY-RUN (aucun dispatch, aucun provider)' : 'DISPATCH'));

        if ($entries->isNotEmpty()) {
            $this->table(
                ['#', 'blog_post_id', 'dossier_id', 'title'],
                $entries->values()->map(fn (DossierBlogPost $entry, int $index): array => [
                    $index + 1,
                    $entry->blog_post_id,
                    $entry->dossier_id,
                    Str::limit((string) $entry->blogPost?->title, 60),
                ])->all(),
            );
        }

        if ($dryRun) {
            $this->info("{$entries->count()} Article(s) seraient planifiés sur la queue `{$queue}`. Rien n’a été dispatché.");

            return self::SUCCESS;
        }

        $dispatched = $dispatcher->dispatchForEntries($entries, $queue);

        $this->info("{$dispatched} job(s) d’indexation planifié(s) sur la queue `{$queue}` via le pipeline métier.");

        return self::SUCCESS;
    }

    private function resolveOrganization(string $reference): ?Organization
    {
        $reference = trim($reference);

        if ($reference === '') {
            return null;
        }

        $query = Organization::query()->where('slug', $reference);

        if (Str::isUuid($reference)) {
            $query->orWhere($query->getModel()->getKeyName(), $reference);
        }

        return $query->first();
    }

    /**
     * @return Builder<DossierBlogPost>
     */
    private function eligibleEntries(Organization $organization): Builder
    {
        return DossierBlogPost::query()
            ->where('dossier_blog_posts.organization_id', $organization->id)
            ->join('blog_posts', 'blog_posts.id', '=', 'dossier_blog_posts.blog_post_id')
            // Meme eligibilite que `DossierArticleIndexer::findPublishedPost()`
            // (`publiclyReadable()`, TASK-1307) : PAS `listed_in_blog`, un
            // filtre d'affichage, sans rapport avec l'indexation.
            ->where('blog_posts.organization_id', $organization->id)
            ->where('blog_posts.status', 'published')
            ->whereNotNull('blog_posts.published_at')
            ->where('blog_posts.published_at', '<=', now())
            ->whereNull('blog_posts.deleted_at')
            ->orderBy('dossier_blog_posts.created_at')
            ->orderBy('dossier_blog_posts.id');
    }

    /**
     * @return int|null|false null = pas de limite ; false = valeur invalide
     */
    private function resolveLimit(): int|null|false
    {
        $raw = $this->option('limit');

        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        if (! ctype_digit(trim((string) $raw)) || (int) $raw < 1) {
            return false;
        }

        return (int) $raw;
    }

    private function connectionLabel(): string
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        return "{$connection} / {$database}";
    }
}
