<?php

namespace Tests\Feature\Dossiers;

use App\Models\Dossier;
use App\Models\DossierMember;
use App\Models\Organization;
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
            ->with($organization->id, $dossier->id, 'needle query', 5)
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

    public function test_dossier_editor_member_can_search(): void
    {
        [$organization, $owner, $dossier] = $this->fixture();
        $member = $this->user($organization);
        $this->addMember($organization, $dossier, $member, DossierMember::ROLE_EDITOR, $owner);

        $this->mockSearchService()
            ->shouldReceive('search')
            ->once()
            ->with($organization->id, $dossier->id, 'needle', 5)
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
            ->with($organization->id, $dossier->id, 'needle', 5)
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
            ->with($organization->id, $dossier->id, 'needle', 5)
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
