<?php

namespace Tests\Feature\Dossiers;

use App\Models\Dossier;
use App\Models\DossierMember;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\DossierSemanticSearchService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as HttpClientResponse;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class DossierSemanticSearchControllerTest extends TestCase
{
    public function test_unauthenticated_users_are_refused(): void
    {
        [$organization, $owner, $dossier] = $this->fixture();

        $this->mockSearchService()->shouldNotReceive('search');

        $this->getJson($this->searchUrl($organization, $dossier, ['query' => 'needle']))
            ->assertUnauthorized();
    }

    public function test_dossier_owner_can_search_and_receives_stable_json_with_citation_url(): void
    {
        [$organization, $owner, $dossier] = $this->fixture();

        $this->mockSearchService()
            ->shouldReceive('search')
            ->once()
            ->with($organization->id, $dossier->id, 'needle query', \Mockery::pattern('/^org:.+:openai$/'), 5)
            ->andReturn([
                [
                    'blog_post_id' => 'post-uuid',
                    'title' => 'Indexed article',
                    'slug' => 'indexed-article',
                    'chunk_index' => 0,
                    'content' => 'Relevant passage',
                    'distance' => 0.123,
                ],
            ]);

        $this->actingAs($owner)
            ->getJson($this->searchUrl($organization, $dossier, ['query' => '  needle query  ']))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'blog_post_id' => 'post-uuid',
                        'title' => 'Indexed article',
                        'slug' => 'indexed-article',
                        'chunk_index' => 0,
                        'content' => 'Relevant passage',
                        'distance' => 0.123,
                        'citation_url' => route('organization.blog.show', [
                            'organization' => $organization,
                            'post' => 'indexed-article',
                        ]),
                    ],
                ],
            ]);
    }

    public function test_file_result_receives_dossier_file_citation_url_without_exception(): void
    {
        // TASK-1267 : forme reelle d'un resultat `file` rendue par
        // DossierSemanticSearchService::mapSourceRow() — title et slug nuls.
        // Avant le correctif, route('organization.blog.show', ['post' => null])
        // levait UrlGenerationException -> HTTP 500.
        [$organization, $owner, $dossier] = $this->fixture();

        $this->mockSearchService()
            ->shouldReceive('search')
            ->once()
            ->with($organization->id, $dossier->id, 'needle', \Mockery::pattern('/^org:.+:openai$/'), 5)
            ->andReturn([
                $this->fileResult('file-uuid-1', 'contrat-2026.pdf', 2, 'Passage du contrat', 0.234),
            ]);

        $this->withoutExceptionHandling()
            ->actingAs($owner)
            ->getJson($this->searchUrl($organization, $dossier, ['query' => 'needle']))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    $this->fileResult('file-uuid-1', 'contrat-2026.pdf', 2, 'Passage du contrat', 0.234) + [
                        'citation_url' => route('organization.dossiers.files.show', [
                            'organization' => $organization,
                            'dossier' => $dossier,
                            'file' => 'file-uuid-1',
                        ]),
                    ],
                ],
            ]);
    }

    public function test_mixed_article_and_file_results_each_receive_their_own_citation_url(): void
    {
        [$organization, $owner, $dossier] = $this->fixture();

        $article = $this->articleResult('post-uuid', 'Indexed article', 'indexed-article', 0, 'Relevant passage', 0.123);
        $fileA = $this->fileResult('file-uuid-a', 'notes.md', 0, 'Passage A', 0.2);
        $fileB = $this->fileResult('file-uuid-b', 'rapport.docx', 0, 'Passage B', 0.3);

        $this->mockSearchService()
            ->shouldReceive('search')
            ->once()
            ->with($organization->id, $dossier->id, 'needle', \Mockery::pattern('/^org:.+:openai$/'), 5)
            ->andReturn([$article, $fileA, $fileB]);

        $this->withoutExceptionHandling()
            ->actingAs($owner)
            ->getJson($this->searchUrl($organization, $dossier, ['query' => 'needle']))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    $article + [
                        'citation_url' => route('organization.blog.show', [
                            'organization' => $organization,
                            'post' => 'indexed-article',
                        ]),
                    ],
                    $fileA + [
                        'citation_url' => route('organization.dossiers.files.show', [
                            'organization' => $organization,
                            'dossier' => $dossier,
                            'file' => 'file-uuid-a',
                        ]),
                    ],
                    $fileB + [
                        'citation_url' => route('organization.dossiers.files.show', [
                            'organization' => $organization,
                            'dossier' => $dossier,
                            'file' => 'file-uuid-b',
                        ]),
                    ],
                ],
            ]);
    }

    public function test_dossier_editor_member_can_search(): void
    {
        [$organization, $owner, $dossier] = $this->fixture();
        $member = $this->user($organization);
        $this->addMember($organization, $dossier, $member, DossierMember::ROLE_EDITOR, $owner);

        $this->mockSearchService()
            ->shouldReceive('search')
            ->once()
            ->with($organization->id, $dossier->id, 'needle', \Mockery::pattern('/^org:.+:openai$/'), 5)
            ->andReturn([]);

        $this->actingAs($member)
            ->getJson($this->searchUrl($organization, $dossier, ['query' => 'needle']))
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_dossier_reader_member_can_search(): void
    {
        [$organization, $owner, $dossier] = $this->fixture();
        $member = $this->user($organization);
        $this->addMember($organization, $dossier, $member, DossierMember::ROLE_READER, $owner);

        $this->mockSearchService()
            ->shouldReceive('search')
            ->once()
            ->with($organization->id, $dossier->id, 'needle', \Mockery::pattern('/^org:.+:openai$/'), 5)
            ->andReturn([]);

        $this->actingAs($member)
            ->getJson($this->searchUrl($organization, $dossier, ['query' => 'needle']))
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_non_member_is_forbidden(): void
    {
        [$organization, , $dossier] = $this->fixture();
        $user = $this->user($organization);

        $this->mockSearchService()->shouldNotReceive('search');

        $this->actingAs($user)
            ->getJson($this->searchUrl($organization, $dossier, ['query' => 'needle']))
            ->assertForbidden();
    }

    public function test_dossier_from_another_organization_is_not_found(): void
    {
        [$organization, $owner] = $this->fixture();
        [$otherOrganization, , $otherDossier] = $this->fixture();

        $this->mockSearchService()->shouldNotReceive('search');

        $this->actingAs($owner)
            ->getJson($this->searchUrl($organization, $otherDossier, ['query' => 'needle']))
            ->assertNotFound();

        $this->assertNotSame($organization->id, $otherOrganization->id);
    }

    public function test_query_is_required(): void
    {
        [$organization, $owner, $dossier] = $this->fixture();

        $this->mockSearchService()->shouldNotReceive('search');

        $this->actingAs($owner)
            ->getJson($this->searchUrl($organization, $dossier))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['query']);
    }

    public function test_empty_query_is_rejected_after_trimming(): void
    {
        [$organization, $owner, $dossier] = $this->fixture();

        $this->mockSearchService()->shouldNotReceive('search');
        $this->actingAs($owner)
            ->getJson($this->searchUrl($organization, $dossier, ['query' => '   ']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['query']);
    }

    public function test_too_long_query_is_rejected(): void
    {
        [$organization, $owner, $dossier] = $this->fixture();

        $this->mockSearchService()->shouldNotReceive('search');
        $this->actingAs($owner)
            ->getJson($this->searchUrl($organization, $dossier, ['query' => str_repeat('a', 501)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['query']);
    }

    public function test_engine_exception_returns_stable_503_without_secret_details(): void
    {
        $this->assertSearchUnavailableForException(
            new RuntimeException('provider secret sk-live-query needle')
        );
    }

    public function test_laravel_ai_rate_limit_exception_returns_stable_503_without_provider_details(): void
    {
        $this->assertSearchUnavailableForException(
            RateLimitedException::forProvider('openai-secret-provider', 429)
        );
    }

    public function test_laravel_ai_overload_exception_returns_stable_503_without_provider_details(): void
    {
        $this->assertSearchUnavailableForException(
            ProviderOverloadedException::forProvider('openai-secret-provider', 503)
        );
    }

    public function test_provider_transport_exception_returns_stable_503_without_connection_details(): void
    {
        $this->assertSearchUnavailableForException(
            new ConnectionException('connection refused secret sk-live-query needle')
        );
    }

    public function test_provider_http_exception_returns_stable_503_without_response_details(): void
    {
        $this->assertSearchUnavailableForException(
            new RequestException(new HttpClientResponse(new PsrResponse(500, [], 'provider secret sk-live-query needle')))
        );
    }

    /**
     * Resultat `article` tel que rendu par DossierSemanticSearchService::mapSourceRow().
     *
     * @return array<string, mixed>
     */
    private function articleResult(string $postId, string $title, string $slug, int $chunkIndex, string $content, float $distance): array
    {
        return [
            'source_type' => 'article',
            'blog_post_id' => $postId,
            'title' => $title,
            'slug' => $slug,
            'dossier_file_id' => null,
            'filename' => null,
            'mime_type' => null,
            'chunk_index' => $chunkIndex,
            'content' => $content,
            'distance' => $distance,
        ];
    }

    /**
     * Resultat `file` tel que rendu par DossierSemanticSearchService::mapSourceRow() :
     * blog_post_id / title / slug nuls, dossier_file_id + filename + mime_type renseignes.
     *
     * @return array<string, mixed>
     */
    private function fileResult(string $fileId, string $filename, int $chunkIndex, string $content, float $distance): array
    {
        return [
            'source_type' => 'file',
            'blog_post_id' => null,
            'title' => null,
            'slug' => null,
            'dossier_file_id' => $fileId,
            'filename' => $filename,
            'mime_type' => 'text/markdown',
            'chunk_index' => $chunkIndex,
            'content' => $content,
            'distance' => $distance,
        ];
    }

    private function mockSearchService(): MockInterface
    {
        return $this->mock(DossierSemanticSearchService::class);
    }

    private function assertSearchUnavailableForException(Throwable $exception): void
    {
        [$organization, $owner, $dossier] = $this->fixture();

        $this->mockSearchService()
            ->shouldReceive('search')
            ->once()
            ->with($organization->id, $dossier->id, 'needle', \Mockery::pattern('/^org:.+:openai$/'), 5)
            ->andThrow($exception);

        $response = $this->actingAs($owner)
            ->getJson($this->searchUrl($organization, $dossier, ['query' => 'needle']))
            ->assertStatus(503)
            ->assertExactJson(['code' => 'semantic_search_unavailable']);

        $this->assertStringNotContainsString('provider', $response->getContent());
        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertStringNotContainsString('needle', $response->getContent());
        $this->assertStringNotContainsString('openai', $response->getContent());
        $this->assertStringNotContainsString('sk-live-query', $response->getContent());
    }

    /**
     * @return array{0: Organization, 1: User, 2: Dossier}
     */
    private function fixture(): array
    {
        $organization = Organization::factory()->create([
            'slug' => 'org-'.Str::uuid(),
            'is_active' => true,
        ]);
        // TASK-1225 : la recherche semantique exige desormais un embedding
        // TENANT (credential Organization) — plus jamais la cle plateforme.
        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-test-1225-controller',
        ]);
        config()->set('ai.default_for_embeddings', 'openai');
        config()->set('ai.providers.openai.driver', 'openai');
        $owner = $this->user($organization);

        $dossier = Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'name' => 'Searchable dossier',
            'visibility' => 'private',
        ]);

        return [$organization, $owner, $dossier];
    }

    private function user(Organization $organization): User
    {
        return User::factory()->create(['organization_id' => $organization->id]);
    }

    private function addMember(Organization $organization, Dossier $dossier, User $member, string $role, User $owner): void
    {
        DossierMember::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'user_id' => $member->id,
            'role' => $role,
            'added_by' => $owner->id,
        ]);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function searchUrl(Organization $organization, Dossier $dossier, array $query = []): string
    {
        $url = route('organization.dossiers.semantic-search', [
            'organization' => $organization,
            'dossier' => $dossier,
        ]);

        if ($query === []) {
            return $url;
        }

        return $url.'?'.http_build_query($query);
    }
}
