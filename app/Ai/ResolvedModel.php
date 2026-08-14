<?php

namespace App\Ai;

use InvalidArgumentException;

/**
 * Couple provider/modele EXPLICITE d'un appel IA (TASK-1207 / IA P3).
 *
 * Existe pour qu'un provider et un modele ne puissent pas se perdre en route
 * jusqu'au SDK : ils voyagent ensemble, jamais l'un sans l'autre, et jamais
 * sous forme de deux `string` interchangeables dans une signature.
 */
final class ResolvedModel
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
    ) {
        if (trim($provider) === '') {
            throw new InvalidArgumentException('A resolved model requires an explicit provider.');
        }

        if (trim($model) === '') {
            throw new InvalidArgumentException('A resolved model requires an explicit model.');
        }
    }

    /**
     * Representation utilisee par la colonne `ai_interactions.model`, inchangee
     * depuis l'origine : `"{provider}/{model}"`.
     */
    public function trace(): string
    {
        return $this->provider.'/'.$this->model;
    }
}
