<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
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
    private function nombreDeTestsSelectionnes(string $config): int
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
        //
        // TASK-1150 (GO MASTER) : une seule forme de `if:` est toleree ici,
        // et aucune autre. `steps.level.outputs.mode == 'full'` est calculee
        // par l'etape "Determine validation level", qui resout `full` par
        // defaut et ne bascule en `light` que si la PR porte le label
        // `validation:micro-ux` ou `validation:no-app-ci` — jamais par
        // defaut, jamais silencieusement (voir
        // test_the_ci_mode_resolution_is_fail_safe, qui EXECUTE ce calcul
        // plutot que de le lire). Toute autre condition doit continuer a
        // faire echouer ce test.
        $conditionAutorisee = "steps.level.outputs.mode == 'full'";

        foreach (['phpunit.ci-minimal.xml', 'phpunit.ci-feature.xml'] as $config) {
            $etape = $this->etapeQuiLance($config);

            $this->assertNotNull($etape, $config);
            $this->assertArrayNotHasKey('continue-on-error', $etape, $config.' n’est plus bloquante');

            $condition = $etape['if'] ?? null;
            $this->assertTrue(
                $condition === null || $condition === $conditionAutorisee,
                $config.' porte une condition non autorisee : '.var_export($condition, true),
            );
        }
    }

    // ── TASK-1150 : la resolution du mode CI est fail-safe ──────────────────
    //
    // Les tests ci-dessus prouvent qu'une seule EXPRESSION est toleree sur les
    // steps historiques. Ceux-ci prouvent que cette expression, une fois
    // EXECUTEE (pas seulement lue comme texte), se resout toujours vers
    // `full` sauf dans les deux cas explicitement voulus. Meme idiome que
    // `nombreDeTestsSelectionnes()` plus haut : mesurer ce qui se passe
    // reellement, pas ce que le fichier a l'air de dire.

    /** @return array<string, array{0: string, 1: string}> Les deux gates requis. */
    private static function gatesRequis(): array
    {
        return [
            'SQLite Regression Gate (blocking)' => ['.github/workflows/ci-sqlite.yml', 'sqlite-regression-gate'],
            'Quality Gate (blocking)' => [self::WORKFLOW, 'quality-gate'],
        ];
    }

    private function etapeNommee(string $chemin, string $job, string $nom): ?array
    {
        $jobs = Yaml::parseFile(base_path($chemin))['jobs'] ?? [];

        foreach ($jobs[$job]['steps'] ?? [] as $etape) {
            if (($etape['name'] ?? null) === $nom) {
                return $etape;
            }
        }

        return null;
    }

    /**
     * Substitue les deux seules expressions GitHub Actions que le script
     * utilise, puis EXECUTE reellement le script bash extrait du YAML — la
     * seule facon de prouver un comportement plutot qu'une apparence.
     */
    private function resoudreLeMode(string $chemin, string $job, string $event, array $labels): string
    {
        $etape = $this->etapeNommee($chemin, $job, 'Determine validation level');
        $this->assertNotNull($etape, "etape 'Determine validation level' absente de {$job} ({$chemin})");

        $script = $etape['run'] ?? '';

        $script = str_replace('${{ github.event_name }}', $event, $script);
        $script = str_replace(
            '${{ toJson(github.event.pull_request.labels.*.name) }}',
            json_encode($labels),
            $script,
        );

        $this->assertStringNotContainsString(
            '${{',
            $script,
            'expression GitHub non substituee : le test ne prouverait rien',
        );

        $sortie = tempnam(sys_get_temp_dir(), 'gh-output-');
        $scriptPath = tempnam(sys_get_temp_dir(), 'ci-mode-');
        file_put_contents($scriptPath, $script);

        exec(sprintf(
            'GITHUB_OUTPUT=%s bash %s 2>&1',
            escapeshellarg($sortie),
            escapeshellarg($scriptPath),
        ), $out, $code);

        unlink($scriptPath);

        $this->assertSame(0, $code, "le script a echoue : ".implode("\n", $out));

        $contenu = file_get_contents($sortie) ?: '';
        unlink($sortie);

        $this->assertMatchesRegularExpression(
            '/^mode=(full|light)$/m',
            $contenu,
            "sortie GITHUB_OUTPUT inattendue : {$contenu}",
        );

        preg_match('/^mode=(full|light)$/m', $contenu, $m);

        return $m[1];
    }

    /** @return array<string, array{0: string, 1: array<int, string>, 2: string}> */
    public static function scenariosDeResolution(): array
    {
        return [
            'pull_request sans label -> full (defaut fail-safe)' => ['pull_request', [], 'full'],
            'pull_request label micro-ux -> light' => ['pull_request', ['validation:micro-ux'], 'light'],
            'pull_request label no-app-ci -> light' => ['pull_request', ['validation:no-app-ci'], 'light'],
            'pull_request label standard -> full' => ['pull_request', ['validation:standard'], 'full'],
            'pull_request label sensitive -> full' => ['pull_request', ['validation:sensitive'], 'full'],
            'pull_request labels multiples dont micro-ux -> light' => ['pull_request', ['bug', 'validation:micro-ux'], 'light'],
            'push develop/main -> full, meme avec un label residuel' => ['push', ['validation:micro-ux'], 'full'],
            'workflow_dispatch -> full' => ['workflow_dispatch', [], 'full'],
        ];
    }

    #[DataProvider('scenariosDeResolution')]
    public function test_the_ci_mode_resolution_is_fail_safe(string $event, array $labels, string $attendu): void
    {
        foreach (self::gatesRequis() as $nomJob => [$chemin, $job]) {
            $this->assertSame(
                $attendu,
                $this->resoudreLeMode($chemin, $job, $event, $labels),
                "{$nomJob} : event={$event} labels=".json_encode($labels),
            );
        }
    }

    public function test_the_required_jobs_are_never_conditional(): void
    {
        // Le risque documente par MASTER (TASK-1148, workflow_dispatch) :
        // un job entierement skippe peut laisser un required check `Pending`
        // indefiniment. Seule la charge INTERNE d'un job peut varier — jamais
        // son existence.
        foreach (self::gatesRequis() as $nomJob => [$chemin, $job]) {
            $jobs = Yaml::parseFile(base_path($chemin))['jobs'] ?? [];

            $this->assertArrayHasKey($job, $jobs, "{$nomJob} a disparu de {$chemin}");
            $this->assertArrayNotHasKey(
                'if',
                $jobs[$job],
                "{$nomJob} porte une condition au niveau JOB — required check jamais garanti",
            );
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
        // Mesure une fois l'exclusion par groupe **reellement active** : la
        // premiere valeur, 3880, avait ete relevee alors que les annotations
        // `@group` etaient inertes et que rien n'etait exclu.
        //
        // Ce nombre monte quand la suite grandit — c'est normal, mettez-le a
        // jour. S'il **descend**, c'est qu'on a retire quelque chose du gate,
        // et il faut dire quoi.
        $this->assertGreaterThanOrEqual(
            3870,
            $this->nombreDeTestsSelectionnes('phpunit.ci-feature.xml'),
            'le gate couvre moins de tests qu’avant : qu’a-t-on retire ?',
        );
    }

    public function test_the_known_red_group_does_not_grow(): void
    {
        // Le groupe est un **constat**, pas un endroit ou ranger un test qui
        // gene. Il doit se vider ; ce test echouera alors, et c'est voulu — il
        // rappelle de baisser le nombre et de dire ce qui a ete repare.
        //
        // **23 -> 22** : `UserDataLifecycleRegistryTest` est repare par
        // TASK-1114, qui a classe les trente cles etrangeres manquantes.
        //
        // **22 -> 21** : `T347OrganizationScopedAuthTest::test_admin_users_can_sort_by_name`
        // est repare par TASK-1147. Il n'etait pas rouge par defaut produit
        // mais indeterministe : l'application trie par `first_name` puis
        // `name`, et le test comparait l'ordre rendu a un `sort()` PHP sur
        // `name` seul, avec des prenoms tires par Faker. Il verifie desormais
        // l'ordre relatif de deux utilisateurs entierement maitrises.
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
                // **L'attribut, pas l'annotation.** PHPUnit 12 a retire le
                // support des metadonnees en commentaire : mes `@group` etaient
                // inertes, et les douze classes echouaient quand meme dans le
                // premier passage reel de la CI. Compter la chaine en
                // commentaire aurait laisse croire le contraire.
                $annotees += substr_count(file_get_contents($fichier->getPathname()), "Group('ci-known-red')");
            }
        }

        $this->assertSame(
            21,
            $annotees,
            'le groupe des tests deja rouges a change : s’il a grandi, expliquez-vous ; s’il a maigri, baissez ce nombre',
        );
    }

    public function test_the_group_is_declared_with_an_attribute_not_an_annotation(): void
    {
        // PHPUnit 12 a **retire** le support des metadonnees en commentaire :
        // un `@group` y est inerte. Le premier passage reel de la CI l'a
        // montre — les douze classes « exclues » echouaient toutes.
        $restantes = 0;

        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('tests/Feature')),
        );

        foreach ($iterateur as $fichier) {
            if ($fichier->isFile()
                && str_ends_with($fichier->getFilename(), '.php')
                && $fichier->getFilename() !== 'TASK1112CiCoverageTest.php') {
                $restantes += substr_count(file_get_contents($fichier->getPathname()), '@group ci-known-red');
            }
        }

        $this->assertSame(0, $restantes, 'des annotations `@group` inertes subsistent');
    }

    public function test_the_locale_is_pinned_so_both_environments_agree(): void
    {
        // Le premier passage reel a rendu 42 echecs disant « Manifesto » la ou
        // le test attendait « Manifeste » : la CI n'a pas de `.env` et
        // retombait sur le defaut de `config/app.php`.
        foreach (['phpunit.ci-feature.xml', 'phpunit.ci-minimal.xml'] as $config) {
            $this->assertStringContainsString(
                'APP_LOCALE" value="fr"',
                file_get_contents(base_path($config)),
                $config,
            );
        }
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
