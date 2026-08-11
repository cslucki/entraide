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
        $etape = $this->etapeQuiLance(self::WORKFLOW_SQLITE, 'sqlite-regression-gate', self::CONFIG_SQLITE);

        $this->assertNotNull($etape, 'aucune etape ne lance la suite SQLite complete');
        $this->assertStringContainsString('--log-junit', $etape['run'], 'sans JUnit, aucune identite comparable');
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
        foreach (['phpunit.ci-minimal.xml', 'phpunit.ci-feature.xml'] as $config) {
            $etape = $this->etapeQuiLance(self::WORKFLOW_PGSQL, 'quality-gate', $config);

            $this->assertNotNull($etape, "le gate PostgreSQL ne lance plus {$config}");
            $this->assertNotTrue($etape['continue-on-error'] ?? false, "{$config} n'est plus bloquant");
        }
    }
}
