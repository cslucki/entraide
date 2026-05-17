<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Community;
use App\Models\Service;
use App\Models\Skill;
use App\Models\User;
use Tests\TestCase;

class AdminCategoriesTest extends TestCase
{
    private function makeAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    // ── Access control ────────────────────────────────────────────────────────

    public function test_guest_cannot_access_admin_categories(): void
    {
        $this->get(route('admin.categories'))->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_admin_categories(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.categories'))->assertStatus(403);
    }

    public function test_admin_can_view_categories_list(): void
    {
        $admin = $this->makeAdmin();
        Category::factory()->count(3)->create();

        $this->actingAs($admin)->get(route('admin.categories'))->assertOk();
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function test_admin_can_create_a_category(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), [
                'name' => 'Informatique',
                'color' => '#3b82f6',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('categories', [
            'name' => 'Informatique',
            'slug' => 'informatique',
            'color' => '#3b82f6',
        ]);
    }

    public function test_create_category_validates_name_required(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), ['color' => '#3b82f6'])
            ->assertSessionHasErrors('name');
    }

    public function test_create_category_validates_color_format(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), ['name' => 'Test', 'color' => 'not-a-color'])
            ->assertSessionHasErrors('color');
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_admin_can_update_a_category(): void
    {
        $admin = $this->makeAdmin();
        $category = Category::factory()->create();

        $this->actingAs($admin)
            ->patch(route('admin.categories.update', $category), [
                'name' => 'Nouveau nom',
                'color' => '#ef4444',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Nouveau nom',
            'slug' => 'nouveau-nom',
            'color' => '#ef4444',
        ]);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function test_admin_can_delete_empty_category(): void
    {
        $admin = $this->makeAdmin();
        $category = Category::factory()->create();

        $this->actingAs($admin)
            ->delete(route('admin.categories.destroy', $category))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_admin_cannot_delete_category_with_services(): void
    {
        $admin = $this->makeAdmin();
        $org = Community::factory()->create();
        $category = Category::factory()->create();
        Service::factory()->forCategory($category)->create(['community_id' => $org->id]);

        app()->instance('current_organization', $org);

        $this->actingAs($admin)
            ->delete(route('admin.categories.destroy', $category))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_deleting_category_also_deletes_its_skills(): void
    {
        $admin = $this->makeAdmin();
        $category = Category::factory()->create();
        $skill = Skill::factory()->create(['category_id' => $category->id]);

        $this->actingAs($admin)
            ->delete(route('admin.categories.destroy', $category));

        $this->assertDatabaseMissing('skills', ['id' => $skill->id]);
    }

    // ── Skills ────────────────────────────────────────────────────────────────

    public function test_admin_can_add_skill_to_category(): void
    {
        $admin = $this->makeAdmin();
        $category = Category::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.categories.skills.store', $category), ['name' => 'PHP'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('skills', [
            'category_id' => $category->id,
            'name' => 'PHP',
            'slug' => 'php',
        ]);
    }

    public function test_admin_can_delete_a_skill(): void
    {
        $admin = $this->makeAdmin();
        $category = Category::factory()->create();
        $skill = Skill::factory()->create(['category_id' => $category->id]);

        $this->actingAs($admin)
            ->delete(route('admin.skills.destroy', $skill))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('skills', ['id' => $skill->id]);
    }
}
