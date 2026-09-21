<?php

namespace App\Ai\MultiAssistant;

/**
 * Le resultat COMPLET d'un tour « 3 assistants IA ». (TASK-1618)
 *
 * Un seul objet pour toute l'operation, parce que c'est l'operation entiere
 * qui est l'unite de sens : les trois assistants partagent une correlation,
 * des preuves et un ordre. Rendre trois valeurs separees obligerait chaque
 * appelant a les reconstituer — et a se tromper sur celui qui a vraiment fait
 * le retrieval.
 *
 * `outcomes` est TOUJOURS dans l'ordre d'execution (Aperio, Traverse, Limen).
 * Un assistant en echec y figure avec son statut : rien n'est retire, jamais.
 * SLICE E decidera de l'affichage ; ici on garantit seulement que rien ne se
 * perd entre l'appel et l'ecran.
 */
final class MultiAssistantRun
{
    /**
     * @param  list<AssistantOutcome>  $outcomes
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly SharedEvidence $evidence,
        public readonly array $outcomes,
    ) {}

    /** @return list<AssistantOutcome> */
    public function succeeded(): array
    {
        return array_values(array_filter(
            $this->outcomes,
            static fn (AssistantOutcome $outcome): bool => $outcome->succeeded(),
        ));
    }

    /**
     * Au moins un assistant a-t-il repondu ? Un tour ou les trois echouent
     * reste un tour REEL — il a construit ses preuves, il a ses traces — mais
     * l'appelant doit pouvoir le distinguer sans inspecter trois statuts.
     */
    public function hasAnswer(): bool
    {
        return $this->succeeded() !== [];
    }

    public function outcomeFor(string $assistantKey): ?AssistantOutcome
    {
        foreach ($this->outcomes as $outcome) {
            if ($outcome->assistantKey === $assistantKey) {
                return $outcome;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'correlation_id' => $this->correlationId,
            'evidence_turn_id' => $this->evidence->turnId,
            'evidence_fingerprint' => $this->evidence->fingerprint,
            'has_retrieval' => $this->evidence->hasRetrieval,
            'assistants' => array_map(
                static fn (AssistantOutcome $outcome): array => $outcome->toArray(),
                $this->outcomes,
            ),
        ];
    }
}
