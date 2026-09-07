<?php

namespace App\Support\GuestShell;

/**
 * TASK-1441 — le mode d'affichage CHOISI (MASTER Q69) : overlay (page
 * publique classique + Shell flottant) ou shell_first (le Shell est
 * l'experience principale de la landing, la page classique reste
 * accessible). Jamais `off` ici : OFF = `enabled = false`, une seule autorite.
 */
final class GuestShellDisplayMode
{
    public const OVERLAY = 'overlay';

    public const SHELL_FIRST = 'shell_first';

    public const MODES = [self::OVERLAY, self::SHELL_FIRST];

    public const DEFAULT = self::OVERLAY;

    public static function isValid(mixed $mode): bool
    {
        return is_string($mode) && in_array($mode, self::MODES, true);
    }
}
