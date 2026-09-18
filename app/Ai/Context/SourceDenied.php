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
 *
 * TASK-1554 : `$message` permet a un refus deja PORTE PAR UN MESSAGE HUMAIN de
 * devenir typed sans changer un caractere de ce que l'appelant affiche. La
 * raison reste l'identifiant machine ; le message reste ce qu'il etait. Sans ce
 * parametre, typer un refus existant aurait impose de choisir entre les deux —
 * et c'est exactement le choix qu'il ne faut pas avoir a faire.
 */
final class SourceDenied extends RuntimeException
{
    public const REASON_NO_LOOP_IN_CONTEXT = 'no_loop_in_context';

    public const REASON_LOOP_OUTSIDE_ORGANIZATION = 'loop_outside_organization';

    public const REASON_NO_USER_IN_CONTEXT = 'no_user_in_context';

    public function __construct(public readonly string $source, public readonly string $reason, ?string $message = null)
    {
        parent::__construct($message ?? "AI context source [{$source}] denied: {$reason}.");
    }
}
