<?php

namespace Tests\Unit\Ai;

use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Tests\TestCase;

class LaravelAiEmbeddingsSdkTest extends TestCase
{
    public function test_embeddings_fake_generates_configured_dimension_without_network(): void
    {
        config()->set('ai.default_for_embeddings', 'openai');
        config()->set('ai.providers.openai.models.embeddings.default', 'text-embedding-3-small');
        config()->set('ai.providers.openai.models.embeddings.dimensions', 1536);

        Embeddings::fake(function (EmbeddingsPrompt $prompt): array {
            return array_map(
                fn () => Embeddings::fakeEmbedding($prompt->dimensions),
                $prompt->inputs,
            );
        })->preventStrayEmbeddings();

        $response = Embeddings::for(['first article chunk', 'second article chunk'])->generate();

        $this->assertCount(2, $response->embeddings);

        foreach ($response->embeddings as $embedding) {
            $this->assertCount(1536, $embedding);
        }

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt) => $prompt->inputs === ['first article chunk', 'second article chunk']
            && $prompt->dimensions === 1536
            && $prompt->model === 'text-embedding-3-small');
    }
}
