<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1212 — administration Organization de la configuration IA. La cle ne
 * traverse jamais l'ecran : ecriture seule, jamais reaffichee.
 */
class TASK1212OrganizationAiAdminTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $orgAdmin;

    private User $member;

    private User $otherOrgAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        $this->orgAdmin = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->otherOrgAdmin = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        $this->organization->update(['admin_id' => $this->orgAdmin->id]);
        $this->otherOrganization->update(['admin_id' => $this->otherOrgAdmin->id]);
    }

    /**
     * TASK-1306 : `credential_management_mode` par defaut est `platform_managed`
     * — sans configuration prealable (donc sans mode explicite non plus),
     * l'admin d'Organization ne voit JAMAIS le formulaire ni le champ
     * api_key, seulement un statut.
     */
    public function test_the_organization_admin_sees_the_ai_page_without_any_configuration(): void
    {
        $html = $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.ai', $this->organization))
            ->assertOk()
            ->assertSee(__('admin.organization_ai'))
            ->assertSee('data-ai-settings-status="not-ready"', false)
            ->assertSee('data-ai-credential-managed-by="platform"', false)
            ->assertSee(__('admin.organization_ai_platform_managed_not_configured'))
            ->getContent();

        // Aucun champ api_key ni aucun formulaire de credential : seulement
        // le statut lecture seule (le seul <input type=password> possible,
        // celui du credential, est absent).
        $this->assertStringNotContainsString('data-ai-api-key-state', $html);
        $this->assertStringNotContainsString('type="password"', $html);
    }

    /**
     * TASK-1306 : une fois passee en `organization_managed` (par le
     * SuperAdmin), l'admin d'Organization retrouve le formulaire complet —
     * y compris sans avoir encore pose sa propre cle.
     */
    public function test_the_organization_admin_sees_the_editable_form_once_organization_managed_even_without_a_key_yet(): void
    {
        OrganizationAiSetting::factory()->withoutCredential()->create([
            'organization_id' => $this->organization->id,
            'credential_management_mode' => OrganizationAiSetting::CREDENTIAL_MODE_ORGANIZATION,
        ]);

        $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.ai', $this->organization))
            ->assertOk()
            ->assertSee('data-ai-api-key-state="not-set"', false)
            ->assertDontSee('data-ai-credential-managed-by="platform"', false);
    }

    public function test_a_plain_member_and_a_foreign_admin_cannot_reach_the_page(): void
    {
        $this->actingAs($this->member)
            ->get(route('organization.admin.ai', $this->organization))
            ->assertForbidden();

        $this->actingAs($this->otherOrgAdmin)
            ->get(route('organization.admin.ai', $this->organization))
            ->assertForbidden();

        $this->actingAs($this->otherOrgAdmin)
            ->put(route('organization.admin.ai.update', $this->organization), [
                'provider' => 'openrouter', 'model' => 'x', 'api_key' => 'sk-hack', 'is_enabled' => 1,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('organization_ai_settings', 0);
    }

    public function test_the_admin_configures_provider_model_key_and_budget_and_the_key_is_never_shown(): void
    {
        // TASK-1306 : le SuperAdmin a prealablement autorise cette
        // Organization a gerer son propre credential — sans quoi la requete
        // serait refusee avant meme la validation (couvert par
        // TASK1306CredentialManagementModeTest).
        OrganizationAiSetting::factory()->withoutCredential()->create([
            'organization_id' => $this->organization->id,
            'credential_management_mode' => OrganizationAiSetting::CREDENTIAL_MODE_ORGANIZATION,
        ]);

        $this->actingAs($this->orgAdmin)
            ->put(route('organization.admin.ai.update', $this->organization), [
                'provider' => 'openrouter',
                'model' => 'openai/gpt-4o-mini',
                'api_key' => 'sk-or-secret-1234',
                'monthly_budget_usd' => '5.00',
                'is_enabled' => 1,
            ])
            ->assertRedirect(route('organization.admin.ai', $this->organization))
            ->assertSessionHas('success');

        $setting = OrganizationAiSetting::query()->where('organization_id', $this->organization->id)->firstOrFail();
        $this->assertSame('openrouter', $setting->provider);
        $this->assertSame('openai/gpt-4o-mini', $setting->model);
        $this->assertSame('sk-or-secret-1234', $setting->api_key);
        $this->assertSame('5.00', (string) $setting->monthly_budget_usd);
        $this->assertTrue($setting->is_enabled);
        $this->assertNotNull($setting->api_key_updated_at);

        $html = $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.ai', $this->organization))
            ->assertOk()
            ->assertSee('data-ai-settings-status="ready"', false)
            ->assertSee('data-ai-api-key-state="set"', false)
            ->getContent();

        $this->assertStringNotContainsString('sk-or-secret-1234', $html);
        $this->assertStringNotContainsString('secret-1234', $html);
    }

    public function test_an_empty_key_field_keeps_the_existing_key_and_the_checkbox_clears_it(): void
    {
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'api_key' => 'sk-keep-me',
            'credential_management_mode' => OrganizationAiSetting::CREDENTIAL_MODE_ORGANIZATION,
        ]);

        $this->actingAs($this->orgAdmin)
            ->put(route('organization.admin.ai.update', $this->organization), [
                'provider' => 'openrouter', 'model' => 'openai/gpt-4o', 'api_key' => '', 'is_enabled' => 1,
            ])
            ->assertRedirect();

        $setting = OrganizationAiSetting::query()->where('organization_id', $this->organization->id)->firstOrFail();
        $this->assertSame('sk-keep-me', $setting->api_key);
        $this->assertSame('openai/gpt-4o', $setting->model);

        $this->actingAs($this->orgAdmin)
            ->put(route('organization.admin.ai.update', $this->organization), [
                'provider' => 'openrouter', 'model' => 'openai/gpt-4o', 'clear_api_key' => 1, 'is_enabled' => 1,
            ])
            ->assertRedirect();

        $setting->refresh();
        $this->assertNull($setting->api_key);
        $this->assertNull($setting->api_key_updated_at);

        $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.ai', $this->organization))
            ->assertSee('data-ai-settings-status="not-ready"', false);
    }

    public function test_an_unknown_provider_or_missing_model_is_refused(): void
    {
        // TASK-1306 : autorisation prealable requise (voir test precedent).
        OrganizationAiSetting::factory()->withoutCredential()->create([
            'organization_id' => $this->organization->id,
            'credential_management_mode' => OrganizationAiSetting::CREDENTIAL_MODE_ORGANIZATION,
        ]);

        $this->actingAs($this->orgAdmin)
            ->from(route('organization.admin.ai', $this->organization))
            ->put(route('organization.admin.ai.update', $this->organization), [
                'provider' => 'anthropic', 'model' => '', 'is_enabled' => 1,
            ])
            ->assertSessionHasErrors(['provider', 'model']);

        $this->assertDatabaseCount('organization_ai_settings', 1);
    }

    public function test_the_global_admin_can_manage_any_organization_configuration(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->otherOrganization->id]);

        $this->actingAs($superAdmin)
            ->put(route('organization.admin.ai.update', $this->organization), [
                'provider' => 'ollama', 'model' => 'llama3', 'is_enabled' => 0,
            ])
            ->assertRedirect();

        $setting = OrganizationAiSetting::query()->where('organization_id', $this->organization->id)->firstOrFail();
        $this->assertSame('ollama', $setting->provider);
        $this->assertFalse($setting->is_enabled);
    }
}
