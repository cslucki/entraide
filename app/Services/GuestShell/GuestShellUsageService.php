<?php

namespace App\Services\GuestShell;

use App\Models\AiProviderInvocation;
use App\Models\GuestConversation;
use App\Models\GuestMessage;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Support\GuestShell\GuestShellState;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * TASK-1438 — SW-10 : l'observabilite du Shell Welcome (Shell Welcome V3 §14,
 * §17 ; MASTER Q64/Q65). Une seule doctrine, la meme que la garde et le
 * ledger : un cout CONNU est compte quel que soit le statut (un appel qui a
 * echoue apres avoir consomme des tokens a coute) ; un cout INCONNU est un
 * COMPTEUR, jamais converti en 0 USD ; un refus AVANT appel n'existe pas ici
 * (aucune invocation, aucune metrique inventee).
 *
 * Deux unites distinctes, jamais confondues : les MESSAGES visiteur acceptes
 * (`guest_messages` role=user) et les INVOCATIONS IA (lignes du ledger
 * `guest_shell`) — elles peuvent legitimement differer.
 *
 * Jamais de contenu de conversation.
 */
final class GuestShellUsageService
{
    /**
     * @return array{invocations: int, success: int, failed: int, known_cost_usd: float, cost_unknown: int, input_tokens: int, output_tokens: int, visitors: int, conversations: int, visitor_messages: int, accounts_claimed: int}
     */
    public function organizationUsage(Organization $organization, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->usageFor((string) $organization->getKey(), $from, $to);
    }

    /**
     * Les totaux plateforme et la ventilation par Organization (avec l'etat
     * calcule de sa politique), sans jamais un contenu Guest.
     *
     * @return array{totals: array<string, int|float>, organizations: Collection<int, array<string, mixed>>}
     */
    public function platformUsage(CarbonInterface $from, CarbonInterface $to, GuestShellPolicyService $policies): array
    {
        $rows = Organization::query()->orderBy('name')->get()->map(function (Organization $organization) use ($from, $to, $policies) {
            $usage = $this->usageFor((string) $organization->getKey(), $from, $to);
            $state = $policies->state($organization);

            return [
                'organization' => $organization,
                'state' => $state->status,
                'reasons' => $state->reasons,
                'enabled' => (bool) $state->policy->enabled,
                'usage' => $usage,
                'cost_per_conversation' => $usage['conversations'] > 0 ? $usage['known_cost_usd'] / $usage['conversations'] : null,
                'cost_per_visitor' => $usage['visitors'] > 0 ? $usage['known_cost_usd'] / $usage['visitors'] : null,
                'cost_per_account' => $usage['accounts_claimed'] > 0 ? $usage['known_cost_usd'] / $usage['accounts_claimed'] : null,
            ];
        });

        $totals = [];
        foreach (['invocations', 'success', 'failed', 'cost_unknown', 'input_tokens', 'output_tokens', 'visitors', 'conversations', 'visitor_messages', 'accounts_claimed'] as $key) {
            $totals[$key] = (int) $rows->sum(fn (array $row) => $row['usage'][$key]);
        }
        $totals['known_cost_usd'] = (float) $rows->sum(fn (array $row) => $row['usage']['known_cost_usd']);
        $totals['organizations_enabled'] = (int) $rows->where('enabled', true)->count();
        $totals['organizations_active'] = (int) $rows->where('state', GuestShellState::ACTIVE)->count();

        return ['totals' => $totals, 'organizations' => $rows->values()];
    }

    /**
     * @return array{invocations: int, success: int, failed: int, known_cost_usd: float, cost_unknown: int, input_tokens: int, output_tokens: int, visitors: int, conversations: int, visitor_messages: int, accounts_claimed: int}
     */
    private function usageFor(string $organizationId, CarbonInterface $from, CarbonInterface $to): array
    {
        $ledger = AiProviderInvocation::query()
            ->where('organization_id', $organizationId)
            ->where('process', GuestShellPolicyService::PROCESS)
            ->where('operation', AiProviderInvocation::OPERATION_GENERATION)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to);

        $conversations = GuestConversation::query()
            ->where('organization_id', $organizationId)
            ->where('started_at', '>=', $from)
            ->where('started_at', '<', $to);

        return [
            'invocations' => (int) (clone $ledger)->count(),
            'success' => (int) (clone $ledger)->where('status', AiProviderInvocation::STATUS_SUCCESS)->count(),
            'failed' => (int) (clone $ledger)->where('status', AiProviderInvocation::STATUS_FAILED)->count(),
            // V3 §14 : un cout CONNU compte quel que soit le statut.
            'known_cost_usd' => (float) (clone $ledger)->where('cost_status', AiProviderInvocation::COST_KNOWN)->sum('provider_cost'),
            // Un compteur, jamais 0 USD.
            'cost_unknown' => (int) (clone $ledger)->where('cost_status', '!=', AiProviderInvocation::COST_KNOWN)->count(),
            'input_tokens' => (int) (clone $ledger)->sum('input_tokens'),
            'output_tokens' => (int) (clone $ledger)->sum('output_tokens'),
            'visitors' => (int) GuestVisitor::query()->where('organization_id', $organizationId)->where('last_seen_at', '>=', $from)->where('last_seen_at', '<', $to)->count(),
            'conversations' => (int) (clone $conversations)->count(),
            'visitor_messages' => (int) GuestMessage::query()
                ->where('organization_id', $organizationId)
                ->where('role', GuestMessage::ROLE_USER)
                ->where('created_at', '>=', $from)
                ->where('created_at', '<', $to)
                ->count(),
            'accounts_claimed' => (int) (clone $conversations)->whereNotNull('claimed_user_id')->count(),
        ];
    }
}
