<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Le gate SQLite doit rester arme, et sa dette doit rester decroissante.
 *
 * Ces tests suivent l'idiome pose par `TASK1112CiCoverageTest` : lire le
 * **YAML analyse**, pas le texte. Chercher une chaine dans le fichier ne prouve
 * rien — la meme chaine figure dans les commentaires ecrits par la tache, et
 * supprimer l'etape laisserait les tests verts.
 *
 * Ce qu'ils gardent, dans l'ordre des facons de desarmer le dispositif sans
 * s'en rendre compte :
 *
 * 1. supprimer le job, ou son etape de comparaison ;
 * 2. la rendre non bloquante par `continue-on-error` ;
 * 3. faire grossir la reference pour faire passer une PR ;
 * 4. exclure des groupes de la config SQLite, donc reduire la couverture ;
 * 5. partager le groupe de concurrence, ce qui fait s'annuler les deux gates.
 */
class TASK1126SqliteGateTest extends TestCase
{
    private const WORKFLOW_SQLITE = '.github/workflows/ci-sqlite.yml';

    private const WORKFLOW_PGSQL = '.github/workflows/ci-postgresql.yml';

    private const CONFIG_SQLITE = 'phpunit.ci-sqlite.xml';

    private const REFERENCE = '.github/sqlite-known-failures.txt';

    /**
     * Plafond de la dette SQLite. **Ce nombre ne peut que baisser.**
     *
     * Il vaut la mesure prise a la creation du gate. L'augmenter pour faire
     * passer une CI est precisement ce que ce test interdit : il faut une
     * validation orchestrateur ou une tache dediee, et le diff Git doit le
     * montrer.
     */
    private const PLAFOND = 32;

    /** @return array<string, mixed> */
    private function workflow(string $chemin): array
    {
        return Yaml::parseFile(base_path($chemin));
    }

    /** @return list<array<string, mixed>> */
    private function etapes(string $chemin, string $job): array
    {
        $jobs = $this->workflow($chemin)['jobs'] ?? [];

        $this->assertArrayHasKey($job, $jobs, "le job {$job} a disparu");

        return $jobs[$job]['steps'] ?? [];
    }

