<?php

namespace App\Ai;

use InvalidArgumentException;

final class ContexteIa
{
    public function __construct(
        public readonly int $organizationId,
        public readonly ?int $userId,
        public readonly ?int $loopId,
        public readonly string $locale,
        public readonly string $capability,
        public readonly string $correlationId,
        public readonly ?string $source = null,
    ) {
        if ($organizationId < 1) {
            throw new InvalidArgumentException('An AI context requires a valid organization ID.');
        }

        if ($userId !== null && $userId < 1) {
            throw new InvalidArgumentException('The AI context user ID must be valid when provided.');
        }

        if ($loopId !== null && $loopId < 1) {
            throw new InvalidArgumentException('The AI context loop ID must be valid when provided.');
        }

        if (trim($locale) === '') {
            throw new InvalidArgumentException('An AI context requires an explicit locale.');
        }

        if (! preg_match('/^[a-z0-9_]+$/', $capability)) {
            throw new InvalidArgumentException('An AI context requires a valid capability ID.');
        }

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $correlationId)) {
            throw new InvalidArgumentException('An AI context requires a valid correlation ID.');
        }

        if ($source !== null && trim($source) === '') {
            throw new InvalidArgumentException('The AI context source cannot be empty when provided.');
        }
    }
}
