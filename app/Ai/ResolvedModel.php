<?php

namespace App\Ai;

use InvalidArgumentException;

/**
 * Couple provider/modele EXPLICITE d'un appel IA (TASK-1207 / IA P3).
 *
 * Existe pour qu'un provider et un modele ne puissent pas se perdre en route
 * jusqu'au SDK : ils voyagent ensemble, jamais l'un sans l'autre, et jamais
 * sous forme de deux `string` interchangeables dans une signature.
 *
 * TASK-1212 (P4-lite) : `instance` est le nom de l'instance Laravel AI SDK a
 * invoquer — celle qui porte le credential de l'Organization. `provider`
 * reste la famille (openrouter, openai, ollama) utilisee par la trace et le
 * catalogue de prix. Aucun credential ne transite par cet objet.
 */
final class ResolvedModel
{
    public readonly string $instance;

    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        ?string $instance = null,
    ) {
        if (trim($provider) === '') {
            throw new InvalidArgumentException('A resolved model requires an explicit provider.');
        }

        if (trim($model) === '') {
            throw new InvalidArgumentException('A resolved model requires an explicit model.');
        }

        $this->instance = trim((string) $instance) !== '' ? $instance : $provider;
    }

    /**
     * Representation utilisee par la colonne `ai_interactions.model`, inchangee
     * depuis l'origine : `"{provider}/{model}"`. L'instance SDK n'y figure pas.
     */
    public function trace(): string
    {
        return $this->provider.'/'.$this->model;
    }
}
