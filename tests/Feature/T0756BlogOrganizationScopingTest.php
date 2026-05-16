<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveUrlOrganization;
use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\Community;
use App\Models\User;
use Tests\TestCase;

/**
 * T075.6 — Blog Organization Scoping + Containment
 *
 * Vérifie que le Blog est strictement scopé à l'Organization résolue :
 * index, show, store, update, destroy, comments — tout est containment-safe.
 */
class T0756BlogOrganizationScopingTest extends TestCase
{
    protected function tearDown(): void
    {
        ResolveUrlOrganization::$defaultOrganizationId = null;

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // Index — listing scoped to resolved Organization
    // ─────────────────────────────────────────────────────────────

    public function test_blog_index_lists_only_resolved_organization_posts(): void
    {
        [$organizationA, $organizationB] = $this->createOrganizations();

        $userA = $this->createUser($organizationA);
        $userB = $this->createUser($organizationB);

        $postA = $this->createPost($userA, $organizationA, ['title' => 'Article Org A spécifique']);
        $postB = $this->createPost($userB, $organizationB, ['title' => 'Article Org B spécifique']);

        $response = $this->get(route('blog.index'));

        $response->assertOk();
        $response->assertSeeText('Article Org A spécifique');
        $response->assertDontSeeText('Article Org B spécifique');
    }

    // ─────────────────────────────────────────────────────────────
    // Show — cross-Organization blocked
    // ─────────────────────────────────────────────────────────────

    public function test_blog_show_blocks_cross_organization_post(): void
    {
        [$organizationA, $organizationB] = $this->createOrganizations();

        $userB = $this->createUser($organizationB);
        $postInOrgB = $this->createPost($userB, $organizationB);

        // Org A résolue par défaut — post Org B ne doit pas être accessible
        $this->get(route('blog.show', $postInOrgB))
            ->assertNotFound();
    }

    public function test_blog_show_returns_post_in_resolved_organization(): void
    {
        [$organizationA] = $this->createOrganizations();

        $userA = $this->createUser($organizationA);
        $postA = $this->createPost($userA, $organizationA);

        $this->get(route('blog.show', $postA))
            ->assertOk();
    }

    // ─────────────────────────────────────────────────────────────
    // Store — hidden field tampering + organization required
    // ─────────────────────────────────────────────────────────────

    public function test_blog_store_uses_resolved_organization_not_tampered_community_id(): void
    {
        [$organizationA, $organizationB] = $this->createOrganizations();

        $user = $this->createUser($organizationA);

        $this->actingAs($user)
            ->post(route('blog.store'), array_merge($this->validPostData(), [
                'community_id' => $organizationB->id,
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('blog_posts', [
            'user_id' => $user->id,
            'community_id' => $organizationA->id,
        ]);

        $this->assertDatabaseMissing('blog_posts', [
            'user_id' => $user->id,
            'community_id' => $organizationB->id,
        ]);
    }

    public function test_blog_store_fails_safe_when_no_organization_resolved(): void
    {
        // Aucune Organization active en base — middleware ne peut rien résoudre.
        $user = User::factory()->create(['community_id' => null]);

        $this->actingAs($user)
            ->post(route('blog.store'), $this->validPostData())
            ->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────
    // Update / Destroy — cross-Organization blocked
    // ─────────────────────────────────────────────────────────────

    public function test_blog_update_cross_organization_is_blocked(): void
    {
        [$organizationA, $organizationB] = $this->createOrganizations();

        // Author of the post is in Org B
        $authorB = $this->createUser($organizationB);
        $postInOrgB = $this->createPost($authorB, $organizationB);

        // Org A est résolue par défaut — l'auteur tente d'éditer depuis le contexte Org A
        $this->actingAs($authorB)
            ->put(route('blog.update', $postInOrgB), $this->validPostData())
            ->assertNotFound();
    }

    public function test_blog_destroy_cross_organization_is_blocked(): void
    {
        [$organizationA, $organizationB] = $this->createOrganizations();

        $authorB = $this->createUser($organizationB);
        $postInOrgB = $this->createPost($authorB, $organizationB);

        $this->actingAs($authorB)
            ->delete(route('blog.destroy', $postInOrgB))
            ->assertNotFound();

        $this->assertDatabaseHas('blog_posts', [
            'id' => $postInOrgB->id,
            'deleted_at' => null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Comments — cross-Organization blocked
    // ─────────────────────────────────────────────────────────────

    public function test_blog_comment_store_on_cross_organization_post_is_blocked(): void
    {
        [$organizationA, $organizationB] = $this->createOrganizations();

        $userA = $this->createUser($organizationA);
        $authorB = $this->createUser($organizationB);
        $postInOrgB = $this->createPost($authorB, $organizationB);

        $this->actingAs($userA)
            ->post(route('blog.comment.store', $postInOrgB), [
                'content' => 'Tentative de commentaire cross-organization',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('blog_comments', [
            'blog_post_id' => $postInOrgB->id,
            'user_id' => $userA->id,
        ]);
    }

    public function test_blog_comment_store_blocks_parent_id_from_another_post(): void
    {
        [$organizationA, $organizationB] = $this->createOrganizations();

        $authorA = $this->createUser($organizationA);
        $postInOrgA = $this->createPost($authorA, $organizationA);

        $authorB = $this->createUser($organizationB);
        $postInOrgB = $this->createPost($authorB, $organizationB);

        // Parent comment lives on post B (Org B)
        $parentCommentOnPostB = BlogComment::create([
            'blog_post_id' => $postInOrgB->id,
            'user_id' => $authorB->id,
            'parent_id' => null,
            'content' => 'Commentaire parent sur post Org B',
            'is_approved' => true,
        ]);

        // Org A est résolue par défaut — user A tente de commenter post A
        // en pointant parent_id sur un commentaire de post B (cross-post / cross-Organization).
        $this->actingAs($authorA)
            ->post(route('blog.comment.store', $postInOrgA), [
                'content' => 'Réponse cross-post tentée',
                'parent_id' => $parentCommentOnPostB->id,
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('blog_comments', [
            'blog_post_id' => $postInOrgA->id,
            'parent_id' => $parentCommentOnPostB->id,
        ]);

        $this->assertDatabaseMissing('blog_comments', [
            'user_id' => $authorA->id,
            'parent_id' => $parentCommentOnPostB->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /** @return array{Community, Community} */
    private function createOrganizations(): array
    {
        $organizationA = Community::factory()->create(['is_active' => true]);
        $organizationB = Community::factory()->create(['is_active' => true]);

        ResolveUrlOrganization::$defaultOrganizationId = (string) $organizationA->id;

        return [$organizationA, $organizationB];
    }

    private function createUser(Community $organization): User
    {
        return User::factory()->create(['community_id' => $organization->id]);
    }

    private function createPost(User $user, Community $organization, array $overrides = []): BlogPost
    {
        return BlogPost::create(array_merge([
            'user_id' => $user->id,
            'community_id' => $organization->id,
            'title' => 'Article de test '.uniqid(),
            'content' => str_repeat('Contenu de test pour vérifier le scoping Organization. ', 5),
            'status' => 'published',
            'published_at' => now(),
        ], $overrides));
    }

    private function validPostData(): array
    {
        return [
            'title' => 'Article de test T075.6',
            'summary' => 'Résumé de test',
            'content' => str_repeat('Contenu de test pour valider la création scopée Organization. ', 3),
            'status' => 'draft',
        ];
    }
}
