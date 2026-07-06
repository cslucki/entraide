<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\User;
use Tests\TestCase;

class T969BlogTagsTest extends TestCase
{
    protected function tearDown(): void
    {
        Organization::where('is_default', true)->update(['is_default' => false]);

        parent::tearDown();
    }

    public function test_blog_store_normalizes_hash_prefixed_tags(): void
    {
        $organization = $this->createOrganization();
        $user = $this->createUser($organization);

        $this->actingAs($user)
            ->post(route('organization.blog.store', ['organization' => $organization->slug]), $this->validPostData([
                'tags' => '#Cyberworkers, BouclePro, ##Cyberworkers, ###AI',
            ]))
            ->assertRedirect();

        $post = BlogPost::where('user_id', $user->id)->firstOrFail();

        $this->assertSame(['AI', 'BouclePro', 'Cyberworkers'], $post->tags()->orderBy('name')->pluck('name')->all());
        $this->assertDatabaseHas('tags', [
            'name' => 'Cyberworkers',
            'slug' => 'cyberworkers',
            'organization_id' => $organization->id,
        ]);
        $this->assertDatabaseMissing('tags', [
            'name' => '#Cyberworkers',
            'organization_id' => $organization->id,
        ]);
    }

    public function test_blog_update_replaces_and_removes_tags_after_normalization(): void
    {
        $organization = $this->createOrganization();
        $user = $this->createUser($organization);
        $post = $this->createPost($user, $organization);

        $existing = Tag::create([
            'name' => 'Cyberworkers',
            'slug' => 'cyberworkers',
            'organization_id' => $organization->id,
        ]);
        $removed = Tag::create([
            'name' => 'OldTag',
            'slug' => 'oldtag',
            'organization_id' => $organization->id,
        ]);
        $post->tags()->syncWithPivotValues([$existing->id, $removed->id], ['organization_id' => $organization->id]);

        $this->actingAs($user)
            ->put(route('organization.blog.update', ['organization' => $organization->slug, 'post' => $post]), $this->validPostData([
                'tags' => '##Cyberworkers, #BouclePro',
            ]))
            ->assertRedirect();

        $post->refresh();

        $this->assertSame(['BouclePro', 'Cyberworkers'], $post->tags()->orderBy('name')->pluck('name')->all());
        $this->assertDatabaseMissing('blog_post_tag', [
            'blog_post_id' => $post->id,
            'tag_id' => $removed->id,
        ]);
    }

    public function test_blog_show_defensively_renders_legacy_hash_tag_without_double_hash(): void
    {
        $organization = $this->createOrganization();
        $user = $this->createUser($organization);
        $post = $this->createPost($user, $organization);
        $legacyTag = Tag::create([
            'name' => '#Cyberworkers',
            'slug' => 'cyberworkers-legacy',
            'organization_id' => $organization->id,
        ]);

        $post->tags()->syncWithPivotValues([$legacyTag->id], ['organization_id' => $organization->id]);

        $this->get(route('organization.blog.show', ['organization' => $organization->slug, 'post' => $post]))
            ->assertOk()
            ->assertSeeText('#Cyberworkers')
            ->assertDontSeeText('##Cyberworkers');
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
            'content' => str_repeat('Contenu de test T969 pour valider les tags Blog. ', 4),
            'status' => 'published',
            'published_at' => now(),
        ], $overrides));
    }

    private function validPostData(array $overrides = []): array
    {
        $organization = Organization::where('is_default', true)->firstOrFail();

        return array_merge([
            'title' => 'Article T969 Tags',
            'summary' => 'Résumé T969',
            'content' => str_repeat('Contenu de test T969 assez long pour passer la validation Blog. ', 3),
            'status' => 'published',
            'category_id' => Category::factory()->create(['organization_id' => $organization->id])->id,
        ], $overrides);
    }
}
