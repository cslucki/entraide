<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class T1008PublicDomainRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_t1008_root_keeps_default_organization_redirect(): void
    {
        $main = Organization::factory()->create([
            'slug' => 'main',
            'is_active' => true,
            'is_default' => true,
            'homepage_template' => 'bouclepro_hero_v2',
        ]);

        $this->get('/')
            ->assertRedirect(route('organization.home', $main));
    }

    public function test_t1008_launchpals_short_route_redirects_permanently_to_org_route(): void
    {
        $response = $this->get('/launchpals');

        $response->assertStatus(301);
        $this->assertSame('/org/launchpals', parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $this->assertFalse(app()->bound('current_organization'));
    }

    public function test_t1008_demo_redirects_temporarily_to_external_demo_without_open_redirect(): void
    {
        $this->get('/demo')
            ->assertStatus(302)
            ->assertHeader('Location', 'https://lastprod.com/bouclepro-prototype/');

        $this->get('/demo?lang=fr')
            ->assertStatus(302)
            ->assertHeader('Location', 'https://lastprod.com/bouclepro-prototype/?lang=fr');

        $this->get('/demo?lang=en')
            ->assertStatus(302)
            ->assertHeader('Location', 'https://lastprod.com/bouclepro-prototype/?lang=en');

        $this->get('/demo?lang=de&next=https://evil.test')
            ->assertStatus(302)
            ->assertHeader('Location', 'https://lastprod.com/bouclepro-prototype/');
    }

    public function test_t1008_organization_routes_and_health_check_remain_compatible(): void
    {
        Organization::factory()->create([
            'slug' => 'main',
            'is_active' => true,
            'is_default' => true,
            'is_public' => true,
            'homepage_template' => 'bouclepro_hero_v2',
        ]);
        Organization::factory()->create([
            'slug' => 'launchpals',
            'is_active' => true,
            'is_public' => true,
            'homepage_template' => 'artscilab_hero',
        ]);

        $this->get('/org/main/')->assertOk();
        $this->get('/org/launchpals/')->assertOk();
        $this->get('/up')->assertOk();
    }

    public function test_t1008_generated_urls_are_local_paths_and_not_hostname_dependent(): void
    {
        $this->assertSame('/org/launchpals', route('organization.home', ['organization' => 'launchpals'], false));
        $this->assertStringNotContainsString('laravel.cloud', route('public.launchpals', absolute: false));
        $this->assertStringNotContainsString('bouclepro.com', route('public.launchpals', absolute: false));
        $this->assertSame('/launchpals', route('public.launchpals', absolute: false));
        $this->assertSame('/demo', route('public.demo', absolute: false));
    }
}
