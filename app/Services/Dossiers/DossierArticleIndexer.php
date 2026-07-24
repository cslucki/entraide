<?php

namespace App\Services\Dossiers;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierChunk;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DossierArticleIndexer
{
    public function __construct(
        private readonly DossierSemanticSearchGate $gate,
        private readonly ArticleTextExtractor $extractor,
        private readonly ArticleChunker $chunker,
        private readonly DossierChunkEmbeddingService $embeddings,
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

            $embeddingResult = $this->embeddings->embed(array_column($chunks, 'content'));

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
