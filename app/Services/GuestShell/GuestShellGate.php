<?php

namespace App\Services\GuestShell;

use App\Ai\CapabilityRegistry;
use App\Ai\ContexteIa;
use App\Ai\ProviderResolver;
use App\Models\GuestConversation;
use App\Models\GuestMessage;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Services\Ai\AiProviderInvocationLedger;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiPricingCatalog;
use App\Support\GuestShell\GuestShellClearance;
use App\Support\GuestShell\GuestShellPrompt;
use DomainException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * TASK-1436 — SW-6 : la garde economique du Shell Welcome, AVANT tout appel
 * provider (Addendum V2 §11, cadre Cyril 21h50 §5, MASTER Q60/Q61).
 *
 * Quatorze controles (Shell Welcome V3 §13), dans l'ordre canonique ; le PREMIER refus arrete tout et
 * aucun provider n'est appele — un refus n'ecrit rien (ni ledger, ni trace).
 * Le credential est TOUJOURS celui de l'Organization, resolu par
 * `ProviderResolver` (`OrganizationAiSetting`) : la primitive plateforme de la
 * supervision n'existe pas sur ce chemin (cadre Cyril §6 — P0 si elle y entre). Le plafond plateforme de SW-1 devient ici un
 * vrai coupe-circuit. Les bornes d'entree/sortie ont chacune UNE autorite
 * (`ai.shell.max_input_chars`, `ai.guest_shell.max_output_tokens`) ; le
 * visiteur ne controle jamais `max_tokens`.
 */
final class GuestShellGate
{
    public function __construct(
        private readonly GuestShellPolicyService $policies,
        private readonly GuestConversationService $conversations,
        private readonly ProviderResolver $providers,
        private readonly AiEconomicGuard $economy,
        private readonly CapabilityRegistry $capabilities,
        private readonly GuestShellPromptResolver $prompts,
    ) {}

