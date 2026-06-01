<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class OrganizationModelTest extends TestCase
{
    public function test_organization_has_customization_fields(): void
    {
        $organization = Organization::factory()->create([
            'hero_title' => 'Bienvenue chez nous',
            'hero_description' => 'Description de la communauté',
            'accent_color' => '#ff5733',
            'welcome_points' => 200,
        ]);

        $this->assertEquals('Bienvenue chez nous', $organization->hero_title);
        $this->assertEquals('Description de la communauté', $organization->hero_description);
        $this->assertEquals('#ff5733', $organization->accent_color);
        $this->assertEquals(200, $organization->welcome_points);
    }

    public function test_organization_default_values(): void
    {
        $organization = Organization::factory()->create();

        $this->assertEquals('#6366f1', $organization->accent_color);
        $this->assertEquals(100, $organization->welcome_points);
    }

    public function test_organization_admin_relation(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->create(['admin_id' => $admin->id]);

        $this->assertTrue($organization->admin()->exists());
        $this->assertEquals($admin->id, $organization->admin->id);
    }

    public function test_organization_admin_null_when_deleted(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->create(['admin_id' => $admin->id]);

        $admin->delete();
        $organization->refresh();

        $this->assertNull($organization->admin_id);
    }

    public function test_find_by_slug_returns_active_organization(): void
    {
        Organization::factory()->create(['slug' => 'test-org', 'is_active' => true]);
        Organization::factory()->create(['slug' => 'inactive-org', 'is_active' => false]);

        $found = Organization::findBySlug('test-org');
        $this->assertNotNull($found);
        $this->assertEquals('test-org', $found->slug);
    }

    public function test_find_by_slug_returns_null_for_inactive(): void
    {
        Organization::factory()->create(['slug' => 'inactive-org', 'is_active' => false]);

        $found = Organization::findBySlug('inactive-org');
        $this->assertNull($found);
    }

    public function test_find_by_slug_returns_null_for_nonexistent(): void
    {
        $found = Organization::findBySlug('nonexistent');
        $this->assertNull($found);
    }

    public function test_get_hero_image_url_returns_asset_when_set(): void
    {
        $organization = Organization::factory()->create(['hero_image' => 'organizations/my-hero.jpg']);

        $url = $organization->getHeroImageUrl();
        $this->assertStringContainsString('organizations/my-hero.jpg', $url);
    }

    public function test_get_hero_image_url_returns_default_when_not_set(): void
    {
        $organization = Organization::factory()->create();

        $url = $organization->getHeroImageUrl();
        $this->assertStringContainsString('/images/default-hero.jpg', $url);
    }

    public function test_organization_is_fillable(): void
    {
        $organization = new Organization;

        $fillable = $organization->getFillable();

        $this->assertContains('admin_id', $fillable);
        $this->assertContains('hero_image', $fillable);
        $this->assertContains('hero_title', $fillable);
        $this->assertContains('hero_description', $fillable);
        $this->assertContains('accent_color', $fillable);
        $this->assertContains('welcome_points', $fillable);
    }
}
