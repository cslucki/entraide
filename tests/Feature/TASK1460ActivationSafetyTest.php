<?php

namespace Tests\Feature;

use App\Ai\Agents\GuestShellAgent;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopSession;
use App\Models\WorkshopSessionInterest;
use App\Services\GuestShell\GuestIdentityThrottle;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Services\Workshops\WorkshopService;
use App\Services\Workshops\WorkshopSessionService;
use App\Support\Ai\AiEconomicGuard;
use App\Support\GuestShell\GuestShellState;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1460 — Activation safety (Shell Welcome V3 P1, Growth V3 §3, audit OPUS
 * F1/F2/F3) — les trois gardes a fermer AVANT tout provider reel :
 *  F1. anti-rafale PRE-IDENTITE par Organization (Shell + interet) avant ensure() ;
 *  F2. state() dit BUDGET_BLOCKED quand le budget IA GLOBAL de l'Organization est atteint ;
 *  F3. une reponse provider obtenue puis un ledger en panne = ligne `unknown`/failed + repli.
 */
class TASK1460ActivationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private User $adminA;

    private Workshop $wa;

    private WorkshopSession $s1;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 5.0, 'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0, 'ai.guest_shell.identity_rate_limit_per_minute' => 3]);
        $policies = app(GuestShellPolicyService::class);
        foreach (['a', 'b'] as $key) {
            $org = Organization::factory()->create(['slug' => "org-{$key}-1460", 'name' => strtoupper($key).' Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
            $policies->update($org, ['enabled' => true, 'max_messages' => 5]);
            OrganizationAiSetting::create(['organization_id' => $org->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test', 'is_enabled' => true]);
            $this->{$key} = $org;
        }
        $this->adminA = User::factory()->create(['organization_id' => $this->a->id]);
        $this->a->update(['admin_id' => $this->adminA->id]);
        $workshops = app(WorkshopService::class);
        $sessions = app(WorkshopSessionService::class);
        $this->wa = $workshops->create($this->a, ['title' => 'Découvrir l\'IA', 'slug' => 'ia-90', 'format' => 'online', 'locale' => 'fr'], $this->adminA);
        $workshops->publish($this->wa, $this->adminA);
        $this->s1 = $sessions->create($this->wa, ['starts_at' => '2026-10-01 18:30', 'timezone' => 'Europe/Paris'], $this->adminA);
        $sessions->publish($this->s1, $this->adminA);
        RateLimiter::clear(GuestIdentityThrottle::key($this->a));
        RateLimiter::clear(GuestIdentityThrottle::key($this->b));
    }

    private function encryptedCookie(string $raw): string
    {
        return app('encrypter')->encrypt(CookieValuePrefix::create(GuestVisitorResolver::COOKIE, app('encrypter')->getKey()).$raw, false);
    }

    /** Un navigateur NEUF : ni session, ni cookies — et la file de cookies du processus de test vidée (T1449 : la CookieJar survit d'une requete a l'autre). */
    private function forgetAuth(): void
    {
        auth()->logout();
        $this->flushSession();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        Cookie::unqueue(GuestVisitorResolver::COOKIE);
    }

    /** Un navigateur NEUF (aucun cookie) parle au Shell. */
    private function newBrowserMessage(Organization $organization): TestResponse
    {
        GuestShellAgent::fake([new TextResponse('Bienvenue.', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini'))]);
        $this->forgetAuth();

        return $this->withCredentials()->postJson(route('organization.shell.message', ['organization' => $organization->slug]), ['message' => 'Bonjour']);
    }

    private function withCookieMessage(string $raw, Organization $organization): TestResponse
    {
        GuestShellAgent::fake([new TextResponse('Bienvenue.', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini'))]);
        $this->forgetAuth();

        return $this->withCredentials()->withUnencryptedCookie(GuestVisitorResolver::COOKIE, $this->encryptedCookie($raw))->postJson(route('organization.shell.message', ['organization' => $organization->slug]), ['message' => 'Encore']);
    }

    // ── F1 ────────────────────────────────────────────────────────────────

    public function test_f1_new_guest_identities_are_throttled_per_organization_before_any_visitor_is_created(): void
    {
        // 3 nouvelles identites par minute (config) : la 4e est refusee AVANT ensure() — aucun visiteur cree.
        $issued = [];
        for ($i = 1; $i <= 3; $i++) {
            $response = $this->newBrowserMessage($this->a)->assertOk()->assertJsonPath('turn', 'answered');
            $issued[] = $response;
        }
        $this->assertSame(3, GuestVisitor::forOrganization($this->a)->count());
        $refused = $this->newBrowserMessage($this->a)->assertOk();
        $refused->assertJsonPath('turn', 'refused')->assertJsonPath('reason', GuestIdentityThrottle::REASON)->assertJsonPath('step', 'organization');
        $this->assertSame(3, GuestVisitor::forOrganization($this->a)->count(), 'la 4e identite n\'est jamais nee');
        $this->assertEmpty(array_filter($refused->headers->getCookies(), fn ($c) => $c->getName() === GuestVisitorResolver::COOKIE), 'aucun cookie Guest pose au refus');
        $this->assertSame(0, AiProviderInvocation::query()->where('organization_id', $this->a->id)->count() - 3, 'aucun appel provider pour l\'identite refusee');

        // Un visiteur EXISTANT (cookie) n'est pas concerne par la rafale pre-identite.
        $raw = Str::random(64);
        RateLimiter::clear(GuestIdentityThrottle::key($this->a));
        $this->withCookieMessage($raw, $this->a)->assertOk()->assertJsonPath('turn', 'answered');
        RateLimiter::hit(GuestIdentityThrottle::key($this->a), 60);
        RateLimiter::hit(GuestIdentityThrottle::key($this->a), 60);
        RateLimiter::hit(GuestIdentityThrottle::key($this->a), 60);
        $this->withCookieMessage($raw, $this->a)->assertOk()->assertJsonPath('turn', 'answered');
        // L'Organization B n'est pas affectee par la rafale de A.
        $this->newBrowserMessage($this->b)->assertOk()->assertJsonPath('turn', 'answered');

        // Meme garde sur l'interet pour une session (V3 §10) : 429, aucun visiteur, aucun interet.
        $url = route('organization.workshop.session.interest', ['organization' => $this->a->slug, 'workshop' => $this->wa->slug, 'session' => $this->s1->id]);
        $before = GuestVisitor::forOrganization($this->a)->count();
        $this->forgetAuth();
        $this->post($url)->assertStatus(429);
        $this->assertSame($before, GuestVisitor::forOrganization($this->a)->count());
        $this->assertSame(0, WorkshopSessionInterest::query()->where('workshop_session_id', $this->s1->id)->count());
        RateLimiter::clear(GuestIdentityThrottle::key($this->a));
        $this->forgetAuth();
        $this->post($url)->assertRedirect();
        $this->assertSame($before + 1, GuestVisitor::forOrganization($this->a)->count());

        // Configuration absente = fail-closed.
        config(['ai.guest_shell.identity_rate_limit_per_minute' => null]);
        RateLimiter::clear(GuestIdentityThrottle::key($this->a));
        $this->newBrowserMessage($this->a)->assertOk()->assertJsonPath('turn', 'refused');
    }

    // ── F2 ────────────────────────────────────────────────────────────────

    public function test_f2_state_is_budget_blocked_when_the_organization_global_ai_budget_is_reached_even_if_the_guest_budget_is_free(): void
    {
        $policies = app(GuestShellPolicyService::class);
        $this->assertSame(GuestShellState::ACTIVE, $policies->state($this->a)->status);

        OrganizationAiSetting::where('organization_id', $this->a->id)->update(['monthly_budget_usd' => 1.00]);
        // Un cout connu d'une AUTRE capability (pas guest_shell) qui epuise le budget global.
        AiInteraction::query()->create(['organization_id' => $this->a->id, 'user_id' => $this->adminA->id, 'process' => 'chat', 'feature' => 'chat', 'model' => 'openai/gpt-4o-mini', 'prompt' => 'q', 'response' => 'r', 'input_tokens' => 10, 'output_tokens' => 10, 'cost_usd' => 1.20, 'cost_unknown' => false]);
        $this->assertGreaterThanOrEqual(1.0, app(AiEconomicGuard::class)->organizationMonthlyCostUsd($this->a));

        $state = $policies->state($this->a);
        $this->assertSame(GuestShellState::BUDGET_BLOCKED, $state->status);
        $this->assertSame(['organization_budget_reached'], $state->reasons);
        // La page publique n'offre plus de saisie ; l'Organization B, elle, reste ACTIVE.
        $this->newBrowserMessage($this->a)->assertOk()->assertJsonPath('turn', 'unavailable');
        $this->assertSame(0, GuestVisitor::forOrganization($this->a)->count(), 'aucune identite creee pour un Shell ferme');
        $this->assertSame(GuestShellState::ACTIVE, $policies->state($this->b)->status);
    }

    // ── F3 ────────────────────────────────────────────────────────────────

    public function test_f3_a_ledger_failure_after_the_provider_answered_leaves_an_unknown_cost_line_and_an_honest_fallback(): void
    {
        // Les autorites economiques sont finales (jamais doublees) : la panne est injectee AU MOMENT DE L'ECRITURE du ledger,
        // apres la reponse provider — la premiere ecriture (succes) tombe, la trace honnete (failed / unknown) doit passer.
        AiProviderInvocation::creating(function (AiProviderInvocation $row): void {
            if ($row->status === 'success') {
                throw new RuntimeException('ledger down after the provider answered');
            }
        });

        $response = $this->newBrowserMessage($this->a)->assertOk();
        $response->assertJsonPath('turn', 'failed')->assertJsonPath('reason', 'ledger_failed');
        $this->assertNotNull($response->json('assistant'), 'le visiteur recoit le repli honnete');
        $rows = AiProviderInvocation::query()->where('organization_id', $this->a->id)->get();
        $this->assertCount(1, $rows, 'exactement UNE ligne au ledger');
        $this->assertSame([AiProviderInvocation::COST_UNKNOWN, 'failed'], [$rows->first()->cost_status, $rows->first()->status]);
        $this->assertStringStartsWith('ledger:', (string) $rows->first()->failure_reason);
        $this->assertSame(1, app(GuestShellPolicyService::class)->monthlyUsage($this->a, now())['cost_unknown'], 'le cout inconnu compte (T1448)');

        // MASTER #53 : le tour SUIVANT est soumis au quota d'operations inconnues (T1448) — avec une limite de 1, il est refuse avant tout provider.
        config(['ai.guest_shell.economic_guard.monthly_unknown_limit' => 1]);
        AiProviderInvocation::flushEventListeners();
        $raw = Str::random(64);
        $next = $this->withCookieMessage($raw, $this->a)->assertOk();
        $this->assertSame('refused', $next->json('turn'), 'quota unknown atteint : refus avant provider');
        $this->assertStringContainsString('unknown', (string) $next->json('reason'));
        $this->assertCount(1, AiProviderInvocation::query()->where('organization_id', $this->a->id)->get(), 'aucun nouvel appel provider');
    }
}
