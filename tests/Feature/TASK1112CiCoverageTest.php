<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * La CI GitHub doit pouvoir devenir rouge sur une regression Feature.
 *
 * Avant cette tache, le « Quality Gate (blocking) » lançait
 * `phpunit.ci-minimal.xml`, dont l'unique testsuite est `tests/Unit` :
 * **aucun test Feature n'a jamais tourne dans un gate bloquant**. Une
 * regression Feature, meme massive, laissait la coche verte. Le seul filet
 * reel etait la suite locale.
 *
 * Ces tests gardent le dispositif lui-meme. Ils sont volontairement
 * elementaires : ce qu'ils protegent, c'est qu'on ne le desactive pas
 * discretement.
 */
class TASK1112CiCoverageTest extends TestCase
{
    private function workflow(): string
    {
        return file_get_contents(base_path('.github/workflows/ci-postgresql.yml'));
    }

    private function configFeature(): string
    {
        return file_get_contents(base_path('phpunit.ci-feature.xml'));
    }

    public function test_the_blocking_job_runs_the_feature_suite(): void
    {
        // C'est tout l'objet de la tache.
        $this->assertStringContainsString('phpunit.ci-feature.xml', $this->workflow());
    }

    public function test_the_blocking_job_still_runs_the_unit_suite(): void
    {
        // Ajouter les Feature ne doit pas retirer les Unit.
        $this->assertStringContainsString('phpunit.ci-minimal.xml', $this->workflow());
    }

    public function test_the_feature_config_really_points_at_the_feature_directory(): void
    {
        $this->assertStringContainsString('<directory>tests/Feature</directory>', $this->configFeature());
    }

    public function test_the_excluded_classes_all_exist(): void
    {
        // Une exclusion qui ne designe plus rien est un mensonge : elle laisse
        // croire qu'une classe est connue rouge alors qu'elle a peut-etre ete
        // renommee — et le fichier renomme, lui, n'est plus exclu ni surveille.
        preg_match_all('#<exclude>([^<]+)</exclude>#', $this->configFeature(), $m);

        $this->assertNotEmpty($m[1], 'aucune exclusion lue');

        foreach ($m[1] as $chemin) {
            $this->assertFileExists(base_path($chemin), $chemin);
        }
    }

    public function test_the_exclusion_list_does_not_grow(): void
    {
        // **Le garde-fou qui compte.** Ajouter une classe a la liste pour faire
        // passer une PR reviendrait a desactiver le gate qu'on vient de poser.
        //
        // Le nombre est fige a la main : il doit **descendre** quand une classe
        // est reparee, et ce test echouera alors — c'est voulu, il rappelle de
        // mettre le chiffre a jour et de dire lequel a ete corrige.
        preg_match_all('#<exclude>#', $this->configFeature(), $m);

        $this->assertCount(
            12,
            $m[0],
            'la liste des classes exclues de la CI a change : si une classe a ete reparee, baissez ce nombre ; si vous en ajoutez une, expliquez-vous',
        );
    }

    public function test_the_ci_never_targets_the_development_database(): void
    {
        // Le garde-fou PostgreSQL ne doit pas etre affaibli : la CI tourne sur
        // un conteneur, jamais sur la base de developpement.
        $workflow = $this->workflow();

        $this->assertStringContainsString('DB_DATABASE: bouclepro_test', $workflow);
        $this->assertStringNotContainsString('DB_DATABASE: bouclepro'."\n", $workflow);
        $this->assertStringContainsString('DB_DATABASE" value="bouclepro_test"', $this->configFeature());
    }

    public function test_the_ci_never_runs_a_destructive_migration(): void
    {
        foreach (['migrate:fresh', 'migrate:refresh', 'db:wipe', 'pg-validate'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $this->workflow(), $interdit);
        }
    }
}
