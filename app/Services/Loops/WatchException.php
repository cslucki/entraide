<?php

namespace App\Services\Loops;

use RuntimeException;

/**
 * TASK-1879 — une regle de Veille a ete refusee.
 *
 * Son message est deja traduit et destine a etre montre : le composant l'affiche
 * tel quel, comme PollException.
 */
class WatchException extends RuntimeException {}
