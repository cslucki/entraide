<?php

namespace App\Services\Dossiers;

use App\Ai\ProviderResolver;
use App\Listeners\RecordSdkEmbeddingsInvocation;
use App\Models\Dossier;
use App\Models\DossierChunk;
use App\Models\DossierFile;
use App\Models\Organization;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Clone structurel de DossierArticleIndexer pour les fichiers TXT/Markdown
 * (TASK-1216) : meme doctrine P4/staleness (TASK-1214), meme forme, source
 * distincte (`dossier_file_id` au lieu de `blog_post_id`). Deliberement une
 * classe separee — DossierArticleIndexer reste intouche, zero risque de
 * regression sur l'ingestion Article.
 */
class DossierFileIndexer
{
    public function __construct(
        private readonly DossierSemanticSearchGate $gate,
        private readonly FileContentExtractor $extractor,
        private readonly ArticleChunker $chunker,
        private readonly DossierChunkEmbeddingService $embeddings,
        private readonly ProviderResolver $providers,
    ) {}

    public function synchronize(string $organizationId, string $dossierId, string $fileId): int
    {
        return $this->withOrganizationContext($organizationId, function (?Organization $organization) use ($organizationId, $dossierId, $fileId): int {
            if (! $organization || ! $this->gate->isEnabledFor($organizationId)) {
                return $this->deleteChunks($organizationId, $dossierId, $fileId);
            }

            $dossier = $this->findDossier($organizationId, $dossierId);
            $file = $this->findEligibleFile($organizationId, $dossierId, $fileId);

            if (! $dossier || ! $file) {
                return $this->deleteChunks($organizationId, $dossierId, $fileId);
            }

            $raw = $this->readFile($file);

            if ($raw === null) {
                return $this->deleteChunks($organizationId, $dossierId, $fileId);
            }

            $text = $this->extractor->extract($raw, (string) $file->mime_type, (string) $file->original_name);

            if ($text === null) {
                // Format non supporte / encodage invalide / binaire deguise /
                // taille excessive : jamais de chunk partiel.
                return $this->deleteChunks($organizationId, $dossierId, $fileId);
            }

            $chunks = $this->chunker->chunk($text);

            if ($chunks === []) {
                return $this->deleteChunks($organizationId, $dossierId, $fileId);
            }

            $provider = trim((string) config('ai.default_for_embeddings', 'openai'));
            $model = trim((string) config("ai.providers.{$provider}.models.embeddings.default", 'text-embedding-3-small'));

            // Contenu inchange : index historique conserve sans appel provider,
            // meme sans credential P4 disponible (TASK-1214).
            if ($this->alreadyIndexed($organizationId, $dossierId, $fileId, $chunks, $provider, $model)) {
                return count($chunks);
            }

            // TASK-1214 : l'ingestion passe par le credential de l'Organization
            // (P4), jamais par la cle plateforme. Sans instance tenant, aucun
            // nouvel embedding n'est produit.
            $instance = $this->providers->resolveEmbeddingInstance($organizationId);

            if ($instance === null) {
                // Contenu modifie (alreadyIndexed est faux) mais pas de
                // credential : l'ancienne representation ne doit pas continuer
                // a etre servie comme actuelle. Reindexable quand P4 revient.
                return $this->deleteChunks($organizationId, $dossierId, $fileId);
            }

            Context::add(RecordSdkEmbeddingsInvocation::TRACE_CONTEXT_KEY, [
                'organization_id' => $organizationId,
                'scenario_id' => 'dossier_embeddings_index',
                'metadata' => [
                    'dossier_id' => $dossierId,
                    'dossier_file_id' => $fileId,
                    'chunk_count' => count($chunks),
                ],
            ]);

            try {
                $embeddingResult = $this->embeddings->embed(array_column($chunks, 'content'), $instance);
            } catch (\Throwable $exception) {
                // Echec APRES un changement detecte : la version stockee est
                // desormais perimee, on ne la sert pas comme actuelle.
                $this->deleteChunks($organizationId, $dossierId, $fileId);
                throw $exception;
            } finally {
                Context::forget(RecordSdkEmbeddingsInvocation::TRACE_CONTEXT_KEY);
            }

            if (count($embeddingResult['embeddings']) !== count($chunks)) {
                throw new RuntimeException('Embedding count does not match generated chunk count.');
            }

            if ($this->findEligibleFile($organizationId, $dossierId, $fileId) === null) {
                return $this->deleteChunks($organizationId, $dossierId, $fileId);
            }

            return DB::transaction(function () use ($organizationId, $dossierId, $fileId, $chunks, $embeddingResult): int {
                $this->deleteChunks($organizationId, $dossierId, $fileId);

                $indexedAt = now();

                foreach ($chunks as $index => $chunk) {
                    if (! array_key_exists($index, $embeddingResult['embeddings'])) {
                        throw new RuntimeException("Missing embedding vector for chunk index {$index}.");
                    }

                    DossierChunk::create([
                        'organization_id' => $organizationId,
                        'dossier_id' => $dossierId,
                        'blog_post_id' => null,
                        'dossier_file_id' => $fileId,
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

    private function findEligibleFile(string $organizationId, string $dossierId, string $fileId): ?DossierFile
    {
        return DossierFile::query()
            ->whereKey($fileId)
            ->where('organization_id', $organizationId)
            ->where('dossier_id', $dossierId)
            ->first();
    }

    private function readFile(DossierFile $file): ?string
    {
        try {
            $contents = Storage::disk($file->disk)->get($file->path);
        } catch (\Throwable) {
            return null;
        }

        return is_string($contents) ? $contents : null;
    }

    private function deleteChunks(string $organizationId, string $dossierId, string $fileId): int
    {
        DossierChunk::query()
            ->where('organization_id', $organizationId)
            ->where('dossier_id', $dossierId)
            ->where('dossier_file_id', $fileId)
            ->delete();

        return 0;
    }

    /**
     * @param  array<int, array{chunk_index: int, content: string, content_hash: string, token_count: int}>  $chunks
     */
    private function alreadyIndexed(
        string $organizationId,
        string $dossierId,
        string $fileId,
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
            ->where('dossier_file_id', $fileId)
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
