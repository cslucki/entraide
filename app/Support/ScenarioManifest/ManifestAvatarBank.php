<?php

namespace App\Support\ScenarioManifest;

/**
 * Index LOCAL des banques d'avatars fictifs (spec 13).
 *
 * TASK-1641 n'avait livre que l'INDEX : la liste des cles declarees, afin
 * qu'un `avatar` inconnu rende `AVATAR_NOT_FOUND` au Validator au lieu de
 * passer et d'echouer plus tard, au Load. Les assets manquaient, et un avatar
 * declare n'etait donc jamais ecrit.
 *
 * TASK-1647 livre les assets eux-memes, a cote de l'index qui les nomme :
 * `<banque>/<cle>.svg`. Cette classe sait les LIRE ({@see asset()}).
 * L'ECRITURE sur un disque et l'ecriture de `users.avatar` appartiennent au
 * LOADER : ce namespace n'ecrit rien, ne connait pas `Storage` et ne depend pas
 * du framework — deux tests d'architecture le verifient fichier par fichier.
 *
 * TASK-1654 lui ajoute la FORME du chemin publie ({@see publishedPath()},
 * {@see keyFromPublishedPath()}), et voici pourquoi cette classe et pas une
 * autre.
 *
 * ## Le defaut que cette autorite ferme
 *
 * L'asset publie est volontairement PARTAGE : son chemin ne porte aucun
 * discriminant d'Organization, si bien que deux personas — meme sandbox ou deux
 * sandboxes — designent le MEME fichier. Personne n'en repondait.
 *
 * `AdminController::updateUser()` supprimait l'ancien avatar sans condition :
 * ecrit pour l'upload individuel (`avatars/…`), ou « un fichier = une
 * personne » est vrai. Sur un persona, la meme ligne detruisait l'asset de tous
 * les autres. Et `ScenarioCaptureSerializer::clefDAvatar()` repondait a une
 * MOITIE de la question — le basename est-il une cle connue ? — sans regarder
 * le repertoire : `avatars/female-03.jpg` s'y relisait comme un asset de
 * banque.
 *
 * Deux demi-reponses, aucun proprietaire. Le defaut ne vivait dans aucun des
 * deux fichiers : chacun etait correct pour le monde qu'il connaissait.
 *
 * ## Pourquoi ICI
 *
 * Parce que le test d'architecture
 * {@see ScenarioManifestHasNoSideEffectsTest::test_the_validator_namespace_reads_only_its_own_versioned_avatar_index()}
 * designe DEJA cette classe comme la seule du namespace autorisee a lire la
 * banque sur disque. Elle sait quelle banque existe et quelles cles sont
 * valides ; il ne lui manquait que la forme du chemin. La poser ailleurs
 * aurait fabrique une quatrieme recopie de la meme regle.
 *
 * Le DISQUE, lui, reste au loader : construire une chaine ne demande ni
 * `Storage` ni le framework, et la garde d'architecture le prouve a chaque
 * execution.
 *
 * L'index est un fichier JSON versionne et auditable ; le manifeste ne connait
 * jamais un chemin physique, il ne cite qu'une cle logique (`female-03`). Le
 * controle est purement local : aucune requete reseau, conformement a la
 * spec 9.1 ("sans appeler de provider").
 */
final class ManifestAvatarBank
{
    /** Syntaxe d'une cle d'avatar, spec 7.2. */
    public const KEY_PATTERN = '/^(female|male|neutral)-[0-9]{2}$/';

    private const INDEX_DIRECTORY = 'resources/scenario-manifest/avatar-banks';

    /** @var array<string, list<string>> */
    private static array $cache = [];

    public static function has(string $bank, string $key): bool
    {
        return in_array($key, self::keys($bank), true);
    }

    /**
     * Le contenu de l'asset d'une cle, ou `null` si la cle n'est pas de la
     * banque ou si son fichier manque.
     *
     * La cle est verifiee contre l'INDEX avant toute lecture, jamais contre
     * le systeme de fichiers : c'est ce qui empeche un nom de servir a lire
     * un chemin arbitraire. `has()` filtre la cle, `keys()` filtre deja le nom
     * de banque, et le format de cle est fige par {@see KEY_PATTERN}.
     *
     * Le loader appelle ceci pour obtenir un CONTENU ; il derive lui-meme le
     * chemin physique de destination. Le manifeste, lui, ne connait qu'une
     * cle logique (spec 13).
     */
    public static function asset(string $bank, string $key): ?string
    {
        if (! self::has($bank, $key)) {
            return null;
        }

        $path = dirname(__DIR__, 3).'/'.self::INDEX_DIRECTORY.'/'.$bank.'/'.$key.'.svg';

        if (! is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);

        return is_string($content) && $content !== '' ? $content : null;
    }

    /** Type de media des assets de banque, pour l'entete de stockage. */
    public const ASSET_MEDIA_TYPE = 'image/svg+xml';

    /** Extension des assets de banque. */
    public const ASSET_EXTENSION = 'svg';

