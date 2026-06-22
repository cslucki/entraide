<?php

namespace Tests\Feature\Console;

use App\Models\AdminAiInteraction;
use App\Models\User;
use App\Notifications\AiBudgetExceeded;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CheckAiBudgetsTest extends TestCase
{
    private function makeAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function makeInteraction(array $overrides = []): AdminAiInteraction
    {
        return AdminAiInteraction::create(array_merge([
            'scenario_id' => 'supervision_content',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'status' => 'success',
            'input_excerpt' => 'Test excerpt',
            'input_hash' => hash('sha256', 'Test excerpt'),
            'input_length' => 12,
            'result_summary' => 'Test summary',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'latency_ms' => 1200,
            'cost_usd' => 0.0015,
        ], $overrides));
    }

    public function test_under_threshold_no_alert(): void
    {
        Notification::fake();
        $this->makeAdmin();

        $this->artisan('ai:check-budgets')
            ->assertSuccessful()
            ->expectsOutput('All AI budgets are within limits.');

        Notification::assertNothingSent();
    }

    public function test_over_threshold_sends_alert(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();

        $this->makeInteraction(['cost_usd' => 6.00]);

        $this->artisan('ai:check-budgets')
            ->assertSuccessful()
            ->expectsOutputToContain('supervision_content');

        Notification::assertSentTo(
            $admin,
            AiBudgetExceeded::class,
            function (AiBudgetExceeded $notification) {
                return $notification->scenarioId === 'supervision_content'
                    && $notification->currentCost === 6.00
                    && $notification->budgetLimit === 5.00;
            }
        );
    }

    public function test_no_duplicate_alert_same_month(): void
    {
        Notification::fake();
        $this->makeAdmin();
        $this->makeInteraction(['cost_usd' => 6.00]);

        $cacheKey = 'ai_budget_alert_supervision_content_'.now()->format('Y_m');
        Cache::put($cacheKey, true, now()->endOfMonth());

        $this->artisan('ai:check-budgets')
            ->assertSuccessful()
            ->expectsOutput('All AI budgets are within limits.');

        Notification::assertNothingSent();
    }

    public function test_multiple_scenarios(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();

        $this->makeInteraction(['scenario_id' => 'supervision_content', 'cost_usd' => 10.00]);
        $this->makeInteraction(['scenario_id' => 'clarify_help_request', 'cost_usd' => 5.00]);

        $this->artisan('ai:check-budgets')
            ->assertSuccessful();

        Notification::assertSentTo(
            $admin,
            AiBudgetExceeded::class,
            2
        );
    }

    public function test_no_alert_when_no_admins(): void
    {
        Notification::fake();
        User::factory()->create(['is_admin' => false]);

        $this->makeInteraction(['cost_usd' => 10.00]);

        $this->artisan('ai:check-budgets')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_skips_scenarios_with_zero_budget(): void
    {
        Notification::fake();
        $this->makeAdmin();
        $this->makeInteraction(['scenario_id' => 'supervision_content', 'cost_usd' => 10.00]);

        config(['ai.budget_alerts' => []]);

        $this->artisan('ai:check-budgets')
            ->assertSuccessful()
            ->expectsOutput('All AI budgets are within limits.');

        Notification::assertNothingSent();
    }

    public function test_sum_respects_current_month_only(): void
    {
        Notification::fake();
        $this->makeAdmin();

        $this->makeInteraction(['cost_usd' => 3.00, 'created_at' => now()->subMonth()]);
        $this->makeInteraction(['cost_usd' => 1.00]);

        $this->artisan('ai:check-budgets')
            ->assertSuccessful()
            ->expectsOutput('All AI budgets are within limits.');

        Notification::assertNothingSent();
    }

    public function test_ignores_non_success_interactions(): void
    {
        Notification::fake();
        $this->makeAdmin();

        $this->makeInteraction(['cost_usd' => 10.00, 'status' => 'failed']);

        $this->artisan('ai:check-budgets')
            ->assertSuccessful()
            ->expectsOutput('All AI budgets are within limits.');

        Notification::assertNothingSent();
    }

    public function test_alert_sent_to_all_admins(): void
    {
        Notification::fake();
        $admin1 = $this->makeAdmin();
        $admin2 = $this->makeAdmin();

        $this->makeInteraction(['cost_usd' => 6.00]);

        $this->artisan('ai:check-budgets')
            ->assertSuccessful();

        Notification::assertSentTo(
            [$admin1, $admin2],
            AiBudgetExceeded::class
        );
    }
}
