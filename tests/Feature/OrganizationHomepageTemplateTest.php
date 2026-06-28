<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationHomepageTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $user;

    private Organization $otherOrg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'test-org', 'is_active' => true, 'is_public' => true]);
        $this->otherOrg = Organization::factory()->create(['slug' => 'other-org', 'is_active' => true, 'is_public' => true]);
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->user = User::factory()->create(['is_admin' => false]);
    }

    public function test_fallback_when_no_template(): void
    {
        $response = $this->get('/org/test-org');

        $response->assertOk();
        $response->assertSee('BouclePro');
    }

    public function test_custom_template_renders_hero_v2(): void
    {
        $this->org->update([
            'homepage_template' => 'bouclepro_hero_v2',
            'homepage_settings' => ['headline' => 'Custom Hero Title'],
        ]);

        $response = $this->get('/org/test-org');

        $response->assertOk();
        $response->assertSee('Custom Hero Title');
        $response->assertSee('bp-hero-v2');
    }

    public function test_tenant_isolation(): void
    {
        $this->org->update([
            'homepage_template' => 'bouclepro_hero_v2',
            'homepage_settings' => ['headline' => 'Org A Hero'],
        ]);

        $responseA = $this->get('/org/test-org');
        $responseA->assertSee('Org A Hero');

        $responseB = $this->get('/org/other-org');
        $responseB->assertOk();
        $responseB->assertDontSee('Org A Hero');
    }

    public function test_default_template_renders_fallback(): void
    {
        $this->org->update([
            'homepage_template' => 'default',
        ]);

        $response = $this->get('/org/test-org');

        $response->assertOk();
        $response->assertDontSee('bp-hero-v2');
    }

    public function test_superadmin_can_access_homepage_settings(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.organizations.homepage', $this->org));

        $response->assertOk();
        $response->assertSee('Template');
        $response->assertSee('bouclepro_hero_v2');
    }

    public function test_non_admin_cannot_access_homepage_settings(): void
    {
        $this->actingAs($this->user)->get(route('admin.organizations.homepage', $this->org))->assertForbidden();
    }

    public function test_guest_cannot_access_homepage_settings(): void
    {
        $this->get(route('admin.organizations.homepage', $this->org))->assertRedirectToRoute('login');
    }

    public function test_superadmin_can_update_template(): void
    {
        $this->actingAs($this->admin)->put(route('admin.organizations.homepage.update', $this->org), [
            'homepage_template' => 'bouclepro_hero_v2',
            'headline' => 'Test Headline',
            'subheadline' => 'Test Subheadline',
            'primary_cta_label' => 'Get Started',
            'primary_cta_url' => '/join',
            'secondary_cta_label' => 'Learn More',
            'secondary_cta_url' => '/about',
        ])->assertRedirect(route('admin.organizations.homepage', $this->org));

        $this->org->refresh();

        $this->assertEquals('bouclepro_hero_v2', $this->org->homepage_template);
        $this->assertEquals('Test Headline', $this->org->homepage_settings['headline']);
        $this->assertEquals('Test Subheadline', $this->org->homepage_settings['subheadline']);
        $this->assertEquals('Get Started', $this->org->homepage_settings['primary_cta_label']);
        $this->assertEquals('/join', $this->org->homepage_settings['primary_cta_url']);
    }

    public function test_invalid_template_is_rejected(): void
    {
        $this->actingAs($this->admin)->put(route('admin.organizations.homepage.update', $this->org), [
            'homepage_template' => 'nonexistent_template',
        ])->assertSessionHasErrors('homepage_template');
    }

    public function test_superadmin_can_reset_to_default(): void
    {
        $this->org->update([
            'homepage_template' => 'bouclepro_hero_v2',
            'homepage_settings' => ['headline' => 'Old'],
        ]);

        $this->actingAs($this->admin)->put(route('admin.organizations.homepage.update', $this->org), [
            'homepage_template' => 'default',
        ])->assertRedirect(route('admin.organizations.homepage', $this->org));

        $this->org->refresh();

        $this->assertEquals('default', $this->org->homepage_template);
    }

    public function test_homepage_settings_casts_to_array(): void
    {
        $org = Organization::factory()->create([
            'homepage_settings' => ['headline' => 'Array Test'],
        ]);

        $this->assertIsArray($org->homepage_settings);
        $this->assertEquals('Array Test', $org->homepage_settings['headline']);
    }

    public function test_homepage_settings_null_when_not_set(): void
    {
        $org = Organization::factory()->create();

        $this->assertNull($org->homepage_settings);
    }
}
