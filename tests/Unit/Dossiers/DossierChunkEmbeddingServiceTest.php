<?php

namespace Tests\Unit\Dossiers;

use App\Services\Dossiers\DossierChunkEmbeddingService;
use InvalidArgumentException;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use RuntimeException;
use Tests\TestCase;

class DossierChunkEmbeddingServiceTest extends TestCase
{
    public function test_it_generates_a_single_batch_of_configured_embeddings_without_network(): void
    {
        $this->configureEmbeddings(dimensions: 1536);

        Embeddings::fake(function (EmbeddingsPrompt $prompt): array {
            return array_map(
                fn (int $index): array => array_fill(0, $prompt->dimensions, ($index + 1) / 10),
                array_keys($prompt->inputs),
            );
        })->preventStrayEmbeddings();

        $result = (new DossierChunkEmbeddingService)->embed([
            'first exact chunk',
            'second exact chunk',
            'third exact chunk',
        ]);

        $this->assertSame('openai', $result['provider']);
        $this->assertSame('text-embedding-3-small', $result['model']);
        $this->assertSame(1536, $result['dimensions']);
        $this->assertCount(3, $result['embeddings']);
        $this->assertSame(0.1, $result['embeddings'][0][0]);
        $this->assertSame(0.2, $result['embeddings'][1][0]);
        $this->assertSame(0.3, $result['embeddings'][2][0]);

        foreach ($result['embeddings'] as $embedding) {
            $this->assertCount(1536, $embedding);
        }

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === [
            'first exact chunk',
            'second exact chunk',
            'third exact chunk',
        ]
            && count($prompt) === 3
            && $prompt->provider->name() === 'openai'
            && $prompt->model === 'text-embedding-3-small'
            && $prompt->dimensions === 1536);
    }

    public function test_empty_text_list_returns_no_embeddings_without_sdk_generation(): void
    {
        $this->configureEmbeddings(dimensions: 8);

        Embeddings::fake(fn (): array => throw new RuntimeException('SDK should not be called.'))
            ->preventStrayEmbeddings();

        $result = (new DossierChunkEmbeddingService)->embed([]);

        $this->assertSame('openai', $result['provider']);
        $this->assertSame('text-embedding-3-small', $result['model']);
        $this->assertSame(8, $result['dimensions']);
        $this->assertSame([], $result['embeddings']);

        Embeddings::assertNothingGenerated();
    }

    public function test_it_rejects_non_string_inputs(): void
    {
        $this->configureEmbeddings(dimensions: 8);

        Embeddings::fake()->preventStrayEmbeddings();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Embedding text at index 1 must be a non-empty string.');

        (new DossierChunkEmbeddingService)->embed(['valid', 123]);
    }

    public function test_it_rejects_blank_strings(): void
    {
        $this->configureEmbeddings(dimensions: 8);

        Embeddings::fake()->preventStrayEmbeddings();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Embedding text at index 1 must be a non-empty string.');

        (new DossierChunkEmbeddingService)->embed(['valid', '   ']);
    }

    public function test_it_rejects_non_list_text_arrays(): void
    {
        $this->configureEmbeddings(dimensions: 8);

        Embeddings::fake()->preventStrayEmbeddings();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Embedding texts must be a list.');

        (new DossierChunkEmbeddingService)->embed(['first' => 'valid']);
    }

    public function test_it_rejects_invalid_dimensions(): void
    {
        $this->configureEmbeddings(dimensions: 0);

        Embeddings::fake()->preventStrayEmbeddings();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Embedding dimensions must be a positive integer.');

        (new DossierChunkEmbeddingService)->embed(['valid']);
    }

    public function test_it_rejects_empty_provider(): void
    {
        $this->configureEmbeddings(dimensions: 8);
        config()->set('ai.default_for_embeddings', '   ');

        Embeddings::fake()->preventStrayEmbeddings();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Embedding provider must be configured.');

        (new DossierChunkEmbeddingService)->embed(['valid']);
    }

    public function test_it_rejects_empty_model(): void
    {
        $this->configureEmbeddings(dimensions: 8);
        config()->set('ai.providers.openai.models.embeddings.default', '   ');

        Embeddings::fake()->preventStrayEmbeddings();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Embedding model must be configured.');

        (new DossierChunkEmbeddingService)->embed(['valid']);
    }

    public function test_it_rejects_too_few_vectors(): void
    {
        $this->configureEmbeddings(dimensions: 4);

        Embeddings::fake(fn (): array => [array_fill(0, 4, 0.1)])
            ->preventStrayEmbeddings();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Embedding response count does not match input count.');

        (new DossierChunkEmbeddingService)->embed(['one', 'two']);
    }

    public function test_it_rejects_too_many_vectors(): void
    {
        $this->configureEmbeddings(dimensions: 4);

        Embeddings::fake(fn (): array => [
            array_fill(0, 4, 0.1),
            array_fill(0, 4, 0.2),
            array_fill(0, 4, 0.3),
        ])->preventStrayEmbeddings();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Embedding response count does not match input count.');

        (new DossierChunkEmbeddingService)->embed(['one', 'two']);
    }

    public function test_it_rejects_wrong_vector_dimensions(): void
    {
        $this->configureEmbeddings(dimensions: 4);

        Embeddings::fake(fn (): array => [array_fill(0, 3, 0.1)])
            ->preventStrayEmbeddings();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Embedding vector at index 0 does not match configured dimensions.');

        (new DossierChunkEmbeddingService)->embed(['one']);
    }

    public function test_gateway_exception_is_propagated(): void
    {
        $this->configureEmbeddings(dimensions: 4);

        Embeddings::fake(fn (): array => throw new RuntimeException('Provider unavailable.'))
            ->preventStrayEmbeddings();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Provider unavailable.');

        (new DossierChunkEmbeddingService)->embed(['one']);
    }

    private function configureEmbeddings(int $dimensions): void
    {
        config()->set('ai.default_for_embeddings', 'openai');
        config()->set('ai.caching.embeddings.cache', false);
        config()->set('ai.providers.openai.models.embeddings.default', 'text-embedding-3-small');
        config()->set('ai.providers.openai.models.embeddings.dimensions', $dimensions);
    }
}
