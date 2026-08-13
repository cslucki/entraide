<?php

namespace App\Console\Commands;

use App\Models\DossierBlogPost;
use App\Models\Organization;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use App\Support\AiValidation\AiValidationDatabaseGuard;
use Illuminate\Console\Command;
use RuntimeException;

class AiValidationIndexArtSciLabCommand extends Command
{
    private const ORGANIZATION_SLUG = 'artscilab-demo';

    private const MAX_EXTERNAL_INVOCATIONS = 25;

    private const PROVIDER = 'openrouter';

    private const MODEL = 'openai/text-embedding-3-small';

    private const DIMENSIONS = 1536;

    protected $signature = 'ai-validation:index-artscilab';

    protected $description = 'Planifie explicitement l’indexation IA ArtSciLab dans le banc PostgreSQL dédié';

    public function handle(DossierArticleIndexingDispatcher $dispatcher): int
    {
        if (! app()->environment('ai-validation')) {
            throw new RuntimeException('Cette commande exige APP_ENV=ai-validation.');
        }

        AiValidationDatabaseGuard::assertSafe(AiValidationDatabaseGuard::ALLOWED_CONNECTION);
        $this->assertEmbeddingConfiguration();

        $organization = Organization::query()->where('slug', self::ORGANIZATION_SLUG)->firstOrFail();
        $entries = DossierBlogPost::query()
            ->where('organization_id', $organization->id)
            ->orderBy('dossier_id')
            ->orderBy('blog_post_id')
            ->get(['organization_id', 'dossier_id', 'blog_post_id']);

        $invocationMaximum = DossierBlogPost::query()
            ->join('blog_posts', 'blog_posts.id', '=', 'dossier_blog_posts.blog_post_id')
            ->where('dossier_blog_posts.organization_id', $organization->id)
            ->where('blog_posts.organization_id', $organization->id)
            ->where('blog_posts.status', 'published')
            ->whereNotNull('blog_posts.published_at')
            ->where('blog_posts.published_at', '<=', now())
            ->whereNull('blog_posts.deleted_at')
            ->count();

        if ($invocationMaximum > self::MAX_EXTERNAL_INVOCATIONS) {
            throw new RuntimeException(
                "Indexation refusée : {$invocationMaximum} invocations SDK potentielles dépassent la limite de ".self::MAX_EXTERNAL_INVOCATIONS.'.'
            );
        }

        $this->line('Base : '.AiValidationDatabaseGuard::ALLOWED_DATABASE);
        $this->line("Organization : {$organization->slug} ({$organization->id})");
        $this->line("Articles liés à indexer : {$invocationMaximum}");
        $this->line("Maximum d’invocations SDK externes : {$invocationMaximum}/".self::MAX_EXTERNAL_INVOCATIONS);

        $dispatched = $dispatcher->dispatchForEntries($entries);

        $this->info("{$dispatched} job(s) d’indexation planifié(s) via le pipeline métier.");

        return self::SUCCESS;
    }

    private function assertEmbeddingConfiguration(): void
    {
        $provider = config('ai.default_for_embeddings');
        $model = config('ai.providers.openrouter.models.embeddings.default');
        $dimensions = config('ai.providers.openrouter.models.embeddings.dimensions');

        if ($provider !== self::PROVIDER || $model !== self::MODEL || $dimensions !== self::DIMENSIONS) {
            throw new RuntimeException(
                'Configuration embeddings refusée : openrouter / openai/text-embedding-3-small / 1536 exigé.'
            );
        }

        if (! filled(config('ai.providers.openrouter.key'))) {
            throw new RuntimeException('OPENROUTER_API_KEY manque dans l’environnement ai-validation.');
        }
    }
}
