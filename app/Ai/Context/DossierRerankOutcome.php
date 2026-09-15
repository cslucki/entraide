<?php

namespace App\Ai\Context;

/**
 * TASK-1560 — ce que le rerank a fait, et les lignes qu'il rend.
 *
 * Pourquoi un objet plutot qu'un simple `array` : le nombre de sources
 * FINALES n'existe pas encore quand le rerank se termine. `diversify()` et
 * `topK` passent APRES lui. Faire inscrire `final_count` par le reranker
 * reviendrait a lui faire declarer une valeur qu'il ne peut pas connaitre —
 * exactement la fabrication que ce projet refuse ailleurs.
 *
 * Cet objet porte donc ce que le rerank SAIT, et laisse l'appelant — le seul
 * niveau qui verra les deux nombres — composer l'unique evenement journalise.
 */
final class DossierRerankOutcome
{
    /**
     * @param  list<array<string, mixed>>  $rows  les lignes, reordonnees en cas
     *                                            de succes, INCHANGEES sinon
     */
    public function __construct(
        public readonly array $rows,
        public readonly bool $attempted,
        public readonly bool $succeeded,
        public readonly int $candidateCount,
        public readonly ?string $provider,
        public readonly ?string $model,
        public readonly ?int $durationMs,
        /** Classe de l'exception en cas d'echec — jamais son message, qui pourrait porter du contenu. */
        public readonly ?string $failureReason = null,
    ) {}

    /**
     * Le chemin n'a pas ete tente : rerank desactive, aucun credential tenant,
     * ou bassin trop petit pour que reordonner ait un sens.
     *
     * `$raison` n'est renseignee que lorsque la non-tentative vient d'une
     * DEFAILLANCE et non d'un choix — typiquement une configuration incomplete
     * qui fait echouer la resolution du credential. Sans elle, une installation
     * cassee serait indiscernable d'un tenant qui n'a simplement pas de cle, et
     * un refus deterministe se deguiserait en « rien a reranker ».
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function notAttempted(array $rows, ?string $raison = null): self
    {
        return new self(
            rows: $rows,
            attempted: false,
            succeeded: false,
            candidateCount: count($rows),
            provider: null,
            model: null,
            durationMs: null,
            failureReason: $raison,
        );
    }

    /** Le rerank a ete tente et a echoue : les lignes rendues sont l'ordre dense d'origine. */
    public function fellBack(): bool
    {
        return $this->attempted && ! $this->succeeded;
    }
}
