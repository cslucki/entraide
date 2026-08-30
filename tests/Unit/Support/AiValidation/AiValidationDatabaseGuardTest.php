<?php

namespace Tests\Unit\Support\AiValidation;

use App\Support\AiValidation\AiValidationDatabaseGuard;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1201 — le garde-fou n'a jamais besoin d'une vraie connexion
 * PostgreSQL : il n'inspecte que la configuration résolue. Testable en
 * SQLite `:memory:`, sans jamais approcher `bouclepro_ai_validation`.
 */
class AiValidationDatabaseGuardTest extends TestCase
{
    public function test_allows_the_exact_authorized_target(): void
    {
        $this->configureAllowedConnection();

        AiValidationDatabaseGuard::assertSafe(
            'bouclepro_ai_validation',
            '/home/dev/projects/sites/worktrees/example-worktree',
        );

        $this->assertTrue(AiValidationDatabaseGuard::isSafe('bouclepro_ai_validation'));
    }

    public function test_rejects_any_other_connection_name(): void
    {
        $this->configureAllowedConnection();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("connexion 'pgsql' refusée");

        AiValidationDatabaseGuard::assertSafe('pgsql');
    }

    public function test_rejects_bouclepro_as_database_name(): void
    {
        $this->configureAllowedConnection(['database' => 'bouclepro']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("base 'bouclepro' refusée");

        AiValidationDatabaseGuard::assertSafe('bouclepro_ai_validation');
    }

    public function test_rejects_bouclepro_test_as_database_name(): void
    {
        $this->configureAllowedConnection(['database' => 'bouclepro_test']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("base 'bouclepro_test' refusée");

        AiValidationDatabaseGuard::assertSafe('bouclepro_ai_validation');
    }

    public function test_rejects_rehearsal_database(): void
    {
        $this->configureAllowedConnection(['database' => 'entraide_rehearsal']);

        $this->expectException(RuntimeException::class);

        AiValidationDatabaseGuard::assertSafe('bouclepro_ai_validation');
    }

    public function test_rejects_a_remote_host(): void
    {
        $this->configureAllowedConnection(['host' => 'db.production.example.com']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hôte');

        AiValidationDatabaseGuard::assertSafe('bouclepro_ai_validation');
    }

    public function test_accepts_localhost_as_well_as_the_loopback_ip(): void
    {
        $this->configureAllowedConnection(['host' => 'localhost']);

        AiValidationDatabaseGuard::assertSafe('bouclepro_ai_validation');

        $this->addToAssertionCount(1);
    }

    public function test_rejects_production_environment(): void
    {
        $this->configureAllowedConnection();
        config()->set('app.env', 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('production');

        AiValidationDatabaseGuard::assertSafe('bouclepro_ai_validation');
    }

    public function test_rejects_execution_from_the_main_repository_path(): void
    {
        $this->configureAllowedConnection();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('test.laravel');

        AiValidationDatabaseGuard::assertSafe(
            'bouclepro_ai_validation',
            '/home/dev/projects/sites/test.laravel',
        );
    }

    public function test_missing_connection_configuration_is_rejected(): void
    {
        config()->set('database.connections.bouclepro_ai_validation', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('introuvable');

        AiValidationDatabaseGuard::assertSafe('bouclepro_ai_validation');
    }

    public function test_is_safe_returns_false_instead_of_throwing(): void
    {
        $this->configureAllowedConnection(['database' => 'bouclepro']);

        $this->assertFalse(AiValidationDatabaseGuard::isSafe('bouclepro_ai_validation'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function configureAllowedConnection(array $overrides = []): void
    {
        config()->set('app.env', 'local');
        config()->set('database.connections.bouclepro_ai_validation', array_merge([
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => '5432',
            'database' => 'bouclepro_ai_validation',
            'username' => 'bouclepro',
            'password' => 'unused-in-this-test',
        ], $overrides));
    }
}
