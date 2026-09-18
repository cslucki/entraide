<?php

namespace App\Support\GuestShell;

use App\Models\OrganizationAiSetting;
use App\Models\OrganizationGuestShellPolicy;

/**
 * TASK-1429 — SW-1 : l'etat calcule du Shell Welcome d'une Organization,
 * tel que l'Addendum V2 §2 le demande : ACTIVE / DISABLED / NO_CREDENTIAL /
 * BUDGET_BLOCKED / MISCONFIGURED. Un seul statut, des raisons lisibles,
 * jamais de secret.
 */
final class GuestShellState
{
    public const ACTIVE = 'ACTIVE';

    public const DISABLED = 'DISABLED';

    public const NO_CREDENTIAL = 'NO_CREDENTIAL';

    public const BUDGET_BLOCKED = 'BUDGET_BLOCKED';

    public const MISCONFIGURED = 'MISCONFIGURED';

    public const STATUSES = [self::ACTIVE, self::DISABLED, self::NO_CREDENTIAL, self::BUDGET_BLOCKED, self::MISCONFIGURED];

    /**
     * @param  list<string>  $reasons
     * @param  array{messages: int, cost_usd: float, cost_unknown: int}  $monthlyUsage
     */
    public function __construct(
        public readonly string $status,
        public readonly array $reasons,
        public readonly OrganizationGuestShellPolicy $policy,
        public readonly ?OrganizationAiSetting $setting,
        public readonly array $monthlyUsage,
    ) {
        if (! in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown guest shell status [{$status}].");
        }
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** Provider et modele REELLEMENT utilises (ceux de l'autorite IA de l'Organization), jamais la cle. */
    public function providerLabel(): ?string
    {
        if ($this->setting === null || trim((string) $this->setting->provider) === '') {
            return null;
        }

        return trim($this->setting->provider.' / '.(string) $this->setting->model);
    }

    public function averageCostPerMessage(): ?float
    {
        return $this->monthlyUsage['messages'] > 0 ? $this->monthlyUsage['cost_usd'] / $this->monthlyUsage['messages'] : null;
    }
}
