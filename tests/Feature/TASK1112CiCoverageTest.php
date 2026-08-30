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

    /**
     * Les etapes de TOUS les jobs du workflow, a plat.
     *
     * TASK-1334 : jusqu'ici cette methode ne lisait que le job `quality-gate`,
     * parce qu'un seul job faisait tout. Le workflow en compte desormais
     * quatre — `classify`, `unit`, `feature`, `quality-gate` — et les etapes
     * de test vivent dans `unit` et `feature`. Chercher dans l'ensemble des
     * jobs est **plus strict**, pas moins : une etape ne peut plus echapper a
     * ces gardes en changeant de job.
     */
    private function etapes(): array
    {
        $jobs = $this->workflow()['jobs'] ?? [];

        $this->assertNotSame([], $jobs, 'le workflow ne declare plus aucun job');

        $etapes = [];

        foreach ($jobs as $job) {
            foreach ($job['steps'] ?? [] as $etape) {
                $etapes[] = $etape;
            }
        }

        return $etapes;
    }

    /** Les etapes d'un job nomme. */
    private function etapesDuJob(string $job): array
    {
        $jobs = $this->workflow()['jobs'] ?? [];

        $this->assertArrayHasKey($job, $jobs, "le job {$job} a disparu du workflow");

        return $jobs[$job]['steps'] ?? [];
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
        //
        // TASK-1334 : la suite Feature n'est plus lancee par une etape unique
        // mais par quatre shards, via des configurations
        // `phpunit.ci-feature.shard-K.xml` **derivees** de
        // `phpunit.ci-feature.xml`. Le test ne cherche donc plus le nom de la
        // configuration de reference, mais la commande qui lance un shard.
        $this->assertNotNull(
            $this->etapeQuiLance('phpunit.ci-feature.shard-'),
            'aucune etape ne lance la suite Feature',
        );

        // Et surtout : le generateur doit tourner avec `--verify`. Sans lui,
        // un shard pourrait se lancer sur une decoupe incomplete et la CI
        // serait verte en ayant oublie des tests — exactement la tricherie que
        // ce fichier existe pour rendre impossible.
        $generation = $this->etapeQuiLance('shard-tests.php');

        $this->assertNotNull($generation, 'le generateur de shards ne tourne plus');
        $this->assertStringContainsString(
            '--verify',
            $generation['run'],
            'le generateur tourne sans --verify : une decoupe incomplete passerait inapercue',
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
        // TASK-1334 : la charge ne varie plus au sein d'un job mais entre
        // jobs, si bien que les etapes de test ne portent plus AUCUNE
        // condition — le `if` a migre au niveau du job `feature`, ou il est
        // verifie par test_only_the_non_required_jobs_may_be_conditional().
        // Aucune condition n'est donc toleree ici, ce qui est plus strict
        // qu'avant.
        foreach (['phpunit.ci-minimal.xml', 'phpunit.ci-feature.shard-'] as $config) {
            $etape = $this->etapeQuiLance($config);

            $this->assertNotNull($etape, $config);
            $this->assertArrayNotHasKey('continue-on-error', $etape, $config.' n’est plus bloquante');
            $this->assertArrayNotHasKey(
                'if',
                $etape,
                $config.' porte une condition d’etape : la charge se module par job, pas par etape',
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

    /**
     * Les deux checks EXIGES par le ruleset GitHub `Required CI checks`.
     *
     * Les noms sont ceux du ruleset, au mot pres. Renommer un de ces jobs sans
     * mettre a jour le ruleset dans le meme mouvement rendrait la protection
     * de branche inoperante : elle attendrait un check qui n'existe plus, et
     * plus rien ne pourrait merger.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function gatesRequis(): array
    {
        return [
            'SQLite Regression Gate (blocking)' => ['.github/workflows/ci-sqlite.yml', 'sqlite-regression-gate'],
            'Quality Gate (blocking)' => [self::WORKFLOW, 'quality-gate'],
        ];
    }

    /**
     * Les jobs qui RESOLVENT le mode CI.
     *
     * TASK-1334 : ce n'est plus le meme ensemble que les gates requis. Cote
     * PostgreSQL, la resolution a ete extraite dans un job `classify` sans
     * conteneur ni checkout, precisement pour qu'elle precede la creation des
     * conteneurs lourds — `services:` etant evalue avant la premiere etape
     * d'un job, la classification ne pouvait pas rester dans le job qui porte
     * la base. Cote SQLite, le workflow est inchange.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function jobsQuiResolventLeMode(): array
    {
        return [
            'SQLite CI / classify' => ['.github/workflows/ci-sqlite.yml', 'classify'],
            'PostgreSQL CI / classify' => [self::WORKFLOW, 'classify'],
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
        foreach (self::jobsQuiResolventLeMode() as $nomJob => [$chemin, $job]) {
            $this->assertSame(
                $attendu,
                $this->resoudreLeMode($chemin, $job, $event, $labels),
                "{$nomJob} : event={$event} labels=".json_encode($labels),
            );
        }
    }

    public function test_the_required_jobs_are_never_conditional(): void
    {
        // Le risque documente par MASTER (TASK-1148, workflow_dispatch) : un
        // job entierement skippe peut laisser un required check `Pending`
        // indefiniment. **L'existence d'un job requis ne doit jamais dependre
        // de quoi que ce soit.**
        //
        // TASK-1334 : ce test exigeait auparavant l'absence pure et simple de
        // `if:`. Cette formulation etait juste tant qu'un job requis n'avait
        // pas de `needs:`. Elle devient dangereuse des qu'il en a un — et
        // `quality-gate` en a desormais trois.
        //
        // Car un job qui declare `needs:` et **aucun** `if:` est skippe des
        // qu'un job amont echoue. Le required check ne rapporte alors jamais,
        // et la PR reste bloquee en `Pending` : precisement le risque que ce
        // test existe pour interdire. `if: always()` est la seule expression
        // qui garantisse l'execution en toutes circonstances.
        //
        // La regle est donc reformulee, et elle est plus stricte qu'avant :
        //   - job requis sans `needs:` -> aucun `if:` ;
        //   - job requis avec `needs:` -> `if: always()`, et rien d'autre.
        foreach (self::gatesRequis() as $nomJob => [$chemin, $job]) {
            $jobs = Yaml::parseFile(base_path($chemin))['jobs'] ?? [];

            $this->assertArrayHasKey($job, $jobs, "{$nomJob} a disparu de {$chemin}");

            $condition = $jobs[$job]['if'] ?? null;
            $depend = array_key_exists('needs', $jobs[$job]);

            if (! $depend) {
                $this->assertNull(
                    $condition,
                    "{$nomJob} porte une condition au niveau JOB — required check jamais garanti",
                );

                continue;
            }

            $this->assertSame(
                'always()',
                $condition,
                "{$nomJob} declare `needs:` sans `if: always()` : il sera skippe des qu’un job amont "
                    ."echoue, et le required check restera Pending indefiniment",
            );
        }
    }

    public function test_only_the_non_required_jobs_may_be_conditional(): void
    {
        // TASK-1334 : la charge ne se module plus au sein d'un job mais entre
        // jobs. C'est legitime — a condition que seuls des jobs NON requis
        // soient conditionnels, et que leur condition depende de la
        // classification, jamais d'autre chose.
        //
        // Sans cette garde, on pourrait rendre `feature` conditionnel a
        // n'importe quoi (une branche, un acteur, un `false` litteral) et
        // supprimer la suite Feature du gate sans qu'aucun test ne bronche.
        $jobs = $this->workflow()['jobs'] ?? [];
        $requis = ['quality-gate'];

        foreach ($jobs as $nom => $job) {
            $condition = $job['if'] ?? null;

            if ($condition === null) {
                continue;
            }

            if (in_array($nom, $requis, true)) {
                continue; // couvert par le test precedent
            }

            $this->assertStringContainsString(
                'needs.classify.outputs.mode',
                $condition,
                "le job {$nom} est conditionne par autre chose que la classification : ".$condition,
            );
        }

        // Et la classification elle-meme ne peut pas etre conditionnelle :
        // tout le reste en depend.
        $this->assertArrayNotHasKey(
            'if',
            $jobs['classify'] ?? [],
            'le job de classification est conditionnel : plus rien ne peut resoudre le mode',
        );
    }

    public function test_the_classification_precedes_every_database_container(): void
    {
        // La raison d'etre du job `classify`. `services:` est evalue AVANT la
        // premiere etape d'un job : tant que la resolution du mode vivait dans
        // le job qui porte la base, le conteneur pgvector naissait avant meme
        // qu'on sache si la charge etait `light` ou `full`.
        //
        // Tout job qui declare un service doit donc dependre de `classify`.
        $jobs = $this->workflow()['jobs'] ?? [];

        $this->assertArrayNotHasKey(
            'services',
            $jobs['classify'] ?? [],
            'le job de classification porte un conteneur : il ne peut plus le preceder',
        );

        foreach ($jobs as $nom => $job) {
            if (! array_key_exists('services', $job)) {
                continue;
            }

            $needs = (array) ($job['needs'] ?? []);

            $this->assertContains(
                'classify',
                $needs,
                "le job {$nom} cree un conteneur sans attendre la classification",
            );
        }
    }

    public function test_the_feature_step_runs_after_the_migrations(): void
    {
        // TASK-1334 : l'ordre se lit desormais DANS le job `feature`. Le
        // comparer sur la liste a plat de tous les jobs ne voudrait plus rien
        // dire — les etapes de `unit` s'y intercaleraient.
        $etapes = array_values($this->etapesDuJob('feature'));

        $indice = fn (string $aiguille) => collect($etapes)
            ->search(fn (array $e) => str_contains($e['run'] ?? '', $aiguille));

        $migrations = $indice('artisan migrate');
        $tests = $indice('phpunit.ci-feature.shard-');
        $generation = $indice('shard-tests.php');

        $this->assertIsInt($migrations, 'le job feature ne lance plus les migrations');
        $this->assertIsInt($tests, 'le job feature ne lance plus de shard');
        $this->assertIsInt($generation, 'le job feature ne genere plus les shards');

        $this->assertLessThan($tests, $migrations, 'la suite Feature tourne avant les migrations');

        // La decoupe doit exister avant qu'on tente de la lancer : sinon le
        // shard echouerait sur un fichier de configuration absent, ce qui est
        // bruyant mais moins clair qu'une garde explicite.
        $this->assertLessThan($tests, $generation, 'un shard est lance avant d’avoir ete genere');
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
        // TASK-1334 : la verification portait sur le seul job `quality-gate`.
        // Elle porte desormais sur **tous** les jobs qui declarent une base ou
        // un conteneur — il y en a cinq (`unit` plus quatre shards), et il
        // suffirait qu'un seul vise la mauvaise base pour que la CI ecrase des
        // donnees de developpement.
        $jobs = $this->workflow()['jobs'] ?? [];
        $verifies = 0;

        foreach ($jobs as $nom => $job) {
            if (isset($job['env']['DB_DATABASE'])) {
                $this->assertSame(
                    'bouclepro_test',
                    $job['env']['DB_DATABASE'],
                    "le job {$nom} vise une autre base que bouclepro_test",
                );
                $verifies++;
            }

            if (isset($job['services']['postgres'])) {
                $this->assertSame(
                    'bouclepro_test',
                    $job['services']['postgres']['env']['POSTGRES_DB'] ?? null,
                    "le conteneur du job {$nom} expose une autre base que bouclepro_test",
                );
                $verifies++;
            }
        }

        $this->assertGreaterThan(
            0,
            $verifies,
            'plus aucun job ne declare de base : la garde ne verifie plus rien',
        );

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
        // Sans `timeout-minutes`, le defaut de GitHub est 360 minutes : un
        // test qui pend retiendrait un runner six heures.
        //
        // TASK-1334 : la garde couvre desormais **tous** les jobs, pas le seul
        // gate. Le motif n'est pas theorique — un essai reel du 2026-08-30
        // etait reste bloque plus de 40 minutes sur « Initialize containers »,
        // un incident d'infrastructure GitHub que seule la borne du job peut
        // interrompre. Un job ajoute demain sans borne rouvrirait le trou.
        foreach ($this->workflow()['jobs'] ?? [] as $nom => $job) {
            $this->assertArrayHasKey(
                'timeout-minutes',
                $job,
                "le job {$nom} n’a pas de borne : il peut retenir un runner six heures",
            );
        }
    }

    public function test_the_shards_cover_the_whole_feature_suite(): void
    {
        // **La garde qui rend le decoupage honnete.**
        //
        // Le seul moyen de rendre la CI plus rapide en trichant serait
        // d'oublier des tests. Ce test mesure ce que les shards selectionnent
        // REELLEMENT — meme idiome que nombreDeTestsSelectionnes() plus haut —
        // et le compare a la configuration de reference. Compter les fichiers
        // ne suffirait pas : une exclusion de groupe mal propagee ferait
        // disparaitre des methodes sans toucher au nombre de fichiers.
        $reference = $this->nombreDeTestsSelectionnes('phpunit.ci-feature.xml');

        $this->assertGreaterThan(0, $reference, 'la configuration de reference ne selectionne plus rien');

        exec(
            'cd '.escapeshellarg(base_path()).' && php .github/scripts/shard-tests.php --total=4 --verify',
            $sortieGeneration,
            $codeGeneration,
        );

        $this->assertSame(
            0,
            $codeGeneration,
            "la generation des shards a echoue :\n".implode("\n", $sortieGeneration),
        );

        $somme = 0;

        for ($shard = 1; $shard <= 4; $shard++) {
            $config = "phpunit.ci-feature.shard-{$shard}.xml";

            $this->assertFileExists(base_path($config), "{$config} n’a pas ete genere");

            $compte = $this->nombreDeTestsSelectionnes($config);

            $this->assertGreaterThan(0, $compte, "{$config} ne selectionne aucun test");

            $somme += $compte;
        }

        $this->assertSame(
            $reference,
            $somme,
            "les quatre shards selectionnent {$somme} tests la ou la suite de reference en selectionne "
                ."{$reference} : le decoupage a perdu ou duplique des tests",
        );
    }
}
