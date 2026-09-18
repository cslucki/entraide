<?php

namespace App\Support\GuestShell;

use App\Models\AiProviderInvocation;
use App\Models\GuestMessage;

/**
 * TASK-1437 — SW-7 : le resultat d'un tour du Shell Welcome.
 *
 * - `refused`  : une garde a dit non AVANT tout appel — rien n'est ecrit,
 *                ni message, ni ledger ; `reason`/`step` viennent de SW-6 ;
 * - `answered` : le message visiteur est enregistre, le provider a repondu,
 *                l'invocation est au ledger, la reponse pointe vers elle ;
 * - `failed`   : le message visiteur est enregistre (le tour est consomme,
 *                l'appel a ete tente), l'invocation est au ledger en `failed`
 *                avec la verite disponible, et un message assistant de repli
 *                LOCAL est enregistre (MASTER Q62) — aucun second appel.
 */
final class GuestShellTurn
{
    public const REFUSED = 'refused';

    public const ANSWERED = 'answered';

    public const FAILED = 'failed';

    private function __construct(
        public readonly string $status,
        public readonly ?string $reason,
        public readonly ?string $step,
        public readonly ?GuestMessage $userMessage,
        public readonly ?GuestMessage $assistantMessage,
        public readonly ?AiProviderInvocation $invocation,
    ) {}

    public static function refused(string $step, string $reason): self
    {
        return new self(self::REFUSED, $reason, $step, null, null, null);
    }

    public static function answered(GuestMessage $user, GuestMessage $assistant, AiProviderInvocation $invocation): self
    {
        return new self(self::ANSWERED, null, null, $user, $assistant, $invocation);
    }

    public static function failed(GuestMessage $user, GuestMessage $fallback, AiProviderInvocation $invocation, string $reason): self
    {
        return new self(self::FAILED, $reason, null, $user, $fallback, $invocation);
    }

    public function isRefused(): bool
    {
        return $this->status === self::REFUSED;
    }

    public function isAnswered(): bool
    {
        return $this->status === self::ANSWERED;
    }
}
