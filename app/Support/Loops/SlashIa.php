<?php

namespace App\Support\Loops;

/**
 * Detection du prefixe `/ia` dans le composeur du ChatLoop (TASK-1299).
 *
 * Invocation si et seulement si le corps COMMENCE par `/ia` — position 0,
 * insensible a la casse — suivi d'un blanc ou de la fin du corps. Tout le
 * reste est un message ordinaire : ni « Regarde /ia ici », ni `/iat`, ni
 * `//ia` ne declenchent quoi que ce soit — dans un fil a dix personnes, un
 * message ordinaire ne reveille JAMAIS l'IA.
 *
 * Le parseur ne decide que la detection. Le corps est persiste tel que tape
 * par l'appelant, prefixe compris : on n'invente pas un message que
 * l'utilisateur n'a pas ecrit.
 */
final class SlashIa
{
    /**
     * null : pas une invocation. '' : invocation vide (aide locale, aucun
     * cout). Sinon : la question, trimee, a transmettre au modele.
     */
    public static function question(string $body): ?string
    {
        if (preg_match('/^\/ia(?:\s(?<question>.*))?$/isu', $body, $matches) !== 1) {
            return null;
        }

        return trim($matches['question'] ?? '');
    }
}
