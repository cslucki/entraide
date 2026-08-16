<?php

namespace App\Services\Dossiers;

use App\Listeners\RecordSdkEmbeddingsInvocation;
use InvalidArgumentException;
use Laravel\Ai\Embeddings;
use RuntimeException;
use Throwable;

class DossierChunkEmbeddingService
{
    /**
     * Generate embeddings for plain-text chunks in one SDK batch.
     *
     * @param  array<int, string>  $texts
     * @return array{provider: string, model: string, dimensions: int, embeddings: array<int, array<int, float|int>>}
     */
    /**
     * TASK-1213 : `$instance` designe l'instance Laravel AI SDK a invoquer
     * (celle qui porte le credential de l'Organization, voir ProviderResolver).
     * Le `provider` renvoye reste la famille configuree pour l'index : c'est
     * elle qui est comparee a `dossier_chunks.embedding_provider`.
     */
    public function embed(array $texts, ?string $instance = null): array
    {
        $provider = $this->configuredProvider();
        $model = $this->configuredModel($provider);
        $dimensions = $this->configuredDimensions($provider);

        $this->validateTexts($texts);

        if ($texts === []) {
            return [
                'provider' => $provider,
                'model' => $model,
                'dimensions' => $dimensions,
                'embeddings' => [],
            ];
        }

        try {
            $response = Embeddings::for($texts)
                ->dimensions($dimensions)
                ->generate($instance ?? $provider, $model);
        } catch (Throwable $exception) {
            // TASK-1200 : le SDK ne dispatche aucun événement d'échec (voir
            // RecordSdkEmbeddingsInvocation). C'est le seul endroit qui peut
            // observer un échec réel, donc le seul qui peut l'enregistrer —
            // sans jamais changer le comportement fonctionnel : on relance
            // exactement l'exception d'origine, inchangée.
            RecordSdkEmbeddingsInvocation::recordFailure($provider, $model);

            throw $exception;
        }

        $embeddings = $response->embeddings;

        if (count($embeddings) !== count($texts)) {
            throw new RuntimeException('Embedding response count does not match input count.');
        }

        foreach ($embeddings as $index => $embedding) {
            if (! is_array($embedding) || count($embedding) !== $dimensions) {
                throw new RuntimeException("Embedding vector at index {$index} does not match configured dimensions.");
            }
        }

        return [
            'provider' => $instance !== null ? $provider : ($response->meta->provider ?: $provider),
            'model' => $response->meta->model ?: $model,
            'dimensions' => $dimensions,
            'embeddings' => $embeddings,
        ];
    }

    /**
     * Famille du provider d'embeddings de l'index (`ai.default_for_embeddings`).
     */
    public function provider(): string
    {
        return $this->configuredProvider();
    }

    private function configuredProvider(): string
    {
        $provider = trim((string) config('ai.default_for_embeddings', 'openai'));

        if ($provider === '') {
            throw new InvalidArgumentException('Embedding provider must be configured.');
        }

        return $provider;
    }

    private function configuredModel(string $provider): string
    {
        $model = trim((string) config("ai.providers.{$provider}.models.embeddings.default", 'text-embedding-3-small'));

        if ($model === '') {
            throw new InvalidArgumentException('Embedding model must be configured.');
        }

        return $model;
    }

    private function configuredDimensions(string $provider): int
    {
        $dimensions = (int) config("ai.providers.{$provider}.models.embeddings.dimensions", 1536);

        if ($dimensions <= 0) {
            throw new InvalidArgumentException('Embedding dimensions must be a positive integer.');
        }

        return $dimensions;
    }

    /**
     * @param  array<int, mixed>  $texts
     */
    private function validateTexts(array $texts): void
    {
        if (! array_is_list($texts)) {
            throw new InvalidArgumentException('Embedding texts must be a list.');
        }

        foreach ($texts as $index => $text) {
            if (! is_string($text) || trim($text) === '') {
                throw new InvalidArgumentException("Embedding text at index {$index} must be a non-empty string.");
            }
        }
    }
}
