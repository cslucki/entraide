<?php

namespace Tests\Feature;

use App\Ai\Agents\GuestShellAgent;
use App\Models\AdminAiPrompt;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\OrganizationGuestShellPolicy;
use App\Models\User;
use App\Services\GuestShell\GuestPageContextResolver;
use App\Services\GuestShell\GuestShellDisplayModeResolver;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Support\GuestShell\GuestPageContext;
use App\Support\GuestShell\GuestShellDisplay;
use App\Support\GuestShell\GuestShellDisplayMode;
use App\Support\GuestShell\GuestShellState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * TASK-1441 — Shell display modes OFF / OVERLAY / SHELL_FIRST (MASTER Q69).
 * OFF = `enabled = false`, unique autorite ; `display_mode` = overlay |
 * shell_first ; decision EFFECTIVE fail-closed (politique, prompt, page) ;
 * SuperAdmin ecrit, OrgAdmin lit ; `organization_home` seule surface V1.
 */
class TASK1441GuestShellDisplayModeTest extends TestCase
{
    use RefreshDatabase;

    private const SW6_TABLES = ['guest_visitors', 'guest_conversations', 'guest_messages'];

    private Organization $org;

    private Organization $other;

    private User $superAdmin;

    private User $orgAdmin;

    private GuestShellPolicyService $policies;

