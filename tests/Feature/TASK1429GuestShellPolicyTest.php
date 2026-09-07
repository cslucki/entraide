<?php

namespace Tests\Feature;

use App\Models\AiProviderInvocation;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\OrganizationGuestShellPolicy;
use App\Models\User;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Support\GuestShell\GuestShellState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1429 — SW-1 : la politique Shell Welcome par Organization et son
 * etat calcule (Addendum V2 §2), reglee depuis /admin/ai-config.
 *
 * - sans ligne = DISABLED, et consulter la page n'ecrit rien ;
 * - l'etat est CALCULE (jamais persiste) dans l'ordre DISABLED →
 *   MISCONFIGURED → NO_CREDENTIAL → BUDGET_BLOCKED → ACTIVE, et fail-closed
 *   tant que le plafond plateforme n'est pas configure ;
 * - le budget n'est jamais « illimite » : sans budget Organization, le
 *   plafond du process s'applique ;
 * - seul le SuperAdmin ecrit ; le formulaire ne duplique ni provider, ni
 *   modele, ni cle.
 */
class TASK1429GuestShellPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $superAdmin;

    private User $orgAdmin;

    private GuestShellPolicyService $policies;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 20.0, 'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0]);

        $this->org = Organization::factory()->create(['slug' => 'org-a-1429', 'name' => 'Alpha Corp', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);
        $this->orgAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $this->org->update(['admin_id' => $this->orgAdmin->id]);
        $this->policies = app(GuestShellPolicyService::class);
    }

    private function usableSetting(array $overrides = []): OrganizationAiSetting
    {
        return OrganizationAiSetting::create(array_merge([
            'organization_id' => $this->org->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-not-a-real-key',
            'is_enabled' => true,
        ], $overrides));
    }

    private function invocation(Organization $org, float $cost, string $process = GuestShellPolicyService::PROCESS, string $costStatus = AiProviderInvocation::COST_KNOWN, string $status = AiProviderInvocation::STATUS_SUCCESS): AiProviderInvocation
    {
        return AiProviderInvocation::create([
            'organization_id' => $org->id,
            'user_id' => null,
            'process' => $process,
            'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            'provider_cost' => $cost,
            'currency' => 'USD',
            'cost_status' => $costStatus,
            'status' => $status,
        ]);
    }

    // ── 1. Defaut et lecture sans ecriture ────────────────────────────────

    public function test_without_a_row_the_policy_is_disabled_and_reading_writes_nothing(): void
    {
        $state = $this->policies->state($this->org);

        $this->assertSame(GuestShellState::DISABLED, $state->status);
        $this->assertSame(['disabled'], $state->reasons);
        $this->assertSame(OrganizationGuestShellPolicy::DEFAULT_MAX_MESSAGES, $state->policy->max_messages);
        $this->assertSame(OrganizationGuestShellPolicy::DEFAULT_RETENTION_DAYS, $state->policy->retention_days);
        $this->assertDatabaseCount('organization_guest_shell_policies', 0);

        $this->actingAs($this->superAdmin)->get(route('admin.ai-config'))->assertOk()
            ->assertSee('data-guest-shell-org="org-a-1429" data-guest-shell-status="DISABLED"', false);
        $this->assertDatabaseCount('organization_guest_shell_policies', 0);
    }

    // ── 2. L'etat calcule, dans l'ordre ─────────────────────────────────────

    public function test_the_state_is_computed_in_order_and_fails_closed(): void
    {
        $this->policies->update($this->org, ['enabled' => true]);

        // Organisation non publique : MISCONFIGURED, meme avec un credential.
        $this->org->update(['is_public' => false]);
        $this->usableSetting();
        $state = $this->policies->state($this->org->fresh());
        $this->assertSame(GuestShellState::MISCONFIGURED, $state->status);
        $this->assertSame(['organization_not_public'], $state->reasons);

        // Publique mais sans reglage IA : NO_CREDENTIAL.
        $this->org->update(['is_public' => true]);
        OrganizationAiSetting::where('organization_id', $this->org->id)->delete();
        $state = $this->policies->state($this->org->fresh());
        $this->assertSame(GuestShellState::NO_CREDENTIAL, $state->status);
        $this->assertSame(['no_ai_setting'], $state->reasons);

        // Reglage present mais cle absente : NO_CREDENTIAL (raison precise).
        $setting = $this->usableSetting(['api_key' => null]);
        $this->assertSame(['api_key_missing'], $this->policies->state($this->org->fresh())->reasons);
        $setting->update(['api_key' => 'sk-test']);
        $setting->update(['is_enabled' => false]);
        $this->assertSame(['ai_setting_unusable'], $this->policies->state($this->org->fresh())->reasons);
        $setting->update(['is_enabled' => true]);

        // Plafond plateforme NON configure : fail-closed, jamais ACTIVE.
        config(['ai.guest_shell.platform_monthly_ceiling_usd' => null]);
        $state = $this->policies->state($this->org->fresh());
        $this->assertSame(GuestShellState::MISCONFIGURED, $state->status);
        $this->assertSame(['platform_ceiling_unset'], $state->reasons);

        // Tout est la : ACTIVE, provider/modele reels affiches, jamais la cle.
        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 20.0]);
        $state = $this->policies->state($this->org->fresh());
        $this->assertSame(GuestShellState::ACTIVE, $state->status);
        $this->assertSame([], $state->reasons);
        $this->assertSame('openrouter / openai/gpt-4o-mini', $state->providerLabel());
        $this->assertTrue($state->isActive());
    }

    public function test_the_budget_is_never_unlimited_and_the_platform_ceiling_wins(): void
    {
        $this->policies->update($this->org, ['enabled' => true]);
        $this->usableSetting();

        // Sans budget Organization : le plafond du process (2.00) s'applique.
        $this->invocation($this->org, 1.50);
        $this->assertSame(GuestShellState::ACTIVE, $this->policies->state($this->org)->status);
        $this->invocation($this->org, 0.60);
        $state = $this->policies->state($this->org);
        $this->assertSame(GuestShellState::BUDGET_BLOCKED, $state->status);
        $this->assertSame(['process_budget_reached'], $state->reasons);
        $this->assertSame(2, $state->monthlyUsage['messages']);
        $this->assertEqualsWithDelta(2.10, $state->monthlyUsage['cost_usd'], 0.0001);

        // Un budget Organization plus large deplace la borne.
        $this->policies->update($this->org, ['guest_monthly_budget_usd' => 5.00]);
        $this->assertSame(GuestShellState::ACTIVE, $this->policies->state($this->org)->status);
        $this->invocation($this->org, 3.00);
        $state = $this->policies->state($this->org);
        $this->assertSame(GuestShellState::BUDGET_BLOCKED, $state->status);
        $this->assertSame(['guest_monthly_budget_reached'], $state->reasons);

        // Le plafond plateforme compte TOUTES les Organizations et gagne.
        $this->policies->update($this->org, ['guest_monthly_budget_usd' => 1000.00]);
        $this->assertSame(GuestShellState::ACTIVE, $this->policies->state($this->org)->status);
        $other = Organization::factory()->create(['slug' => 'org-b-1429', 'is_active' => true, 'is_public' => true]);
        $this->invocation($other, 15.00);
        $state = $this->policies->state($this->org);
        $this->assertSame(GuestShellState::BUDGET_BLOCKED, $state->status);
        $this->assertSame(['platform_ceiling_reached'], $state->reasons);
    }

    public function test_the_monthly_usage_reads_only_the_guest_shell_process_of_this_organization_and_this_month(): void
    {
        $this->policies->update($this->org, ['enabled' => true]);
        $this->usableSetting();
        $other = Organization::factory()->create(['slug' => 'org-b-1429', 'is_active' => true, 'is_public' => true]);

        $this->invocation($this->org, 0.10);
        $this->invocation($this->org, 0.20, costStatus: AiProviderInvocation::COST_UNKNOWN);
        $this->invocation($this->org, 0.30, status: AiProviderInvocation::STATUS_FAILED);
        $this->invocation($this->org, 0.40, process: 'clarify_help_request');
        $this->invocation($other, 0.50);
        $old = $this->invocation($this->org, 0.60);
        AiProviderInvocation::whereKey($old->id)->update(['created_at' => now()->subMonths(2)]);

        $usage = $this->policies->state($this->org)->monthlyUsage;

        $this->assertSame(2, $usage['messages'], 'succes du process guest_shell de cette Organization ce mois-ci (cout connu + inconnu)');
        // TASK-1438 (V3 §14) : le cout CONNU compte quel que soit le statut — l'echec a 0.30 a coute.
        $this->assertEqualsWithDelta(0.40, $usage['cost_usd'], 0.0001, 'tout cout CONNU est somme, succes ou echec');
        $this->assertSame(1, $usage['failed']);
        $this->assertSame(3, $usage['invocations']);
        $this->assertSame(1, $usage['cost_unknown']);
    }

    // ── 3. L'ecriture : SuperAdmin seul, bornes, pas de duplication ─────────

    public function test_only_a_platform_admin_can_update_and_the_values_are_bounded(): void
    {
        $payload = ['organization_id' => $this->org->id, 'enabled' => '1', 'max_messages' => 25, 'retention_days' => 30, 'guest_monthly_budget_usd' => '3.50'];

        $this->actingAs($this->orgAdmin)->post(route('admin.ai-config.guest-shell'), $payload)->assertForbidden();
        $this->assertDatabaseCount('organization_guest_shell_policies', 0);

        $this->actingAs($this->superAdmin)->post(route('admin.ai-config.guest-shell'), $payload)
            ->assertRedirectToRoute('admin.ai-config')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('organization_guest_shell_policies', ['organization_id' => $this->org->id, 'enabled' => true, 'max_messages' => 25, 'retention_days' => 30, 'guest_monthly_budget_usd' => 3.50]);

        // Bornes : 0 et 1000 messages, 0 et 999 jours sont refuses ; vide = budget NULL (plafond du process).
        $this->actingAs($this->superAdmin)->post(route('admin.ai-config.guest-shell'), array_merge($payload, ['max_messages' => 0]))->assertSessionHasErrors('max_messages');
        $this->actingAs($this->superAdmin)->post(route('admin.ai-config.guest-shell'), array_merge($payload, ['max_messages' => 1000]))->assertSessionHasErrors('max_messages');
        $this->actingAs($this->superAdmin)->post(route('admin.ai-config.guest-shell'), array_merge($payload, ['retention_days' => 999]))->assertSessionHasErrors('retention_days');
        $this->actingAs($this->superAdmin)->post(route('admin.ai-config.guest-shell'), array_merge($payload, ['enabled' => '0', 'guest_monthly_budget_usd' => '']))->assertSessionHasNoErrors();
        $policy = OrganizationGuestShellPolicy::forOrganization($this->org);
        $this->assertFalse($policy->enabled);
        $this->assertNull($policy->guest_monthly_budget_usd);
        $this->assertDatabaseCount('organization_guest_shell_policies', 1);

        // Une Organization inconnue est refusee.
        $this->actingAs($this->superAdmin)->post(route('admin.ai-config.guest-shell'), array_merge($payload, ['organization_id' => '00000000-0000-7000-8000-000000000000']))->assertSessionHasErrors('organization_id');
    }

    public function test_the_admin_page_shows_the_state_and_never_duplicates_provider_model_or_key(): void
    {
        $this->policies->update($this->org, ['enabled' => true, 'max_messages' => 7, 'guest_monthly_budget_usd' => 4.00]);
        $this->usableSetting(['api_key' => 'sk-live-SECRET-should-never-render']);
        $this->invocation($this->org, 0.25);

        $html = $this->actingAs($this->superAdmin)->get(route('admin.ai-config'))->assertOk()->getContent();
        preg_match('/<form[^>]*data-guest-shell-org="org-a-1429"[^>]*>.*?<\/form>/s', $html, $m);
        $this->assertNotEmpty($m, 'bloc Shell Welcome de l Organization absent');
        $block = $m[0];

        $this->assertStringContainsString('data-guest-shell-status="ACTIVE"', $block);
        $this->assertStringContainsString('openrouter / openai/gpt-4o-mini', $block);
        $this->assertStringContainsString('platform_managed', $block);
        $this->assertStringContainsString('value="7"', $block);
        $this->assertStringContainsString('0.2500 USD', $block);
        $this->assertStringNotContainsString('sk-live-SECRET', $html);
        foreach (['name="provider"', 'name="model"', 'name="api_key"'] as $duplicated) {
            $this->assertStringNotContainsString($duplicated, $block, "le bloc Shell Welcome ne duplique pas {$duplicated}");
        }
        $this->assertStringContainsString(route('admin.ai-config.guest-shell'), $block);
    }
}
