<?php

namespace App\Support\GuestShell;

use InvalidArgumentException;

/**
 * TASK-1441 — la DECISION d'affichage effective (MASTER Q69) : « est-il
 * coherent d'afficher cette experience ici ? » — `off` avec une raison bornee,
 * ou `overlay` / `shell_first`. Ce n'est PAS la garde provider (SW-6 reste
 * la garde definitive avant tout appel) : aucun visiteur, aucune
 * conversation, aucun message n'est consulte ici.
 */
final class GuestShellDisplay
{
    public const OFF = 'off';

    public const REASON_DISABLED = 'disabled';

    public const REASON_POLICY_NOT_READY = 'policy_not_ready';

    public const REASON_NO_ACTIVE_PROMPT = 'no_active_prompt';

    public const REASON_PAGE_NOT_ELIGIBLE = 'page_not_eligible';

    /** TASK-1445 (MASTER Q73) : un utilisateur authentifie n'a JAMAIS le Guest Shell — l'experience membre seulement. */
    public const REASON_AUTHENTICATED = 'authenticated';

    public const REASONS = [
        self::REASON_DISABLED,
        self::REASON_POLICY_NOT_READY,
        self::REASON_NO_ACTIVE_PROMPT,
        self::REASON_PAGE_NOT_ELIGIBLE,
        self::REASON_AUTHENTICATED,
        GuestShellDisplayMode::OVERLAY,
        GuestShellDisplayMode::SHELL_FIRST,
    ];

    private function __construct(
        public readonly string $mode,
        public readonly string $reason,
        public readonly ?string $policyStatus,
    ) {
        if (! in_array($mode, [self::OFF, ...GuestShellDisplayMode::MODES], true)) {
            throw new InvalidArgumentException("Unknown guest shell display [{$mode}].");
        }
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException("Unknown guest shell display reason [{$reason}].");
        }
    }

    public static function off(string $reason, ?string $policyStatus = null): self
    {
        return new self(self::OFF, $reason, $policyStatus);
    }

    public static function shown(string $mode, string $policyStatus): self
    {
        if (! GuestShellDisplayMode::isValid($mode)) {
            throw new InvalidArgumentException("Unknown guest shell display mode [{$mode}].");
        }

        return new self($mode, $mode, $policyStatus);
    }

    public function isVisible(): bool
    {
        return $this->mode !== self::OFF;
    }
}
