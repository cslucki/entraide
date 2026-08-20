<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Organization;
use App\Models\User;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiPricingCatalog;
use App\Support\Ai\AiUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TASK1205AiEconomicGuardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $user;

    private AiEconomicGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();
        $this->user = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->guard = new AiEconomicGuard;

        config([
            'ai_pricing.overrides' => [],
            'ai_pricing.models' => [
                'priced' => ['model' => ['input_per_1m' => 1.0, 'output_per_1m' => 2.0]],
                'local' => ['*' => ['input_per_1m' => 0.0, 'output_per_1m' => 0.0, 'free' => true]],
            ],
        ]);
    }

    public function test_monthly_known_budget_is_organization_and_process_scoped(): void
    {
        $this->interaction($this->organization, 'chatloop.summarize', 1.99, false);
        $this->interaction($this->otherOrganization, 'chatloop.summarize', 50.00, false);
        $this->interaction($this->organization, 'chatloop.answer', 50.00, false);

        $allowed = $this->authorize();

        $this->assertTrue($allowed->allowed);
        $this->assertSame(1.99, $allowed->knownMonthlyCostUsd);
    }

    public function test_monthly_budget_at_or_above_limit_is_refused(): void
    {
        $this->interaction($this->organization, 'chatloop.summarize', 2.00, false);
        $this->assertSame(AiEconomicGuard::REASON_MONTHLY_BUDGET_REACHED, $this->authorize()->reason);

        AiInteraction::query()->delete();
        AiProviderInvocation::query()->delete();
        $this->interaction($this->organization, 'chatloop.summarize', 2.01, false);
        $this->assertSame(AiEconomicGuard::REASON_MONTHLY_BUDGET_REACHED, $this->authorize()->reason);
    }

    public function test_unknown_quota_is_organization_scoped_and_ignores_historical_null_status(): void
    {
        foreach (range(1, 9) as $unused) {
            $this->interaction($this->organization, 'chatloop.summarize', null, true);
        }

        $this->interaction($this->organization, 'chatloop.summarize', 0.00, null);
        $this->interaction($this->otherOrganization, 'chatloop.summarize', null, true);

        $allowed = $this->authorize();
        $this->assertTrue($allowed->allowed);
        $this->assertSame(9, $allowed->successfulUnknownCount);

        $this->interaction($this->organization, 'chatloop.summarize', null, true);
        $this->assertSame(AiEconomicGuard::REASON_UNKNOWN_QUOTA_REACHED, $this->authorize()->reason);
    }

    public function test_provider_reported_cost_has_priority_including_legitimate_zero(): void
    {
        $cost = $this->guard->finalize('openrouter', 'unpriced', AiUsage::of(1_000_000, 1_000_000), '0.123456');
        $this->assertTrue($cost->isKnown());
        $this->assertSame(0.123456, $cost->costUsd);

        $zero = $this->guard->finalize('openrouter', 'unpriced', AiUsage::notObserved(), 0);
        $this->assertTrue($zero->isKnown());
        $this->assertSame(0.0, $zero->costUsd);
    }

    public function test_catalog_is_fallback_and_unknown_never_becomes_zero(): void
    {
        $known = $this->guard->finalize('priced', 'model', AiUsage::of(1_000_000, 500_000));
        $this->assertSame(2.0, $known->costUsd);
        $this->assertFalse($known->costUnknown);

        $unknown = $this->guard->finalize('openrouter', 'missing', AiUsage::of(100, 50));
        $this->assertNull($unknown->costUsd);
        $this->assertTrue($unknown->costUnknown);
        $this->assertSame(AiPricingCatalog::REASON_MODEL_NOT_IN_CATALOG, $unknown->reason);

        $free = $this->guard->finalize('local', 'anything', AiUsage::notObserved());
        $this->assertSame(0.0, $free->costUsd);
        $this->assertFalse($free->costUnknown);
    }

    private function authorize()
    {
        return $this->guard->authorize(
            $this->organization,
            'chatloop.summarize',
            'openrouter',
            'unpriced',
            2.00,
            10,
        );
    }

    private function interaction(Organization $organization, string $process, ?float $cost, ?bool $unknown): void
    {
        AiInteraction::create([
            'user_id' => $this->user->id,
            'organization_id' => $organization->id,
            'process' => $process,
            'feature' => 'chatloop_ai_summarize',
            'model' => 'provider/model',
            'prompt' => 'prompt',
            'response' => 'response',
            'input_tokens' => 1,
            'output_tokens' => 1,
            'cost_usd' => $cost,
            'cost_unknown' => $unknown,
        ]);

        // TASK-1260 : une generation moderne ecrit LES DEUX tables dans la
        // meme methode ; l'autorite de la garde est le ledger depuis le
        // cutover. Une ligne `cost_unknown = NULL` reste sans jumelle : elle
        // figure l'historique d'avant P1-2, anterieur au ledger.
        if ($unknown === null) {
            return;
        }

        AiProviderInvocation::create([
            'organization_id' => $organization->id,
            'user_id' => $this->user->id,
            'process' => $process,
            'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'provider' => 'provider',
            'model' => 'model',
            'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            'provider_cost' => $unknown ? null : $cost,
            'currency' => $unknown ? null : 'USD',
            'cost_status' => $unknown ? AiProviderInvocation::COST_UNKNOWN : AiProviderInvocation::COST_KNOWN,
            'cost_source' => $unknown ? 'unknown' : 'catalog_estimated',
            'status' => AiProviderInvocation::STATUS_SUCCESS,
            'correlation_id' => (string) Str::uuid(),
        ]);
    }
}
