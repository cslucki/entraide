<?php

namespace App\Support\ScenarioManifest;

/**
 * Index LOCAL des banques d'avatars fictifs (spec 13).
 *
 * Perimetre volontairement reduit : cette TASK ne livre PAS la banque
 * d'avatars — ni image, ni resolution physique, ni ecran. Elle livre le seul
 * element dont le Validator a besoin pour tenir sa promesse : la liste des
 * cles declarees, afin qu'un `avatar` inconnu rende `AVATAR_NOT_FOUND` au lieu
 * de passer et d'echouer plus tard, au Load.
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
