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
     * TASK-1565 — le vocabulaire, BORNE, des non-tentatives.
     *
     * Une seule liste pour tout le chemin documentaire : le rerank nomme ce
     * qu'il observe lui-meme, `DossierRetrievalSource` nomme les deux causes
     * que lui seul peut distinguer (voir plus bas). Deux vocabulaires auraient
     * fini par se contredire.
     *
     * Regle : **ne jamais inventer une raison que le code reel ne permet pas de
     * determiner.** Chaque constante ci-dessous correspond a une branche
     * EXISTANTE, et a une seule.
     */

    /** Moins de deux candidats : reordonner n'a pas d'alternative. */
    public const REASON_BELOW_MINIMUM_CANDIDATES = 'below_minimum_candidates';

    /** Aucune question a soumettre au reranker. */
    public const REASON_EMPTY_QUERY = 'empty_query';

    /** `DossierRerankGate` ferme : drapeau plateforme, ou Organization non autorisee. */
    public const REASON_GATE_CLOSED = 'gate_closed';

    /** Aucune ligne `organization_ai_settings`, ou configuration inutilisable. */
    public const REASON_ORGANIZATION_SETTING_MISSING_OR_UNUSABLE = 'organization_setting_missing_or_unusable';

    /** Pas de credential tenant capable de reranker (famille non supportee, ou cle vide). */
    public const REASON_NO_CREDENTIAL = 'no_credential';

    /** Rerank autorise mais inexploitable : configuration plateforme incomplete, ou appel en echec. */
    public const REASON_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    /**
     * Le bassin etait VIDE en sortant du filtre `max_distance`.
     *
     * Nommee par `DossierRetrievalSource`, seule a savoir d'ou venait le
     * bassin : le rerank, lui, ne voit qu'un tableau vide et dit
     * `below_minimum_candidates`. La distinction est tout l'objet de T1565 —
     * « personne n'a rien trouve » et « le filtre a tout ecarte » sont deux
     * diagnostics opposes qui produisent le meme zero.
     */
    public const REASON_EMPTY_AFTER_DISTANCE_FILTER = 'empty_after_distance_filter';

    /** Un seul candidat a survecu : le reordonner n'a aucun sens. */
    public const REASON_NOT_APPLICABLE = 'not_applicable';

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
        /**
         * TASK-1565 — POURQUOI le rerank n'a pas ete tente, dans le vocabulaire
         * borne ci-dessus. `null` des que `attempted` vaut true : une tentative
         * n'a pas de raison de non-tentative, et en fabriquer une rendrait la
         * trace illisible.
         *
         * Distincte de `failureReason`, qui reste la CLASSE d'une exception.
         * Les deux coexistent sur le chemin `provider_unavailable` : l'une dit
         * la categorie, l'autre dit quoi est tombe.
         */
        public readonly ?string $reasonNotAttempted = null,
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
     * TASK-1565 : `$reasonNotAttempted` dit LAQUELLE de ces causes s'est
     * produite. Sans elle, toutes rendaient le meme `attempted = false` et la
     * trace ne pouvait pas departager un pilote eteint d'un bassin vide.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function notAttempted(array $rows, ?string $raison = null, ?string $reasonNotAttempted = null): self
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
            reasonNotAttempted: $reasonNotAttempted,
        );
    }

    /** Le rerank a ete tente et a echoue : les lignes rendues sont l'ordre dense d'origine. */
    public function fellBack(): bool
    {
        return $this->attempted && ! $this->succeeded;
    }
}
