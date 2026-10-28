<?php

namespace App\Services\Loops\Exceptions;

use RuntimeException;

/**
 * TASK-1720 volet B — un refus METIER d'inviter, pas une panne.
 *
 * Le message qu'elle porte est destine a la personne qui invite : il nomme la
 * cause — adresse non verifiee, compte en attente de validation, compte ferme
 * — pour qu'elle puisse agir, au lieu d'un echec muet ou d'un courriel parti
 * dans le vide.
 *
 * Une exception plutot qu'un retour nul : `invite()` rend une `LoopInvitation`
 * et ses quatre appelants s'y fient. Un `null` glisse jusqu'au premier
 * `->token` et devient une erreur de type loin de sa cause.
 */
class LoopInvitationRefusedException extends RuntimeException
{
}
