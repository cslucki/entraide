<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1290 — les deux primitives de creation Blog doivent refuser tout
 * acteur qui n'appartient pas a l'Organization resolue. Les routes courtes
 * resolvent l'Organization par defaut ; les routes prefixees resolvent celle
 * de l'URL. Dans les deux cas, `users.organization_id` reste l'autorite.
 */
#[Group('sensitive')]
class TASK1290BlogWriteMembershipTest extends TestCase
{
    use RefreshDatabase;

    private Organization $defaultOrganization;

    private Organization $otherOrganization;

    private User $member;

    private User $stranger;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultOrganization = Organization::factory()->create([
            'name' => 'BouclePro 1290',
            'slug' => 'bouclepro-1290',
            'is_active' => true,
            'is_default' => true,
        ]);
        $this->otherOrganization = Organization::factory()->create([
            'name' => 'Autre Organization 1290',
            'slug' => 'autre-org-1290',
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->member = User::factory()->create([
            'organization_id' => $this->defaultOrganization->id,
            'is_admin' => false,
        ]);
        $this->stranger = User::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'is_admin' => false,
        ]);

        $this->category = Category::create([
            'name_b2c' => 'Categorie 1290',
            'name_b2b' => 'Categorie B2B 1290',
            'slug' => 'categorie-1290',
            'color' => '#6366f1',
            'organization_id' => $this->defaultOrganization->id,
        ]);
    }

    public function test_a_stranger_cannot_store_a_post_on_the_short_surface(): void
    {
        $this->actingAs($this->stranger)
            ->post(route('blog.store'), $this->storePayload('Intrusion courte'))
            ->assertForbidden();

        $this->assertNoPostBy($this->stranger);
    }

    public function test_a_stranger_cannot_store_a_post_on_the_organization_surface(): void
    {
        $this->actingAs($this->stranger)
            ->post(route('organization.blog.store', [
                'organization' => $this->defaultOrganization->slug,
            ]), $this->storePayload('Intrusion prefixee'))
            ->assertForbidden();

        $this->assertNoPostBy($this->stranger);
    }

    public function test_a_stranger_cannot_create_a_draft_on_the_short_surface(): void
    {
        $this->actingAs($this->stranger)
            ->postJson(route('blog.create-draft'), $this->draftPayload('Brouillon intrusion courte'))
            ->assertForbidden();

        $this->assertNoPostBy($this->stranger);
    }

    public function test_a_stranger_cannot_create_a_draft_on_the_organization_surface(): void
    {
        $this->actingAs($this->stranger)
            ->postJson(route('organization.blog.create-draft', [
                'organization' => $this->defaultOrganization->slug,
            ]), $this->draftPayload('Brouillon intrusion prefixee'))
            ->assertForbidden();

        $this->assertNoPostBy($this->stranger);
    }

    public function test_a_legitimate_member_keeps_the_historical_store_behavior(): void
    {
        $this->actingAs($this->member)
            ->post(route('blog.store'), $this->storePayload('Article legitime'))
            ->assertRedirect();

        $this->assertDatabaseHas('blog_posts', [
            'user_id' => $this->member->id,
            'organization_id' => $this->defaultOrganization->id,
            'title' => 'Article legitime',
        ]);
    }

    public function test_a_legitimate_member_keeps_the_historical_draft_behavior(): void
    {
        $response = $this->actingAs($this->member)
            ->postJson(route('organization.blog.create-draft', [
                'organization' => $this->defaultOrganization->slug,
            ]), $this->draftPayload('Brouillon legitime'))
            ->assertOk()
            ->assertJsonStructure(['post_id', 'edit_url']);

        $this->assertDatabaseHas('blog_posts', [
            'id' => $response->json('post_id'),
            'user_id' => $this->member->id,
            'organization_id' => $this->defaultOrganization->id,
            'title' => 'Brouillon legitime',
            'status' => 'draft',
        ]);
    }

    public function test_a_platform_admin_from_another_organization_remains_refused(): void
    {
        $admin = User::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('blog.store'), $this->storePayload('Admin intrusion courte'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson(route('organization.blog.create-draft', [
                'organization' => $this->defaultOrganization->slug,
            ]), $this->draftPayload('Admin intrusion prefixee'))
            ->assertForbidden();

        $this->assertNoPostBy($admin);
    }

    /** @return array<string, mixed> */
    private function storePayload(string $title): array
    {
        return [
            'title' => $title,
            'summary' => 'Resume de test TASK-1290.',
            'content' => '<p>Contenu de test TASK-1290 assez long.</p>',
            'status' => 'draft',
            'category_id' => $this->category->id,
        ];
    }

    /** @return array<string, mixed> */
    private function draftPayload(string $title): array
    {
        return [
            'title' => $title,
            'summary' => 'Resume du brouillon TASK-1290.',
            'category_id' => $this->category->id,
        ];
    }

    private function assertNoPostBy(User $user): void
    {
        $this->assertDatabaseMissing('blog_posts', [
            'user_id' => $user->id,
            'organization_id' => $this->defaultOrganization->id,
        ]);
    }
}
