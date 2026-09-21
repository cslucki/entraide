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
     * @param  list<array<string, mixed>>  $sources
     */
    private function __construct(
        public readonly string $assistantKey,
        public readonly string $status,
        public readonly ?string $answer,
        public readonly ?string $errorCode,
        public readonly array $sources,
        public readonly ?string $turnId,
        public readonly ?string $model,
    ) {}

    /** @param list<array<string, mixed>> $sources */
    public static function success(string $key, string $answer, array $sources, string $turnId, string $model): self
    {
        return new self($key, self::STATUS_SUCCESS, $answer, null, $sources, $turnId, $model);
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
        ];
    }
}
