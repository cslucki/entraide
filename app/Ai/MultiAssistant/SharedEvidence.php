<?php

namespace App\Ai\MultiAssistant;

use App\Ai\Context\ContexteBorne;

/**
 * Les preuves construites UNE FOIS, partagees par les trois assistants.
 * (TASK-1618)
 *
 * Porte le contexte borne ET son identite : `turnId` est le tour E1 qui a
 * REELLEMENT fait le retrieval, `correlationId` regroupe l'operation metier.
 * Les deux voyagent avec les preuves parce que chaque assistant devra les
 * citer dans sa trace — `source_turn_id` et `source_correlation_id`. Sans
 * eux, la reutilisation serait silencieuse, et c'est precisement ce que le
 * CDC interdit.
 *
 * `fingerprint` est la CONDITION du partage, pas un confort : deux demandes
 * ne partagent leurs preuves que si elles portent la meme empreinte —
 * meme user, meme Organization, meme Boucle, meme question, memes sources
 * autorisees. Une seule difference, et on reconstruit.
 */
final class SharedEvidence
{
    public function __construct(
        public readonly ContexteBorne $borne,
        public readonly string $turnId,
        public readonly string $correlationId,
        public readonly string $fingerprint,
        /** Le retrieval a-t-il reellement eu lieu (vs contexte vide) ? */
        public readonly bool $hasRetrieval,
    ) {}

    /**
     * L'empreinte des conditions de partage.
     *
     * Les cinq termes du CDC §3, plus les sources REELLEMENT autorisees —
     * c'est elle qui porte « memes permissions » : deux membres aux droits
     * differents ne voient pas les memes sources, donc n'obtiennent pas la
     * meme empreinte, donc ne partagent rien.
     *
     * @param  list<string>  $allowedSources
     */
    public static function fingerprintFor(
        string $organizationId,
        string $loopId,
        ?string $userId,
        string $question,
        array $allowedSources,
    ): string {
        sort($allowedSources);

        return hash('sha256', implode('|', [
            $organizationId,
            $loopId,
            $userId ?? '',
            trim($question),
            implode(',', $allowedSources),
        ]));
    }
}
