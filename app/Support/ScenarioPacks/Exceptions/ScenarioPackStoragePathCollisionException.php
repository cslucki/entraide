<?php

namespace App\Support\ScenarioPacks\Exceptions;

use RuntimeException;

/**
 * Le pack s'apprete a ecrire un fichier a un chemin de storage deja occupe,
 * alors que ce chemin n'est pas inscrit dans CE chargement (donc pas un
 * fichier que ce chargement a lui-meme produit lors d'un passage anterieur).
 * Contrat TASK-1245 : un fichier preexistant n'est jamais ecrase ni
 * supprime ; ownership non prouvable -> refus explicite du chargement, pas
 * d'overwrite silencieux.
 */
class ScenarioPackStoragePathCollisionException extends RuntimeException
{
    public static function forPath(string $entityType, string $internalKey, string $disk, string $path): self
    {
        return new self(
            "Le fichier '{$path}' (disque '{$disk}') existe deja et n'appartient pas a ce chargement ".
            "(entite '{$entityType}:{$internalKey}'). Un fichier preexistant n'est jamais ecrase : chargement refuse."
        );
    }
}
