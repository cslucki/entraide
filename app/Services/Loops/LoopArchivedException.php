<?php

namespace App\Services\Loops;

use RuntimeException;

/**
 * Une ecriture a ete tentee sur une Boucle archivee.
 *
 * Un type a part plutot qu'un RuntimeException nu : les appelants doivent
 * pouvoir distinguer « la Boucle est en lecture seule » — qui merite un message
 * clair et pas une erreur — de toutes les autres pannes.
 */
class LoopArchivedException extends RuntimeException {}