    private GuestShellDisplayModeResolver $display;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 5.0, 'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0]);
        $this->org = Organization::factory()->create(['slug' => 'org-a-14xx', 'name' => 'Alpha Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->other = Organization::factory()->create(['slug' => 'org-b-14xx', 'name' => 'Beta Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->orgAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $this->org->update(['admin_id' => $this->orgAdmin->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);
        $this->policies = app(GuestShellPolicyService::class);
        $this->display = app(GuestShellDisplayModeResolver::class);
    }

    private function ready(): void
    {
        $this->policies->update($this->org, ['enabled' => true, 'max_messages' => 10]);
        OrganizationAiSetting::create(['organization_id' => $this->org->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test', 'is_enabled' => true]);
        $this->assertSame(GuestShellState::ACTIVE, $this->policies->state($this->org->fresh())->status);
    }

    private function page(string $path, ?Organization $organization = null): ?GuestPageContext
    {
        return app(GuestPageContextResolver::class)->fromRoute($organization ?? $this->org->fresh(), Route::getRoutes()->match(Request::create($path, 'GET')));
    }

    // ── 1. Persistance : jamais `off`, defaut technique overlay, rien de public par lui-meme ──

    public function test_display_mode_is_bounded_to_overlay_or_shell_first_and_defaults_to_overlay_without_making_anything_public(): void
    {
        $this->assertTrue(Schema::hasColumn('organization_guest_shell_policies', 'display_mode'));
        $this->assertSame(['overlay', 'shell_first'], GuestShellDisplayMode::MODES);

        $policy = $this->policies->update($this->org, ['max_messages' => 5]);
        $this->assertSame(GuestShellDisplayMode::OVERLAY, $policy->fresh()->display_mode, 'defaut technique');

        // Les politiques EXISTANTES au moment de la migration : la colonne prend overlay sans rien rendre public.
        DB::table('organization_guest_shell_policies')->insert(['id' => (string) Str::uuid(), 'organization_id' => $this->other->id, 'enabled' => false, 'max_messages' => 10, 'retention_days' => 90, 'created_at' => now(), 'updated_at' => now()]);
        $legacy = $this->policies->policyFor($this->other);
        $this->assertTrue($legacy->exists);
        $this->assertSame(GuestShellDisplayMode::OVERLAY, $legacy->display_mode, 'defaut de la colonne = overlay');
        $this->assertSame(GuestShellDisplay::REASON_DISABLED, $this->display->resolve($this->other->fresh(), $this->page('/org/org-b-14xx', $this->other))->reason);
        $this->assertFalse($policy->fresh()->enabled, 'le defaut ne rend rien public');
        $this->assertSame(GuestShellDisplay::OFF, $this->display->resolve($this->org->fresh(), $this->page('/org/org-a-14xx'))->mode);

        $this->policies->update($this->org, ['display_mode' => GuestShellDisplayMode::SHELL_FIRST]);
        $this->assertSame(GuestShellDisplayMode::SHELL_FIRST, $policy->fresh()->display_mode);
        $this->policies->update($this->org, ['max_messages' => 7]);
        $this->assertSame(GuestShellDisplayMode::SHELL_FIRST, $policy->fresh()->display_mode, 'absent = conserve');

        foreach (['off', 'OFF', 'hidden', '', 'shell-first'] as $invalid) {
            try {
                $this->policies->update($this->org, ['display_mode' => $invalid]);
                $this->fail("refuse [{$invalid}] : OFF n'est pas un mode, enabled=false est l'unique autorite");
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(GuestShellDisplayMode::SHELL_FIRST, $policy->fresh()->display_mode);
    }

    // ── 2. La decision effective, dans l'ordre ─────────────────────────────

    public function test_the_effective_display_is_decided_in_order_and_fails_closed(): void
    {
        $home = $this->page('/org/org-a-14xx');
        $this->assertSame(GuestPageContext::KIND_ORGANIZATION_HOME, $home->kind);

        // 1. enabled=false → off/disabled, quel que soit le mode choisi.
        $this->policies->update($this->org, ['enabled' => false, 'display_mode' => GuestShellDisplayMode::SHELL_FIRST]);
        $decision = $this->display->resolve($this->org->fresh(), $home);
        $this->assertSame([GuestShellDisplay::OFF, GuestShellDisplay::REASON_DISABLED], [$decision->mode, $decision->reason]);
        $this->assertFalse($decision->isVisible());

        // 2. enabled mais politique pas prete (aucune autorite IA) → off/policy_not_ready.
        $this->policies->update($this->org, ['enabled' => true]);
        $decision = $this->display->resolve($this->org->fresh(), $home);
        $this->assertSame([GuestShellDisplay::OFF, GuestShellDisplay::REASON_POLICY_NOT_READY, GuestShellState::NO_CREDENTIAL], [$decision->mode, $decision->reason, $decision->policyStatus]);

        OrganizationAiSetting::create(['organization_id' => $this->org->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test', 'is_enabled' => true]);
        config(['ai.guest_shell.platform_monthly_ceiling_usd' => null]);
        $decision = $this->display->resolve($this->org->fresh(), $home);
        $this->assertSame([GuestShellDisplay::OFF, GuestShellDisplay::REASON_POLICY_NOT_READY, GuestShellState::MISCONFIGURED], [$decision->mode, $decision->reason, $decision->policyStatus], 'plafond plateforme non fixe = pas pret');
        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 5.0]);

        // 3. politique ACTIVE mais aucun prompt actif → off/no_active_prompt.
        AdminAiPrompt::where('scenario_id', 'guest_shell_welcome')->update(['is_active' => false]);
        $decision = $this->display->resolve($this->org->fresh(), $home);
        $this->assertSame([GuestShellDisplay::OFF, GuestShellDisplay::REASON_NO_ACTIVE_PROMPT, GuestShellState::ACTIVE], [$decision->mode, $decision->reason, $decision->policyStatus]);
        AdminAiPrompt::where('scenario_id', 'guest_shell_welcome')->update(['is_active' => true]);

        // 4. page absente / non eligible / d'une autre Organization → off/page_not_eligible.
        $this->assertSame(GuestShellDisplay::REASON_PAGE_NOT_ELIGIBLE, $this->display->resolve($this->org->fresh(), null)->reason);
        $this->assertSame(GuestShellDisplay::REASON_PAGE_NOT_ELIGIBLE, $this->display->resolve($this->org->fresh(), $this->page('/org/org-a-14xx/register'))->reason, 'jamais un Shell sur l\'inscription (V1)');
        $this->assertSame(GuestShellDisplay::REASON_PAGE_NOT_ELIGIBLE, $this->display->resolve($this->org->fresh(), $this->page('/org/org-b-14xx', $this->other))->reason, 'page d\'une autre Organization : ferme');

        // 5. tout est pret → le mode choisi.
        $decision = $this->display->resolve($this->org->fresh(), $home);
        $this->assertSame([GuestShellDisplayMode::SHELL_FIRST, GuestShellDisplayMode::SHELL_FIRST, GuestShellState::ACTIVE], [$decision->mode, $decision->reason, $decision->policyStatus]);
        $this->assertTrue($decision->isVisible());
        $this->policies->update($this->org, ['display_mode' => GuestShellDisplayMode::OVERLAY]);
        $this->assertSame(GuestShellDisplayMode::OVERLAY, $this->display->resolve($this->org->fresh(), $home)->mode);
    }

    public function test_the_display_decision_never_calls_a_provider_nor_reads_visitors_conversations_or_messages(): void
    {
        $this->ready();
        GuestShellAgent::fake([]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $decision = $this->display->resolve($this->org->fresh(), $this->page('/org/org-a-14xx'));
        $this->assertTrue($decision->isVisible());
        GuestShellAgent::assertNeverPrompted();
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            foreach (self::SW6_TABLES as $table) {
                $this->assertDoesNotMatchRegularExpression('/[`"]'.preg_quote($table, '/').'[`"]/', $sql, "SW-6 en double : {$table} — {$sql}");
            }
        }
    }

    public function test_the_workshop_kinds_are_not_eligible_even_when_the_policy_is_ready(): void
    {
        $this->ready();
        $this->assertSame([GuestPageContext::KIND_ORGANIZATION_HOME], GuestShellDisplayModeResolver::ELIGIBLE_KINDS);
        $workshop = new GuestPageContext((string) $this->org->id, GuestPageContext::KIND_WORKSHOP_PAGE, 'w1', 'Atelier', null, 'organization.workshops.show');
        $this->assertSame(GuestShellDisplay::REASON_PAGE_NOT_ELIGIBLE, $this->display->resolve($this->org->fresh(), $workshop)->reason);
    }

    // ── 3. Gouvernance : SuperAdmin ecrit, OrgAdmin lit ────────────────────

    public function test_the_super_admin_chooses_the_mode_in_ai_config_and_the_org_admin_only_reads_it(): void
    {
        $this->ready();
        $payload = ['organization_id' => $this->org->id, 'enabled' => '1', 'max_messages' => 10, 'retention_days' => 90, 'guest_monthly_budget_usd' => '', 'display_mode' => 'shell_first'];

        $this->actingAs($this->orgAdmin)->post(route('admin.ai-config.guest-shell'), $payload)->assertForbidden();
        $this->assertSame(GuestShellDisplayMode::OVERLAY, OrganizationGuestShellPolicy::forOrganization($this->org)->display_mode);

        $this->actingAs($this->superAdmin)->post(route('admin.ai-config.guest-shell'), $payload)->assertRedirect(route('admin.ai-config'))->assertSessionHasNoErrors();
        $this->assertSame(GuestShellDisplayMode::SHELL_FIRST, OrganizationGuestShellPolicy::forOrganization($this->org)->display_mode);
        $this->actingAs($this->superAdmin)->post(route('admin.ai-config.guest-shell'), array_merge($payload, ['display_mode' => 'off']))->assertSessionHasErrors('display_mode');
        $this->assertSame(GuestShellDisplayMode::SHELL_FIRST, OrganizationGuestShellPolicy::forOrganization($this->org)->display_mode);

        $html = $this->actingAs($this->superAdmin)->get(route('admin.ai-config'))->assertOk()->getContent();
        $block = substr($html, strpos($html, 'data-guest-shell-org="org-a-14xx"'));
        $block = substr($block, 0, strpos($block, '</form>'));
        $this->assertStringContainsString('name="display_mode"', $block);
        $this->assertStringContainsString('data-guest-shell-display-mode="shell_first"', $block);
        $this->assertStringNotContainsString('value="off"', $block, 'OFF n\'est pas proposé comme mode');

        $consumption = $this->actingAs($this->orgAdmin)->get(route('organization.admin.ai-consumption', ['organization' => $this->org->slug]))->assertOk()->getContent();
        $this->assertStringContainsString('data-consumption-guest-mode="shell_first"', $consumption, 'l\'OrgAdmin voit le mode');
        $this->assertStringNotContainsString('name="display_mode"', $consumption, 'lecture seule : aucun formulaire');
        $this->assertStringContainsString('data-consumption-guest-effective="shell_first"', $consumption, 'et la decision effective sur l\'accueil public');
    }

    public function test_the_org_admin_sees_the_effective_decision_not_only_the_preference(): void
    {
        $this->policies->update($this->org, ['enabled' => true, 'display_mode' => GuestShellDisplayMode::SHELL_FIRST]);
        $consumption = $this->actingAs($this->orgAdmin)->get(route('organization.admin.ai-consumption', ['organization' => $this->org->slug]))->assertOk()->getContent();
        $this->assertStringContainsString('data-consumption-guest-mode="shell_first"', $consumption);
        $this->assertStringContainsString('data-consumption-guest-effective="off"', $consumption, 'pas prete (aucune autorite IA) : effectif OFF');
        $this->assertStringContainsString('data-consumption-guest-effective-reason="policy_not_ready"', $consumption);
    }
}
