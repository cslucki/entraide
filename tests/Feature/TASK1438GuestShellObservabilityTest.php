<?php

namespace Tests\Feature;

use App\Models\AiProviderInvocation;
use App\Models\GuestConversation;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\GuestShell\GuestConversationService;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestShellUsageService;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Support\GuestShell\GuestShellState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1438 — SW-10 : l'observabilite du Shell Welcome (Shell Welcome V3 §14,
 * §17, §18 ; MASTER Q64/Q65) — AVANT toute UI publique.
 *
 * Ce qui est mesure :
 * 1. les metriques d'une Organization sur une periode : invocations, succes,
 *    echecs, cout CONNU (compte quel que soit le statut), cout inconnu
 *    (compteur), tokens, visiteurs, conversations, messages visiteur, comptes ;
 *    l'ancien mois et l'autre Organization n'y entrent pas ;
 * 2. les totaux plateforme = la somme des Organizations, avec l'etat de
 *    chaque politique ;
 * 3. l'OrgAdmin voit SON Organization dans /ai-consumption, rien d'autre ;
 * 4. le SuperAdmin voit tout dans /admin/shell-welcome, l'OrgAdmin non ;
 * 5. aucun contenu de conversation dans un dashboard ;
 * 6. dashboard = garde = ledger : monthlyUsage (SW-1) suit la meme doctrine.
 */
