<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1306 — cockpit central IA des Organizations : actions visibles sans
 * scroll, configuration inline depuis /admin/ai-organizations (EXACTEMENT
 * organization.admin.ai.update, aucune deuxieme autorite de credential), et
 * `credential_management_mode` (`platform_managed` par defaut /
 * `organization_managed`).
 *
 * TASK1212OrganizationAiAdminTest couvre deja la mecanique "cle ecrite,
 * jamais reaffichee" sous `organization_managed`. Ce fichier couvre ce qui
 * est NOUVEAU : la garde serveur `platform_managed`, le changement de mode
 * (reserve SuperAdmin), la configuration inline depuis le cockpit, et
 * l'invariant `main` == Organization scopee.
 */
class TASK1306CredentialManagementModeTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_A = 'sk-or-task1306-a-never-rendered';

    private const KEY_B = 'sk-or-task1306-b-never-rendered';

    private function superAdmin(): User
    {
        $platform = Organization::factory()->create(['slug' => 'plateforme-1306']);

        return User::factory()->create(['is_admin' => true, 'organization_id' => $platform->id]);
    }

    // ── Defaut platform_managed sur une ligne existante ─────────────────────

    public function test_a_newly_created_setting_defaults_to_platform_managed(): void
    {
        $organization = Organization::factory()->create();

        $setting = OrganizationAiSetting::factory()->create(['organization_id' => $organization->id]);

        $this->assertSame(
            OrganizationAiSetting::CREDENTIAL_MODE_PLATFORM,
            $setting->fresh()->credential_management_mode,
        );
    }

    public function test_an_organization_without_any_setting_row_is_effectively_platform_managed(): void
    {
        $this->assertSame(
            OrganizationAiSetting::CREDENTIAL_MODE_PLATFORM,
            OrganizationAiSetting::effectiveCredentialMode(null),
        );
    }

    // ── Garde serveur platform_managed (pas seulement l'UI) ─────────────────

    public function test_platform_managed_forbids_a_forged_put_from_the_organization_admin(): void
    {
        $organization = Organization::factory()->create();
        $orgAdmin = User::factory()->create(['organization_id' => $organization->id]);
        $organization->update(['admin_id' => $orgAdmin->id]);

        // Aucune ligne : mode effectif platform_managed par defaut.
        $this->actingAs($orgAdmin)
            ->put(route('organization.admin.ai.update', $organization), [
                'provider' => 'openrouter', 'model' => 'x', 'api_key' => 'sk-forged', 'is_enabled' => 1,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('organization_ai_settings', 0);

        // Ligne existante EXPLICITEMENT platform_managed : meme refus.
        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'credential_management_mode' => OrganizationAiSetting::CREDENTIAL_MODE_PLATFORM,
            'api_key' => self::KEY_A,
        ]);

        $this->actingAs($orgAdmin)
            ->put(route('organization.admin.ai.update', $organization), [
                'provider' => 'ollama', 'model' => 'llama3', 'api_key' => 'sk-forged-2', 'is_enabled' => 1,
            ])
            ->assertForbidden();

        $this->assertSame(self::KEY_A, OrganizationAiSetting::query()->where('organization_id', $organization->id)->value('api_key'));
    }

    public function test_platform_managed_never_leaks_the_key_field_to_the_organization_admin(): void
    {
        $organization = Organization::factory()->create();
        $orgAdmin = User::factory()->create(['organization_id' => $organization->id]);
        $organization->update(['admin_id' => $orgAdmin->id]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'api_key' => self::KEY_A,
        ]); // credential_management_mode platform_managed par defaut

        $html = $this->actingAs($orgAdmin)
            ->get(route('organization.admin.ai', $organization))
            ->assertOk()
            ->assertSee('data-ai-credential-managed-by="platform"', false)
            ->assertSee(__('admin.organization_ai_platform_managed_configured'))
            ->getContent();

        $this->assertStringNotContainsString('data-ai-api-key-state', $html);
        $this->assertStringNotContainsString('type="password"', $html);
        $this->assertStringNotContainsString(self::KEY_A, $html);
    }

    // ── SuperAdmin seul peut changer le mode ────────────────────────────────

    public function test_only_the_superadmin_can_change_the_credential_management_mode(): void
    {
        $organization = Organization::factory()->create();
        $orgAdmin = User::factory()->create(['organization_id' => $organization->id]);
        $organization->update(['admin_id' => $orgAdmin->id]);
        $member = User::factory()->create(['organization_id' => $organization->id]);
        $foreignOrganization = Organization::factory()->create();
        $foreignAdmin = User::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignOrganization->update(['admin_id' => $foreignAdmin->id]);

        foreach ([$orgAdmin, $member, $foreignAdmin] as $user) {
            $this->actingAs($user)
                ->put(route('organization.admin.ai.credential-mode.update', $organization), [
                    'credential_management_mode' => OrganizationAiSetting::CREDENTIAL_MODE_ORGANIZATION,
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('organization_ai_settings', 0);

        $superAdmin = $this->superAdmin();
        $this->actingAs($superAdmin)
            ->put(route('organization.admin.ai.credential-mode.update', $organization), [
                'credential_management_mode' => OrganizationAiSetting::CREDENTIAL_MODE_ORGANIZATION,
            ])
            ->assertRedirect(route('organization.admin.ai', $organization))
            ->assertSessionHas('success');

        $setting = OrganizationAiSetting::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(OrganizationAiSetting::CREDENTIAL_MODE_ORGANIZATION, $setting->credential_management_mode);
        // Creee sans credential : is_enabled reste false tant qu'aucune cle n'est posee.
        $this->assertFalse($setting->is_enabled);
        $this->assertNull($setting->api_key);
    }

    public function test_switching_the_mode_does_not_touch_an_existing_credential(): void
    {
        $organization = Organization::factory()->create();
        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'api_key' => self::KEY_A,
            'monthly_budget_usd' => 9.5,
        ]);

        $superAdmin = $this->superAdmin();
        $this->actingAs($superAdmin)
            ->put(route('organization.admin.ai.credential-mode.update', $organization), [
                'credential_management_mode' => OrganizationAiSetting::CREDENTIAL_MODE_ORGANIZATION,
            ])
            ->assertRedirect();

        $setting = OrganizationAiSetting::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(self::KEY_A, $setting->api_key);
        $this->assertSame('9.50', number_format((float) $setting->monthly_budget_usd, 2, '.', ''));
        $this->assertSame(OrganizationAiSetting::CREDENTIAL_MODE_ORGANIZATION, $setting->credential_management_mode);
    }

    // ── Configuration inline depuis le cockpit (meme autorite) ──────────────

    public function test_the_cockpit_shows_the_essential_columns_without_relying_on_the_end_of_a_wide_table(): void
    {
        $superAdmin = $this->superAdmin();
        Organization::factory()->create(['name' => 'Cockpit Org 1306']);

        $html = $this->actingAs($superAdmin)->get(route('admin.ai-organizations'))->assertOk()->getContent();

        $theadStart = strpos($html, '<thead');
        $theadEnd = strpos($html, '</thead>');
        $thead = substr($html, $theadStart, $theadEnd - $theadStart);

        // Les 7 colonnes essentielles, dans cet ordre, aucune metrique
        // detaillee (utilisateurs/generations/RAG/...) dans l'entete visible.
        $expectedOrder = [
            'ai.platform_col_organization', 'ai.platform_col_ready', 'ai.platform_col_credential',
            'ai.platform_col_management', 'ai.platform_col_budget', 'ai.platform_col_consumed',
        ];
        $lastPos = -1;
        foreach ($expectedOrder as $key) {
            $pos = strpos($thead, __($key));
            $this->assertNotFalse($pos, $key.' present dans l\'entete');
            $this->assertGreaterThan($lastPos, $pos, $key.' dans l\'ordre attendu');
            $lastPos = $pos;
        }
        $this->assertStringNotContainsString(__('ai.platform_col_rag'), $thead);
        $this->assertStringNotContainsString(__('ai.platform_col_last_activity'), $thead);
    }

    public function test_the_superadmin_sets_a_key_for_the_first_time_through_the_canonical_route_reached_from_the_cockpit(): void
    {
        $superAdmin = $this->superAdmin();
        $organization = Organization::factory()->create(['slug' => 'cockpit-first-setup-1306']);

        $listing = $this->actingAs($superAdmin)->get(route('admin.ai-organizations'))->assertOk()->getContent();
        $this->assertStringContainsString('data-platform-org-modal="cockpit-first-setup-1306"', $listing);
        $this->assertStringContainsString('data-platform-org-credential="not-configured"', $this->rowOf($listing, $organization));

        // Meme route que /org/{slug}/admin/ai — la modale ne fait qu'inclure
        // ce formulaire, elle ne cree aucune deuxieme autorite.
        $this->actingAs($superAdmin)
            ->put(route('organization.admin.ai.update', $organization), [
                'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => self::KEY_A,
                'monthly_budget_usd' => '10.00', 'is_enabled' => 1,
            ])
            ->assertRedirect(route('organization.admin.ai', $organization))
            ->assertSessionHas('success');

        $setting = OrganizationAiSetting::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(self::KEY_A, $setting->api_key);

        $after = $this->actingAs($superAdmin)->get(route('admin.ai-organizations'))->assertOk()->getContent();
        $this->assertStringContainsString('data-platform-org-credential="configured"', $this->rowOf($after, $organization));
        $this->assertStringNotContainsString(self::KEY_A, $after);
    }

    public function test_replacing_an_existing_key_persists_the_new_one_and_never_renders_either(): void
    {
        $superAdmin = $this->superAdmin();
        $organization = Organization::factory()->create();
        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'api_key' => self::KEY_A,
        ]);

        $this->actingAs($superAdmin)
            ->put(route('organization.admin.ai.update', $organization), [
                'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => self::KEY_B, 'is_enabled' => 1,
            ])
            ->assertRedirect();

        $setting = OrganizationAiSetting::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(self::KEY_B, $setting->api_key);
        $this->assertNotSame(self::KEY_A, $setting->api_key);

        $html = $this->actingAs($superAdmin)->get(route('admin.ai-organizations'))->assertOk()->getContent();
        $this->assertStringNotContainsString(self::KEY_A, $html);
        $this->assertStringNotContainsString(self::KEY_B, $html);
    }

    public function test_an_empty_key_field_from_the_superadmin_keeps_the_existing_key(): void
    {
        $superAdmin = $this->superAdmin();
        $organization = Organization::factory()->create();
        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'api_key' => self::KEY_A,
        ]);
        $before = DB::table('organization_ai_settings')->where('organization_id', $organization->id)->first();

        $this->actingAs($superAdmin)
            ->put(route('organization.admin.ai.update', $organization), [
                'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => '',
                'monthly_budget_usd' => '3.00', 'is_enabled' => 1,
            ])
            ->assertRedirect();

        $after = DB::table('organization_ai_settings')->where('organization_id', $organization->id)->first();
        $this->assertSame($before->api_key, $after->api_key);
        $this->assertSame($before->api_key_updated_at, $after->api_key_updated_at);
        $this->assertSame('3.00', number_format((float) $after->monthly_budget_usd, 2, '.', ''));
    }

    // ── main == Organization scopee, aucune branche `if main` ───────────────

    public function test_main_and_a_scoped_organization_behave_identically_through_the_whole_flow(): void
    {
        $superAdmin = $this->superAdmin();
        $main = Organization::factory()->create(['slug' => 'main', 'name' => 'BouclePro']);
        $scoped = Organization::factory()->create(['slug' => 'scoped-1306', 'name' => 'Scoped 1306']);

        foreach ([$main, $scoped] as $organization) {
            // Defaut platform_managed, non configuree.
            $this->assertSame(
                OrganizationAiSetting::CREDENTIAL_MODE_PLATFORM,
                OrganizationAiSetting::effectiveCredentialMode(
                    OrganizationAiSetting::query()->where('organization_id', $organization->id)->first(),
                ),
            );

            // Le SuperAdmin bascule le mode.
            $this->actingAs($superAdmin)
                ->put(route('organization.admin.ai.credential-mode.update', $organization), [
                    'credential_management_mode' => OrganizationAiSetting::CREDENTIAL_MODE_ORGANIZATION,
                ])
                ->assertRedirect(route('organization.admin.ai', $organization));

            $orgAdmin = User::factory()->create(['organization_id' => $organization->id]);
            $organization->update(['admin_id' => $orgAdmin->id]);

            // L'admin d'Organization peut desormais configurer sa propre cle.
            $this->actingAs($orgAdmin)
                ->put(route('organization.admin.ai.update', $organization), [
                    'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => self::KEY_A.'-'.$organization->slug,
                    'is_enabled' => 1,
                ])
                ->assertRedirect(route('organization.admin.ai', $organization))
                ->assertSessionHas('success');

            $setting = OrganizationAiSetting::query()->where('organization_id', $organization->id)->firstOrFail();
            $this->assertTrue($setting->hasCredential());
        }

        // Le cockpit liste les deux de facon identique.
        $listing = $this->actingAs($superAdmin)->get(route('admin.ai-organizations'))->assertOk()->getContent();
        foreach ([$main, $scoped] as $organization) {
            $this->assertStringContainsString('data-platform-org-configure="'.$organization->slug.'"', $listing);
            $this->assertStringContainsString('data-platform-org-modal="'.$organization->slug.'"', $listing);
            $this->assertStringContainsString('data-platform-org-credential="configured"', $this->rowOf($listing, $organization));
            $this->assertStringContainsString('data-platform-org-credential-mode="'.OrganizationAiSetting::CREDENTIAL_MODE_ORGANIZATION.'"', $this->rowOf($listing, $organization));
        }
    }

    // ── Invariants de securite (section 7) ───────────────────────────────────

    public function test_the_key_never_appears_in_any_data_attribute_of_the_cockpit(): void
    {
        $superAdmin = $this->superAdmin();
        $organization = Organization::factory()->create();
        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'api_key' => self::KEY_A,
        ]);

        $html = $this->actingAs($superAdmin)->get(route('admin.ai-organizations'))->assertOk()->getContent();

        $this->assertStringNotContainsString(self::KEY_A, $html);

        $cipher = (string) DB::table('organization_ai_settings')->where('organization_id', $organization->id)->value('api_key');
        $this->assertNotSame('', $cipher);
        $this->assertStringNotContainsString($cipher, $html);

        preg_match_all('/data-[a-z0-9-]+="([^"]*)"/i', $html, $dataAttrs);
        foreach ($dataAttrs[1] as $value) {
            $this->assertStringNotContainsString(self::KEY_A, $value);
            $this->assertStringNotContainsString($cipher, $value);
        }
    }

    public function test_the_replacement_field_is_always_empty_across_every_organization_on_the_cockpit(): void
    {
        $superAdmin = $this->superAdmin();
        OrganizationAiSetting::factory()->create(['organization_id' => Organization::factory()->create()->id, 'api_key' => self::KEY_A]);
        OrganizationAiSetting::factory()->withoutCredential()->create(['organization_id' => Organization::factory()->create()->id]);
        Organization::factory()->create(); // aucune ligne du tout

        $html = $this->actingAs($superAdmin)->get(route('admin.ai-organizations'))->assertOk()->getContent();

        $matched = preg_match_all('/name="api_key"[^>]*value="([^"]*)"/', $html, $matches);
        $this->assertGreaterThanOrEqual(3, $matched);
        foreach ($matches[1] as $value) {
            $this->assertSame('', $value);
        }
    }

    /** Le HTML de la ligne `<tr data-platform-org="{id}">…</tr>` de l'Organization. */
    private function rowOf(string $html, Organization $organization): string
    {
        $start = strpos($html, 'data-platform-org="'.$organization->id.'"');
        $this->assertNotFalse($start, 'ligne de '.$organization->slug.' presente');
        $end = strpos($html, '</tr>', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }
}
