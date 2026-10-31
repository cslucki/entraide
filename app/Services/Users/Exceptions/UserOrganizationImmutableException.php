<?php

namespace App\Services\Users\Exceptions;

use RuntimeException;

/**
 * TASK-1726 — l'Organization d'un compte existant ne se change pas.
 *
 * Levee par la garde `updating` du modele `User` (voir `User::booted()`), au
 * moment ou une ecriture PAR LE MODELE tenterait de deplacer un compte d'une
 * Organization vers une autre, ou de le detacher de la sienne.
 *
 * Pourquoi une exception, et non un `return` silencieux : un deplacement de
 * tenant qui echoue sans bruit est pire que le deplacement lui-meme. Le code
 * appelant croirait avoir reussi, et l'ecart ne se verrait qu'une fois le
 * contenu melange. Ici l'appel casse, a la ligne exacte, avec les deux
 * identifiants dans le message.
 */
class UserOrganizationImmutableException extends RuntimeException
{
    public function __construct(
        public readonly string $userId,
        public readonly ?string $fromOrganizationId,
        public readonly ?string $toOrganizationId,
    ) {
        parent::__construct(sprintf(
            "L'Organization du compte %s est immuable : refus de passer de %s a %s. "
            .'Un compte appartient a une Organization ; pour en rejoindre une autre, '
            .'il faut un compte distinct (TASK-1726).',
            $userId,
            $fromOrganizationId ?? 'NULL',
            $toOrganizationId ?? 'NULL',
        ));
    }
}
