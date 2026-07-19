<?php

namespace Tests\Feature\Dossiers;

use App\Models\Dossier;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DossierSemanticSearchService;
use InvalidArgumentException;
use Laravel\Ai\Embeddings;
use RuntimeException;
use Tests\TestCase;

class DossierSemanticSearchServiceTest extends TestCase
{
    public function test_empty_query_is_rejected(): void
    {
        Embeddings::fake()->preventStrayEmbeddings();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Semantic search query must not be empty.');

        app(DossierSemanticSearchService::class)->search(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            '   ',
        );
    }

    public function test_invalid_limit_is_rejected(): void
    {
        Embeddings::fake()->preventStrayEmbeddings();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Semantic search limit must be between 1 and 5.');

        app(DossierSemanticSearchService::class)->search(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            'query',
            6,
        );
    }

    public function test_disabled_gate_returns_empty_without_embeddings(): void
    {
        [$organization, $dossier] = $this->fixture();
        config()->set('ai.dossiers.semantic_search.enabled', false);
        Embeddings::fake()->preventStrayEmbeddings();

        $result = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'query');

        $this->assertSame([], $result);
        Embeddings::assertNothingGenerated();
    }

    public function test_organization_outside_allowlist_returns_empty_without_embeddings(): void
    {
        [$organization, $dossier] = $this->fixture();
        $this->enableGate('00000000-0000-4000-8000-000000000000');
        Embeddings::fake()->preventStrayEmbeddings();

        $result = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'query');

        $this->assertSame([], $result);
        Embeddings::assertNothingGenerated();
    }

    public function test_missing_dossier_returns_empty_without_embeddings(): void
    {
        [$organization] = $this->fixture();
        $this->enableGate($organization->id);
        Embeddings::fake()->preventStrayEmbeddings();

        $result = app(DossierSemanticSearchService::class)->search(
            $organization->id,
            '22222222-2222-4222-8222-222222222222',
            'query',
        );

        $this->assertSame([], $result);
        Embeddings::assertNothingGenerated();
    }

    public function test_cross_tenant_dossier_returns_empty_without_embeddings(): void
    {
        [$organization] = $this->fixture();
        [, $otherDossier] = $this->fixture();
        $this->enableGate($organization->id);
        Embeddings::fake()->preventStrayEmbeddings();

        $result = app(DossierSemanticSearchService::class)->search($organization->id, $otherDossier->id, 'query');

        $this->assertSame([], $result);
        Embeddings::assertNothingGenerated();
    }

    public function test_non_postgresql_driver_throws_explicit_exception_without_embeddings(): void
    {
        [$organization, $dossier] = $this->fixture();
        $this->enableGate($organization->id);
        Embeddings::fake()->preventStrayEmbeddings();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Dossier semantic search requires PostgreSQL pgvector.');

        try {
            app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'query');
        } finally {
            Embeddings::assertNothingGenerated();
        }
    }

    /**
     * @return array{0: Organization, 1: Dossier}
     */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $organization->id]);

        $dossier = Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'name' => 'Semantic dossier',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        return [$organization, $dossier];
    }

    private function enableGate(string $organizationId): void
    {
        config()->set('ai.dossiers.semantic_search.enabled', true);
        config()->set('ai.dossiers.semantic_search.organization_ids', [$organizationId]);
    }
}
