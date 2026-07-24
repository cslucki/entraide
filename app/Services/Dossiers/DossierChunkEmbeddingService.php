<?php

namespace App\Services\Dossiers;

use InvalidArgumentException;
use Laravel\Ai\Embeddings;
use RuntimeException;

class DossierChunkEmbeddingService
{
    /**
     * Generate embeddings for plain-text chunks in one SDK batch.
     *
     * @param  array<int, string>  $texts
     * @return array{provider: string, model: string, dimensions: int, embeddings: array<int, array<int, float|int>>}
     */
    public function embed(array $texts): array
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

        $response = Embeddings::for($texts)
            ->dimensions($dimensions)
            ->generate($provider, $model);

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
            'provider' => $response->meta->provider ?: $provider,
            'model' => $response->meta->model ?: $model,
            'dimensions' => $dimensions,
            'embeddings' => $embeddings,
        ];
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