    /**
     * Emplacement d'APPLICATION des assets de banque, partage par toutes les
     * sandboxes — jamais un chemin par sandbox.
     *
     * Ce partage n'est pas une commodite : un persona de demonstration porte
     * une identite fictive stable, et republier le meme SVG par sandbox serait
     * une ecriture inutile sur un stockage distant. Le prix de ce choix est
     * exactement le defaut que {@see keyFromPublishedPath()} ferme.
     */
    public const PUBLISHED_DIRECTORY = 'scenario-avatars';

    /**
     * Le chemin CANONIQUE ou un asset de banque est publie, ou `null` si le
     * couple (banque, cle) n'est pas de la banque.
     *
     * Fail-closed volontaire : une cle inconnue ne rend pas un chemin
     * « probable », elle ne rend rien. C'est ce qui empeche un nom recu de
     * l'exterieur de fabriquer une destination d'ecriture.
     */
    public static function publishedPath(string $bank, string $key): ?string
    {
        if (! self::has($bank, $key)) {
            return null;
        }

        return self::PUBLISHED_DIRECTORY.'/'.$bank.'/'.$key.'.'.self::ASSET_EXTENSION;
    }

    /**
     * Ce chemin `users.avatar` designe-t-il REELLEMENT un asset immuable de
     * banque ?
     *
     * LA question, a un seul endroit. Tout geste destructeur sur un fichier
     * d'avatar doit la poser ici avant d'agir.
     */
    public static function isPublishedAsset(string $path): bool
    {
        return self::keyFromPublishedPath($path) !== null;
    }

    /**
     * La cle logique d'un chemin publie, ou `null` si ce chemin n'est pas un
     * asset de banque.
     *
     * ## Pourquoi la preuve porte sur le CHEMIN ENTIER
     *
     * Une ressemblance de nom ne dit rien de la provenance. `female-03` est une
     * cle valide de la banque ; `avatars/female-03.svg` n'en est pas pour
     * autant un asset de banque — c'est l'upload individuel d'une personne qui
     * se trouve porter ce nom. Conclure l'inverse, c'est soit refuser de
     * supprimer un fichier qu'il fallait supprimer (fuite de stockage), soit
     * declarer dans un manifeste un avatar de banque qui n'existe pas.
     *
     * ## La forme exigee, et comment elle est prouvee
     *
     * EXACTEMENT trois segments : `<repertoire>/<banque>/<cle>.svg`.
     *
     * Le comptage des segments est ce qui refuse d'un coup le segment en trop,
     * le prefixe absolu, le double separateur et la traversee (`../`) : aucune
     * de ces formes ne compte trois segments, et aucune n'a donc besoin d'etre
     * enumeree ici.
     *
     * Puis, ceinture : le chemin canonique est RECONSTRUIT depuis le couple
     * (banque, cle) reconnu, et l'egalite exacte est exigee. Une forme qui
     * aurait franchi les controles ci-dessus sans etre la forme canonique
     * echoue la. La preuve ne repose donc pas sur ma lecture exhaustive des
     * formes hostiles — elle repose sur la seule forme LEGITIME.
     */
    public static function keyFromPublishedPath(string $path): ?string
    {
        $segments = explode('/', $path);

        if (count($segments) !== 3) {
            return null;
        }

        [$directory, $bank, $file] = $segments;

        if ($directory !== self::PUBLISHED_DIRECTORY) {
            return null;
        }

        $suffix = '.'.self::ASSET_EXTENSION;

        if (! str_ends_with($file, $suffix)) {
            return null;
        }

        $key = substr($file, 0, -strlen($suffix));

        // `has()` tranche les DEUX inconnues d'un coup : `keys()` rend une
        // liste vide pour une banque dont le nom n'est pas celui d'une banque,
        // donc une banque inconnue ne peut pas porter de cle connue.
        if (! self::has($bank, $key)) {
            return null;
        }

        return self::publishedPath($bank, $key) === $path ? $key : null;
    }

    /**
     * @return list<string>
     */
    public static function keys(string $bank): array
    {
        if (array_key_exists($bank, self::$cache)) {
            return self::$cache[$bank];
        }

        // La banque est nommee par une valeur du schema (`assets.avatar_bank`,
        // constante exacte en V1), jamais par une chaine libre : ce nom ne peut
        // donc pas servir a lire un fichier arbitraire. La garde ci-dessous
        // fige cette propriete plutot que de compter dessus.
        if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $bank) !== 1) {
            return self::$cache[$bank] = [];
        }

        $path = dirname(__DIR__, 3).'/'.self::INDEX_DIRECTORY.'/'.$bank.'.json';

        if (! is_file($path)) {
            return self::$cache[$bank] = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $keys = is_array($decoded) && isset($decoded['keys']) && is_array($decoded['keys'])
            ? array_values(array_filter($decoded['keys'], is_string(...)))
            : [];

        return self::$cache[$bank] = $keys;
    }
}
