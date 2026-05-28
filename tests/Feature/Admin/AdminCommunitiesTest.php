<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminCommunitiesTest extends TestCase
{
    public function test_guest_cannot_access_communities(): void
    {
        $this->get(route('admin.organizations'))->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_communities(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.organizations'))->assertForbidden();
    }

    public function test_admin_can_view_communities_list(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Organization::factory()->count(3)->create();

        $this->actingAs($admin)->get(route('admin.organizations'))
            ->assertOk()
            ->assertSee(Organization::first()->name);
    }

    public function test_admin_can_create_community(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.organizations.store'), [
            'name'           => 'Test Community',
            'welcome_points' => 150,
        ])->assertRedirect(route('admin.organizations'));

        $this->assertDatabaseHas('organizations', [
            'name'           => 'Test Community',
            'slug'           => 'test-community',
            'welcome_points' => 150,
            'is_active'      => true,
        ]);
    }

    public function test_create_community_auto_generates_slug(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.organizations.store'), [
            'name'           => 'My Awesome Community',
            'welcome_points' => 100,
        ])->assertRedirect(route('admin.organizations'));

        $this->assertDatabaseHas('organizations', ['slug' => 'my-awesome-community']);
    }

    public function test_create_community_validates_name_required(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.organizations.store'), [
            'name'           => '',
            'welcome_points' => 100,
        ])->assertSessionHasErrors('name');
    }

    public function test_create_community_validates_name_unique(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Organization::factory()->create(['name' => 'Existing Community']);

        $this->actingAs($admin)->post(route('admin.organizations.store'), [
            'name'           => 'Existing Community',
            'welcome_points' => 100,
        ])->assertSessionHasErrors('name');
    }

    public function test_create_community_validates_slug_format(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.organizations.store'), [
            'name'           => 'Bad Slug',
            'slug'           => 'BAD SLUG!',
            'welcome_points' => 100,
        ])->assertSessionHasErrors('slug');
    }

    public function test_create_community_validates_color_format(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.organizations.store'), [
            'name'           => 'Color Test',
            'accent_color'   => 'not-a-color',
            'welcome_points' => 100,
        ])->assertSessionHasErrors('accent_color');
    }

    public function test_admin_can_edit_community(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $organization = Organization::factory()->create(['name' => 'Old Name']);

        $this->actingAs($admin)
            ->get(route('admin.organizations.edit', $organization))
            ->assertOk()
            ->assertSee('Old Name');
    }

    public function test_admin_can_update_community(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $organization = Organization::factory()->create(['name' => 'Old Name', 'slug' => 'old-name']);

        $this->actingAs($admin)->put(route('admin.organizations.update', $organization), [
            'name'           => 'New Name',
            'slug'           => 'new-name',
            'hero_title'     => 'Welcome!',
            'accent_color'   => '#ff0000',
            'welcome_points' => 200,
        ])->assertRedirect(route('admin.organizations'));

        $organization->refresh();
        $this->assertEquals('New Name', $organization->name);
        $this->assertEquals('new-name', $organization->slug);
        $this->assertEquals('#ff0000', $organization->accent_color);
        $this->assertEquals(200, $organization->welcome_points);
    }

    public function test_admin_can_toggle_community_active(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $organization = Organization::factory()->create(['is_active' => true]);

        $this->actingAs($admin)->post(route('admin.organizations.toggle-active', $organization))
            ->assertRedirect();

        $organization->refresh();
        $this->assertFalse($organization->is_active);
    }

    public function test_admin_can_soft_delete_community(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $user->update(['organization_id' => $organization->id]);

        $this->actingAs($admin)->delete(route('admin.organizations.destroy', $organization))
            ->assertRedirect();

        $user->refresh();
        $this->assertNull($user->organization_id);
        $this->assertSoftDeleted('organizations', ['id' => $organization->id]);
    }

    public function test_soft_delete_nullifies_community_id_on_related_models(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $organization = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($admin)->delete(route('admin.organizations.destroy', $organization));

        $this->assertDatabaseHas('users', ['id' => $user->id, 'organization_id' => null]);
        $this->assertSoftDeleted('organizations', ['id' => $organization->id]);
    }

    public function test_admin_can_assign_responsable(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $responsable = User::factory()->create(['name' => 'John Doe']);
        $organization = Organization::factory()->create();

        $this->actingAs($admin)->put(route('admin.organizations.update', $organization), [
            'name'           => $organization->name,
            'slug'           => $organization->slug,
            'admin_id'       => $responsable->id,
            'welcome_points' => 100,
        ])->assertRedirect(route('admin.organizations'));

        $organization->refresh();
        $this->assertEquals($responsable->id, $organization->admin_id);
    }

    public function test_admin_can_create_public_community(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.organizations.store'), [
            'name'           => 'Public Community',
            'is_public'      => 1,
            'welcome_points' => 100,
        ])->assertRedirect(route('admin.organizations'));

        $organization = Organization::where('name', 'Public Community')->first();
        $this->assertTrue($organization->is_public);
    }

    public function test_admin_can_toggle_public_visibility(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $organization = Organization::factory()->create(['is_public' => false]);

        $this->actingAs($admin)->put(route('admin.organizations.update', $organization), [
            'name'           => $organization->name,
            'slug'           => $organization->slug,
            'is_public'      => 1,
            'welcome_points' => 100,
        ]);

        $organization->refresh();
        $this->assertTrue($organization->is_public);
    }

    public function test_community_index_shows_visibility_badges(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Organization::factory()->create(['name' => 'Public Test', 'is_public' => true]);
        Organization::factory()->create(['name' => 'Private Test', 'is_public' => false]);

        $this->actingAs($admin)->get(route('admin.organizations'))
            ->assertSee('Publique')
            ->assertSee('Privée');
    }
}
