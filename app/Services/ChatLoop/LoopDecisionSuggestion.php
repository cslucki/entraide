<?php

namespace App\Services\ChatLoop;

/**
 * Suggestion de capitalisation d'une Decision (TASK-1327 / Premium-1).
 *
 * C'est un BROUILLON ephemere, jamais une Decision : rien de ce que porte cet
 * objet n'a ete ecrit dans `loop_decisions`, et rien ne le sera tant que
 * l'humain n'a pas valide via `LoopDecisionsCard::promote()` — la surface
 * canonique de TASK-1106.
 *
 * Le contrat de provenance est celui de Core-1 (TASK-1321) :
 *
 * - `messageId` est un FAIT VERIFIE serveur : le message existe dans CETTE
 *   Boucle, n'est pas supprime, n'est pas deja promu, et figurait dans
 *   l'ensemble exact que le Context Builder a fourni au modele. Une
 *   suggestion sans fait verifiable n'existe pas (`found = false`).
 * - `title` et `rationale` sont un WORDING IA, non verifie : pre-remplis dans
 *   le formulaire, editables, jamais presentes comme la decision elle-meme.
 *
 * `decidedOn` n'est PAS pose par le modele : `LoopDecisionService::promote()`
 * applique deja son repli canonique — la date du message, « quand ca s'est
 * decide, pas quand on l'a remarque ».
 */
final class LoopDecisionSuggestion
{
    private function __construct(
        public readonly bool $found,
        public readonly ?string $messageId,
        public readonly string $title,
        public readonly string $rationale,
        /** Extrait du message source, pour l'afficher a cote du brouillon. */
        public readonly ?string $excerpt,
        public readonly ?string $aiInteractionId,
    ) {}

    public static function found(
        string $messageId,
        string $title,
        string $rationale,
        ?string $excerpt,
        string $aiInteractionId,
    ): self {
        return new self(true, $messageId, $title, $rationale, $excerpt, $aiInteractionId);
    }

    /** Discussion sans conclusion claire : aucune suggestion forcee. */
    public static function none(?string $aiInteractionId = null): self
    {
        return new self(false, null, '', '', null, $aiInteractionId);
    }
}
