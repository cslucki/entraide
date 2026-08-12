<?php

namespace App\Console\Commands;

use App\Support\AiValidation\AiValidationDatabaseGuard;
use Database\Seeders\AiValidationDatasetSeeder;
use Illuminate\Console\Command;

/**
 * TASK-1201 — seule commande autorisée à réinitialiser
 * `bouclepro_ai_validation`. Écrite mais volontairement JAMAIS exécutée
 * dans cette TASK (verrou DB : aucun `migrate:fresh` contre PostgreSQL réel
 * sans GO explicite de Cyril).
 *
 * `AiValidationDatabaseGuard::assertSafe()` est appelé avec le nom de
 * connexion EXPLICITE (`bouclepro_ai_validation`), pas la connexion par
 * défaut : `migrate:fresh --database=...` cible cette connexion nommée
 * indépendamment de `config('database.default')`, la garde doit donc
 * vérifier la même cible que celle réellement utilisée par les appels
 * `migrate:fresh`/`db:seed` ci-dessous.
 */
class AiValidationResetCommand extends Command
{
    protected $signature = 'ai-validation:reset {--yes : Ignore la confirmation interactive}';

    protected $description = 'Réinitialise et ensemence bouclepro_ai_validation avec le dataset TASK-1201 (jamais bouclepro/bouclepro_test)';

    public function handle(): int
    {
        AiValidationDatabaseGuard::assertSafe(AiValidationDatabaseGuard::ALLOWED_CONNECTION);

        $config = config('database.connections.'.AiValidationDatabaseGuard::ALLOWED_CONNECTION);

        $this->warn('Cette commande DÉTRUIT puis recrée toutes les tables de la connexion "'.AiValidationDatabaseGuard::ALLOWED_CONNECTION.'".');
        $this->line('Base ciblée : '.($config['database'] ?? '?'));
        $this->line('Hôte        : '.($config['host'] ?? '?'));

        if (! $this->option('yes') && ! $this->confirm('Confirmer la réinitialisation de '.AiValidationDatabaseGuard::ALLOWED_DATABASE.' ?')) {
            $this->info('Annulé.');

            return self::SUCCESS;
        }

        $this->call('migrate:fresh', [
            '--database' => AiValidationDatabaseGuard::ALLOWED_CONNECTION,
            '--force' => true,
        ]);

        $this->call('db:seed', [
            '--class' => AiValidationDatasetSeeder::class,
            '--database' => AiValidationDatabaseGuard::ALLOWED_CONNECTION,
            '--force' => true,
        ]);

        $this->info(AiValidationDatabaseGuard::ALLOWED_DATABASE.' réinitialisée et ensemencée.');

        return self::SUCCESS;
    }
}
