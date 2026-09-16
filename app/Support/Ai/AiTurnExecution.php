<?php

namespace App\Support\Ai;

use App\Models\AiInteraction;
use App\Models\LoopMessage;
use App\Services\Ai\DTO\KnowledgeAnswer;

/**
 * TASK-1585 — le RESULTAT d'une execution par `AiTurnExecutor` : ce qui a ete
 * produit (l'interaction, la reponse documentaire quand le chemin en rend
 * une) et, si le service a REFUSE, le refus tel quel — message et classe.
 *
 * Un refus n'est pas un plantage de l'executeur : c'est un resultat
 * d'observation (economie, ACL, idempotence, panne). L'interaction peut
 * exister meme refusee (arret anticipe non generatif, V0-B) : l'appelant
 * l'inspecte quand elle existe.
 */
final class AiTurnExecution
{
    public function __construct(
        public readonly string $mode,
        public readonly string $runId,
        public readonly string $runKind,
        public readonly ?AiInteraction $interaction,
        public readonly ?KnowledgeAnswer $answer,
        public readonly ?string $refusalMessage = null,
        public readonly ?string $refusalClass = null,
        /**
         * TASK-1588 (review Opus F2) — le tour a ete EXECUTE (provider,
         * ledger, interaction) mais n'a pas pu etre inscrit au manifeste :
         * dit tel quel, jamais confondu avec « rien n'est parti ».
         */
        public readonly ?string $manifestFailure = null,
        /**
         * TASK-1591 — la bulle IA publiee (Lab seulement, `executeForLab`) :
         * le tour suivant d'un scenario y REPOND. Toujours `null` hors Lab.
         */
        public readonly ?LoopMessage $publishedMessage = null,
    ) {}

    public function refused(): bool
    {
        return $this->refusalMessage !== null;
    }
}
