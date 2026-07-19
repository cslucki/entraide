<?php

namespace Tests\Feature\Dossiers;

use App\Models\Dossier;
use App\Models\DossierMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DossierSemanticSearchService;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

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
        [$organization, $owner, $dossier] = $this->fixture();

        $this->mockSearchService()
            ->shouldReceive('search')
            ->once()
            ->with($organization->id, $dossier->id, 'needle', 5)
            ->andThrow(new RuntimeException('provider secret sk-live-query needle'));

        $response = $this->actingAs($owner)
            ->getJson($this->searchUrl($organization, $dossier, ['query' => 'needle']))
            ->assertStatus(503)
            ->assertExactJson(['code' => 'semantic_search_unavailable']);

        $this->assertStringNotContainsString('provider', $response->getContent());
        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertStringNotContainsString('needle', $response->getContent());
    }

    private function mockSearchService(): MockInterface
    {
        return $this->mock(DossierSemanticSearchService::class);
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
