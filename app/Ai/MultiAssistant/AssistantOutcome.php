<?php

namespace App\Ai\MultiAssistant;

/**
 * Ce qu'UN assistant a produit — ou pourquoi il n'a rien produit.
 * (TASK-1618)
 *
 * Structure de retour du CDC §12. Un echec ne fait PAS tomber les voisins :
 * chaque assistant rend son propre verdict, et l'orchestrateur rend les
 * trois. SLICE E decidera comment l'interface les montre ; ici on garantit
 * seulement qu'un succes obtenu n'est jamais perdu a cause d'un echec
 * ulterieur.
 */
final class AssistantOutcome
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_ERROR = 'error';

    /** Refus AVANT tout appel provider : aucune invocation, aucun cout. */
    public const STATUS_REFUSED = 'refused';

    /**
     * Le modele gratuit n'a pas pu repondre MAINTENANT (HTTP 429).
     * (TASK-1619)
     *
     * Distinct de toute autre panne, et ce n'est pas un detail de
     * classification : c'est le seul echec dont le remede soit « reessayer
     * dans un instant ». Une panne quelconque ne se reessaie pas, elle se
     * signale ; une saturation, si. Confondre les deux donnerait au membre un
     * bouton qui ne sert a rien, ou le priverait du seul qui serve.
     *
     * Le pool gratuit PARTAGE d'OpenRouter est sature regulierement — la
     * recette de TASK-1618 l'a rencontre sur les trois modeles et trois
     * fournisseurs amont differents. Ce n'est donc pas un cas rare a traiter
     * plus tard : c'est le cas nominal d'un palier gratuit.
     */
    public const ERROR_RATE_LIMITED = 'RATE_LIMITED';

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  list<string>  $followUps
     */
    private function __construct(
        public readonly string $assistantKey,
        public readonly string $status,
        public readonly ?string $answer,
        public readonly ?string $errorCode,
        public readonly array $sources,
        public readonly ?string $turnId,
        public readonly ?string $model,
        /**
         * TASK-1619 — « Pour aller plus loin », produites DANS le meme tour
         * provider que la reponse. Jamais un second appel : le precedent est
         * TASK-1595, et il tient pour la meme raison ici — une question
         * suggeree ne vaut pas une generation de plus.
         *
         * @var list<string>
         */
        public readonly array $followUps = [],
    ) {}

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  list<string>  $followUps
     */
    public static function success(string $key, string $answer, array $sources, string $turnId, string $model, array $followUps = []): self
    {
        return new self($key, self::STATUS_SUCCESS, $answer, null, $sources, $turnId, $model, $followUps);
    }

    public static function error(string $key, string $errorCode, ?string $turnId = null, ?string $model = null): self
    {
        return new self($key, self::STATUS_ERROR, null, $errorCode, [], $turnId, $model);
    }

    /**
     * Refuse AVANT le provider — modele absent, preuve perimee, plus gratuit,
     * budget atteint. Distinct de `error` : il n'y a eu aucun appel, donc
     * aucune ligne au ledger, et c'est une information, pas une omission.
     *
     * Le `turnId` existe quand meme : le refus est un TOUR, il porte sa trace
     * metier (assistant, correlation, raison). Sans lui, un refus serait la
     * seule chose que l'inspection ne saurait pas nommer.
     */
    public static function refused(string $key, string $errorCode, ?string $turnId = null): self
    {
        return new self($key, self::STATUS_REFUSED, null, $errorCode, [], $turnId, null);
    }

    /**
     * Le modele est sature. L'appel EST parti — il a donc sa ligne au ledger,
     * comme tout appel emis — mais rien n'est revenu.
     */
    public static function rateLimited(string $key, ?string $turnId = null, ?string $model = null): self
    {
        return new self($key, self::STATUS_ERROR, null, self::ERROR_RATE_LIMITED, [], $turnId, $model);
    }

    /** Peut-on proposer « Reessayer » ? Seule la saturation le merite. */
    public function isRetryable(): bool
    {
        return $this->errorCode === self::ERROR_RATE_LIMITED;
    }

    public function succeeded(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'assistant' => $this->assistantKey,
            'status' => $this->status,
            'answer' => $this->answer,
            'error_code' => $this->errorCode,
            'sources' => $this->sources,
            'turn_id' => $this->turnId,
            'model' => $this->model,
            'follow_ups' => $this->followUps,
        ];
    }
}
