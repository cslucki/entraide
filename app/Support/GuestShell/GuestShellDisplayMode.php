<?php

namespace App\Support\GuestShell;

/**
 * TASK-1441 — le mode d'affichage CHOISI (MASTER Q69) : overlay (page
 * publique classique + Shell flottant) ou shell_first (le Shell est
 * l'experience principale de la landing, la page classique reste
 * accessible). Jamais `off` ici : OFF = `enabled = false`, une seule autorite.
 *
 * TASK-1500 — le rail gauche devient un CHOIX, pas un acquis (decision Cyril
 * du 10/09/2026 04h37). Deux facons d'etre « Shell First » :
 *
 * - `shell_first`      : le Shell seul, conversation plein viewport ;
 * - `shell_first_rail` : le meme Shell, plus le rail d'icones a gauche, pour
 *                        que la page ressemble a une Boucle.
 *
 * Ces deux modes partagent TOUT sauf le rail. C'est pourquoi le code ne
 * compare jamais a `SHELL_FIRST` directement : il demande `isShellFirst()`.
 * Une comparaison litterale oubliee ferait retomber `shell_first_rail` sur le
 * chemin overlay — un defaut silencieux, invisible en test unitaire.
 */
final class GuestShellDisplayMode
{
    public const OVERLAY = 'overlay';

    public const SHELL_FIRST = 'shell_first';

    public const SHELL_FIRST_RAIL = 'shell_first_rail';

    public const MODES = [self::OVERLAY, self::SHELL_FIRST, self::SHELL_FIRST_RAIL];

    /** Les modes ou le Shell EST la page. Le rail ne change pas cette nature. */
    public const SHELL_FIRST_MODES = [self::SHELL_FIRST, self::SHELL_FIRST_RAIL];

    public const DEFAULT = self::OVERLAY;

    public static function isValid(mixed $mode): bool
    {
        return is_string($mode) && in_array($mode, self::MODES, true);
    }

    /** Le Shell est-il la page ? Vrai pour les DEUX variantes shell-first. */
    public static function isShellFirst(mixed $mode): bool
    {
        return is_string($mode) && in_array($mode, self::SHELL_FIRST_MODES, true);
    }

    /** Le rail d'icones est-il demande ? Seul `shell_first_rail` le porte. */
    public static function showsRail(mixed $mode): bool
    {
        return $mode === self::SHELL_FIRST_RAIL;
    }
}
