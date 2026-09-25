<?php

namespace App\Support\ScenarioPacks\Manifest;

use App\Support\ScenarioManifest\ManifestValidationResult;
use RuntimeException;

/**
 * TASK-1642 — un manifeste a ete presente au Load sans en remplir les
 * preconditions (spec 5.2 : Load exige `VALID` et un digest inchange).
 *
 * Levee AVANT toute ecriture metier, toujours. Le message reste lisible et ne
 * transporte ni SQL, ni classe, ni chemin serveur : il peut etre affiche a un
 * SuperAdmin tel quel.
 */
class ManifestNotLoadableException extends RuntimeException
{
    public static function invalidManifest(ManifestValidationResult $result): self
    {
        $first = $result->errors()[0] ?? null;

        return new self(sprintf(
            'Manifest is INVALID (%d error(s)); nothing was written.%s',
            count($result->errors()),
            $first === null ? '' : sprintf(' First error: %s at %s — %s', $first->code->value, $first->path, $first->message),
        ));
    }

    /**
     * Une reference du manifeste n'a pas pu etre resolue AU CHARGEMENT.
     *
     * Injoignable sur un document VALID : le Validator resout tout le graphe
     * avant Load (spec 8.2). La garde existe parce qu'un adaptateur ne doit
     * jamais SUPPOSER que son appelant a valide — il doit echouer, pas ecrire
     * un monde a moitie construit.
     */
    public static function unresolvedReference(string $collection, string $key): self
    {
        return new self(sprintf(
            "Manifest reference '%s' could not be resolved in '%s' at load time; nothing more was written.",
            $key,
            $collection,
        ));
    }

    /**
     * Course perdue ET gagnant introuvable : etat qui ne devrait pas exister,
     * signale plutot que masque par un chargement silencieux.
     */
    public static function concurrentLoadLost(string $digest): self
    {
        return new self(sprintf(
            'A concurrent load of the same approved manifest (%s) won the race but could not be read back; nothing was kept.',
            substr($digest, 0, 12),
        ));
    }

    /**
     * Le cas que la spec redoute : un document modifie ENTRE l'approbation
     * humaine et le chargement. Le digest approuve ne correspond plus, et le
     * Load s'arrete avant d'avoir cree quoi que ce soit.
     */
    public static function digestMismatch(string $approved, string $actual): self
    {
        return new self(sprintf(
            'Manifest digest does not match the approved digest (approved %s, got %s); nothing was written.',
            substr($approved, 0, 12),
            substr($actual, 0, 12),
        ));
    }
}