class TASK1438GuestShellObservabilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    private User $superAdmin;

    private GuestConversation $conversationA;

    private GuestShellUsageService $usage;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 5.0]);
        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1438', 'name' => 'Alpha Corp', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1438', 'name' => 'Beta SAS', 'is_active' => true, 'is_public' => true, 'locale' => 'en']);
        $this->adminA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->adminB = User::factory()->create(['organization_id' => $this->orgB->id]);
        $this->orgA->update(['admin_id' => $this->adminA->id]);
        $this->orgB->update(['admin_id' => $this->adminB->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => true]);

        app(GuestShellPolicyService::class)->update($this->orgA, ['enabled' => true, 'max_messages' => 10]);
        OrganizationAiSetting::create(['organization_id' => $this->orgA->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test', 'is_enabled' => true]);
        // B : politique fermee, aucune configuration IA.

        $conversations = app(GuestConversationService::class);
        $visitorA = app()->make(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => Str::random(64)]), $this->orgA);
        $this->conversationA = $conversations->start($visitorA);
        $conversations->acceptUserMessage($this->conversationA, 'SECRET-BODY-A1 : bonjour, je cherche un freelance');
        $conversations->recordAssistantMessage($this->conversationA, 'SECRET-ANSWER-A1', null);
        $conversations->acceptUserMessage($this->conversationA, 'SECRET-BODY-A2');
        $visitorB = app()->make(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => Str::random(64)]), $this->orgB);
        $conversations->acceptUserMessage($conversations->start($visitorB), 'SECRET-BODY-B1');

        $this->invocation($this->orgA, 0.10, 100, 50);
        $this->invocation($this->orgA, 0.30, 80, 0, status: AiProviderInvocation::STATUS_FAILED);
        $this->invocation($this->orgA, null, 40, 20, costStatus: AiProviderInvocation::COST_UNKNOWN);
        $this->invocation($this->orgA, 0.90, 10, 10, process: 'chatloop.summarize');
        $this->invocation($this->orgB, 0.50, 30, 30);
        $old = $this->invocation($this->orgA, 0.70, 10, 10);
        AiProviderInvocation::whereKey($old->id)->update(['created_at' => now()->subMonths(2)]);

        $this->usage = app(GuestShellUsageService::class);
    }

    private function invocation(Organization $org, ?float $cost, int $in, int $out, string $status = AiProviderInvocation::STATUS_SUCCESS, string $costStatus = AiProviderInvocation::COST_KNOWN, string $process = GuestShellPolicyService::PROCESS): AiProviderInvocation
    {
        return AiProviderInvocation::create([
            'organization_id' => $org->id, 'process' => $process, 'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'status' => $status, 'cost_status' => $costStatus,
            'provider_cost' => $cost, 'currency' => $cost === null ? null : 'USD', 'input_tokens' => $in, 'output_tokens' => $out,
        ]);
    }

    private function month(): array
    {
        return [CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->startOfMonth()->addMonth()];
    }

    // ── 1. Les metriques d'une Organization ────────────────────────────────

    public function test_organization_metrics_follow_the_ledger_doctrine_and_stay_in_their_organization_and_period(): void
    {
        [$from, $to] = $this->month();
        $a = $this->usage->organizationUsage($this->orgA, $from, $to);

        $this->assertSame(3, $a['invocations'], 'guest_shell seulement, ce mois seulement');
        $this->assertSame(2, $a['success']);
        $this->assertSame(1, $a['failed']);
        $this->assertEqualsWithDelta(0.40, $a['known_cost_usd'], 0.0001, 'le cout CONNU compte quel que soit le statut (V3 §14) — pas le process chatloop, pas l\'ancien mois');
        $this->assertSame(1, $a['cost_unknown'], 'un compteur, jamais 0 USD');
        $this->assertSame(220, $a['input_tokens']);
        $this->assertSame(70, $a['output_tokens']);
        $this->assertSame(1, $a['visitors']);
        $this->assertSame(1, $a['conversations']);
        $this->assertSame(2, $a['visitor_messages'], 'messages visiteur acceptes — une unite distincte des invocations');
        $this->assertSame(0, $a['accounts_claimed']);

        $b = $this->usage->organizationUsage($this->orgB, $from, $to);
        $this->assertSame(1, $b['invocations']);
        $this->assertEqualsWithDelta(0.50, $b['known_cost_usd'], 0.0001);
        $this->assertSame(1, $b['visitor_messages']);

        // Periode precedente : rien pour A, sauf l'ancienne ligne.
        $previous = $this->usage->organizationUsage($this->orgA, $from->subMonths(2), $from->subMonth());
        $this->assertSame(1, $previous['invocations']);
        $this->assertSame(0, $previous['visitor_messages']);

        $this->conversationA->forceFill(['claimed_user_id' => $this->adminA->id, 'claimed_at' => now()])->save();
        $this->assertSame(1, $this->usage->organizationUsage($this->orgA, $from, $to)['accounts_claimed']);
    }

    // ── 2. Totaux plateforme = somme + etat des politiques ─────────────────

    public function test_platform_totals_are_the_sum_of_organizations_with_each_policy_state(): void
    {
        [$from, $to] = $this->month();
        $platform = $this->usage->platformUsage($from, $to, app(GuestShellPolicyService::class));

        $this->assertSame(4, $platform['totals']['invocations']);
        $this->assertEqualsWithDelta(0.90, $platform['totals']['known_cost_usd'], 0.0001);
        $this->assertSame(1, $platform['totals']['cost_unknown']);
        $this->assertSame(1, $platform['totals']['failed']);
        $this->assertSame(2, $platform['totals']['visitors']);
        $this->assertSame(2, $platform['totals']['conversations']);
        $this->assertSame(3, $platform['totals']['visitor_messages']);
        $this->assertSame(1, $platform['totals']['organizations_enabled']);
        $this->assertSame(1, $platform['totals']['organizations_active']);

        $rows = $platform['organizations']->keyBy(fn (array $row) => $row['organization']->slug);
        $this->assertSame(GuestShellState::ACTIVE, $rows['org-a-1438']['state']);
        $this->assertSame(GuestShellState::DISABLED, $rows['org-b-1438']['state']);
        $this->assertEqualsWithDelta(0.40, $rows['org-a-1438']['cost_per_conversation'], 0.0001);
        $this->assertNull($rows['org-a-1438']['cost_per_account'], 'aucun compte : pas de division par zero, pas de 0 invente');
    }

    // ── 3. OrgAdmin : son Organization, rien d'autre ───────────────────────

    public function test_the_org_admin_console_shows_the_guest_block_of_its_organization_only(): void
    {
        $url = route('organization.admin.ai-consumption', ['organization' => $this->orgA->slug]);
        $html = $this->actingAs($this->adminA)->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('data-consumption-guest-block', $html);
        $this->assertStringContainsString('data-consumption-guest-state="active"', $html);
        $this->assertStringContainsString('data-consumption-guest-metric="invocations" data-consumption-guest-value="3"', $html);
        $this->assertStringContainsString('data-consumption-guest-metric="failed" data-consumption-guest-value="1"', $html);
        $this->assertStringContainsString('data-consumption-guest-metric="known_cost_usd" data-consumption-guest-value="0.4"', $html);
        $this->assertStringContainsString('data-consumption-guest-metric="cost_unknown" data-consumption-guest-value="1"', $html);
        $this->assertStringContainsString('data-consumption-guest-metric="visitor_messages" data-consumption-guest-value="2"', $html);
        $this->assertStringNotContainsString('0.5000', $html, 'le cout de B n\'apparait pas chez A');
        $this->assertStringNotContainsString('SECRET-', $html, 'jamais un contenu de conversation');

        $this->actingAs($this->adminB)->get($url)->assertForbidden();
        $htmlB = $this->actingAs($this->adminB)->get(route('organization.admin.ai-consumption', ['organization' => $this->orgB->slug]))->assertOk()->getContent();
        $this->assertStringContainsString('data-consumption-guest-state="disabled"', $htmlB);
        $this->assertStringContainsString('data-consumption-guest-metric="invocations" data-consumption-guest-value="1"', $htmlB);
    }

    // ── 4 & 5. SuperAdmin : tout, sans contenu ; OrgAdmin : non ───────────

    public function test_the_platform_page_is_admin_only_shows_totals_per_organization_and_never_a_transcript(): void
    {
        $url = route('admin.guest-shell');
        $this->assertSame('/admin/shell-welcome', parse_url($url, PHP_URL_PATH));
        $this->actingAs($this->adminA)->get($url)->assertForbidden();
        $this->actingAs($this->adminB)->get($url)->assertForbidden();

        $html = $this->actingAs($this->superAdmin)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('data-guest-shell-total="invocations" data-guest-shell-value="4"', $html);
        $this->assertStringContainsString('data-guest-shell-total="failed" data-guest-shell-value="1"', $html);
        $this->assertStringContainsString('data-guest-shell-total="cost_unknown" data-guest-shell-value="1"', $html);
        $this->assertStringContainsString('data-guest-shell-total="visitor_messages" data-guest-shell-value="3"', $html);
        $this->assertStringContainsString('data-guest-shell-org="org-a-1438" data-guest-shell-state="active"', $html);
        $this->assertStringContainsString('data-guest-shell-org="org-b-1438" data-guest-shell-state="disabled"', $html);
        $this->assertStringContainsString('Alpha Corp', $html);
        $this->assertStringContainsString('Beta SAS', $html);
        $this->assertStringContainsString('$0.4000', $html);
        $this->assertStringContainsString('$0.5000', $html);
        $this->assertStringNotContainsString('SECRET-', $html, 'jamais un contenu de conversation');
        $this->assertStringContainsString('data-guest-shell-filters', $html);

        // La periode se filtre : le mois d'il y a deux mois ne porte que l'ancienne ligne de A.
        $from = CarbonImmutable::now()->subMonths(2)->startOfMonth();
        $htmlOld = $this->actingAs($this->superAdmin)->get($url.'?from='.$from->format('Y-m-d').'&to='.$from->endOfMonth()->format('Y-m-d'))->assertOk()->getContent();
        $this->assertStringContainsString('data-guest-shell-total="invocations" data-guest-shell-value="1"', $htmlOld);
        $this->assertStringContainsString('data-guest-shell-total="visitor_messages" data-guest-shell-value="0"', $htmlOld);
    }

    // ── 6. dashboard = garde = ledger ──────────────────────────────────────

    public function test_the_policy_state_usage_follows_the_same_doctrine_as_the_dashboard(): void
    {
        [$from, $to] = $this->month();
        $state = app(GuestShellPolicyService::class)->state($this->orgA);
        $dashboard = $this->usage->organizationUsage($this->orgA, $from, $to);

        $this->assertEqualsWithDelta($dashboard['known_cost_usd'], $state->monthlyUsage['cost_usd'], 0.0001, 'le cout connu de l\'echec compte des deux cotes');
        $this->assertSame($dashboard['invocations'], $state->monthlyUsage['invocations']);
        $this->assertSame($dashboard['failed'], $state->monthlyUsage['failed']);
        $this->assertSame($dashboard['success'], $state->monthlyUsage['messages']);
    }

    // ── 7. Le plafond plateforme suit la meme doctrine ─────────────────────

    public function test_the_platform_ceiling_counts_the_known_cost_of_failures_too(): void
    {
        $policies = app(GuestShellPolicyService::class);
        $this->assertEqualsWithDelta(0.90, $policies->platformMonthlyCostUsd(now()), 0.0001, 'A 0.10 + A echec 0.30 + B 0.50 — pas chatloop, pas l\'ancien mois');

        // 0.85 < 0.90 : le plafond est atteint UNIQUEMENT si l'echec connu compte (sinon 0.60 < 0.85).
        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 0.85]);
        $state = $policies->state($this->orgA);
        $this->assertSame(GuestShellState::BUDGET_BLOCKED, $state->status, 'le verdict, pas la configuration');
        $this->assertSame(['platform_ceiling_reached'], $state->reasons);
    }
}
