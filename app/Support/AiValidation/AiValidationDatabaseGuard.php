<?php

namespace App\Support\AiValidation;

use RuntimeException;

/**
 * Garde-fou strict pour l'environnement de validation IA (TASK-1201).
 *
 * Allowlist, pas blacklist : refuse tout ce qui n'est pas EXACTEMENT la
 * cible autorisée. Aucun mode --force, aucun contournement. Toute commande
 * pouvant réinitialiser ou ensemencer `bouclepro_ai_validation` doit appeler
 * `assertSafe()` avant la moindre opération destructive.
 */
final class AiValidationDatabaseGuard
{
    public const ALLOWED_CONNECTION = 'bouclepro_ai_validation';

    public const ALLOWED_DATABASE = 'bouclepro_ai_validation';

    /** @var list<string> */
    public const ALLOWED_HOSTS = ['127.0.0.1', 'localhost'];

    public const FORBIDDEN_PATH_FRAGMENT = '/sites/test.laravel';

    private function __construct() {}

    /**
     * Lève une exception si la cible n'est pas exactement autorisée.
     * Ne renvoie jamais un simple booléen d'échec : soit la garde passe,
     * soit elle bloque immédiatement.
     */
    public static function assertSafe(?string $connectionName = null, ?string $basePathOverride = null): void
    {
        $connectionName ??= config('database.default');

        if ($connectionName !== self::ALLOWED_CONNECTION) {
            throw new RuntimeException(
                "AiValidationDatabaseGuard: connexion '{$connectionName}' refusée. ".
                'Seule la connexion "'.self::ALLOWED_CONNECTION.'" est autorisée.'
            );
        }

        $config = config("database.connections.{$connectionName}");

        if (! is_array($config)) {
            throw new RuntimeException(
                "AiValidationDatabaseGuard: connexion '{$connectionName}' introuvable dans config/database.php."
            );
        }

        $database = $config['database'] ?? null;

        if ($database !== self::ALLOWED_DATABASE) {
            throw new RuntimeException(
                "AiValidationDatabaseGuard: base '{$database}' refusée. ".
                'Seule "'.self::ALLOWED_DATABASE.'" est autorisée. Jamais bouclepro, '.
                'bouclepro_test, ni aucune base rehearsal/mock/prod.'
            );
        }

        $host = $config['host'] ?? null;

        if (! in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new RuntimeException(
                "AiValidationDatabaseGuard: hôte '{$host}' refusé. ".
                'Seuls '.implode(', ', self::ALLOWED_HOSTS).' sont autorisés (local uniquement).'
            );
        }

        if (config('app.env') === 'production') {
            throw new RuntimeException(
                'AiValidationDatabaseGuard: APP_ENV=production refusé absolument.'
            );
        }

        $basePath = $basePathOverride ?? base_path();

        if (str_contains($basePath, self::FORBIDDEN_PATH_FRAGMENT)) {
            throw new RuntimeException(
                "AiValidationDatabaseGuard: base_path() '{$basePath}' pointe vers test.laravel. ".
                "Cette commande ne doit jamais s'exécuter depuis le dépôt principal."
            );
        }
    }

    /**
     * Variante booléenne, pour un contexte qui veut décider plutôt
     * qu'échouer bruyamment (ex : un endpoint de diagnostic).
     */
    public static function isSafe(?string $connectionName = null): bool
    {
        try {
            self::assertSafe($connectionName);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
