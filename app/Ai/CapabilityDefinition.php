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

        if (trim($promptKey) === '') {
            throw new InvalidArgumentException('A capability requires an explicit prompt key.');
        }
    }

    public function allowsScope(string $scope): bool
    {
        return in_array($scope, $this->allowedScopes, true);
    }
}
