<?php

namespace App\Support\Images;

use RuntimeException;

/**
 * TASK-1873 — leve par la couche metier : un lot asynchrone ne peut pas partir sur une
 * connexion qui ne l'est pas.
 */
final class ImageQueueNotConfigured extends RuntimeException
{
    public function __construct(public readonly string $connectionName, public readonly ?string $driverName)
    {
        parent::__construct(sprintf(
            "Le traitement en arriere-plan des images n'est pas configure (connexion « %s », pilote « %s »).",
            $connectionName,
            $driverName ?? 'inconnu',
        ));
    }
}
