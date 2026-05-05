<?php

namespace Tests\Feature\Admin;

use App\Models\Community;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminCommunitiesTest extends TestCase
{
    public function test_guest_cannot_access_communities(): void
    {
        $this->get(route('admin.communities'))->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_communities(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.communities'))->assertForbidden();
    }

    public function test_admin_can_view_communities_list(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Community::factory()->count(3)->create();

        $this->actingAs($admin)->get(route('admin.communities'))
            ->assertOk()
            ->assertSee(Community::first()->name);
    }

    public function test_admin_can_create_community(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.communities.store'), [
            'name'           => 'Test Community',
            'welcome_points' => 150,
        ])->assertRedirect(route('admin.communities'));

        $this->assertDatabaseHas('communities', [
            'name'           => 'Test Community',
            'slug'           => 'test-community',
            'welcome_points' => 150,
            'is_active'      => true,
        ]);
    }

    public function test_create_community_auto_generates_slug(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.communities.store'), [
            'name'           => 'My Awesome Community',
            'welcome_points' => 100,
        ])->assertRedirect(route('admin.communities'));

        $this->assertDatabaseHas('communities', ['slug' => 'my-awesome-community']);
    }

    public function test_create_community_validates_name_required(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.communities.store'), [
            'name'           => '',
            'welcome_points' => 100,
        ])->assertSessionHasErrors('name');
    }

    public function test_create_community_validates_name_unique(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Community::factory()->create(['name' => 'Existing Community']);

        $this->actingAs($admin)->post(route('admin.communities.store'), [
            'name'           => 'Existing Community',
            'welcome_points' => 100,
        ])->assertSessionHasErrors('name');
    }

    public function test_create_community_validates_slug_format(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.communities.store'), [
            'name'           => 'Bad Slug',
            'slug'           => 'BAD SLUG!',
            'welcome_points' => 100,
        ])->assertSessionHasErrors('slug');
    }

    public function test_create_community_validates_color_format(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.communities.store'), [
            'name'           => 'Color Test',
            'accent_color'   => 'not-a-color',
            'welcome_points' => 100,
        ])->assertSessionHasErrors('accent_color');
    }

    public function test_admin_can_edit_community(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $community = Community::factory()->create(['name' => 'Old Name']);

        $this->actingAs($admin)
            ->get(route('admin.communities.edit', $community))
            ->assertOk()
            ->assertSee('Old Name');
    }

    public function test_admin_can_update_community(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $community = Community::factory()->create(['name' => 'Old Name', 'slug' => 'old-name']);

        $this->actingAs($admin)->put(route('admin.communities.update', $community), [
            'name'           => 'New Name',
            'slug'           => 'new-name',
            'hero_title'     => 'Welcome!',
            'accent_color'   => '#ff0000',
            'welcome_points' => 200,
        ])->assertRedirect(route('admin.communities'));

        $community->refresh();
        $this->assertEquals('New Name', $community->name);
        $this->assertEquals('new-name', $community->slug);
        $this->assertEquals('#ff0000', $community->accent_color);
        $this->assertEquals(200, $community->welcome_points);
    }

    public function test_admin_can_toggle_community_active(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $community = Community::factory()->create(['is_active' => true]);

        $this->actingAs($admin)->post(route('admin.communities.toggle-active', $community))
            ->assertRedirect();

        $community->refresh();
        $this->assertFalse($community->is_active);
    }

    public function test_admin_can_soft_delete_community(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $community = Community::factory()->create();

        $user->update(['community_id' => $community->id]);

        $this->actingAs($admin)->delete(route('admin.communities.destroy', $community))
            ->assertRedirect();

        $user->refresh();
        $this->assertNull($user->community_id);
        $this->assertSoftDeleted('communities', ['id' => $community->id]);
    }

    public function test_soft_delete_nullifies_community_id_on_related_models(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $community = Community::factory()->create();

        $user = User::factory()->create(['community_id' => $community->id]);

        $this->actingAs($admin)->delete(route('admin.communities.destroy', $community));

        $this->assertDatabaseHas('users', ['id' => $user->id, 'community_id' => null]);
        $this->assertSoftDeleted('communities', ['id' => $community->id]);
    }

    public function test_admin_can_assign_responsable(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $responsable = User::factory()->create(['name' => 'John Doe']);
        $community = Community::factory()->create();

        $this->actingAs($admin)->put(route('admin.communities.update', $community), [
            'name'           => $community->name,
            'slug'           => $community->slug,
            'admin_id'       => $responsable->id,
            'welcome_points' => 100,
        ])->assertRedirect(route('admin.communities'));

        $community->refresh();
        $this->assertEquals($responsable->id, $community->admin_id);
    }

    public function test_admin_can_create_public_community(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.communities.store'), [
            'name'           => 'Public Community',
            'is_public'      => 1,
            'welcome_points' => 100,
        ])->assertRedirect(route('admin.communities'));

        $community = Community::where('name', 'Public Community')->first();
        $this->assertTrue($community->is_public);
    }

    public function test_admin_can_toggle_public_visibility(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $community = Community::factory()->create(['is_public' => false]);

        $this->actingAs($admin)->put(route('admin.communities.update', $community), [
            'name'           => $community->name,
            'slug'           => $community->slug,
            'is_public'      => 1,
            'welcome_points' => 100,
        ]);

        $community->refresh();
        $this->assertTrue($community->is_public);
    }

    public function test_community_index_shows_visibility_badges(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Community::factory()->create(['name' => 'Public Test', 'is_public' => true]);
        Community::factory()->create(['name' => 'Private Test', 'is_public' => false]);

        $this->actingAs($admin)->get(route('admin.communities'))
            ->assertSee('Publique')
            ->assertSee('Privée');
    }
}
