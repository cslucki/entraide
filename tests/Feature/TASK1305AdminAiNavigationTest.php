<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1305 — suite de l'audit AI_ADMIN_INFORMATION_ARCHITECTURE_AUDIT (25/08).
 *
 * La surface canonique du credential IA (`/org/{organization}/admin/ai`,
 * TASK-1212) fonctionnait deja identiquement pour `main` et pour toute
 * Organization scopee. Cette TASK corrige uniquement la NAVIGATION :
 *  - `/admin/ai-organizations` (TASK-1270) rejoint le menu SuperAdmin ;
 *  - `/admin/organizations/{organization}/edit` porte un resume IA lecture
 *    seule (jamais la cle) avec des liens vers les 5 surfaces org ;
 *  - les 3 items de menu Org Admin qui ne rendent qu'un `comingSoon()`
 *    (ai-supervision, member-ai-profiles, ai-interactions) sont retires du
 *    menu, sans toucher aux routes/controllers.
 *
 * Aucune branche `if main` : les tests le prouvent en exercant le meme
 * chemin avec le slug `main` et avec un slug quelconque.
 */
class TASK1305AdminAiNavigationTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-or-task1305-never-rendered';

    private function superAdmin(): User
    {
        $platform = Organization::factory()->create(['slug' => 'plateforme-1305']);

        return User::factory()->create(['is_admin' => true, 'organization_id' => $platform->id]);
    }

    // ── SuperAdmin menu ─────────────────────────────────────────────────────

    public function test_superadmin_menu_links_to_ai_organizations(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('href="'.route('admin.ai-organizations').'"', false)
            ->assertSee('Organizations &amp; IA', false);
    }

    public function test_superadmin_menu_labels_ai_config_as_platform_scoped(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('href="'.route('admin.ai-config').'"', false)
            ->assertSee(__('admin.ai_config_title'));
    }

    public function test_admin_ai_config_page_shows_platform_scope_intro(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->get(route('admin.ai-config'))
            ->assertOk()
            ->assertSee(__('admin.ai_config_intro'));
    }

    // ── /admin/organizations/{organization}/edit : résumé IA ────────────────

    public function test_organization_edit_shows_ai_summary_for_a_configured_organization(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create(['name' => 'Configurée 1305', 'slug' => 'configuree-1305']);
        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => self::KEY,
            'monthly_budget_usd' => 8.50,
            'is_enabled' => true,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.organizations.edit', $organization))
            ->assertOk();

        $response->assertSee(__('admin.organization_ai_summary_ready'))
            ->assertSee('openrouter')
            ->assertSee('openai/gpt-4o-mini')
            ->assertSee('$8.50', false)
            ->assertSee('href="'.route('organization.admin.ai', ['organization' => $organization->slug]).'"', false)
            ->assertSee('href="'.route('organization.admin.ai-cockpit', ['organization' => $organization->slug]).'"', false)
            ->assertSee('href="'.route('organization.admin.ai-behavior', ['organization' => $organization->slug]).'"', false)
            ->assertSee('href="'.route('organization.admin.ai-knowledge', ['organization' => $organization->slug]).'"', false)
            ->assertSee('href="'.route('organization.admin.ai-consumption', ['organization' => $organization->slug]).'"', false);

        $this->assertStringNotContainsString(self::KEY, $response->getContent());
    }

    public function test_organization_edit_shows_not_configured_for_an_organization_without_setting(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create(['name' => 'Sans reglage 1305', 'slug' => 'sans-reglage-1305']);

        $this->actingAs($admin)
            ->get(route('admin.organizations.edit', $organization))
            ->assertOk()
            ->assertSee(__('admin.organization_ai_summary_not_ready'))
            ->assertSee('href="'.route('organization.admin.ai', ['organization' => $organization->slug]).'"', false);
    }

    /**
     * L'invariant du GO : `main` suit exactement le même chemin, sans
     * branche spéciale. Même assertions que l'Organization sans réglage
     * ci-dessus, sur le slug `main`.
     */
    public function test_organization_edit_works_identically_for_the_main_slug(): void
    {
        $admin = $this->superAdmin();
        $main = Organization::factory()->create(['name' => 'BouclePro', 'slug' => 'main']);

        $this->actingAs($admin)
            ->get(route('admin.organizations.edit', $main))
            ->assertOk()
            ->assertSee(__('admin.organization_ai_summary_not_ready'))
            ->assertSee('href="'.route('organization.admin.ai', ['organization' => 'main']).'"', false)
            ->assertSee('href="'.route('organization.admin.ai-cockpit', ['organization' => 'main']).'"', false)
            ->assertSee('href="'.route('organization.admin.ai-behavior', ['organization' => 'main']).'"', false)
            ->assertSee('href="'.route('organization.admin.ai-knowledge', ['organization' => 'main']).'"', false)
            ->assertSee('href="'.route('organization.admin.ai-consumption', ['organization' => 'main']).'"', false);
    }

    public function test_organization_edit_ai_block_never_renders_the_api_key(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create(['slug' => 'secret-1305']);
        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => self::KEY,
            'monthly_budget_usd' => 3,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.organizations.edit', $organization))
            ->assertOk();

        $html = $response->getContent();
        $this->assertStringNotContainsString(self::KEY, $html);
        $this->assertStringNotContainsString('sk-or-', $html);

        foreach (\Illuminate\Support\Facades\DB::table('organization_ai_settings')->pluck('api_key') as $cipher) {
            $this->assertStringNotContainsString((string) $cipher, $html);
        }

        $start = strpos($html, 'data-admin-org-ai-block');
        $this->assertNotFalse($start, 'bloc IA present sur la page');
        $end = strpos($html, '</div>', strpos($html, '<div class="flex flex-wrap', $start));
        $block = substr($html, $start, $end - $start);
        $this->assertStringNotContainsString('<form', $block);
        $this->assertStringNotContainsString('<input', $block);
        $this->assertStringNotContainsString('api_key', $block);
    }

    // ── Org Admin menu : placeholders masqués, invariant main/scoped ───────

    public function test_org_admin_menu_hides_coming_soon_items_when_ai_profiles_disabled(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create(['slug' => 'org-1305-off', 'ai_profiles_enabled' => false]);

        $this->actingAs($admin)
            ->get(route('organization.admin.dashboard', $organization->slug))
            ->assertOk()
            ->assertDontSee(__('navigation.org_admin_ai_supervision'))
            ->assertDontSee(__('navigation.org_admin_member_ai_profiles'))
            ->assertDontSee(__('navigation.org_admin_ai_interactions'));
    }

    /**
     * Le point nouveau : même avec `ai_profiles_enabled = true`, les 3
     * pages `comingSoon()` restent hors menu — masquées tant qu'elles ne
     * sont pas réelles, pas seulement quand le flag est déjà à faux.
     */
    public function test_org_admin_menu_hides_coming_soon_items_even_when_ai_profiles_enabled(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create(['slug' => 'org-1305-on', 'ai_profiles_enabled' => true]);

        $this->actingAs($admin)
            ->get(route('organization.admin.dashboard', $organization->slug))
            ->assertOk()
            ->assertDontSee(__('navigation.org_admin_ai_supervision'))
            ->assertDontSee(__('navigation.org_admin_member_ai_profiles'))
            ->assertDontSee(__('navigation.org_admin_ai_interactions'));
    }

    /**
     * Invariant du GO : le menu Org Admin IA est structurellement identique
     * pour `main` et pour une Organization scopée quelconque — mêmes 5
     * liens réels, mêmes 3 liens absents, aucune branche `if main`.
     */
    public function test_org_admin_ia_menu_is_identical_for_main_and_a_scoped_organization(): void
    {
        $admin = $this->superAdmin();
        $main = Organization::factory()->create(['slug' => 'main', 'ai_profiles_enabled' => false]);
        $scoped = Organization::factory()->create(['slug' => 'scoped-1305', 'ai_profiles_enabled' => true]);

        foreach ([$main, $scoped] as $organization) {
            $this->actingAs($admin)
                ->get(route('organization.admin.dashboard', $organization->slug))
                ->assertOk()
                ->assertSee('href="'.route('organization.admin.ai-cockpit', ['organization' => $organization->slug]).'"', false)
                ->assertSee('href="'.route('organization.admin.ai', ['organization' => $organization->slug]).'"', false)
                ->assertSee('href="'.route('organization.admin.ai-behavior', ['organization' => $organization->slug]).'"', false)
                ->assertSee('href="'.route('organization.admin.ai-knowledge', ['organization' => $organization->slug]).'"', false)
                ->assertSee('href="'.route('organization.admin.ai-consumption', ['organization' => $organization->slug]).'"', false)
                ->assertDontSee(__('navigation.org_admin_ai_supervision'))
                ->assertDontSee(__('navigation.org_admin_member_ai_profiles'))
                ->assertDontSee(__('navigation.org_admin_ai_interactions'));
        }
    }

    // ── Routes/controllers conservés (comingSoon toujours atteignable par URL) ──

    public function test_coming_soon_routes_still_resolve_when_visited_directly(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create(['slug' => 'org-1305-direct', 'ai_profiles_enabled' => true]);

        $this->actingAs($admin)
            ->get(route('organization.admin.ai-supervision', $organization->slug))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('organization.admin.member-ai-profiles', $organization->slug))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('organization.admin.ai-interactions', $organization->slug))
            ->assertOk();
    }
}