    public function clear(Organization $organization, GuestVisitor $visitor, GuestConversation $conversation, string $candidateMessage): GuestShellClearance
    {
        if ($visitor->organization_id !== $organization->getKey() || $conversation->organization_id !== $organization->getKey() || $conversation->guest_visitor_id !== $visitor->getKey()) {
            // Une incoherence tenant n'est pas une raison metier : c'est une faute de code.
            throw new DomainException('Guest shell clearance requires a visitor and a conversation of the same Organization.');
        }

        $policy = $this->policies->policyFor($organization);

        // 1. La politique de l'Organization.
        if (! $policy->enabled) {
            return GuestShellClearance::refuse(GuestShellClearance::STEP_POLICY, 'shell_disabled');
        }

        // 2. L'Organization elle-meme : active ET publique.
        if (! $organization->is_active) {
            return GuestShellClearance::refuse(GuestShellClearance::STEP_ORGANIZATION, 'organization_inactive');
        }
        if (! $organization->is_public) {
            return GuestShellClearance::refuse(GuestShellClearance::STEP_ORGANIZATION, 'organization_not_public');
        }

        // 3. Le credential de l'Organization — par la resolution normale, jamais une cle plateforme.
        $definition = $this->capabilities->get(CapabilityRegistry::GUEST_SHELL_WELCOME);
        $correlationId = (string) Str::uuid();
        $contexte = new ContexteIa(
            organizationId: (string) $organization->getKey(),
            userId: null,
            loopId: null,
            locale: (string) ($conversation->locale ?: $organization->locale ?: config('app.locale', 'fr')),
            capability: CapabilityRegistry::GUEST_SHELL_WELCOME,
            correlationId: $correlationId,
            source: 'guest_shell',
        );
        try {
            $resolved = $this->providers->resolve(CapabilityRegistry::GUEST_SHELL_WELCOME, $contexte);
        } catch (DomainException $exception) {
            return GuestShellClearance::refuse(GuestShellClearance::STEP_CREDENTIAL, 'no_credential', ['detail' => $exception->getMessage()]);
        }

        // 4. Le prompt d'accueil ACTIF en base (SW-2) — Shell Welcome V3 §13 : sans lui, rien ne part.
        $prompt = $this->prompts->resolve();
        if (! $prompt instanceof GuestShellPrompt) {
            return GuestShellClearance::refuse(GuestShellClearance::STEP_PROMPT, 'no_active_prompt');
        }

        // 5. La limite de messages de CETTE conversation (SW-4) — conversation active ET tours restants.
        if (! $conversation->isActive() || $this->conversations->remainingUserMessages($conversation, $policy) <= 0) {
            return GuestShellClearance::refuse(GuestShellClearance::STEP_CONVERSATION_LIMIT, 'max_messages_reached', ['max_messages' => $policy->max_messages]);
        }

        // 6. Le quota TRANSVERSE du visiteur (toutes conversations) + le rate limit anti-rafale.
        $monthlyMax = config('ai.guest_shell.visitor_monthly_max_messages');
        if (! is_int($monthlyMax) || $monthlyMax < 1) {
            return GuestShellClearance::refuse(GuestShellClearance::STEP_VISITOR_QUOTA, 'visitor_quota_unset');
        }
        $used = $this->conversations->userMessagesThisMonth($visitor);
        if ($used >= $monthlyMax) {
            return GuestShellClearance::refuse(GuestShellClearance::STEP_VISITOR_QUOTA, 'visitor_monthly_quota_reached', ['used' => $used, 'max' => $monthlyMax]);
        }
        $perMinute = config('ai.guest_shell.rate_limit_per_minute');
        if (! is_int($perMinute) || $perMinute < 1) {
            return GuestShellClearance::refuse(GuestShellClearance::STEP_VISITOR_QUOTA, 'rate_limit_unset');
        }
        $rateKey = self::rateKey($organization, $visitor);
        if (RateLimiter::tooManyAttempts($rateKey, $perMinute)) {
            return GuestShellClearance::refuse(GuestShellClearance::STEP_VISITOR_QUOTA, 'rate_limited', ['retry_after_seconds' => RateLimiter::availableIn($rateKey)]);
        }

        // 7, 8, 9, 11. Budgets et politique de prix — les autorites existantes, une seule fois.
        // Le budget passe au processus `guest_shell` EST le budget Guest de l'Organization
        // (sa politique, sinon le defaut plateforme) : en V1 les deux coincident.
        $verdict = $this->economy->authorize(
            $organization,
            GuestShellPolicyService::PROCESS,
            $resolved->provider,
            $resolved->model,
            $this->policies->effectiveMonthlyBudgetUsd($policy),
            (int) config('ai.guest_shell.economic_guard.monthly_unknown_limit', 10),
        );
        if (! $verdict->allowed) {
            [$step, $reason] = match ($verdict->reason) {
                AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED => [GuestShellClearance::STEP_ORGANIZATION_BUDGET, 'organization_budget_reached'],
                AiEconomicGuard::REASON_MONTHLY_BUDGET_REACHED => [$policy->guest_monthly_budget_usd !== null ? GuestShellClearance::STEP_GUEST_BUDGET : GuestShellClearance::STEP_PROCESS_BUDGET, $policy->guest_monthly_budget_usd !== null ? 'guest_monthly_budget_reached' : 'process_budget_reached'],
                AiEconomicGuard::REASON_UNKNOWN_QUOTA_REACHED => [GuestShellClearance::STEP_PRICING, 'unknown_cost_quota_reached'],
                default => [GuestShellClearance::STEP_PROCESS_BUDGET, (string) $verdict->reason],
            };

            return GuestShellClearance::refuse($step, $reason, ['known_monthly_cost_usd' => $verdict->knownMonthlyCostUsd]);
        }

        // 10. Le plafond PLATEFORME : un vrai coupe-circuit, pas un indicateur (SW-1 -> SW-6).
        $ceiling = config('ai.guest_shell.platform_monthly_ceiling_usd');
        if (! is_numeric($ceiling) || (float) $ceiling <= 0) {
            return GuestShellClearance::refuse(GuestShellClearance::STEP_PLATFORM_CEILING, 'platform_ceiling_unset');
        }
        $platformCost = $this->policies->platformMonthlyCostUsd(now());
        if ($platformCost >= (float) $ceiling) {
            return GuestShellClearance::refuse(GuestShellClearance::STEP_PLATFORM_CEILING, 'platform_ceiling_reached', ['platform_monthly_cost_usd' => $platformCost, 'ceiling_usd' => (float) $ceiling]);
        }

        // 11. Politique de prix : un modele sans tarif connu n'est admis que sous le quota « inconnu » (deja verifie) — on le dit.
        $pricingKnown = AiPricingCatalog::hasRate($resolved->provider, $resolved->model);

        // 12. Borne d'entree — l'autorite du Shell (SW-4), cote serveur.
        $length = mb_strlen(trim($candidateMessage));
        if ($length === 0 || $length > GuestMessage::maxUserBodyLength()) {
            return GuestShellClearance::refuse(GuestShellClearance::STEP_INPUT_BOUND, 'input_out_of_bounds', ['length' => $length, 'max' => GuestMessage::maxUserBodyLength()]);
        }

        // 13. Borne de sortie — UNE autorite (MASTER Q61), jamais fournie par le visiteur.
        $maxOutput = config('ai.guest_shell.max_output_tokens');
        if (! is_int($maxOutput) || $maxOutput < 1) {
            return GuestShellClearance::refuse(GuestShellClearance::STEP_OUTPUT_BOUND, 'output_bound_unset');
        }
        $maxOutput = min($maxOutput, $definition->maxOutput);

        // 14. Le ledger est pret : l'autorite economique existe et l'Organization est identifiee.
        if (! app()->bound(AiProviderInvocationLedger::class) && ! class_exists(AiProviderInvocationLedger::class)) {
            return GuestShellClearance::refuse(GuestShellClearance::STEP_LEDGER, 'ledger_unavailable');
        }

        // Tout est vert : ce passage compte dans la rafale (il precede un appel).
        RateLimiter::hit($rateKey, 60);

        return GuestShellClearance::allow($resolved, $prompt, $maxOutput, $correlationId, [
            'pricing_known' => $pricingKnown,
            'known_monthly_cost_usd' => $verdict->knownMonthlyCostUsd,
            'visitor_monthly_used' => $used,
            'visitor_monthly_max' => $monthlyMax,
            'remaining_in_conversation' => $this->conversations->remainingUserMessages($conversation, $policy),
        ]);
    }

    /** Cle de rafale : l'Organization ET le visiteur — le meme cookie sur deux Organizations ne melange rien (MASTER Q60). */
    public static function rateKey(Organization $organization, GuestVisitor $visitor): string
    {
        return 'guest-shell:'.$organization->getKey().':'.$visitor->getKey();
    }
}
