<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class T348TagsTenantScopingTest extends TestCase
{
    protected function tearDown(): void
    {
        Organization::where('is_default', true)->update(['is_default' => false]);

        parent::tearDown();
    }

    public function test_tags_can_have_same_slug_across_organizations(): void
    {
        $orgA = Organization::factory()->create(['is_active' => true]);
        $orgB = Organization::factory()->create(['is_active' => true]);

        $tagA = Tag::create(['slug' => 'design', 'name' => 'Design', 'organization_id' => $orgA->id]);
        $tagB = Tag::create(['slug' => 'design', 'name' => 'Design', 'organization_id' => $orgB->id]);

        $this->assertNotNull($tagA);
        $this->assertNotNull($tagB);
        $this->assertEquals('design', $tagA->slug);
        $this->assertEquals('design', $tagB->slug);
        $this->assertNotEquals($tagA->id, $tagB->id);
    }

    public function test_duplicate_slug_within_same_organization_throws(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);

        Tag::create(['slug' => 'design', 'name' => 'Design', 'organization_id' => $org->id]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unique|duplicate/i');

        DB::transaction(function () use ($org): void {
            Tag::create(['slug' => 'design', 'name' => 'Another Design', 'organization_id' => $org->id]);
        });
    }

    public function test_tags_created_via_service_store_have_organization_id(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $org->update(['is_default' => true]);

        $user = User::factory()->complete()->create(['organization_id' => $org->id]);
        $category = Category::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->post(route('services.store'), [
                'title' => 'My Test Service with Tags',
                'description' => 'This is a long enough description for validation purposes to pass the minimum 100 characters requirement.',
                'category_id' => $category->id,
                'delivery_mode' => 'remote',
                'points_cost' => 50,
                'tags' => 'design, development',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tags', [
            'slug' => 'design',
            'organization_id' => $org->id,
        ]);

        $this->assertDatabaseHas('tags', [
            'slug' => 'development',
            'organization_id' => $org->id,
        ]);
    }

    public function test_service_tag_pivot_stores_organization_id(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $org->update(['is_default' => true]);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $service = Service::factory()->forUser($user)->create([
            'organization_id' => $org->id,
        ]);

        $tag = Tag::create(['slug' => 'design', 'name' => 'Design', 'organization_id' => $org->id]);

        $service->tags()->syncWithPivotValues([$tag->id], ['organization_id' => $org->id]);

        $this->assertDatabaseHas('service_tag', [
            'service_id' => $service->id,
            'tag_id' => $tag->id,
            'organization_id' => $org->id,
        ]);
    }

    public function test_blog_post_tag_pivot_stores_organization_id(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $category = Category::factory()->create(['organization_id' => $org->id]);
        $post = BlogPost::create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'category_id' => $category->id,
            'status' => 'published',
            'title' => 'Test Blog Post',
            'content' => '<p>Test content for blog post tag scoping test case.</p>',
        ]);

        $tag = Tag::create(['slug' => 'design', 'name' => 'Design', 'organization_id' => $org->id]);

        $post->tags()->syncWithPivotValues([$tag->id], ['organization_id' => $org->id]);

        $this->assertDatabaseHas('blog_post_tag', [
            'blog_post_id' => $post->id,
            'tag_id' => $tag->id,
            'organization_id' => $org->id,
        ]);
    }

    public function test_first_or_create_with_organization_id_returns_existing_tag(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);

        Tag::create(['slug' => 'design', 'name' => 'Design', 'organization_id' => $org->id]);

        $tag = Tag::firstOrCreate(
            ['slug' => 'design', 'organization_id' => $org->id],
            ['name' => 'Design', 'slug' => 'design']
        );

        $this->assertEquals(1, Tag::where('slug', 'design')->where('organization_id', $org->id)->count());
    }

    public function test_first_or_create_with_organization_id_creates_when_missing(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);

        $tag = Tag::firstOrCreate(
            ['slug' => 'design', 'organization_id' => $org->id],
            ['name' => 'Design', 'slug' => 'design']
        );

        $this->assertDatabaseHas('tags', [
            'slug' => 'design',
            'organization_id' => $org->id,
        ]);
    }
}
