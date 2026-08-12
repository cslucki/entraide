<?php

namespace App\Console\Commands;

use App\Models\AdminAiInteraction;
use App\Models\User;
use App\Notifications\AiBudgetExceeded;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckAiBudgets extends Command
{
    protected $signature = 'ai:check-budgets';

    protected $description = 'Check AI monthly budgets per scenario and alert admins if exceeded';

    public function handle(): int
    {
        $budgets = config('ai.budget_alerts', []);
        $alerted = false;

        foreach ($budgets as $scenarioId => $limit) {
            $scoped = fn () => AdminAiInteraction::query()
                ->where('scenario_id', $scenarioId)
                ->where('created_at', '>=', now()->startOfMonth())
                ->where('status', 'success');

            // Arithmetique inchangee par TASK-1132 : SUM() ignore les NULL, et
            // les appels sans tarif connu contribuaient deja 0 auparavant. Le
            // total, les seuils et le declenchement des alertes sont identiques.
            $currentCost = $scoped()->sum('cost_usd');

            // Ce que P1-2 ajoute : rendre visible qu'un total est PARTIEL des
            // qu'un appel echappe a la mesure. Sans cela, `cost_unknown` ferait
            // silencieusement passer des appels pour gratuits dans un budget.
            $unmeasured = $scoped()->where('cost_unknown', true)->count();

            if ($unmeasured > 0) {
                $this->warn(sprintf(
                    'Scenario %s: %d interaction(s) with an unmeasurable cost this month. Total %s is a floor, not the full spend.',
                    $scenarioId,
                    $unmeasured,
                    $currentCost,
                ));
            }

            if ($currentCost <= $limit) {
                continue;
            }

            $cacheKey = "ai_budget_alert_{$scenarioId}_".now()->format('Y_m');
            if (Cache::get($cacheKey)) {
                continue;
            }

            $admins = User::where('is_admin', true)->get();
            foreach ($admins as $admin) {
                rescue(
                    fn () => $admin->notify(new AiBudgetExceeded(
                        scenarioId: $scenarioId,
                        currentCost: (float) $currentCost,
                        budgetLimit: (float) $limit,
                    )),
                    function (\Throwable $e) use ($scenarioId, $admin) {
                        Log::error('ai_budget_alert_notification_failed', [
                            'event' => 'ai_budget_alert_notification_failed',
                            'scenario_id' => $scenarioId,
                            'admin_id' => $admin->id,
                            'organization_id' => $admin->organization_id,
                            'exception_class' => get_class($e),
                        ]);
                    },
                    false,
                );
            }

            Cache::put($cacheKey, true, now()->endOfMonth());
            $alerted = true;

            $this->info("Budget alert sent for scenario: {$scenarioId} ({$currentCost} > {$limit})");
        }

        if (! $alerted) {
            $this->info('All AI budgets are within limits.');
        }

        return Command::SUCCESS;
    }
}
