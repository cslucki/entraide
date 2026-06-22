<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminCategoriesTest extends TestCase
{
    private Organization $org;

    private function makeAdmin(): User
    {
        $this->org = Organization::factory()->create(['is_active' => true]);

        return User::factory()->create(['is_admin' => true, 'organization_id' => $this->org->id]);
    }

    private function makeCategory(array $overrides = []): Category
    {
        return Category::factory()->create(array_merge(
            ['organization_id' => $this->org->id],
            $overrides
        ));
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
        $this->makeCategory(['name_b2c' => 'Test Cat']);
        $otherOrg = Organization::factory()->create(['name' => 'Autre org', 'slug' => 'autre-org']);
        Category::factory()->create(['name_b2c' => 'Autre cat', 'organization_id' => $otherOrg->id]);

        $this->actingAs($admin)->get(route('admin.categories'))
            ->assertOk()
            ->assertSee('Test Cat')
            ->assertDontSee('Autre cat')
            ->assertSee('Organisation active')
            ->assertSee($this->org->name)
            ->assertSee($this->org->slug)
            ->assertSee(Str::limit($this->org->id, 8, ''));

        $this->actingAs($admin)->get(route('admin.categories', ['organization_id' => $otherOrg->id]))
            ->assertOk()
            ->assertSee('Autre cat')
            ->assertDontSee('Test Cat')
            ->assertSee('Autre org')
            ->assertSee('autre-org');
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function test_admin_can_create_a_category(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), [
                'name_b2c' => 'Dépannage informatique',
                'name_b2b' => 'Informatique',
                'color' => '#3b82f6',
                'organization_id' => $this->org->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('categories', [
            'name_b2c' => 'Dépannage informatique',
            'name_b2b' => 'Informatique',
            'slug' => 'depannage-informatique',
            'color' => '#3b82f6',
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_create_category_validates_name_required(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), ['color' => '#3b82f6', 'organization_id' => $this->org->id])
            ->assertSessionHasErrors('name_b2c');
    }

    public function test_create_category_validates_color_format(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), ['name_b2c' => 'Test', 'name_b2b' => 'Test', 'color' => 'not-a-color', 'organization_id' => $this->org->id])
            ->assertSessionHasErrors('color');
    }

    public function test_admin_can_view_category_skills_on_edit_form(): void
    {
        $admin = $this->makeAdmin();
        $category = $this->makeCategory(['name_b2c' => 'Dépannage informatique']);
        Skill::factory()->create(['category_id' => $category->id, 'organization_id' => $this->org->id, 'name' => 'Assistance ordinateur']);

        $this->actingAs($admin)->get(route('admin.categories.edit', $category))
            ->assertOk()
            ->assertSee('Dépannage informatique')
            ->assertSee('Compétences liées')
            ->assertSee('Assistance ordinateur');
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_admin_can_update_a_category(): void
    {
        $admin = $this->makeAdmin();
        $category = $this->makeCategory();

        $this->actingAs($admin)
            ->put(route('admin.categories.update', $category), [
                'name_b2c' => 'Nouveau nom',
                'name_b2b' => 'New name',
                'color' => '#ef4444',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name_b2c' => 'Nouveau nom',
            'name_b2b' => 'New name',
            'slug' => 'nouveau-nom',
            'color' => '#ef4444',
        ]);
    }

    public function test_admin_can_update_category_from_selected_org(): void
    {
        $admin = $this->makeAdmin();
        $otherOrg = Organization::factory()->create();
        $category = Category::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($admin)
            ->put(route('admin.categories.update', $category), [
                'name_b2c' => 'Hacked',
                'name_b2b' => 'Hacked',
                'color' => '#000000',
            ])
            ->assertRedirect(route('admin.categories', ['organization_id' => $otherOrg->id]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name_b2c' => 'Hacked',
            'name_b2b' => 'Hacked',
        ]);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function test_admin_can_delete_empty_category(): void
    {
        $admin = $this->makeAdmin();
        $category = $this->makeCategory();

        $this->actingAs($admin)
            ->delete(route('admin.categories.destroy', $category))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_admin_cannot_delete_category_with_services(): void
    {
        $admin = $this->makeAdmin();
        $category = $this->makeCategory();
        Service::factory()->forCategory($category)->create(['organization_id' => $this->org->id]);

        $this->actingAs($admin)
            ->delete(route('admin.categories.destroy', $category))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_deleting_category_also_deletes_its_skills(): void
    {
        $admin = $this->makeAdmin();
        $category = $this->makeCategory();
        $skill = Skill::factory()->create(['category_id' => $category->id, 'organization_id' => $this->org->id]);

        $this->actingAs($admin)
            ->delete(route('admin.categories.destroy', $category));

        $this->assertDatabaseMissing('skills', ['id' => $skill->id]);
    }

    public function test_admin_can_delete_empty_category_from_selected_org(): void
    {
        $admin = $this->makeAdmin();
        $otherOrg = Organization::factory()->create();
        $category = Category::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($admin)
            ->delete(route('admin.categories.destroy', $category))
            ->assertRedirect(route('admin.categories', ['organization_id' => $otherOrg->id]))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    // ── Skills ────────────────────────────────────────────────────────────────

    public function test_admin_can_add_skill_to_category(): void
    {
        $admin = $this->makeAdmin();
        $category = $this->makeCategory();

        $this->actingAs($admin)
            ->post(route('admin.categories.skills.store', $category), ['name' => 'PHP'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('skills', [
            'category_id' => $category->id,
            'name' => 'PHP',
            'slug' => 'php',
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_admin_can_delete_a_skill(): void
    {
        $admin = $this->makeAdmin();
        $category = $this->makeCategory();
        $skill = Skill::factory()->create(['category_id' => $category->id, 'organization_id' => $this->org->id]);

        $this->actingAs($admin)
            ->delete(route('admin.skills.destroy', $skill))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('skills', ['id' => $skill->id]);
    }

    public function test_admin_can_delete_skill_from_selected_org(): void
    {
        $admin = $this->makeAdmin();
        $otherOrg = Organization::factory()->create();
        $category = Category::factory()->create(['organization_id' => $otherOrg->id]);
        $skill = Skill::factory()->create(['category_id' => $category->id, 'organization_id' => $otherOrg->id]);

        $this->actingAs($admin)
            ->delete(route('admin.skills.destroy', $skill))
            ->assertRedirect(route('admin.categories', ['organization_id' => $otherOrg->id]))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('skills', ['id' => $skill->id]);
    }
}
