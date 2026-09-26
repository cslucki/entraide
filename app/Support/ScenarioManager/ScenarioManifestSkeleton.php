<?php

namespace App\Support\ScenarioManager;

use App\Support\ScenarioManifest\ManifestSchema;

/**
 * TASK-1649 — le squelette d'un scenario vide (CDC 9.1).
 *
 * « Creer un Manifest squelette avec toutes les collections V1 attendues.
 * Aucune donnee metier creee. »
 *
 * ## La STRUCTURE est derivee, les VALEURS sont explicites
 *
 * Recopier a la main la liste des vingt collections serait la garantie de
 * l'oublier a la prochaine evolution du schema : le squelette d'un scenario
 * neuf perdrait une famille sans que rien ne proteste, et l'auteur se
 * demanderait pourquoi « polls » n'existe pas dans son document.
 *
 * La structure vient donc de {@see ManifestSchema::envelope()} :
 *
 * - `const` -> la valeur imposee par le schema ;
 * - `array` -> une collection VIDE ;
 * - `object` -> on descend dedans.
 *
 * Les valeurs scalaires, elles, ne se devinent pas : un `purpose` doit vouloir
 * dire quelque chose, un `proposed_slug` doit etre un slug. Elles sont donc
 * declarees ici, une par une, et **une cle scalaire ajoutee au schema sans
 * valeur ici fait ECHOUER la construction** au lieu de produire un document
 * silencieusement incomplet.
 */
final class ScenarioManifestSkeleton
{
    /**
     * Construit le document d'un scenario vide.
     *
     * @return array<string, mixed>
     */
    public static function build(string $scenarioKey, string $name, string $locale = 'fr'): array
    {
        $graines = self::graines($scenarioKey, $name, $locale);

        return self::depuisSchema(ManifestSchema::envelope(), $graines, '');
    }

    /**
     * Le document, rendu tel qu'il sera stocke : indente, lisible, et
     * immediatement editable a la main.
     */
    public static function json(string $scenarioKey, string $name, string $locale = 'fr'): string
    {
        return json_encode(
            self::build($scenarioKey, $name, $locale),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $noeuds
     * @param  array<string, mixed>  $graines
     * @return array<string, mixed>
     */
    private static function depuisSchema(array $noeuds, array $graines, string $prefixe): array
    {
        $document = [];

        foreach ($noeuds as $cle => $declaration) {
            $chemin = $prefixe === '' ? $cle : $prefixe.'.'.$cle;

            $document[$cle] = match ($declaration['type'] ?? null) {
                'const' => $declaration['value'],
                'array' => [],
                'object' => self::depuisSchema($declaration['fields'] ?? [], $graines, $chemin),
                default => self::graine($graines, $chemin),
            };
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $graines
     */
    private static function graine(array $graines, string $chemin): mixed
    {
        if (! array_key_exists($chemin, $graines)) {
            // Bruyant, et c'est voulu. Un squelette auquel il manque une cle
            // scalaire est un document que le Validator refusera, et son
            // auteur n'aurait aucun moyen de savoir ce qu'il devait y mettre.
            throw new \LogicException(sprintf(
                'Le squelette de scenario n a pas de valeur de depart pour « %s ». '
                .'Une cle scalaire a ete ajoutee a ManifestSchema sans etre declaree ici.',
                $chemin
            ));
        }

        return $graines[$chemin];
    }

    /**
     * @return array<string, mixed>
     */
    private static function graines(string $scenarioKey, string $name, string $locale): array
    {
        return [
            'id' => $scenarioKey,
            // CDC 9.4 fixe `1.0.0` pour une duplication ; un scenario neuf
            // part du meme point, sans quoi « nouveau » et « duplique »
            // n'auraient pas la meme origine.
            'version' => '1.0.0',
            'name' => $name,
            'description' => 'Decrivez ici le monde que ce scenario represente.',
            'purpose' => 'Decrivez ici ce que ce scenario sert a demontrer ou a tester.',
            'locale' => $locale,
            'organization.name' => $name,
            'organization.proposed_slug' => $scenarioKey,
            'organization.description' => 'Decrivez ici l organisation fictive que ce scenario fait naitre.',
            'organization.locale' => $locale,
        ];
    }
}
