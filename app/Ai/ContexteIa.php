<?php

namespace App\Ai;

use InvalidArgumentException;

/**
 * Contexte metier d'UNE operation IA (TASK-1206 / IA P3).
 *
 * Porte les identifiants deja autorises par l'appelant, jamais un modele
 * Eloquent : le contexte ne doit pouvoir ni recharger, ni elargir, ni
 * contourner la portee que l'appelant a etablie avant de le construire.
 *
 * Invariants :
 * - `organizationId` est OBLIGATOIRE — Organization = Tenant ;
 * - `loopId` n'est JAMAIS un tenant, seulement une portee a l'interieur du
 *   tenant : les deux identifiants coexistent et ne se substituent pas ;
 * - aucun `community_id` : la dette legacy ne remonte pas dans P3.
 *
 * TASK-1207 : les identifiants sont des UUID (string). TASK-1206 les avait
 * types `int` faute de call site reel ; `Organization`, `User` et `Loop`
 * utilisent tous `HasUuids`. Le premier appelant reel tranche.
 */
final class ContexteIa
{
    public function __construct(
        public readonly string $organizationId,
        public readonly ?string $userId,
        public readonly ?string $loopId,
        public readonly string $locale,
        public readonly string $capability,
        public readonly string $correlationId,
        public readonly ?string $source = null,
    ) {
        if (! self::isUuid($organizationId)) {
            throw new InvalidArgumentException('An AI context requires a valid organization ID.');
        }

        if ($userId !== null && ! self::isUuid($userId)) {
            throw new InvalidArgumentException('The AI context user ID must be valid when provided.');
        }

        if ($loopId !== null && ! self::isUuid($loopId)) {
            throw new InvalidArgumentException('The AI context loop ID must be valid when provided.');
        }

        if (trim($locale) === '') {
            throw new InvalidArgumentException('An AI context requires an explicit locale.');
        }

        if (! preg_match('/^[a-z0-9_]+$/', $capability)) {
            throw new InvalidArgumentException('An AI context requires a valid capability ID.');
        }

        if (! self::isUuid($correlationId)) {
            throw new InvalidArgumentException('An AI context requires a valid correlation ID.');
        }

        if ($source !== null && trim($source) === '') {
            throw new InvalidArgumentException('The AI context source cannot be empty when provided.');
        }
    }

    private static function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value,
        );
    }
}
