<?php

namespace Tests\Feature;

use App\Ai\Agents\GuestShellAgent;
use App\Models\AiProviderInvocation;
use App\Models\GuestConversation;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Services\GuestShell\GuestConversationService;
use App\Services\GuestShell\GuestShellGate;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestShellResponder;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiEconomicVerdict;
use App\Support\GuestShell\GuestShellClearance;
use App\Support\GuestShell\GuestShellTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1448 — SW-6b (Growth Workshops Acquisition V3 §3, P0) : une tentative
 * provider Guest qui a ATTEINT le provider et echoue a un cout INCONNU ne peut
 * pas disparaitre du quota `unknown`.
 *
 * Regle :
 *  - refus avant provider = zero cout, rien d'ecrit ;
 *  - provider appele + cout connu = cout compte quel que soit le statut ;
 *  - provider appele + cout inconnu = UNE operation du quota `unknown`, meme
 *    `failed` — jamais supposee gratuite ;
 *  - aucun retry automatique Guest ;
 *  - correctif BORNE au process `guest_shell` : les autres process gardent la
 *    regle `status = success` de TASK-1260 (une panne retentee par un job ne
 *    ferme pas un process).
 *
 * Preuves :
 *  1. bout en bout : un echec provider (cout inconnu) consomme le quota, le
 *     tour suivant est REFUSE avant tout appel provider ;
 *  2. la garde : `guest_shell` compte l'echec, un autre process ne le compte
 *     pas (et compte toujours son succes inconnu) ;
 *  3. le compteur d'observabilite de la politique suit exactement la garde.
 */
class TASK1448GuestUnknownFailedCostTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private GuestVisitor $visitor;

    private GuestConversation $conversation;

    private GuestShellResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.guest_shell.platform_monthly_ceiling_usd' => 5.0,
            'ai.guest_shell.visitor_monthly_max_messages' => 30,
            'ai.guest_shell.rate_limit_per_minute' => 6,
            'ai.guest_shell.max_output_tokens' => 650,
            'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0,
            // Le quota d'inconnus le plus petit possible : UNE tentative suffit a le remplir.
            'ai.guest_shell.economic_guard.monthly_unknown_limit' => 1,
        ]);

        $this->org = Organization::factory()->create(['slug' => 'org-a-1448', 'name' => 'CyberWorkers', 'is_active' => true, 'is_public' => true, 'locale' => 'fr', 'hero_title' => 'Entraide entre freelances']);
        app(GuestShellPolicyService::class)->update($this->org, ['enabled' => true, 'max_messages' => 5]);
        OrganizationAiSetting::create(['organization_id' => $this->org->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-not-a-real-key', 'is_enabled' => true]);

        $this->visitor = app()->make(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => Str::random(64)]), $this->org, ['locale' => 'fr']);
        $this->conversation = app(GuestConversationService::class)->start($this->visitor);
        $this->responder = app(GuestShellResponder::class);
        RateLimiter::clear(GuestShellGate::rateKey($this->org, $this->visitor));
    }

    private function respond(string $message): GuestShellTurn
    {
        return $this->responder->respond($this->org->fresh(), $this->visitor->fresh(), $this->conversation->fresh(), $message);
    }

    private function ledgerRow(string $process, string $status, string $costStatus, ?float $cost = null, ?string $correlationId = null): AiProviderInvocation
    {
        return AiProviderInvocation::create([
            'organization_id' => $this->org->id, 'process' => $process, 'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'status' => $status, 'cost_status' => $costStatus,
            'provider_cost' => $cost, 'currency' => $cost === null ? null : 'USD', 'correlation_id' => $correlationId,
        ]);
    }

    private function authorize(string $process, int $unknownLimit = 1): AiEconomicVerdict
    {
        return app(AiEconomicGuard::class)->authorize($this->org->fresh(), $process, 'openrouter', 'openai/gpt-4o-mini', 100.0, $unknownLimit);
    }

    // ── 1. Bout en bout ────────────────────────────────────────────────────

    public function test_a_failed_guest_attempt_with_unknown_cost_consumes_the_unknown_quota_and_the_next_turn_is_refused_before_any_provider_call(): void
    {
        // L'appel part et echoue APRES avoir atteint le provider : timeout / 5xx / reponse ambigue.
        GuestShellAgent::fake(fn () => throw new RuntimeException('upstream timeout'));

        $failed = $this->respond('Bonjour, quels ateliers proposez-vous ?');

        $this->assertSame(GuestShellTurn::FAILED, $failed->status);
        $this->assertSame('provider_failed', $failed->reason);
        $this->assertSame(1, AiProviderInvocation::count(), 'une tentative = une ligne, jamais un retry');
        $row = AiProviderInvocation::firstOrFail();
        $this->assertSame(AiProviderInvocation::STATUS_FAILED, $row->status);
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $row->cost_status, 'jamais suppose gratuit : cout INCONNU explicite');
        $this->assertNull($row->provider_cost);

        // Le compteur de la politique et la garde disent la meme chose : UNE operation inconnue.
        $usage = app(GuestShellPolicyService::class)->monthlyUsage($this->org, now());
        $this->assertSame(1, $usage['cost_unknown'], 'l\'echec au cout inconnu COMPTE dans le quota (V3 §3 P0)');
        $this->assertSame(1, $usage['failed']);
        $this->assertSame(0.0, $usage['cost_usd'], 'aucun cout connu n\'est invente');

        // Le tour suivant : le quota (1) est atteint -> REFUS AVANT tout appel provider.
        GuestShellAgent::fake([new TextResponse('jamais envoye', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini'))]);
        $messagesBefore = $this->conversation->fresh()->message_count;

        $refused = $this->respond('Et pour les freelances ?');

        $this->assertSame(GuestShellTurn::REFUSED, $refused->status);
        $this->assertSame(GuestShellClearance::STEP_PRICING, $refused->step);
        $this->assertSame('unknown_cost_quota_reached', $refused->reason);
        $this->assertSame(1, AiProviderInvocation::count(), 'premier refus = ZERO appel provider, aucune ligne de plus');
        $this->assertSame($messagesBefore, $this->conversation->fresh()->message_count, 'un refus ne consomme pas le tour du visiteur');
        $this->assertSame(0, DB::table('ai_interactions')->count(), 'le Guest n\'ecrit jamais ai_interactions');
    }

    // ── 2. La garde, bornee au process ─────────────────────────────────────

    public function test_the_guard_counts_failed_unknown_attempts_for_guest_shell_only_and_keeps_the_success_rule_elsewhere(): void
    {
        $this->assertSame(['guest_shell'], AiEconomicGuard::UNKNOWN_QUOTA_COUNTS_FAILED_ATTEMPTS, 'correctif BORNE : le Shell Welcome seulement, pas les jobs d\'ingestion ni les autres process');

        // guest_shell : un echec au cout inconnu = une operation du quota.
        $this->ledgerRow(GuestShellPolicyService::PROCESS, AiProviderInvocation::STATUS_FAILED, AiProviderInvocation::COST_UNKNOWN);
        $guest = $this->authorize(GuestShellPolicyService::PROCESS);
        $this->assertFalse($guest->allowed);
        $this->assertSame(AiEconomicGuard::REASON_UNKNOWN_QUOTA_REACHED, $guest->reason);
        $this->assertSame(1, $guest->successfulUnknownCount, 'la tentative en echec est comptee');

        // Un echec au cout CONNU ne compte pas dans le quota inconnu : il compte dans le COUT (deja pinne, T1438).
        $this->ledgerRow(GuestShellPolicyService::PROCESS, AiProviderInvocation::STATUS_FAILED, AiProviderInvocation::COST_KNOWN, 0.25);
        $guest = $this->authorize(GuestShellPolicyService::PROCESS, unknownLimit: 5);
        $this->assertTrue($guest->allowed);
        $this->assertSame(1, $guest->successfulUnknownCount, 'un echec au cout connu n\'est pas une operation inconnue');
        $this->assertEqualsWithDelta(0.25, $guest->knownMonthlyCostUsd, 0.0001, 'mais son cout connu est compte quel que soit le statut');

        // Un autre process du ledger (TASK-1260) : l'echec inconnu n'entre PAS dans son quota...
        $this->ledgerRow('loop_knowledge.answer', AiProviderInvocation::STATUS_FAILED, AiProviderInvocation::COST_UNKNOWN);
        $other = $this->authorize('loop_knowledge.answer');
        $this->assertTrue($other->allowed, 'une panne retentee par un job ne ferme pas un process (regle TASK-1260 inchangee)');
        $this->assertSame(0, $other->successfulUnknownCount);

        // ...et son succes inconnu y entre toujours.
        $this->ledgerRow('loop_knowledge.answer', AiProviderInvocation::STATUS_SUCCESS, AiProviderInvocation::COST_UNKNOWN);
        $other = $this->authorize('loop_knowledge.answer');
        $this->assertFalse($other->allowed);
        $this->assertSame(AiEconomicGuard::REASON_UNKNOWN_QUOTA_REACHED, $other->reason);
        $this->assertSame(1, $other->successfulUnknownCount);

        // Le quota Guest reste un compte d'OPERATIONS : deux lignes d'une meme correlation (uuid en base) = une operation.
        $correlation = (string) Str::uuid();
        $this->ledgerRow(GuestShellPolicyService::PROCESS, AiProviderInvocation::STATUS_FAILED, AiProviderInvocation::COST_UNKNOWN, correlationId: $correlation);
        $this->ledgerRow(GuestShellPolicyService::PROCESS, AiProviderInvocation::STATUS_SUCCESS, AiProviderInvocation::COST_UNKNOWN, correlationId: $correlation);
        $this->assertSame(2, $this->authorize(GuestShellPolicyService::PROCESS, unknownLimit: 10)->successfulUnknownCount);
    }

    // ── 3. L'observabilite suit la garde ───────────────────────────────────

    public function test_the_policy_unknown_counter_counts_every_guest_attempt_without_known_cost_whatever_the_status(): void
    {
        $this->ledgerRow(GuestShellPolicyService::PROCESS, AiProviderInvocation::STATUS_FAILED, AiProviderInvocation::COST_UNKNOWN);
        $this->ledgerRow(GuestShellPolicyService::PROCESS, AiProviderInvocation::STATUS_SUCCESS, AiProviderInvocation::COST_UNKNOWN);
        $this->ledgerRow(GuestShellPolicyService::PROCESS, AiProviderInvocation::STATUS_FAILED, AiProviderInvocation::COST_KNOWN, 0.30);
        $this->ledgerRow(GuestShellPolicyService::PROCESS, AiProviderInvocation::STATUS_SUCCESS, AiProviderInvocation::COST_KNOWN, 0.10);
        $this->ledgerRow('loop_knowledge.answer', AiProviderInvocation::STATUS_FAILED, AiProviderInvocation::COST_UNKNOWN);

        $usage = app(GuestShellPolicyService::class)->monthlyUsage($this->org, now());

        $this->assertSame(4, $usage['invocations'], 'guest_shell seulement');
        $this->assertSame(2, $usage['failed']);
        $this->assertSame(2, $usage['messages']);
        $this->assertSame(2, $usage['cost_unknown'], 'echec inconnu + succes inconnu : deux operations, jamais 0 USD');
        $this->assertEqualsWithDelta(0.40, $usage['cost_usd'], 0.0001, 'le cout connu compte quel que soit le statut');

        // Le meme chiffre que la garde : l'OrgAdmin/SuperAdmin voient ce qui bloque reellement.
        $this->assertSame(2, $this->authorize(GuestShellPolicyService::PROCESS, unknownLimit: 10)->successfulUnknownCount);
    }
}
