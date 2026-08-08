<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * La CI GitHub doit pouvoir devenir rouge sur une regression Feature.
 *
 * Avant cette tache, le « Quality Gate (blocking) » lançait
 * `phpunit.ci-minimal.xml`, dont l'unique testsuite est `tests/Unit` :
 * **aucun test Feature n'a jamais tourne dans un gate bloquant**.
 *
 * ## Ce que la premiere version de ce fichier ne gardait pas
 *
 * Elle cherchait la chaine `phpunit.ci-feature.xml` **dans le texte** du
 * workflow. Or cette chaine figure aussi dans un commentaire ecrit par la meme
 * tache : **supprimer l'etape laissait les sept tests verts**. La garde suivait
 * le commentaire, pas l'etape.
 *
 * Elle ne verifiait pas non plus que l'etape est bloquante — un
 * `continue-on-error: true` la desactivait sans qu'aucun test ne bronche. Ce
 * n'est pas theorique : un ancien job `legacy-feature-tests` du depot le
 * portait.
 *
 * Ces tests lisent donc le **YAML analyse**, pas le texte, et mesurent ce que
 * la configuration **selectionne reellement**.
 */
class TASK1112CiCoverageTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/ci-postgresql.yml';

    /** @return array<string, mixed> */
    private function workflow(): array
    {
        return Yaml::parseFile(base_path(self::WORKFLOW));
    }

    /** Les etapes du seul job bloquant. */
    private function etapes(): array
    {
        $jobs = $this->workflow()['jobs'] ?? [];

        $this->assertArrayHasKey('quality-gate', $jobs, 'le job bloquant a disparu');

        return $jobs['quality-gate']['steps'] ?? [];
    }

    /** L'etape qui lance une configuration donnee, ou null. */
    private function etapeQuiLance(string $config): ?array
    {
        foreach ($this->etapes() as $etape) {
            if (str_contains($etape['run'] ?? '', $config)) {
                return $etape;
            }
        }

        return null;
    }

    /** Combien de tests une configuration selectionne reellement. */
    private function testsSelectionnes(string $config): int
    {
        $sortie = [];
        exec(
            'cd '.escapeshellarg(base_path()).' && DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test '
            .'php vendor/bin/phpunit --configuration '.escapeshellarg($config).' --list-tests 2>/dev/null',
            $sortie,
        );

        return count(array_filter($sortie, fn (string $l) => str_starts_with($l, ' - ')));
    }

    // ── Le gate lance bien les deux moities ─────────────────────────────────

    public function test_the_blocking_job_runs_the_feature_suite(): void
    {
        // **Lu dans le YAML analyse**, et non cherche dans le texte : la
        // premiere version de ce test passait sur un commentaire.
        $this->assertNotNull(
            $this->etapeQuiLance('phpunit.ci-feature.xml'),
            'aucune etape ne lance la suite Feature',
        );
    }

    public function test_the_blocking_job_still_runs_the_unit_suite(): void
    {
        $this->assertNotNull($this->etapeQuiLance('phpunit.ci-minimal.xml'));
    }

    public function test_both_test_steps_are_actually_blocking(): void
    {
        // Un `continue-on-error: true` desactive une etape sans la retirer.
        // Le depot a deja porte un job `legacy-feature-tests` ainsi neutralise.
        foreach (['phpunit.ci-minimal.xml', 'phpunit.ci-feature.xml'] as $config) {
            $etape = $this->etapeQuiLance($config);

            $this->assertNotNull($etape, $config);
            $this->assertArrayNotHasKey('continue-on-error', $etape, $config.' n’est plus bloquante');
            $this->assertArrayNotHasKey('if', $etape, $config.' est conditionnee');
        }
    }

    public function test_the_feature_step_runs_after_the_migrations(): void
    {
        $etapes = array_values($this->etapes());

        $indice = fn (string $aiguille) => collect($etapes)
            ->search(fn (array $e) => str_contains($e['run'] ?? '', $aiguille));

        $this->assertLessThan(
            $indice('phpunit.ci-feature.xml'),
            $indice('artisan migrate'),
            'la suite Feature tourne avant les migrations',
        );
    }

    // ── Ce que le gate couvre reellement ────────────────────────────────────

    public function test_the_gate_selects_the_whole_feature_suite_minus_the_known_red(): void
    {
        // **La garde qui compte.** Elle mesure ce que la configuration
        // selectionne, et non ce que le fichier a l'air de dire.
        //
        // La premiere version comptait des balises `<exclude>` : remplacer une
        // exclusion de fichier par une exclusion de **repertoire** faisait
        // perdre 385 tests sans qu'aucun test ne bronche.
        //
        // Ce nombre monte quand la suite grandit — c'est normal, mettez-le a
        // jour. S'il **descend**, c'est qu'on a retire quelque chose du gate,
        // et il faut dire quoi.
        $this->assertGreaterThanOrEqual(
            3880,
            $this->testsSelectionnes('phpunit.ci-feature.xml'),
            'le gate couvre moins de tests qu’avant : qu’a-t-on retire ?',
        );
    }

    public function test_the_known_red_group_does_not_grow(): void
    {
        // Le groupe est un **constat**, pas un endroit ou ranger un test qui
        // gene. Il doit se vider ; ce test echouera alors, et c'est voulu — il
        // rappelle de baisser le nombre et de dire ce qui a ete repare.
        $annotees = 0;

        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('tests/Feature')),
        );

        foreach ($iterateur as $fichier) {
            // Ce fichier-ci nomme le groupe dans ses commentaires : le
            // compter reviendrait a se compter soi-meme.
            if ($fichier->isFile()
                && str_ends_with($fichier->getFilename(), '.php')
                && $fichier->getFilename() !== 'TASK1112CiCoverageTest.php') {
                $annotees += substr_count(file_get_contents($fichier->getPathname()), '@group ci-known-red');
            }
        }

        $this->assertSame(
            23,
            $annotees,
            'le groupe des tests deja rouges a change : s’il a grandi, expliquez-vous ; s’il a maigri, baissez ce nombre',
        );
    }

    public function test_the_feature_config_excludes_by_group_and_not_by_file(): void
    {
        // Exclure douze classes entieres sacrifiait **248 tests pour en taire
        // 23**. Le depot exclut deja par groupe dans `phpunit.pgsql.xml`.
        $config = file_get_contents(base_path('phpunit.ci-feature.xml'));

        $this->assertStringContainsString('<group>ci-known-red</group>', $config);
        $this->assertStringNotContainsString('<exclude>tests/Feature', $config);
    }

    // ── Les garde-fous ne sont pas affaiblis ────────────────────────────────

    public function test_the_ci_never_targets_the_development_database(): void
    {
        $env = $this->workflow()['jobs']['quality-gate']['env'] ?? [];

        $this->assertSame('bouclepro_test', $env['DB_DATABASE'] ?? null);

        $service = $this->workflow()['jobs']['quality-gate']['services']['postgres']['env'] ?? [];
        $this->assertSame('bouclepro_test', $service['POSTGRES_DB'] ?? null);

        foreach (['phpunit.ci-feature.xml', 'phpunit.ci-minimal.xml'] as $config) {
            $this->assertStringContainsString(
                'DB_DATABASE" value="bouclepro_test"',
                file_get_contents(base_path($config)),
                $config,
            );
        }
    }

    public function test_the_ci_never_runs_a_destructive_migration(): void
    {
        $texte = file_get_contents(base_path(self::WORKFLOW));

        foreach (['migrate:fresh', 'migrate:refresh', 'db:wipe', 'pg-validate'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $texte, $interdit);
        }
    }

    public function test_the_job_cannot_hang_a_runner_for_six_hours(): void
    {
        // Sans `timeout-minutes`, le defaut de GitHub est 360 minutes. Le job
        // est passe de ~70 s a ~20 min : un test qui pend retiendrait un runner
        // six heures.
        $this->assertArrayHasKey('timeout-minutes', $this->workflow()['jobs']['quality-gate']);
    }
}