    /** L'etape dont la commande contient un fragment donne, ou null. */
    private function etapeQuiLance(string $chemin, string $job, string $fragment): ?array
    {
        foreach ($this->etapes($chemin, $job) as $etape) {
            if (str_contains($etape['run'] ?? '', $fragment)) {
                return $etape;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function reference(): array
    {
        $chemin = base_path(self::REFERENCE);

        $this->assertFileExists($chemin, 'la reference des echecs SQLite a disparu');

        $lignes = [];

        foreach (file($chemin, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ligne) {
            $ligne = trim($ligne);

            if ($ligne !== '' && ! str_starts_with($ligne, '#')) {
                $lignes[] = $ligne;
            }
        }

        return $lignes;
    }

    public function test_the_sqlite_suite_actually_runs_in_ci(): void
    {
        // TASK-1334 : la suite ne tourne plus dans le job du gate mais dans un
        // job `suite` matriciel, sur une configuration de shard derivee de
        // `phpunit.ci-sqlite.xml`.
        $etape = $this->etapeQuiLance(self::WORKFLOW_SQLITE, 'suite', 'phpunit.ci-sqlite.shard-');

        $this->assertNotNull($etape, 'aucune etape ne lance la suite SQLite complete');
        $this->assertStringContainsString('--log-junit', $etape['run'], 'sans JUnit, aucune identite comparable');

        // Le generateur doit tourner avec `--verify`, et conserver la
        // testsuite Unit dans un seul shard : sans `--solo-suite`, elle
        // tournerait quatre fois et le rapport agrege compterait quatre fois
        // les memes tests.
        $generation = $this->etapeQuiLance(self::WORKFLOW_SQLITE, 'suite', 'shard-tests.php');

        $this->assertNotNull($generation, 'le generateur de shards ne tourne plus');
        $this->assertStringContainsString('--verify', $generation['run'], 'decoupe non verifiee');
        $this->assertStringContainsString('--solo-suite=Unit', $generation['run'], 'la suite Unit tournerait 4 fois');
    }

    public function test_every_shard_report_is_fed_to_the_comparison(): void
    {
        // **La garde propre au decoupage.** Le gate compare un ENSEMBLE
        // d'echecs a une reference. Si un rapport de shard n'etait pas passe
        // au script, ses echecs manqueraient a l'ensemble observe et le gate
        // annoncerait un « progres » la ou il y a un trou — c'est le seul
        // moyen pour qu'un decoupage rende ce gate menteur.
        $comparaison = $this->etapeQuiLance(self::WORKFLOW_SQLITE, 'sqlite-regression-gate', 'sqlite-regression-gate.php');

        $this->assertNotNull($comparaison, 'l\'etape de comparaison a disparu');

        $matrice = $this->workflow(self::WORKFLOW_SQLITE)['jobs']['suite']['strategy']['matrix']['shard'] ?? [];

        $this->assertNotSame([], $matrice, 'la matrice des shards a disparu');

        foreach ($matrice as $shard) {
            $this->assertStringContainsString(
                "sqlite-report-{$shard}.xml",
                $comparaison['run'],
                "le rapport du shard {$shard} n'est pas passe a la comparaison",
            );
        }
    }

    public function test_the_comparison_step_exists_and_is_blocking(): void
    {
        $etape = $this->etapeQuiLance(self::WORKFLOW_SQLITE, 'sqlite-regression-gate', 'sqlite-regression-gate.php');

        $this->assertNotNull($etape, 'l\'etape de comparaison nominative a disparu');

        // `continue-on-error: true` desactive une etape sans la supprimer. Un
        // ancien job du depot le portait : ce n'est pas theorique.
        $this->assertNotTrue(
            $etape['continue-on-error'] ?? false,
            'l\'etape de comparaison ne serait plus bloquante',
        );
    }

    public function test_the_two_gates_cannot_cancel_each_other(): void
    {
        $sqlite = $this->workflow(self::WORKFLOW_SQLITE)['concurrency']['group'] ?? null;
        $pgsql = $this->workflow(self::WORKFLOW_PGSQL)['concurrency']['group'] ?? null;

        $this->assertNotNull($sqlite);
        $this->assertNotNull($pgsql);

        // Meme groupe + `cancel-in-progress` = le second gate tue le premier,
        // et une PR n'a jamais ses deux preuves en meme temps.
        $this->assertNotSame($pgsql, $sqlite, 'les deux gates partagent un groupe de concurrence');
    }

    public function test_the_sqlite_configuration_excludes_no_group(): void
    {
        $xml = simplexml_load_file(base_path(self::CONFIG_SQLITE));

        $this->assertNotFalse($xml);

        // Le contrat du gate SQLite n'est pas « zero echec » mais « exactement
        // ceux de la reference ». Exclure un groupe ne le rendrait pas plus
        // vert : cela retirerait seulement des tests du filet.
        $this->assertEmpty(
            $xml->xpath('//groups/exclude/group') ?: [],
            'la configuration SQLite exclut des groupes, donc reduit la couverture',
        );
    }

    public function test_the_sqlite_configuration_pins_the_locale(): void
    {
        $xml = simplexml_load_file(base_path(self::CONFIG_SQLITE));

        $locales = $xml->xpath('//php/env[@name="APP_LOCALE"]') ?: [];

        $this->assertCount(1, $locales, 'APP_LOCALE doit etre fixee : la CI n\'a pas de .env');
        $this->assertSame('fr', (string) $locales[0]['value']);
    }

    public function test_the_known_failure_reference_may_only_shrink(): void
    {
        $reference = $this->reference();

        $this->assertLessThanOrEqual(
            self::PLAFOND,
            count($reference),
            'la reference des echecs SQLite a grossi — cela exige une validation '
            .'orchestrateur ou une tache dediee, pas un ajout de ligne dans une PR metier',
        );
    }

    public function test_the_reference_holds_canonical_identities(): void
    {
        foreach ($this->reference() as $identite) {
            // `Espace.De.Noms.Classe::methode` — la forme que produit le JUnit
            // apres normalisation. Une ligne tronquee par un terminal, ou
            // collee depuis la sortie decorative, ne matcherait jamais.
            $this->assertMatchesRegularExpression(
                '/^[A-Za-z0-9_.]+::[A-Za-z0-9_]+/',
                $identite,
                "identite non canonique dans la reference : {$identite}",
            );
            $this->assertStringNotContainsString('…', $identite, 'identite tronquee dans la reference');
        }
    }

    public function test_the_postgresql_gate_keeps_both_of_its_halves(): void
    {
        // Cette tache ne doit rien retirer au gate PostgreSQL. Les deux
        // moities restent, et restent bloquantes.
        //
        // TASK-1334 : elles ne vivent plus dans un job unique. La suite Unit
        // est dans le job `unit`, la suite Feature dans le job `feature` sous
        // forme de quatre shards. Le nom du job ayant cesse d'etre un point
        // fixe, on cherche l'etape dans l'ensemble des jobs du workflow — ce
        // qui verifie toujours la meme chose : les deux moities tournent, et
        // rien ne les neutralise.
        $moities = [
            'la suite Unit' => ['unit', 'phpunit.ci-minimal.xml'],
            'la suite Feature' => ['feature', 'phpunit.ci-feature.shard-'],
        ];

        foreach ($moities as $quoi => [$job, $fragment]) {
            $etape = $this->etapeQuiLance(self::WORKFLOW_PGSQL, $job, $fragment);

            $this->assertNotNull($etape, "le gate PostgreSQL ne lance plus {$quoi}");
            $this->assertNotTrue($etape['continue-on-error'] ?? false, "{$quoi} n'est plus bloquante");
        }
    }
}
