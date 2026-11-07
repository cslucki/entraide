<?php

namespace Tests\Feature;

use Illuminate\Foundation\Application;
use Tests\TestCase;

/**
 * TASK-1781 — les deux prerequis techniques de la Managed Queue Laravel Cloud.
 *
 * La doc Cloud les enonce en une phrase (relevee le 07/11/2026 sur
 * <https://laravel.com/cloud/docs/queues>, onglet Laravel) :
 *
 *   « Managed queues require Laravel 11.55.0, 12.63.0, 13.19.0, or newer and
 *     the `aws/aws-sdk-php` package in your `composer.json` »
 *
 * Ces deux conditions ne se voient nulle part dans le code applicatif : rien
 * ne les appelle, aucune page ne les rend, aucun job ne les lit. Elles ne
 * vivent que dans `composer.json` et `composer.lock`. Une montee de framework
 * malheureuse, un `composer remove`, ou une resolution qui retrograde le lock
 * les feraient disparaitre SANS RIEN CASSER de visible — jusqu'au jour ou la
 * file managee serait creee et refuserait de fonctionner.
 *
 * C'est exactement le genre de panne muette que ce depot garde par un test.
 *
 * Ces trois tests lisent les ARTEFACTS REELS, jamais une constante recopiee :
 *
 *  1. la version du framework telle que l'application la rend a l'execution,
 *     pas celle ecrite dans le lock — c'est elle qui gouverne ;
 *  2. la DECLARATION dans `composer.json`, car la doc exige le paquet « in
 *     your composer.json » : l'avoir en transitif ne suffit pas. Il l'est
 *     aujourd'hui, par `laravel/ai` et `league/flysystem-aws-s3-v3`, et c'est
 *     precisement ce qui rend l'oubli indetectable ;
 *  3. la classe du transport SQS, chargeable par l'autoloader — la seule
 *     verification qui prouve que le paquet est reellement installe, et pas
 *     seulement nomme.
 *
 * Ce test ne route AUCUN job vers la file managee et n'en cree aucune. Il
 * garde le terrain, rien de plus.
 */
class TASK1781ManagedQueuePrerequisitesTest extends TestCase
{
    /**
     * Le plancher exige par la doc Cloud pour la ligne 13.x.
     */
    private const PLANCHER_LARAVEL = '13.19.0';

    private function composerJson(): array
    {
        $chemin = base_path('composer.json');

        $this->assertFileExists($chemin);

        $contenu = json_decode((string) file_get_contents($chemin), true);

        $this->assertIsArray($contenu, 'composer.json doit etre un JSON valide');

        return $contenu;
    }

    public function test_the_installed_laravel_version_meets_the_managed_queue_floor(): void
    {
        // La version rendue par l'application a l'execution, et non celle lue
        // dans le lock : c'est celle-la qui est reellement chargee.
        $version = Application::VERSION;

        $this->assertTrue(
            version_compare($version, self::PLANCHER_LARAVEL, '>='),
            sprintf(
                'Managed Queue exige Laravel >= %s ; la version chargee est %s. '
                .'Relancer `composer update laravel/framework`.',
                self::PLANCHER_LARAVEL,
                $version
            )
        );
    }

    public function test_the_aws_sdk_is_declared_in_composer_json(): void
    {
        $composer = $this->composerJson();

        // `require`, jamais `require-dev` : la file managee tourne en
        // production, et `composer install --no-dev` est la commande de build
        // de l'environnement Cloud.
        $this->assertArrayHasKey(
            'aws/aws-sdk-php',
            $composer['require'] ?? [],
            'La doc Cloud exige `aws/aws-sdk-php` dans composer.json (section require). '
            .'L\'avoir en dependance transitive ne satisfait pas cette exigence.'
        );

        $this->assertArrayNotHasKey(
            'aws/aws-sdk-php',
            $composer['require-dev'] ?? [],
            'aws/aws-sdk-php ne doit pas etre en require-dev : le build de production '
            .'tourne avec --no-dev.'
        );
    }

    public function test_the_sqs_transport_class_is_loadable(): void
    {
        // Le paquet peut etre nomme dans composer.json et absent de vendor/ :
        // seul le chargement effectif le prouve.
        $this->assertTrue(
            class_exists(\Aws\Sqs\SqsClient::class),
            'Aws\Sqs\SqsClient doit etre chargeable : c\'est le transport sur lequel '
            .'repose la connexion `cloud`.'
        );

        // Le pont cote framework, qui consomme ce client.
        $this->assertTrue(
            class_exists(\Illuminate\Queue\SqsQueue::class),
            'Illuminate\Queue\SqsQueue doit etre present dans le framework installe.'
        );
    }
}
