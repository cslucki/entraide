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
    /**
     * Un chemin de worktree de validation, sur lequel le garde ne s'oppose pas.
     *
     * TASK-1369 — ces tests EMPRUNTAIENT le chemin de la machine.
     *
     * `isSafe()` n'accepte pas de `$basePathOverride` (contrairement a
     * `assertSafe()`), donc il retombait sur `base_path()`, c'est-a-dire le
     * depot canonique `/home/cyril/claude-code/sites/test.laravel` — que le
     * garde T1201 refuse DELIBEREMENT. Le test etait donc rouge en permanence
     * sur la machine de reference, et ce rouge n'a jamais rien dit de
     * l'application : il disait seulement d'ou la suite etait lancee.
     *
     * Le garde n'est pas en cause et n'est pas modifie. C'est au test de
     * CONSTRUIRE le monde qu'il pretend eprouver, au lieu de supposer que la
     * machine qui l'execute est un worktree de validation.
     */
    private const SAFE_BASE_PATH = '/home/dev/projects/sites/worktrees/example-worktree';

    private ?string $originalBasePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalBasePath = $this->app->basePath();
        $this->app->setBasePath(self::SAFE_BASE_PATH);
    }

    protected function tearDown(): void
    {
        if ($this->originalBasePath !== null) {
            $this->app->setBasePath($this->originalBasePath);
            $this->originalBasePath = null;
        }

        parent::tearDown();
    }

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

    /**
     * TASK-1369 — le critere de chemin est une SOUS-CHAINE, et cela se voit.
     *
     * Consequence non evidente, mesuree le 2026-09-02 : un repertoire VOISIN du
     * depot canonique — `.../sites/test.laravel-baseline`, un worktree de
     * comparaison — contient le fragment interdit, donc il est refuse lui
     * aussi. C'est le comportement VOULU (mieux vaut refuser un voisin que
     * laisser passer le depot principal), mais il n'etait ecrit nulle part, et
     * c'est ce qui a rendu une baseline de comparaison invalide sans que rien
     * ne le signale.
     *
     * Les chemins sont DERIVES de la constante du garde. Les ecrire en dur
     * reviendrait a supposer la machine de leur auteur — la faute meme que
     * cette TASK repare.
     */
    public function test_the_forbidden_path_criterion_is_a_substring_match(): void
    {
        $this->configureAllowedConnection();

        $fragment = AiValidationDatabaseGuard::FORBIDDEN_PATH_FRAGMENT;

        // Un VOISIN du depot canonique porte le fragment : refuse.
        try {
            AiValidationDatabaseGuard::assertSafe('bouclepro_ai_validation', '/home/dev'.$fragment.'-baseline');
            $this->fail('Un chemin contenant le fragment interdit doit etre refuse.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('test.laravel', $exception->getMessage());
        }

        // Un chemin qui ne le porte pas passe, meme s'il parle de Laravel.
        AiValidationDatabaseGuard::assertSafe('bouclepro_ai_validation', '/srv/deploy/laravel-app');

        $this->addToAssertionCount(1);
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
