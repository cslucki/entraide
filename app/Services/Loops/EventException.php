<?php

namespace App\Services\Loops;

use RuntimeException;

/**
 * Une regle d'Evenement a ete refusee.
 *
 * Son message est deja traduit et destine a etre montre : l'appelant l'affiche
 * tel quel plutot que d'inventer un texte a partir d'un code.
 */
class EventException extends RuntimeException {}
