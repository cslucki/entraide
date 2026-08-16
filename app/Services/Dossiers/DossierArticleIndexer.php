<?php

namespace App\Services\Dossiers;

use App\Ai\ProviderResolver;
use App\Listeners\RecordSdkEmbeddingsInvocation;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierChunk;
use App\Models\Organization;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DossierArticleIndexer
{
    public function __construct(
        private readonly DossierSemanticSearchGate $gate,
        private readonly ArticleTextExtractor $extractor,
        private readonly ArticleChunker $chunker,
        private readonly DossierChunkEmbeddingService $embeddings,
        private readonly ProviderResolver $providers,
    ) {}

    public function synchronize(string $organizationId, string $dossierId, string $blogPostId): int
    {
        return $this->withOrganizationContext($organizationId, function (?Organization $organization) use ($organizationId, $dossierId, $blogPostId): int {
            if (! $organization || ! $this->gate->isEnabledFor($organizationId)) {
                return $this->deleteChunks($organizationId, $dossierId, $blogPostId);
            }

            $dossier = $this->findDossier($organizationId, $dossierId);
            $post = $this->findPublishedPost($organizationId, $blogPostId);

            if (! $dossier || ! $post || ! $this->isAttached($organizationId, $dossierId, $blogPostId)) {
                return $this->deleteChunks($organizationId, $dossierId, $blogPostId);
            }

            $text = $this->extractor->extract((string) $post->content);
            $chunks = $this->chunker->chunk($text);

            if ($chunks === []) {
                return $this->deleteChunks($organizationId, $dossierId, $blogPostId);
            }

            $provider = trim((string) config('ai.default_for_embeddings', 'openai'));
            $model = trim((string) config("ai.providers.{$provider}.models.embeddings.default", 'text-embedding-3-small'));

            // Contenu inchange : on ne retouche rien, meme si le credential
            // tenant est absent. Un index historique valide, de la meme famille
            // d'embedding, reste servi — son ancien credential plateforme ne le
            // rend pas obsolete (TASK-1214).
            if ($this->alreadyIndexed($organizationId, $dossierId, $blogPostId, $chunks, $provider, $model)) {
                return count($chunks);
            }

            // TASK-1214 : l'ingestion passe par le credential de l'Organization
            // (P4), jamais par la cle plateforme. Sans instance tenant, aucun
            // nouvel embedding n'est produit.
            $instance = $this->providers->resolveEmbeddingInstance($organizationId);

            if ($instance === null) {
                // Le contenu a change (alreadyIndexed est faux) mais on ne peut
                // pas le reindexer : l'ancienne representation ne doit surtout
                // pas continuer a etre servie comme si elle etait a jour. On la
                // retire via le mecanisme de lifecycle existant. Elle sera
                // reindexee quand un credential P4 sera disponible et que la
                // source sera de nouveau synchronisee.
                return $this->deleteChunks($organizationId, $dossierId, $blogPostId);
            }

            Context::add(RecordSdkEmbeddingsInvocation::TRACE_CONTEXT_KEY, [
                'organization_id' => $organizationId,
                'scenario_id' => 'dossier_embeddings_index',
                'metadata' => [
                    'dossier_id' => $dossierId,
                    'blog_post_id' => $blogPostId,
                    'chunk_count' => count($chunks),
                ],
            ]);

            try {
                $embeddingResult = $this->embeddings->embed(array_column($chunks, 'content'), $instance);
            } catch (\Throwable $exception) {
                // Echec d'embedding APRES un changement detecte : la version
                // stockee est desormais perimee. On ne la sert pas comme
                // actuelle — on la retire, puis on relance pour l'observabilite
                // et un eventuel retry (qui reindexera).
                $this->deleteChunks($organizationId, $dossierId, $blogPostId);
                throw $exception;
            } finally {
                Context::forget(RecordSdkEmbeddingsInvocation::TRACE_CONTEXT_KEY);
            }

            if (count($embeddingResult['embeddings']) !== count($chunks)) {
                throw new RuntimeException('Embedding count does not match generated chunk count.');
            }

            if (! $this->isEligible($organizationId, $dossierId, $blogPostId)) {
                return $this->deleteChunks($organizationId, $dossierId, $blogPostId);
            }

            return DB::transaction(function () use ($organizationId, $dossierId, $blogPostId, $chunks, $embeddingResult): int {
                $this->deleteChunks($organizationId, $dossierId, $blogPostId);

                $indexedAt = now();

                foreach ($chunks as $index => $chunk) {
                    if (! array_key_exists($index, $embeddingResult['embeddings'])) {
                        throw new RuntimeException("Missing embedding vector for chunk index {$index}.");
                    }

                    DossierChunk::create([
                        'organization_id' => $organizationId,
                        'dossier_id' => $dossierId,
                        'blog_post_id' => $blogPostId,
                        'chunk_index' => $chunk['chunk_index'],
                        'content' => $chunk['content'],
                        'content_hash' => $chunk['content_hash'],
                        'token_count' => $chunk['token_count'],
                        'embedding' => $embeddingResult['embeddings'][$index],
                        'embedding_provider' => $embeddingResult['provider'],
                        'embedding_model' => $embeddingResult['model'],
                        'indexed_at' => $indexedAt,
                    ]);
                }

                return count($chunks);
            });
        });
    }

    private function findDossier(string $organizationId, string $dossierId): ?Dossier
    {
        return Dossier::query()
            ->whereKey($dossierId)
            ->where('organization_id', $organizationId)
            ->first();
    }

    private function findPublishedPost(string $organizationId, string $blogPostId): ?BlogPost
    {
        return BlogPost::query()
            ->whereKey($blogPostId)
            ->where('organization_id', $organizationId)
            ->published()
            ->first();
    }

    private function isAttached(string $organizationId, string $dossierId, string $blogPostId): bool
    {
        return DossierBlogPost::query()
            ->where('organization_id', $organizationId)
            ->where('dossier_id', $dossierId)
            ->where('blog_post_id', $blogPostId)
            ->exists();
    }

    private function isEligible(string $organizationId, string $dossierId, string $blogPostId): bool
    {
        return $this->findDossier($organizationId, $dossierId) !== null
            && $this->findPublishedPost($organizationId, $blogPostId) !== null
            && $this->isAttached($organizationId, $dossierId, $blogPostId);
    }

    private function deleteChunks(string $organizationId, string $dossierId, string $blogPostId): int
    {
        DossierChunk::query()
            ->where('organization_id', $organizationId)
            ->where('dossier_id', $dossierId)
            ->where('blog_post_id', $blogPostId)
            ->delete();

        return 0;
    }

    /**
     * @param  array<int, array{chunk_index: int, content: string, content_hash: string, token_count: int}>  $chunks
     */
    private function alreadyIndexed(
        string $organizationId,
        string $dossierId,
        string $blogPostId,
        array $chunks,
        string $provider,
        string $model,
    ): bool {
        if ($provider === '' || $model === '') {
            return false;
        }

        $stored = DossierChunk::query()
            ->where('organization_id', $organizationId)
            ->where('dossier_id', $dossierId)
            ->where('blog_post_id', $blogPostId)
            ->where('embedding_provider', $provider)
            ->where('embedding_model', $model)
            ->orderBy('chunk_index')
            ->get(['chunk_index', 'content_hash']);

        if ($stored->count() !== count($chunks)) {
            return false;
        }

        return $stored->every(function (DossierChunk $storedChunk, int $index) use ($chunks): bool {
            $expected = $chunks[$index] ?? null;

            return is_array($expected)
                && $storedChunk->chunk_index === $expected['chunk_index']
                && hash_equals($storedChunk->content_hash, $expected['content_hash']);
        });
    }

    /**
     * @template TReturn
     *
     * @param  callable(?Organization): TReturn  $callback
     * @return TReturn
     */
    private function withOrganizationContext(string $organizationId, callable $callback): mixed
    {
        $hadPrevious = app()->bound('current_organization');
        $previous = $hadPrevious ? app('current_organization') : null;
        $organization = Organization::query()->whereKey($organizationId)->first();

        if ($organization) {
            app()->instance('current_organization', $organization);
        } elseif ($hadPrevious) {
            app()->forgetInstance('current_organization');
        }

        try {
            return $callback($organization);
        } finally {
            if ($hadPrevious) {
                app()->instance('current_organization', $previous);
            } else {
                app()->forgetInstance('current_organization');
            }
        }
    }
}
