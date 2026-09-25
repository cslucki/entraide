<?php

namespace App\Support\ScenarioManifest;

/**
 * Ce que la traversee du schema a RECOLTE, pour que les phases suivantes
 * n'aient pas a reparcourir l'arbre avec une seconde copie du catalogue.
 *
 * Deux copies du catalogue divergeraient : la phase des references finirait par
 * ne plus viser les memes champs que la phase de forme, et un champ ajoute plus
 * tard serait valide dans sa forme sans jamais voir sa reference resolue. Une
 * seule traversee, un seul catalogue.
 */
final class ManifestShapeScan
{
    /** @var list<array{path: string, collection: string, value: string}> */
    public array $references = [];

    /** @var list<array{path: string, content: string, format: string}> */
    public array $contents = [];

    /** @var list<array{path: string, key: string}> */
    public array $avatars = [];

    /**
     * Chemins des champs dont la forme est invalide. Les phases relationnelles
     * s'en servent pour ne pas empiler une seconde erreur sur une valeur dont
     * elles savent deja qu'elle n'a pas le type attendu (spec 9.1 : "toutes les
     * erreurs qu'il peut etablir sans dependre d'une phase invalide").
     *
     * @var array<string, true>
     */
    public array $malformed = [];

    public function markMalformed(string $path): void
    {
        $this->malformed[$path] = true;
    }

    public function isMalformed(string $path): bool
    {
        return array_key_exists($path, $this->malformed);
    }
}
