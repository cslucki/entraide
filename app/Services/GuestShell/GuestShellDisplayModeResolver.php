<?php

namespace App\Services\GuestShell;

use App\Models\Organization;
use App\Support\GuestShell\GuestPageContext;
use App\Support\GuestShell\GuestShellDisplay;
use App\Support\GuestShell\GuestShellDisplayMode;
use Carbon\CarbonInterface;

/**
 * TASK-1441 — Shell display modes (MASTER Q69) : rend une decision
 * EFFECTIVE, jamais une recopie de la colonne. Ordre, le premier verdict
 * gagne :
 *  1. `enabled = false`                      → off / disabled
 *  2. etat calcule de la politique != ACTIVE → off / policy_not_ready
 *     (Organization fermee, credential, plafond plateforme, budgets)
 *  3. aucun prompt `guest_shell_welcome` actif → off / no_active_prompt
 *  4. PageContext absent, d'une autre Organization ou surface non eligible
 *     (V1 : `organization_home` seulement, jamais `signup`) → off / page_not_eligible
 *  5. sinon le `display_mode` choisi (overlay | shell_first).
 *
 * Jamais un widget qui promet un appel refuse — et jamais SW-6 en double :
 * ni visiteur, ni conversation, ni quota, ni appel provider ici.
 */
final class GuestShellDisplayModeResolver
{
    /** Les surfaces ou le Shell peut apparaitre en V1 (MASTER Q69) : l'accueil public, pas l'inscription. */
    public const ELIGIBLE_KINDS = [GuestPageContext::KIND_ORGANIZATION_HOME];

    public function __construct(
        private readonly GuestShellPolicyService $policies,
        private readonly GuestShellPromptResolver $prompts,
    ) {}

    public function resolve(Organization $organization, ?GuestPageContext $page, ?CarbonInterface $now = null): GuestShellDisplay
    {
        $policy = $this->policies->policyFor($organization);
        if (! $policy->enabled) {
            return GuestShellDisplay::off(GuestShellDisplay::REASON_DISABLED);
        }

        $state = $this->policies->state($organization, $now);
        if (! $state->isActive()) {
            return GuestShellDisplay::off(GuestShellDisplay::REASON_POLICY_NOT_READY, $state->status);
        }

        if ($this->prompts->resolve() === null) {
            return GuestShellDisplay::off(GuestShellDisplay::REASON_NO_ACTIVE_PROMPT, $state->status);
        }

        if ($page === null
            || $page->organizationId !== (string) $organization->getKey()
            || ! in_array($page->kind, self::ELIGIBLE_KINDS, true)) {
            return GuestShellDisplay::off(GuestShellDisplay::REASON_PAGE_NOT_ELIGIBLE, $state->status);
        }

        $mode = GuestShellDisplayMode::isValid($policy->display_mode) ? $policy->display_mode : GuestShellDisplayMode::DEFAULT;

        return GuestShellDisplay::shown($mode, $state->status);
    }
}
