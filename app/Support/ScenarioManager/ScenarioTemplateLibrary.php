<?php

namespace App\Support\ScenarioManager;

use App\Support\ScenarioManifest\ManifestCounters;
use App\Support\ScenarioManifest\ManifestErrorBag;
use App\Support\ScenarioManifest\ManifestGraph;
use App\Support\ScenarioManifest\ManifestJsonParser;

/**
 * TASK-1656 — les modeles de scenario publies avec le produit.
 *
 * ## Une SEULE source de verite par modele
 *
 * Le Manifest d'un modele vit sous `resources/scenario-manifest/templates/`,
 * a cote de la banque d'avatars qui suit deja cette convention. Il n'en existe
 * pas de copie : le test du pilote OFSH lit CE fichier, et l'ecran « Modeles »
 * lit CE fichier. Deux JSON maintenus en parallele divergeraient, et personne
 * ne saurait lequel fait foi.
 *
 * ## Ce que cette classe ne fait PAS
 *
 * Elle ne valide pas, ne charge pas, ne cree pas de version. Elle rend un
 * CONTENU et des COMPTEURS. La creation d'un scenario a partir d'un modele
 * passe par {@see ScenarioVersionWriter::import()} — la primitive existante,
 * celle qui sert deja au collage et au fichier. Un modele n'est qu'une
 * provenance de texte de plus.
 *
 * ## Le nom affiche vient du DOCUMENT
 *
 * `name` et `description` ne sont pas redeclares ici : ils sont lus dans le
 * Manifest. Recopier un resume dans le code creerait exactement la divergence
 * que la source unique existe pour empecher — le jour ou le document changerait
 * de description, la carte continuerait d'afficher l'ancienne.
 *
 * Seul le nom COURT est declare : « OFSH » ne se derive pas de l'identifiant
 * `ofsh` sans inventer une regle de transformation.
 */
final class ScenarioTemplateLibrary
{
    private const DIRECTORY = 'resources/scenario-manifest/templates';

    /**
     * Les modeles publies, dans l'ordre d'affichage.
     *
     * @var array<string, array{file: string, short: string}>
     */
    private const TEMPLATES = [
        'ofsh' => [
            'file' => 'ofsh-1.0.0.json',
            'short' => 'OFSH',
        ],
    ];

    /**
     * Compteurs deja calcules, par clef de modele.
     *
     * Le fichier est livre avec le code : ses compteurs ne changent pas d'un
     * appel a l'autre. Les recalculer a chaque carte couterait une douzaine de
     * millisecondes pour rendre exactement le meme resultat.
     *
     * @var array<string, array<string, int>>
     */
    private static array $compteurs = [];

    /** @var array<string, array<string, mixed>> */
    private static array $entetes = [];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::TEMPLATES);
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::TEMPLATES);
    }

    /**
     * Le chemin absolu du Manifest d'un modele, ou `null` si la clef est
     * inconnue.
     *
     * La clef est verifiee contre la liste DECLAREE avant toute lecture,
     * jamais contre le systeme de fichiers : c'est ce qui empeche un nom de
     * servir a lire un chemin arbitraire.
     */
    public static function path(string $key): ?string
    {
        if (! self::has($key)) {
            return null;
        }

        return dirname(__DIR__, 3).'/'.self::DIRECTORY.'/'.self::TEMPLATES[$key]['file'];
    }

    /**
     * Le Manifest d'un modele, tel qu'il est livre.
     *
     * Rend `null` pour une clef inconnue ET pour un fichier manquant : un
     * modele declare dont l'actif a disparu n'est pas un modele utilisable, et
     * l'ecran doit pouvoir le traiter comme absent plutot que rendre une
     * chaine vide qui echouerait plus loin, sans expliquer pourquoi.
     */
    public static function json(string $key): ?string
    {
        $chemin = self::path($key);

        if ($chemin === null || ! is_file($chemin)) {
            return null;
        }

        $contenu = file_get_contents($chemin);

        return $contenu === false ? null : $contenu;
    }

    /**
     * Les compteurs d'un modele, produits par `ManifestCounters` — la MEME
     * autorite que celle de la validation.
     *
     * Le Validator complet n'est pas appele : il coute cinq fois plus cher et
     * l'ecran n'a pas besoin de son verdict, seulement de ses nombres. Mais le
     * comptage, lui, n'est pas reimplemente — un ecran qui compterait
     * autrement afficherait un jour un chiffre que la validation contredit.
     *
     * @return array<string, int>  vide si le modele est introuvable ou illisible
     */
    public static function counters(string $key): array
    {
        if (array_key_exists($key, self::$compteurs)) {
            return self::$compteurs[$key];
        }

        $racine = self::racine($key);

        return self::$compteurs[$key] = $racine === null
            ? []
            : (new ManifestCounters)->count(new ManifestGraph($racine));
    }

    /**
     * Le nom et la description affichables, LUS DANS LE DOCUMENT.
     *
     * @return array{key: string, short: string, name: string, description: string, locale: string, version: string}|null
     */
    public static function describe(string $key): ?array
    {
        if (array_key_exists($key, self::$entetes)) {
            /** @var array{key: string, short: string, name: string, description: string, locale: string, version: string}|null */
            return self::$entetes[$key] ?: null;
        }

        $racine = self::racine($key);

        if ($racine === null) {
            self::$entetes[$key] = [];

            return null;
        }

        return self::$entetes[$key] = [
            'key' => $key,
            'short' => self::TEMPLATES[$key]['short'],
            'name' => (string) ($racine->name ?? $key),
            'description' => (string) ($racine->description ?? ''),
            'locale' => (string) ($racine->locale ?? 'fr'),
            'version' => (string) ($racine->version ?? '1.0.0'),
        ];
    }

    /**
     * Tous les modeles decrits, dans l'ordre de declaration.
     *
     * Un modele dont le fichier manque est OMIS plutot que rendu a demi : la
     * section « Modeles » ne doit pas proposer un geste qui echouera.
     *
     * @return list<array{key: string, short: string, name: string, description: string, locale: string, version: string}>
     */
    public static function all(): array
    {
        $modeles = [];

        foreach (self::keys() as $cle) {
            $decrit = self::describe($cle);

            if ($decrit !== null) {
                $modeles[] = $decrit;
            }
        }

        return $modeles;
    }

    /**
     * L'arbre du Manifest d'un modele, ou `null`.
     *
     * Les erreurs de parsing sont ECARTEES ici : un modele livre avec le code
     * est cense etre bien forme, et un test le prouve. Si l'arbre ne se
     * construit pas, l'appelant recoit `null` et traite le modele comme
     * absent — c'est le seul comportement qui ne fasse pas planter la
     * bibliotheque a cause d'un actif.
     */
    private static function racine(string $key): ?\stdClass
    {
        $json = self::json($key);

        if ($json === null) {
            return null;
        }

        $racine = (new ManifestJsonParser)->parse($json, new ManifestErrorBag);

        return $racine instanceof \stdClass ? $racine : null;
    }
}
