<?php

namespace Tests\Feature;

use App\Models\AiProviderInvocation;
use App\Models\GuestConversation;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Services\GuestShell\GuestConversationService;
use App\Services\GuestShell\GuestShellGate;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Support\GuestShell\GuestShellClearance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1436 — SW-6 : la garde economique du Shell Welcome, AVANT tout appel
 * provider (Addendum V2 §11, cadre Cyril §5, MASTER Q60/Q61).
 *
 * Ce qui est mesure :
 * 1. tout vert = un laissez-passer qui porte le credential de l'ORGANIZATION
 *    (resolution normale), la borne de sortie unique, une correlation — et
 *    RIEN n'est ecrit (ni ledger, ni ai_interactions) ;
 * 2. chaque garde refuse avec une raison bornee, dans l'ordre canonique, et
 *    le premier refus arrete tout ;
 * 3. quota transverse du visiteur (toutes conversations) et rafale par
 *    Organization + visiteur : le meme cookie sur deux Organizations ne se
 *    melange pas ; « nouvelle conversation » ne remet rien a zero ;
 * 4. le plafond plateforme est un coupe-circuit (absent = ferme) ;
 * 5. le chemin Guest n'emprunte jamais la cle plateforme.
 */
class TASK1436GuestShellGateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private GuestVisitor $visitor;

    private GuestVisitor $visitorElsewhere;

    private GuestConversation $conversation;

    private OrganizationAiSetting $setting;

    private GuestShellGate $gate;

    private GuestConversationService $conversations;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.guest_shell.platform_monthly_ceiling_usd' => 5.0,
            'ai.guest_shell.visitor_monthly_max_messages' => 30,
            'ai.guest_shell.rate_limit_per_minute' => 6,
            'ai.guest_shell.max_output_tokens' => 650,
            'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0,
        ]);

        $this->org = Organization::factory()->create(['slug' => 'org-a-1436', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->other = Organization::factory()->create(['slug' => 'org-b-1436', 'is_active' => true, 'is_public' => true, 'locale' => 'en']);
        app(GuestShellPolicyService::class)->update($this->org, ['enabled' => true, 'max_messages' => 3]);
        app(GuestShellPolicyService::class)->update($this->other, ['enabled' => true, 'max_messages' => 3]);
        $this->setting = OrganizationAiSetting::create(['organization_id' => $this->org->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-not-a-real-key', 'is_enabled' => true]);
        OrganizationAiSetting::create(['organization_id' => $this->other->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-not-a-real-key', 'is_enabled' => true]);

        $key = Str::random(64);
        $this->visitor = app()->make(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => $key]), $this->org);
        $this->visitorElsewhere = app()->make(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => $key]), $this->other);
        $this->conversations = app(GuestConversationService::class);
        $this->conversation = $this->conversations->start($this->visitor);
        $this->gate = app(GuestShellGate::class);
        RateLimiter::clear(GuestShellGate::rateKey($this->org, $this->visitor));
    }

    private function clear(?string $message = 'Bonjour, que faites-vous ?'): GuestShellClearance
    {
        return $this->gate->clear($this->org->fresh(), $this->visitor->fresh(), $this->conversation->fresh(), (string) $message);
    }

    private function invocation(Organization $org, float $cost, string $process = GuestShellPolicyService::PROCESS): AiProviderInvocation
    {
        return AiProviderInvocation::create([
            'organization_id' => $org->id,
            'process' => $process,
            'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'status' => AiProviderInvocation::STATUS_SUCCESS,
            'cost_status' => AiProviderInvocation::COST_KNOWN,
            'provider_cost' => $cost,
            'currency' => 'USD',
        ]);
    }

    private function assertRefused(GuestShellClearance $clearance, string $step, string $reason): void
    {
        $this->assertTrue($clearance->isRefused(), "attendu un refus [{$step}/{$reason}]");
        $this->assertSame($step, $clearance->step);
        $this->assertSame($reason, $clearance->reason);
        $this->assertNull($clearance->resolved);
        $this->assertNull($clearance->maxOutputTokens);
    }

    // ── 1. Tout vert ────────────────────────────────────────────────────────

    public function test_when_everything_is_green_the_clearance_carries_the_organization_credential_and_the_output_bound_and_writes_nothing(): void
    {
        $before = AiProviderInvocation::count();
        $clearance = $this->clear();

        $this->assertTrue($clearance->allowed, (string) $clearance->reason);
        $this->assertSame('openrouter', $clearance->resolved?->provider);
        $this->assertSame('openai/gpt-4o-mini', $clearance->resolved?->model);
        $this->assertNotSame('', $clearance->resolved?->instance);
        $this->assertSame(650, $clearance->maxOutputTokens);
        $this->assertTrue(Str::isUuid((string) $clearance->correlationId));
        $this->assertSame(0, $clearance->facts['visitor_monthly_used']);
        $this->assertSame(3, $clearance->facts['remaining_in_conversation']);

        $this->assertSame($before, AiProviderInvocation::count(), 'un laissez-passer n\'est pas un appel : rien au ledger');
        $this->assertSame(0, DB::table('ai_interactions')->count(), 'le Guest n\'ecrit jamais ai_interactions');

        // La borne de sortie ne depasse jamais l'autorite, meme si la definition etait plus large.
        config(['ai.guest_shell.max_output_tokens' => 200]);
        $this->assertSame(200, $this->clear()->maxOutputTokens);
    }

    // ── 2. Politique, Organization, credential ─────────────────────────────

    public function test_policy_organization_and_credential_refuse_first_in_this_order(): void
    {
        app(GuestShellPolicyService::class)->update($this->org, ['enabled' => false]);
        $this->assertRefused($this->clear(), GuestShellClearance::STEP_POLICY, 'shell_disabled');
        app(GuestShellPolicyService::class)->update($this->org, ['enabled' => true]);

        $this->org->update(['is_public' => false]);
        $this->assertRefused($this->clear(), GuestShellClearance::STEP_ORGANIZATION, 'organization_not_public');
        $this->org->update(['is_public' => true, 'is_active' => false]);
        $this->assertRefused($this->clear(), GuestShellClearance::STEP_ORGANIZATION, 'organization_inactive');
        $this->org->update(['is_active' => true]);

        $this->setting->update(['api_key' => null]);
        $this->assertRefused($this->clear(), GuestShellClearance::STEP_CREDENTIAL, 'no_credential');
        $this->setting->update(['api_key' => 'sk-test', 'is_enabled' => false]);
        $this->assertRefused($this->clear(), GuestShellClearance::STEP_CREDENTIAL, 'no_credential');
        $this->setting->delete();
        $this->assertRefused($this->clear(), GuestShellClearance::STEP_CREDENTIAL, 'no_credential');

        // L'ordre : politique fermee ET credential absent -> c'est la politique qui parle.
        app(GuestShellPolicyService::class)->update($this->org, ['enabled' => false]);
        $this->assertRefused($this->clear(), GuestShellClearance::STEP_POLICY, 'shell_disabled');
        $this->assertSame(0, AiProviderInvocation::count());
    }

    // ── 3. Limite de conversation, quota transverse, rafale ────────────────

    public function test_conversation_limit_visitor_quota_and_rate_limit_are_tenant_and_visitor_scoped(): void
    {
        foreach (['Un', 'Deux'] as $body) {
            $this->conversations->acceptUserMessage($this->conversation, $body);
        }
        // Politique abaissee SOUS le compte : la conversation est encore `active`
        // (le statut n'est pose qu'a l'acceptation), c'est la garde qui doit voir
        // qu'il ne reste plus rien — pas le statut.
        app(GuestShellPolicyService::class)->update($this->org, ['max_messages' => 2]);
        $this->assertSame(GuestConversation::STATUS_ACTIVE, $this->conversation->fresh()->status);
        $this->assertRefused($this->clear(), GuestShellClearance::STEP_CONVERSATION_LIMIT, 'max_messages_reached');
        app(GuestShellPolicyService::class)->update($this->org, ['max_messages' => 3]);
        $this->conversations->acceptUserMessage($this->conversation->fresh(), 'Trois');
        $this->assertSame(GuestConversation::STATUS_LIMIT_REACHED, $this->conversation->fresh()->status);
        $this->assertRefused($this->clear(), GuestShellClearance::STEP_CONVERSATION_LIMIT, 'max_messages_reached');

        // Nouvelle conversation : la limite de conversation repart, pas le quota transverse.
        config(['ai.guest_shell.visitor_monthly_max_messages' => 3]);
        $this->conversation = $this->conversations->start($this->visitor);
        $this->assertRefused($this->clear(), GuestShellClearance::STEP_VISITOR_QUOTA, 'visitor_monthly_quota_reached');
        // Le meme cookie dans une AUTRE Organization n'est pas touche par ce quota.
        $elsewhere = $this->gate->clear($this->other, $this->visitorElsewhere, $this->conversations->start($this->visitorElsewhere), 'Hello');
        $this->assertTrue($elsewhere->allowed, (string) $elsewhere->reason);

        config(['ai.guest_shell.visitor_monthly_max_messages' => 30]);
        $this->assertTrue($this->clear()->allowed);

        // Rafale : 6 laissez-passer par minute, par Organization + visiteur ; le 7e attend.
        RateLimiter::clear(GuestShellGate::rateKey($this->org, $this->visitor));
        for ($i = 0; $i < 6; $i++) {
            $this->assertTrue($this->clear()->allowed, "passage {$i}");
        }
        $refused = $this->clear();
        $this->assertRefused($refused, GuestShellClearance::STEP_VISITOR_QUOTA, 'rate_limited');
        $this->assertGreaterThan(0, $refused->facts['retry_after_seconds']);
        $this->assertNotSame(GuestShellGate::rateKey($this->org, $this->visitor), GuestShellGate::rateKey($this->other, $this->visitorElsewhere));
        $this->assertTrue($this->gate->clear($this->other, $this->visitorElsewhere, $this->conversations->resumeOrStart($this->visitorElsewhere), 'Still fine')->allowed);

        // Configuration absente = ferme, jamais illimite.
        RateLimiter::clear(GuestShellGate::rateKey($this->org, $this->visitor));
        config(['ai.guest_shell.visitor_monthly_max_messages' => null]);
        $this->assertRefused($this->clear(), GuestShellClearance::STEP_VISITOR_QUOTA, 'visitor_quota_unset');
        config(['ai.guest_shell.visitor_monthly_max_messages' => 30, 'ai.guest_shell.rate_limit_per_minute' => 0]);
        $this->assertRefused($this->clear(), GuestShellClearance::STEP_VISITOR_QUOTA, 'rate_limit_unset');
    }

    // ── 4. Budgets et plafond plateforme ───────────────────────────────────

    public function test_budgets_and_the_platform_ceiling_are_real_circuit_breakers(): void
    {
        // Le ledger est l'autorite economique du Guest (cadre Cyril §4) : une ligne
        // guest_shell au ledger compte dans le plafond de l'Organization ET dans
        // le budget Guest — sans passer par ai_interactions, que le Guest n'ecrit pas.
        $this->setting->update(['monthly_budget_usd' => 0.05]);
        $this->invocation($this->org, 0.06);
        $this->assertRefused($this->clear(), GuestShellClearance::STEP_ORGANIZATION_BUDGET, 'organization_budget_reached');
        $this->setting->update(['monthly_budget_usd' => null]);

        app(GuestShellPolicyService::class)->update($this->org, ['guest_monthly_budget_usd' => 0.03]);
        $this->assertRefused($this->clear(), GuestShellClearance::STEP_GUEST_BUDGET, 'guest_monthly_budget_reached');

        app(GuestShellPolicyService::class)->update($this->org, ['guest_monthly_budget_usd' => null]);
        config(['ai.guest_shell.economic_guard.monthly_budget_usd' => 0.03]);
        $this->assertRefused($this->clear(), GuestShellClearance::STEP_PROCESS_BUDGET, 'process_budget_reached');
        config(['ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0]);
        $this->assertTrue($this->clear()->allowed);

        // Le plafond plateforme : absent = ferme ; atteint (toutes Organizations confondues) = ferme.
        config(['ai.guest_shell.platform_monthly_ceiling_usd' => null]);
        $this->assertRefused($this->clear(), GuestShellClearance::STEP_PLATFORM_CEILING, 'platform_ceiling_unset');
        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 0.10]);
        $this->invocation($this->other, 0.07);
        $this->assertRefused($this->clear(), GuestShellClearance::STEP_PLATFORM_CEILING, 'platform_ceiling_reached');
        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 5.0]);
        $this->assertTrue($this->clear()->allowed);

        $this->assertSame(2, AiProviderInvocation::count(), 'seules les lignes de fixture existent : la garde n\'ecrit rien');
    }

    // ── 5. Bornes d'entree / sortie ────────────────────────────────────────

    public function test_input_and_output_bounds_are_enforced_server_side_with_single_authorities(): void
    {
        $this->assertRefused($this->clear(''), GuestShellClearance::STEP_INPUT_BOUND, 'input_out_of_bounds');
        $this->assertRefused($this->clear(str_repeat('a', (int) config('ai.shell.max_input_chars') + 1)), GuestShellClearance::STEP_INPUT_BOUND, 'input_out_of_bounds');
        $this->assertTrue($this->clear(str_repeat('a', (int) config('ai.shell.max_input_chars')))->allowed);

        config(['ai.guest_shell.max_output_tokens' => null]);
        $this->assertRefused($this->clear(), GuestShellClearance::STEP_OUTPUT_BOUND, 'output_bound_unset');
        config(['ai.guest_shell.max_output_tokens' => 0]);
        $this->assertRefused($this->clear(), GuestShellClearance::STEP_OUTPUT_BOUND, 'output_bound_unset');
    }

    // ── 6. Jamais la cle plateforme ────────────────────────────────────────

    public function test_the_guest_path_never_uses_the_platform_credential_path(): void
    {
        $sources = '';
        foreach (glob(base_path('app/Services/GuestShell/*.php')) as $file) {
            $sources .= file_get_contents($file);
        }
        $this->assertStringNotContainsString('declarePlatformCredential', $sources);
        $this->assertStringNotContainsString('SupervisionProviderResolver', $sources);
        $this->assertStringNotContainsString('MemberProfileAgentResponder', $sources);
        $this->assertStringContainsString('ProviderResolver', file_get_contents(base_path('app/Services/GuestShell/GuestShellGate.php')));

        // Une incoherence tenant est une faute de code, pas une raison metier.
        $this->expectException(\DomainException::class);
        $this->gate->clear($this->other, $this->visitor, $this->conversation, 'Bonjour');
    }
}
