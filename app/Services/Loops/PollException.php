<?php

namespace App\Services\Loops;

use RuntimeException;

/**
 * Une regle de Sondage a ete refusee.
 *
 * Son message est deja traduit et destine a etre montre : le composant l'affiche
 * tel quel plutot que d'inventer un texte a partir d'un code.
 */
class PollException extends RuntimeException {}
