<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class T969BlogProfileLinksTest extends TestCase
{
    protected function tearDown(): void
    {
        Organization::where('is_default', true)->update(['is_default' => false]);

        parent::tearDown();
    }

    public function test_organization_blog_index_uses_organization_scoped_profile_links(): void
    {
        $organization = $this->createOrganization();
        $author = $this->createUser($organization);

        $this->createPost($author, $organization, ['title' => 'T969 Org Index Link']);

        $this->get(route('organization.blog.index', ['organization' => $organization->slug]))
            ->assertOk()
            ->assertSee(route('organization.profile.show', ['organization' => $organization->slug, 'user' => $author]), false)
            ->assertDontSee(route('profile.show', $author), false);
    }

    public function test_organization_blog_show_uses_organization_scoped_profile_links(): void
    {
        $organization = $this->createOrganization();
        $author = $this->createUser($organization);
        $post = $this->createPost($author, $organization, ['title' => 'T969 Org Show Link']);

        $this->get(route('organization.blog.show', ['organization' => $organization->slug, 'post' => $post]))
            ->assertOk()
            ->assertSee(route('organization.profile.show', ['organization' => $organization->slug, 'user' => $author]), false)
            ->assertDontSee(route('profile.show', $author), false);
    }

    public function test_organization_blog_category_uses_organization_scoped_profile_links(): void
    {
        $organization = $this->createOrganization();
        $author = $this->createUser($organization);
        $category = Category::factory()->create(['organization_id' => $organization->id]);

        $this->createPost($author, $organization, [
            'category_id' => $category->id,
            'title' => 'T969 Org Category Link',
        ]);

        $this->get(route('organization.blog.category', ['organization' => $organization->slug, 'slug' => $category->slug]))
            ->assertOk()
            ->assertSee(route('organization.profile.show', ['organization' => $organization->slug, 'user' => $author]), false)
            ->assertDontSee(route('profile.show', $author), false);
    }

    public function test_root_blog_keeps_root_profile_links(): void
    {
        $organization = $this->createOrganization();
        $author = $this->createUser($organization);

        $this->createPost($author, $organization, ['title' => 'T969 Root Link']);

        $this->get(route('blog.index'))
            ->assertOk()
            ->assertSee(route('profile.show', $author), false)
            ->assertDontSee(route('organization.profile.show', ['organization' => $organization->slug, 'user' => $author]), false);
    }

    private function createOrganization(): Organization
    {
        $organization = Organization::factory()->create(['is_active' => true]);
        $organization->update(['is_default' => true]);

        return $organization;
    }

    private function createUser(Organization $organization): User
    {
        return User::factory()->create(['organization_id' => $organization->id]);
    }

    private function createPost(User $user, Organization $organization, array $overrides = []): BlogPost
    {
        return BlogPost::create(array_merge([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'title' => 'Article T969 '.uniqid(),
            'content' => str_repeat('Contenu de test T969 pour valider les liens profils Blog. ', 4),
            'status' => 'published',
            'published_at' => now(),
        ], $overrides));
    }
}
