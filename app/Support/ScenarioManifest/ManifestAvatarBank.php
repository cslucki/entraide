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
 * `<banque>/<cle>.svg`. Cette classe sait desormais les LIRE ({@see asset()}),
 * et rien de plus. La publication sur un disque et l'ecriture de
 * `users.avatar` appartiennent au LOADER : ce namespace n'ecrit rien, ne
 * connait pas `Storage` et ne depend pas du framework — deux tests
 * d'architecture le verifient fichier par fichier.
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
