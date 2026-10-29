<?php

namespace Tests\Feature;

use App\Support\Ops\DestructiveDatabaseGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1722 — sous PHPUnit, aucune ecriture n'atteint une base de travail.
 *
 * ## Ce que ces gardes mesurent, et ce qu'elles ne font JAMAIS
 *
 * Elles n'executent aucune commande destructrice. Elles ouvrent une connexion
 * vers une base JETABLE et vide (`bouclepro_t1722_scratch`), puis tentent une
 * ecriture triviale : le garde doit la refuser parce que le NOM de la base ne
 * designe pas un banc. Consigne MASTER : « prouver le refus des chemins
 * dangereux sans jamais les executer sur une vraie base de developpement ».
 *
 * ## Pourquoi pas `Artisan::call('migrate:fresh')`
 *
 * Mesure : ni `Artisan::call()` ni `$this->artisan()` ne diffusent
 * `CommandStarting` en processus. Une garde ecrite sur cette porte aurait ete
 * VERTE sans rien prouver — le faux vert classique. On vise donc la couche qui
 * protege reellement : `Connection::beforeExecuting()`.
 *
 * ## L'incident ferme
 *
 * `php artisan test --env=testing` resout `APP_ENV` a **local**, pas
 * `testing` : `phpunit.xml` n'est plus applique, `ArtisanDatabaseGuard` n'a
 * aucune attente pour `local`, et le garde de `Tests\TestCase` est ecrase des
 * que la classe enfant ecrit `use RefreshDatabase;` — 683 des 849 classes du
 * depot. La detection employee ici ne lit donc PAS l'environnement : elle
 * constate la presence de PHPUnit dans le processus.
 */
class TASK1722DestructiveDatabaseGuardTest extends TestCase
{
    private const JETABLE = 'bouclepro_t1722_scratch';

    private function reglagePgsql(): array
    {
        $defaut = config('database.connections.pgsql');

        return is_array($defaut) ? $defaut : [];
    }

    private function connexionVers(string $nom, string $base): void
    {
        config(["database.connections.{$nom}" => array_merge($this->reglagePgsql(), [
            'driver' => 'pgsql',
            'database' => $base,
        ])]);
    }

    private function exigePostgres(): void
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('PostgreSQL indisponible sur ce banc.');
        }
    }

    /**
     * LE cas du 28/10 : un test qui ecrit dans une base de travail.
     *
     * L'assertion porte sur le REFUS, pas sur le libelle d'une couche
     * precise. Deux gardes INDEPENDANTS couvrent desormais ce geste, et
     * lequel parle en premier depend de l'environnement :
     *
     * - sous `bin/test`, `APP_ENV` vaut `testing` : {@see ArtisanDatabaseGuard}
     *   (TASK-1367) voit la contradiction « testing annonce, pgsql vise » et
     *   refuse — c'est lui qu'on entend ici ;
     * - sous `php artisan test --env=testing`, `APP_ENV` retombe a `local` :
     *   ce garde-la se TAIT, et seule la couche de TASK-1722 subsiste.
     *
     * Asserter le libelle du premier aurait donc rendu la garde muette sur le
     * seul cas qui a detruit la base. C'est le refus qui est l'invariant.
     */
    public function test_a_test_cannot_write_to_a_work_database(): void
    {
        $this->exigePostgres();
        $this->connexionVers('t1722_travail', self::JETABLE);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/(ECRITURE DE BANC REFUSEE|ECRITURE REFUSEE)/');

        DB::connection('t1722_travail')->statement('CREATE TABLE t1722_jamais (id int)');
    }

    /**
     * La LECTURE reste possible : le garde protege la donnee, il ne coupe pas
     * la connexion. Sans cela, tout diagnostic deviendrait impossible.
     */
    public function test_reading_a_work_database_stays_possible(): void
    {
        $this->exigePostgres();
        $this->connexionVers('t1722_lecture', self::JETABLE);

        $this->assertSame(1, (int) DB::connection('t1722_lecture')->selectOne('select 1 as n')->n);
    }

    /**
     * Le nom lu est celui que LARAVEL resoudra : `DB_URL` gouverne quand il
     * est la, et la cle `database` peut alors designer tout autre chose.
     */
    public function test_a_database_url_cannot_smuggle_a_work_database_past_the_guard(): void
    {
        $this->exigePostgres();
        $reglage = $this->reglagePgsql();

        $hote = $reglage['host'] ?? '127.0.0.1';
        $port = $reglage['port'] ?? '5432';
        $utilisateur = rawurlencode((string) ($reglage['username'] ?? ''));
        $motDePasse = rawurlencode((string) ($reglage['password'] ?? ''));

        config(['database.connections.t1722_url' => array_merge($reglage, [
            'driver' => 'pgsql',
            'url' => "pgsql://{$utilisateur}:{$motDePasse}@{$hote}:{$port}/".self::JETABLE,
            // Le leurre : un nom de banc dans la cle qu'un garde naif lirait.
            'database' => 'quelque_chose_test',
        ])]);

        $this->expectException(RuntimeException::class);
        // Le nom REEL doit apparaitre dans le refus, quelle que soit la couche
        // qui parle : c'est la preuve que le leurre `database` n'a pas servi.
        $this->expectExceptionMessageMatches('/'.self::JETABLE.'/');

        DB::connection('t1722_url')->statement('CREATE TABLE t1722_jamais (id int)');
    }

    /** sqlite est toujours un banc : la suite entiere en depend. */
    public function test_sqlite_is_always_allowed(): void
    {
        DB::statement('create table if not exists t1722_sqlite_ok (id integer)');
        DB::statement('drop table if exists t1722_sqlite_ok');

        $this->assertTrue(true, 'le banc sqlite ecrit librement');
    }

    /** Le marqueur d'intention existe et porte un nom stable. */
    public function test_the_intent_markers_are_declared(): void
    {
        $this->assertSame('BOUCLEPRO_DESTRUCTIVE_DB_APPROVED', DestructiveDatabaseGuard::APPROVAL_MARKER);
        $this->assertTrue(method_exists(DestructiveDatabaseGuard::class, 'declarerIntention'));
    }
}
