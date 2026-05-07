<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\User;
use Tests\TestCase;

class CommunityModelTest extends TestCase
{
    public function test_community_has_customization_fields(): void
    {
        $community = Community::factory()->create([
            'hero_title' => 'Bienvenue chez nous',
            'hero_description' => 'Description de la communauté',
            'accent_color' => '#ff5733',
            'welcome_points' => 200,
        ]);

        $this->assertEquals('Bienvenue chez nous', $community->hero_title);
        $this->assertEquals('Description de la communauté', $community->hero_description);
        $this->assertEquals('#ff5733', $community->accent_color);
        $this->assertEquals(200, $community->welcome_points);
    }

    public function test_community_default_values(): void
    {
        $community = Community::factory()->create();

        $this->assertEquals('#6366f1', $community->accent_color);
        $this->assertEquals(100, $community->welcome_points);
    }

    public function test_community_admin_relation(): void
    {
        $admin = User::factory()->create();
        $community = Community::factory()->create(['admin_id' => $admin->id]);

        $this->assertTrue($community->admin()->exists());
        $this->assertEquals($admin->id, $community->admin->id);
    }

    public function test_community_admin_null_when_deleted(): void
    {
        $admin = User::factory()->create();
        $community = Community::factory()->create(['admin_id' => $admin->id]);

        $admin->delete();
        $community->refresh();

        $this->assertNull($community->admin_id);
    }

    public function test_find_by_slug_returns_active_community(): void
    {
        Community::factory()->create(['slug' => 'test-community', 'is_active' => true]);
        Community::factory()->create(['slug' => 'inactive-community', 'is_active' => false]);

        $found = Community::findBySlug('test-community');
        $this->assertNotNull($found);
        $this->assertEquals('test-community', $found->slug);
    }

    public function test_find_by_slug_returns_null_for_inactive(): void
    {
        Community::factory()->create(['slug' => 'inactive-community', 'is_active' => false]);

        $found = Community::findBySlug('inactive-community');
        $this->assertNull($found);
    }

    public function test_find_by_slug_returns_null_for_nonexistent(): void
    {
        $found = Community::findBySlug('nonexistent');
        $this->assertNull($found);
    }

    public function test_get_hero_image_url_returns_asset_when_set(): void
    {
        $community = Community::factory()->create(['hero_image' => 'communities/my-hero.jpg']);

        $url = $community->getHeroImageUrl();
        $this->assertStringContainsString('communities/my-hero.jpg', $url);
    }

    public function test_get_hero_image_url_returns_default_when_not_set(): void
    {
        $community = Community::factory()->create();

        $url = $community->getHeroImageUrl();
        $this->assertStringContainsString('/images/default-hero.jpg', $url);
    }

    public function test_community_is_fillable(): void
    {
        $community = new Community();

        $fillable = $community->getFillable();

        $this->assertContains('admin_id', $fillable);
        $this->assertContains('hero_image', $fillable);
        $this->assertContains('hero_title', $fillable);
        $this->assertContains('hero_description', $fillable);
        $this->assertContains('accent_color', $fillable);
        $this->assertContains('welcome_points', $fillable);
    }
}
