<?php

namespace App\Ai;

use InvalidArgumentException;

final class CapabilityDefinition
{
    /**
     * @param  list<string>  $allowedScopes
     * @param  list<string>  $allowedSources
     */
    public function __construct(
        public readonly string $id,
        public readonly string $process,
        public readonly bool $requiresHumanConfirmation,
        public readonly bool $canWrite,
        public readonly array $allowedScopes,
        public readonly array $allowedSources,
        public readonly int $maxOutput,
        public readonly string $promptKey,
        /**
         * Budget de contexte, en caracteres (TASK-1209).
         *
         * `maxOutput` borne ce que le modele produit ; celui-ci borne ce qu'on
         * lui donne. Deux limites distinctes, deux champs distincts.
         */
        public readonly int $contextCharBudget = 12000,
    ) {
        if (! preg_match('/^[a-z0-9_]+$/', $id)) {
            throw new InvalidArgumentException('A capability requires a valid ID.');
        }

        if (! preg_match('/^[a-z0-9_]+(?:\.[a-z0-9_]+)*$/', $process)) {
            throw new InvalidArgumentException('A capability requires a valid process.');
        }

        if ($allowedScopes === [] || $allowedSources === []) {
            throw new InvalidArgumentException('A capability requires explicit scopes and sources.');
        }

        if ($maxOutput < 1) {
            throw new InvalidArgumentException('A capability requires a positive output limit.');
        }

        if ($contextCharBudget < 1) {
            throw new InvalidArgumentException('A capability requires a positive context budget.');
        }

        if (trim($promptKey) === '') {
            throw new InvalidArgumentException('A capability requires an explicit prompt key.');
        }
    }

    public function allowsScope(string $scope): bool
    {
        return in_array($scope, $this->allowedScopes, true);
    }

    /**
     * Pendant de `allowsScope()` pour les sources de contexte (TASK-1209).
     * Le Context Builder s'en sert comme autorite : une source non declaree
     * n'est pas filtree apres coup, elle n'est jamais interrogee.
     */
    public function allowsSource(string $source): bool
    {
        return in_array($source, $this->allowedSources, true);
    }
}
