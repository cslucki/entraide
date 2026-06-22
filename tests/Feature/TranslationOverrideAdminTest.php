<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\TranslationOverride;
use App\Models\User;
use App\Services\TranslationOverrideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranslationOverrideAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->user = User::factory()->create(['is_admin' => false]);
    }

    public function test_admin_can_access_translations_page(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.translations'));

        $response->assertStatus(200);
    }

    public function test_non_admin_cannot_access_translations_page(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('admin.translations'));

        $response->assertStatus(403);
    }

    public function test_admin_can_create_global_override(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.translations.overrides.store'), [
                'organization_id' => '',
                'locale' => 'fr',
                'group' => 'home',
                'key' => 'welcome',
                'value' => 'Bienvenue sur la plateforme',
            ]);

        $response->assertRedirect(route('admin.translations'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('translation_overrides', [
            'organization_id' => null,
            'locale' => 'fr',
            'group' => 'home',
            'key' => 'welcome',
            'value' => 'Bienvenue sur la plateforme',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_admin_can_create_organization_override(): void
    {
        $organization = Organization::factory()->create();

        $response = $this->actingAs($this->admin)
            ->post(route('admin.translations.overrides.store'), [
                'organization_id' => $organization->id,
                'locale' => 'en',
                'group' => 'home',
                'key' => 'welcome',
                'value' => 'Welcome to the platform',
            ]);

        $response->assertRedirect(route('admin.translations'));
        $this->assertDatabaseHas('translation_overrides', [
            'organization_id' => $organization->id,
            'locale' => 'en',
            'value' => 'Welcome to the platform',
        ]);
    }

    public function test_admin_can_update_override(): void
    {
        $override = TranslationOverride::create([
            'locale' => 'fr',
            'group' => 'home',
            'key' => 'welcome',
            'value' => 'Ancienne valeur',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->put(route('admin.translations.overrides.update', $override), [
                'organization_id' => '',
                'locale' => 'fr',
                'group' => 'home',
                'key' => 'welcome',
                'value' => 'Nouvelle valeur',
                'is_active' => '1',
            ]);

        $response->assertRedirect(route('admin.translations'));
        $this->assertDatabaseHas('translation_overrides', [
            'id' => $override->id,
            'value' => 'Nouvelle valeur',
            'is_active' => true,
            'updated_by' => $this->admin->id,
        ]);
    }

    public function test_admin_can_deactivate_override(): void
    {
        $override = TranslationOverride::create([
            'locale' => 'fr',
            'group' => 'home',
            'key' => 'welcome',
            'value' => 'Valeur active',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->patch(route('admin.translations.overrides.deactivate', $override));

        $response->assertRedirect(route('admin.translations'));
        $this->assertDatabaseHas('translation_overrides', [
            'id' => $override->id,
            'is_active' => false,
        ]);
    }

    public function test_validation_rejects_invalid_locale(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.translations.overrides.store'), [
                'locale' => 'de',
                'group' => 'home',
                'key' => 'test',
                'value' => 'Test',
            ]);

        $response->assertSessionHasErrors('locale');
    }

    public function test_validation_rejects_nonexistent_organization(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.translations.overrides.store'), [
                'organization_id' => '00000000-0000-0000-0000-000000000000',
                'locale' => 'fr',
                'group' => 'home',
                'key' => 'test',
                'value' => 'Test',
            ]);

        $response->assertSessionHasErrors('organization_id');
    }

    public function test_validation_rejects_duplicate_override(): void
    {
        TranslationOverride::create([
            'locale' => 'fr',
            'group' => 'home',
            'key' => 'welcome',
            'value' => 'Premier',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.translations.overrides.store'), [
                'locale' => 'fr',
                'group' => 'home',
                'key' => 'welcome',
                'value' => 'Second',
            ]);

        $response->assertSessionHas('error');
        $this->assertEquals(1, TranslationOverride::where('locale', 'fr')->where('group', 'home')->where('key', 'welcome')->count());
    }

    public function test_override_resolves_after_save(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.translations.overrides.store'), [
                'locale' => 'fr',
                'group' => 'home',
                'key' => 'welcome',
                'value' => 'Override admin',
            ]);

        $service = $this->app->make(TranslationOverrideService::class);
        $result = $service->get('home', 'welcome', 'fr');

        $this->assertEquals('Override admin', $result);
    }

    public function test_organization_isolation_in_admin(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.translations.overrides.store'), [
                'organization_id' => $orgA->id,
                'locale' => 'en',
                'group' => 'home',
                'key' => 'welcome',
                'value' => 'Only for A',
            ]);

        $service = $this->app->make(TranslationOverrideService::class);

        $this->assertEquals('Only for A', $service->get('home', 'welcome', 'en', $orgA));
        $this->assertEquals('Welcome', $service->get('home', 'welcome', 'en', $orgB));
    }
}
