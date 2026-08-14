<?php

namespace App\Ai\Context;

use RuntimeException;

/**
 * Une source autorisee par la capability, mais inaccessible ici et maintenant
 * (TASK-1209 / IA P3).
 *
 * La raison est un identifiant technique stable, jamais un texte d'interface et
 * jamais un extrait de la ressource : `sourcesDenied` sert au diagnostic, il ne
 * doit rien laisser fuir de ce qu'il n'a pas eu le droit de lire.
 */
final class SourceDenied extends RuntimeException
{
    public const REASON_NO_LOOP_IN_CONTEXT = 'no_loop_in_context';

    public const REASON_LOOP_OUTSIDE_ORGANIZATION = 'loop_outside_organization';

    public const REASON_NO_USER_IN_CONTEXT = 'no_user_in_context';

    public function __construct(public readonly string $source, public readonly string $reason)
    {
        parent::__construct("AI context source [{$source}] denied: {$reason}.");
    }
}
