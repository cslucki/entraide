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
            'homepage_settings' => ['subheadline' => 'Custom Hero Subtitle'],
        ]);

        $response = $this->get('/org/test-org');

        $response->assertOk();
        $response->assertSee('Custom Hero Subtitle');
        $response->assertSee('bp-hero-v2');
        $response->assertSee('https://bouclepro.com/demo');
        $response->assertSee(route('about'));
        $response->assertSee(route('organization.subscriptions', $this->org));
        $response->assertDontSee('theme-toggle');
    }

    public function test_authenticated_hero_menu_matches_default_user_items(): void
    {
        $this->org->update(['homepage_template' => 'bouclepro_hero_v2']);
        $this->user->update(['organization_id' => $this->org->id]);

        $response = $this->actingAs($this->user)->get('/org/test-org');

        $response->assertOk();
        $response->assertSee(__('navigation.dashboard'));
        $response->assertSee(__('navigation.profile'));
        $response->assertSee(__('navigation.settings'));
        $response->assertSee(__('navigation.points_history'));
        $response->assertSee(__('navigation.favorites'));
        $response->assertSee(__('navigation.help'));
        $response->assertSee(__('navigation.report_bug'));
        $response->assertSee(__('navigation.logout'));
        $response->assertSee(__('navigation.legal_notices'));
        $response->assertSee(__('navigation.version').' '.config('app.version'));
    }

    public function test_tenant_isolation(): void
    {
        $this->org->update([
            'homepage_template' => 'bouclepro_hero_v2',
            'homepage_settings' => ['subheadline' => 'Org A Hero'],
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
        $response->assertSee('BouclePro_Hero');
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
            'subheadline' => 'Test Subheadline',
            'card_create_label' => 'Custom Card Create',
            'card_meet_label' => 'Custom Card Meet',
            'card_help_label' => 'Custom Card Help',
            'card_offer_label' => 'Custom Card Offer',
            'ai_note' => 'Custom AI note',
            'primary_cta_label' => 'Get Started',
            'primary_cta_url' => '/join',
            'secondary_cta_label' => 'Learn More',
            'secondary_cta_url' => 'https://bouclepro.com/demo',
        ])->assertRedirect(route('admin.organizations.homepage', $this->org));

        $this->org->refresh();

        $this->assertEquals('bouclepro_hero_v2', $this->org->homepage_template);
        $this->assertEquals('Test Subheadline', $this->org->homepage_settings['subheadline']);
        $this->assertEquals('Custom Card Create', $this->org->homepage_settings['card_create_label']);
        $this->assertEquals('Custom AI note', $this->org->homepage_settings['ai_note']);
        $this->assertEquals('Get Started', $this->org->homepage_settings['primary_cta_label']);
        $this->assertEquals('/join', $this->org->homepage_settings['primary_cta_url']);
    }

    public function test_homepage_settings_update_are_visible_on_public_hero(): void
    {
        $this->actingAs($this->admin)->put(route('admin.organizations.homepage.update', $this->org), [
            'homepage_template' => 'bouclepro_hero_v2',
            'subheadline' => 'Visible public subtitle',
            'card_create_label' => 'Visible Create Card',
            'card_meet_label' => 'Visible Meet Card',
            'card_help_label' => 'Visible Help Card',
            'card_offer_label' => 'Visible Offer Card',
            'ai_note' => 'Visible AI note',
            'primary_cta_label' => 'Visible Primary CTA',
            'primary_cta_url' => '/custom-primary',
            'secondary_cta_label' => 'Visible Secondary CTA',
            'secondary_cta_url' => 'https://bouclepro.com/demo',
        ]);

        $response = $this->get('/org/test-org');

        $response->assertOk();
        $response->assertSee('Visible public subtitle');
        $response->assertSee('Visible Create Card');
        $response->assertSee('Visible Meet Card');
        $response->assertSee('Visible Help Card');
        $response->assertSee('Visible Offer Card');
        $response->assertSee('Visible AI note');
        $response->assertSee('Visible Primary CTA');
        $response->assertSee('/custom-primary');
        $response->assertSee('Visible Secondary CTA');
    }

    public function test_about_page_is_available_and_translated(): void
    {
        $this->get('/about')
            ->assertOk()
            ->assertSee(__('about.title'))
            ->assertSee('about-caveat');
    }

    public function test_unsafe_homepage_cta_url_is_rejected(): void
    {
        $this->actingAs($this->admin)->put(route('admin.organizations.homepage.update', $this->org), [
            'homepage_template' => 'bouclepro_hero_v2',
            'primary_cta_url' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('primary_cta_url');
    }

    public function test_admin_homepages_page_uses_homepage_design_title(): void
    {
        $this->org->update(['homepage_template' => 'bouclepro_hero_v2']);

        $response = $this->actingAs($this->admin)->get(route('admin.homepages'));

        $response->assertOk();
        $response->assertSee('Homepage design');
        $response->assertSee('BouclePro_Hero');
    }

    public function test_root_redirects_to_default_organization_custom_homepage(): void
    {
        $this->org->update([
            'is_default' => true,
            'homepage_template' => 'bouclepro_hero_v2',
        ]);

        $this->get('/')->assertRedirect(route('organization.home', $this->org));
    }

    public function test_root_redirects_to_main_custom_homepage_when_no_default_organization(): void
    {
        $this->org->update([
            'slug' => 'main',
            'is_default' => false,
            'homepage_template' => 'bouclepro_hero_v2',
        ]);

        $this->get('/')->assertRedirect(route('organization.home', $this->org));
    }

    public function test_hero_uses_member_avatar_urls_not_inline_letter_svgs(): void
    {
        User::factory()->count(3)->create(['organization_id' => $this->org->id]);

        $this->org->update(['homepage_template' => 'bouclepro_hero_v2']);

        $response = $this->get('/org/test-org');

        $response->assertOk();
        $response->assertSee('ui-avatars.com', false);
        $response->assertDontSee('data:image/svg+xml', false);
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
            'homepage_settings' => ['subheadline' => 'Old'],
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
            'homepage_settings' => ['subheadline' => 'Array Test'],
        ]);

        $this->assertIsArray($org->homepage_settings);
        $this->assertEquals('Array Test', $org->homepage_settings['subheadline']);
    }

    public function test_homepage_settings_null_when_not_set(): void
    {
        $org = Organization::factory()->create();

        $this->assertNull($org->homepage_settings);
    }
}
