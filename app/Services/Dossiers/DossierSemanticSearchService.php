<?php

namespace App\Services\Dossiers;

use App\Listeners\RecordSdkEmbeddingsInvocation;
use App\Models\Dossier;
use App\Support\Ai\AiCorrelation;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class DossierSemanticSearchService
{
    public function __construct(
        private DossierSemanticSearchGate $gate,
        private DossierChunkEmbeddingService $embeddings,
    ) {}

    /**
     * @return array<int, array{blog_post_id: string, title: string, slug: string, chunk_index: int, content: string, distance: float}>
     */
    public function search(string $organizationId, string $dossierId, string $query, int $limit = 5): array
    {
        $query = trim($query);

        if ($query === '') {
            throw new InvalidArgumentException('Semantic search query must not be empty.');
        }

        if ($limit < 1 || $limit > 5) {
            throw new InvalidArgumentException('Semantic search limit must be between 1 and 5.');
        }

        if (! $this->gate->isEnabledFor($organizationId)) {
            return [];
        }

        if (! $this->dossierExists($organizationId, $dossierId)) {
            return [];
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Dossier semantic search requires PostgreSQL pgvector.');
        }

        AiCorrelation::id();

        Context::add(RecordSdkEmbeddingsInvocation::TRACE_CONTEXT_KEY, [
            'organization_id' => $organizationId,
            'scenario_id' => 'dossier_embeddings_search',
            'metadata' => [
                'dossier_id' => $dossierId,
            ],
        ]);

        try {
            $embeddingResult = $this->embeddings->embed([$query]);
        } finally {
            Context::forget(RecordSdkEmbeddingsInvocation::TRACE_CONTEXT_KEY);
        }

        $embedding = $embeddingResult['embeddings'][0] ?? null;

        if (! is_array($embedding)) {
            throw new RuntimeException('Semantic search query embedding is missing.');
        }

        return DB::table('dossier_chunks')
            ->join('blog_posts', 'blog_posts.id', '=', 'dossier_chunks.blog_post_id')
            ->join('dossier_blog_posts', function ($join) use ($organizationId, $dossierId) {
                $join->on('dossier_blog_posts.blog_post_id', '=', 'blog_posts.id')
                    ->where('dossier_blog_posts.organization_id', '=', $organizationId)
                    ->where('dossier_blog_posts.dossier_id', '=', $dossierId);
            })
            ->join('dossiers', function ($join) use ($organizationId, $dossierId) {
                $join->on('dossiers.id', '=', 'dossier_chunks.dossier_id')
                    ->where('dossiers.organization_id', '=', $organizationId)
                    ->where('dossiers.id', '=', $dossierId)
                    ->whereNull('dossiers.deleted_at');
            })
            ->where('dossier_chunks.organization_id', $organizationId)
            ->where('dossier_chunks.dossier_id', $dossierId)
            ->where('dossier_chunks.embedding_provider', $embeddingResult['provider'])
            ->where('dossier_chunks.embedding_model', $embeddingResult['model'])
            ->where('blog_posts.organization_id', $organizationId)
            ->where('blog_posts.status', 'published')
            ->whereNotNull('blog_posts.published_at')
            ->where('blog_posts.published_at', '<=', now())
            ->whereNull('blog_posts.deleted_at')
            ->select([
                'blog_posts.id as blog_post_id',
                'blog_posts.title',
                'blog_posts.slug',
                'dossier_chunks.chunk_index',
                'dossier_chunks.content',
            ])
            ->selectVectorDistance('dossier_chunks.embedding', $embedding, as: 'distance')
            ->orderByVectorDistance('dossier_chunks.embedding', $embedding)
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'blog_post_id' => (string) $row->blog_post_id,
                'title' => (string) $row->title,
                'slug' => (string) $row->slug,
                'chunk_index' => (int) $row->chunk_index,
                'content' => (string) $row->content,
                'distance' => (float) $row->distance,
            ])
            ->all();
    }

    /**
     * TASK-1213 : recherche sur PLUSIEURS Dossiers deja autorises, avec UN seul
     * embedding de requete. Le perimetre est intrinseque a la requete SQL
     * (`whereIn dossier_id` + Organization sur chaque table jointe) : rien
     * n'est filtre apres coup, rien d'un autre tenant ne peut sortir. Les
     * Dossiers candidats sont ceux que l'appelant a DEJA verifies comme
     * visibles par l'utilisateur.
     *
     * `$embeddingInstance` : instance SDK du tenant (credential P4) ; null =
     * configuration d'embeddings de la plateforme (indexation, bench).
     *
     * @param  list<string>  $dossierIds
     * @return array<int, array{chunk_id: string, dossier_id: string, dossier_name: string, blog_post_id: string, title: string, slug: string, chunk_index: int, content: string, distance: float}>
     */
    public function searchAcrossDossiers(
        string $organizationId,
        array $dossierIds,
        string $query,
        int $limit = 5,
        ?string $embeddingInstance = null,
        array $traceMetadata = [],
    ): array {
        $query = trim($query);

        if ($query === '') {
            throw new InvalidArgumentException('Semantic search query must not be empty.');
        }

        if ($limit < 1 || $limit > 5) {
            throw new InvalidArgumentException('Semantic search limit must be between 1 and 5.');
        }

        $dossierIds = array_values(array_unique(array_filter(array_map('strval', $dossierIds))));

        if ($dossierIds === [] || ! $this->gate->isEnabledFor($organizationId)) {
            return [];
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Dossier semantic search requires PostgreSQL pgvector.');
        }

        AiCorrelation::id();

        Context::add(RecordSdkEmbeddingsInvocation::TRACE_CONTEXT_KEY, [
            'organization_id' => $organizationId,
            'scenario_id' => 'dossier_embeddings_search',
            'metadata' => array_merge(['dossier_count' => count($dossierIds)], $traceMetadata),
        ]);

        try {
            $embeddingResult = $this->embeddings->embed([$query], $embeddingInstance);
        } finally {
            Context::forget(RecordSdkEmbeddingsInvocation::TRACE_CONTEXT_KEY);
        }

        $embedding = $embeddingResult['embeddings'][0] ?? null;

        if (! is_array($embedding)) {
            throw new RuntimeException('Semantic search query embedding is missing.');
        }

        return DB::table('dossier_chunks')
            ->join('blog_posts', 'blog_posts.id', '=', 'dossier_chunks.blog_post_id')
            ->join('dossier_blog_posts', function ($join) use ($organizationId) {
                $join->on('dossier_blog_posts.blog_post_id', '=', 'blog_posts.id')
                    ->on('dossier_blog_posts.dossier_id', '=', 'dossier_chunks.dossier_id')
                    ->where('dossier_blog_posts.organization_id', '=', $organizationId);
            })
            ->join('dossiers', function ($join) use ($organizationId) {
                $join->on('dossiers.id', '=', 'dossier_chunks.dossier_id')
                    ->where('dossiers.organization_id', '=', $organizationId)
                    ->whereNull('dossiers.deleted_at');
            })
            ->where('dossier_chunks.organization_id', $organizationId)
            ->whereIn('dossier_chunks.dossier_id', $dossierIds)
            ->where('dossier_chunks.embedding_provider', $embeddingResult['provider'])
            ->where('dossier_chunks.embedding_model', $embeddingResult['model'])
            ->where('blog_posts.organization_id', $organizationId)
            ->where('blog_posts.status', 'published')
            ->whereNotNull('blog_posts.published_at')
            ->where('blog_posts.published_at', '<=', now())
            ->whereNull('blog_posts.deleted_at')
            ->select([
                'dossier_chunks.id as chunk_id',
                'dossier_chunks.dossier_id',
                'dossiers.name as dossier_name',
                'blog_posts.id as blog_post_id',
                'blog_posts.title',
                'blog_posts.slug',
                'dossier_chunks.chunk_index',
                'dossier_chunks.content',
            ])
            ->selectVectorDistance('dossier_chunks.embedding', $embedding, as: 'distance')
            ->orderByVectorDistance('dossier_chunks.embedding', $embedding)
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'chunk_id' => (string) $row->chunk_id,
                'dossier_id' => (string) $row->dossier_id,
                'dossier_name' => (string) $row->dossier_name,
                'blog_post_id' => (string) $row->blog_post_id,
                'title' => (string) $row->title,
                'slug' => (string) $row->slug,
                'chunk_index' => (int) $row->chunk_index,
                'content' => (string) $row->content,
                'distance' => (float) $row->distance,
            ])
            ->all();
    }

    private function dossierExists(string $organizationId, string $dossierId): bool
    {
        return Dossier::query()
            ->whereKey($dossierId)
            ->where('organization_id', $organizationId)
            ->exists();
    }
}
