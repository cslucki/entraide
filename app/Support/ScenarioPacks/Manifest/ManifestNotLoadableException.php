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
     * TASK-1644 — un objet TRAINING vise une Loop qui n'est pas de type
     * `training` (spec 12 : « Tous ses objets doivent viser une Loop
     * `type: "training"` »).
     *
     * Le Validator T1641 le refuse deja (`REFERENCE_WRONG_SCOPE`, via
     * `ManifestTrainingInvariants::assertTrainingLoop()`). Cette garde est
     * quand meme la, pour la raison donnee sur `unresolvedReference()` : un
     * applier ne suppose pas que son appelant a valide. Et elle n'est pas
     * theorique — sans elle, un Module pose sur une Boucle `general`
     * allumerait un Support de cours sur une Boucle qui n'a pas la Card pour
     * l'afficher : le contenu existerait, invisible, dans un monde qu'on
     * croirait charge.
     */
    public static function trainingOutsideTrainingLoop(string $collection, string $key, string $loopKey, string $actualType): self
    {
        return new self(sprintf(
            "Manifest training object '%s' in '%s' targets loop '%s' of type '%s'; a training loop is required and nothing more was written.",
            $key,
            $collection,
            $loopKey,
            $actualType,
        ));
    }

    /**
     * TASK-1644 — un etat individuel TRAINING designe un compte qui
     * n'appartient pas a la sandbox du chargement.
     *
     * Le registrar porte deja une garde cross-tenant, mais elle inspecte
     * l'`organization_id` de l'ENTITE inscrite. Une progression ou une remise
     * tient son `organization_id` de sa sequence/de son travail — donc correct
     * — tout en pointant par `user_id` vers un compte d'une AUTRE
     * Organization : la ligne passerait la garde du registre en emportant une
     * identite etrangere dans la sandbox. C'est le seul endroit ou ce
     * croisement est possible, donc le seul endroit ou il doit etre verifie.
     */
    public static function userOutsideSandbox(string $collection, string $key, string $userKey): self
    {
        return new self(sprintf(
            "Manifest training object '%s' in '%s' references user '%s', which does not belong to the sandbox organization; nothing more was written.",
            $key,
            $collection,
            $userKey,
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
