<?php

namespace Tests\Feature\Admin;

use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\Service;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class T971AdminTagsManagerTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $org = Organization::factory()->create(['is_active' => true]);

        return User::factory()->create([
            'is_admin' => true,
            'organization_id' => $org->id,
        ]);
    }

    public function test_guest_is_redirected_from_admin_tags(): void
    {
        $this->get(route('admin.tags'))
            ->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_admin_tags(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('admin.tags'))
            ->assertForbidden();
    }

    public function test_admin_can_view_tags_index(): void
    {
        $admin = $this->makeAdmin();
        $org = $admin->organization;

        $tags = Tag::factory()->count(3)->create(['organization_id' => $org->id]);
        foreach ($tags as $tag) {
            Service::factory()->create(['organization_id' => $org->id])->tags()->attach($tag);
        }

        $this->actingAs($admin)
            ->get(route('admin.tags', ['organization_id' => $org->id]))
            ->assertOk()
            ->assertSee('3 tags');
    }

    public function test_index_shows_usage_counts(): void
    {
        $admin = $this->makeAdmin();
        $org = $admin->organization;

        $tagBlog = Tag::factory()->create(['organization_id' => $org->id]);
        $tagSvc = Tag::factory()->create(['organization_id' => $org->id]);
        $tagBoth = Tag::factory()->create(['organization_id' => $org->id]);

        Service::factory()->create(['organization_id' => $org->id])->tags()->attach($tagSvc);
        Service::factory()->create(['organization_id' => $org->id])->tags()->attach($tagBoth);

        BlogPost::create([
            'user_id' => $admin->id,
            'organization_id' => $org->id,
            'title' => 'Test Blog Post',
            'content' => 'Lorem ipsum dolor sit amet consectetur adipisicing elit. Sed quia voluptatum, sequi ipsa minima minus perspiciatis magnam vero itaque. Aperiam, totam recusandae.',
            'status' => 'draft',
        ])->tags()->attach($tagBlog);

        BlogPost::create([
            'user_id' => $admin->id,
            'organization_id' => $org->id,
            'title' => 'Test Blog Post 2',
            'content' => 'Lorem ipsum dolor sit amet consectetur adipisicing elit. Sed quia voluptatum, sequi ipsa minima minus perspiciatis magnam vero itaque. Aperiam, totam recusandae.',
            'status' => 'draft',
        ])->tags()->attach($tagBoth);

        $this->actingAs($admin)
            ->get(route('admin.tags', ['organization_id' => $org->id]))
            ->assertOk()
            ->assertSee($tagBlog->name)
            ->assertSee($tagSvc->name)
            ->assertSee($tagBoth->name);
    }

    public function test_index_search_filter(): void
    {
        $admin = $this->makeAdmin();
        $org = $admin->organization;

        $tagLaravel = Tag::factory()->create(['name' => 'laravel', 'organization_id' => $org->id]);
        Tag::factory()->create(['name' => 'symfony', 'organization_id' => $org->id]);
        Tag::factory()->create(['name' => 'react', 'organization_id' => $org->id]);

        Service::factory()->create(['organization_id' => $org->id])->tags()->attach($tagLaravel);

        $this->actingAs($admin)
            ->get(route('admin.tags', ['search' => 'laravel', 'organization_id' => $org->id]))
            ->assertOk()
            ->assertSee('laravel')
            ->assertDontSee('symfony')
            ->assertDontSee('react');
    }

    public function test_index_organization_filter(): void
    {
        $admin = $this->makeAdmin();
        $orgA = Organization::factory()->create(['is_active' => true]);
        $orgB = Organization::factory()->create(['is_active' => true]);

        $tagA = Tag::factory()->create(['name' => 'tag-a', 'organization_id' => $orgA->id]);
        Tag::factory()->create(['name' => 'tag-b', 'organization_id' => $orgB->id]);

        Service::factory()->create(['organization_id' => $orgA->id])->tags()->attach($tagA);

        $this->actingAs($admin)
            ->get(route('admin.tags', ['organization_id' => $orgA->id]))
            ->assertOk()
            ->assertSee('tag-a')
            ->assertDontSee('tag-b');
    }

    public function test_admin_can_view_tag_edit_form(): void
    {
        $admin = $this->makeAdmin();
        $tag = Tag::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.tags.edit', $tag))
            ->assertOk()
            ->assertSee($tag->name)
            ->assertSee($tag->slug);
    }

    public function test_admin_can_update_tag_name_and_slug(): void
    {
        $admin = $this->makeAdmin();
        $tag = Tag::factory()->create(['name' => 'old-name', 'slug' => 'old-slug']);

        $this->actingAs($admin)
            ->put(route('admin.tags.update', $tag), [
                'name' => 'New Name',
                'slug' => 'new-slug',
            ])
            ->assertRedirect(route('admin.tags'))
            ->assertSessionHas('success');

        $tag->refresh();
        $this->assertEquals('New Name', $tag->name);
        $this->assertEquals('new-slug', $tag->slug);
    }

    public function test_update_autogenerates_slug_when_empty(): void
    {
        $admin = $this->makeAdmin();
        $tag = Tag::factory()->create(['name' => 'old', 'slug' => 'old-slug']);

        $this->actingAs($admin)
            ->put(route('admin.tags.update', $tag), [
                'name' => 'New Tag Name',
                'slug' => '',
            ])
            ->assertRedirect(route('admin.tags'));

        $tag->refresh();
        $this->assertEquals('new-tag-name', $tag->slug);
    }

    public function test_update_prevents_slug_collision_in_same_organization(): void
    {
        $admin = $this->makeAdmin();
        $org = $admin->organization;
        Tag::factory()->create(['name' => 'existing', 'slug' => 'existing', 'organization_id' => $org->id]);
        $tag = Tag::factory()->create(['name' => 'mine', 'slug' => 'mine', 'organization_id' => $org->id]);

        $this->actingAs($admin)
            ->put(route('admin.tags.update', $tag), [
                'name' => 'existing',
            ])
            ->assertSessionHas('error');
    }

    public function test_update_prevents_slug_collision_in_same_organization_by_slug(): void
    {
        $admin = $this->makeAdmin();
        $org = $admin->organization;
        Tag::factory()->create(['name' => 'a', 'slug' => 'taken', 'organization_id' => $org->id]);
        $tag = Tag::factory()->create(['name' => 'b', 'slug' => 'b', 'organization_id' => $org->id]);

        $this->actingAs($admin)
            ->put(route('admin.tags.update', $tag), [
                'name' => 'My Tag',
                'slug' => 'taken',
            ])
            ->assertSessionHas('error');
    }

    public function test_admin_can_delete_unused_tag(): void
    {
        $admin = $this->makeAdmin();
        $tag = Tag::factory()->create();

        $this->actingAs($admin)
            ->delete(route('admin.tags.destroy', $tag))
            ->assertRedirect(route('admin.tags'))
            ->assertSessionHas('success');

        $this->assertModelMissing($tag);
    }

    public function test_admin_cannot_delete_tag_used_by_blog(): void
    {
        $admin = $this->makeAdmin();
        $org = $admin->organization;
        $tag = Tag::factory()->create(['organization_id' => $org->id]);

        $post = BlogPost::create([
            'user_id' => $admin->id,
            'organization_id' => $org->id,
            'title' => 'Test Post',
            'content' => 'Lorem ipsum dolor sit amet consectetur adipisicing elit. Sed quia voluptatum, sequi ipsa.',
            'status' => 'draft',
        ]);
        $post->tags()->attach($tag);

        $this->actingAs($admin)
            ->delete(route('admin.tags.destroy', $tag))
            ->assertSessionHas('error');

        $this->assertModelExists($tag);
    }

    public function test_admin_cannot_delete_tag_used_by_service(): void
    {
        $admin = $this->makeAdmin();
        $org = $admin->organization;
        $tag = Tag::factory()->create(['organization_id' => $org->id]);

        $service = Service::factory()->create(['organization_id' => $org->id]);
        $service->tags()->attach($tag);

        $this->actingAs($admin)
            ->delete(route('admin.tags.destroy', $tag))
            ->assertSessionHas('error');

        $this->assertModelExists($tag);
    }
}
