<?php

namespace Tests\Feature;

use App\Support\Ops\ArtisanDatabaseGuard;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1367 — refuser une ecriture quand l'environnement ANNONCE contredit la
 * base REELLEMENT visee.
 *
 * ## L'incident
 *
 * `APP_ENV=testing php artisan migrate --force` a migre `bouclepro`. Il
 * n'existe aucun `.env.testing` : l'environnement ne charge donc rien et
 * retombe sur `.env`. Le `force sqlite` de `phpunit.xml` ne vaut que pour le
 * runner PHPUnit.
 *
 * ## Ce que ce fichier garde
 *
 * Que le garde s'arme SUR CONTRADICTION et sur elle seule. Un garde qui se
 * declencherait sur le nom d'une base bloquerait un developpeur qui migre son
 * propre poste — et un garde-fou qui gene l'usage legitime finit desarme.
 *
 * ## Aucun test ne vise `bouclepro`
 *
 * Contrainte explicite. Les scenarios de refus sont joues en faisant
 * CONTREDIRE une connexion sqlite avec l'attente d'un autre environnement :
 * la contradiction est reelle, la base ne l'est pas.
 */
class TASK1367ArtisanDatabaseGuardTest extends TestCase
{
    // =====================================================================
    // A. Le garde s'arme sur CONTRADICTION, et sur elle seule
    // =====================================================================

    /**
     * 1. `testing` pointant ailleurs que sur sqlite : le garde s'arme.
     *
     * C'est l'incident, reproduit sans postgres : on annonce `testing`, et la
     * connexion etablie n'est pas une base de test.
     */
    public function test_the_guard_arms_when_the_declared_environment_is_contradicted(): void
    {
        $this->app['env'] = 'ai-validation';

        ArtisanDatabaseGuard::arm($this->app);

        // Une connexion sqlite sous `ai-validation`, qui attend
        // `bouclepro_ai_validation` : contradiction.
        $connection = DB::connection();
        Event::dispatch(new ConnectionEstablished($connection));

        $this->assertSame(1, $this->armedCallbacks($connection));
    }

    /** 2. Environnement coherent : aucun garde. */
    public function test_no_guard_when_the_environment_matches_its_target(): void
    {
        $this->app['env'] = 'testing';

        ArtisanDatabaseGuard::arm($this->app);

        $connection = DB::connection();
        Event::dispatch(new ConnectionEstablished($connection));

        $this->assertSame('sqlite', $connection->getDriverName(), 'Le pre-requis : la suite tourne bien sur sqlite.');
        $this->assertSame(0, $this->armedCallbacks($connection));
    }

    /**
     * 3. Un environnement qui ne PROMET rien n'est jamais garde.
     *
     * `local` et `production` ne figurent pas dans la table des attentes : ils
     * ne peuvent donc pas se contredire. C'est ce qui protege le developpeur
     * qui migre son poste, et le deploiement de production.
     */
    public function test_an_environment_that_promises_nothing_is_never_guarded(): void
    {
        foreach (['local', 'production', 'staging'] as $environment) {
            $this->refreshApplication();
            $this->app['env'] = $environment;

            ArtisanDatabaseGuard::arm($this->app);

            $connection = DB::connection();
            Event::dispatch(new ConnectionEstablished($connection));

            $this->assertSame(0, $this->armedCallbacks($connection), $environment);
        }
    }

    // =====================================================================
    // B. Une fois arme : les ecritures tombent, les lectures passent
    // =====================================================================

    /** 4. Une ECRITURE est refusee, et rien n'a ete ecrit. */
    public function test_a_write_is_refused_once_the_guard_is_armed(): void
    {
        $connection = $this->armedConnection();

        DB::statement('create table task1367_probe (id integer)');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ECRITURE REFUSEE');

        Event::dispatch(new ConnectionEstablished($connection));
        $connection->insert('insert into task1367_probe (id) values (1)');
    }

    /**
     * 5. Une LECTURE n'est PAS bloquee.
     *
     * Exigence explicite : une commande non mutative sous environnement
     * incoherent ne doit pas etre bloquee arbitrairement.
     */
    public function test_a_read_is_never_blocked(): void
    {
        $connection = $this->armedConnection();

        DB::statement('create table task1367_readable (id integer)');
        Event::dispatch(new ConnectionEstablished($connection));

        $this->assertSame([], $connection->select('select * from task1367_readable'));
    }

    /** 6. Le controle de transaction n'est pas une mutation. */
    public function test_transaction_control_is_not_treated_as_a_mutation(): void
    {
        $readOnly = new ReflectionMethod(ArtisanDatabaseGuard::class, 'isReadOnly');
        $readOnly->setAccessible(true);

        foreach (['begin', 'commit', 'rollback', 'savepoint sp1', 'release sp1', 'set names utf8'] as $query) {
            $this->assertTrue($readOnly->invoke(null, $query), $query);
        }
    }

    // =====================================================================
    // C. La classification des instructions
    // =====================================================================

    /** 7. Ce qui compte comme lecture, et ce qui compte comme ecriture. */
    public function test_statements_are_classified_by_their_verb(): void
    {
        $readOnly = new ReflectionMethod(ArtisanDatabaseGuard::class, 'isReadOnly');
        $readOnly->setAccessible(true);

        foreach (['select 1', 'SELECT 1', '  select 1', 'show tables', 'pragma foreign_keys', 'explain select 1'] as $query) {
            $this->assertTrue($readOnly->invoke(null, $query), "lecture attendue : {$query}");
        }

        foreach ([
            'insert into t values (1)',
            'update t set a = 1',
            'delete from t',
            'create table t (id integer)',
            'alter table t add column x integer',
            'drop table t',
            'truncate t',
        ] as $query) {
            $this->assertFalse($readOnly->invoke(null, $query), "ecriture attendue : {$query}");
        }
    }

    /**
     * 8. Un commentaire SQL en tete ne masque pas le verbe.
     *
     * Laravel prefixe certaines instructions ; lire naivement le premier mot
     * aurait laisse passer une ecriture commentee.
     */
    public function test_a_leading_sql_comment_does_not_hide_the_verb(): void
    {
        $readOnly = new ReflectionMethod(ArtisanDatabaseGuard::class, 'isReadOnly');
        $readOnly->setAccessible(true);

        $this->assertFalse($readOnly->invoke(null, '/* migration */ insert into t values (1)'));
        $this->assertFalse($readOnly->invoke(null, "-- une note\ncreate table t (id integer)"));
        $this->assertTrue($readOnly->invoke(null, '/* lecture */ select 1'));
    }

    /**
     * 9. Une expression de table commune est traitee comme une ECRITURE.
     *
     * `WITH x AS (...) SELECT` lit, mais `WITH x AS (...) INSERT` ecrit. On ne
     * peut pas trancher sur le premier mot : dans le doute, on refuse. Ce
     * fail-closed ne coute rien, parce que le garde ne s'execute QUE dans une
     * situation deja anormale.
     */
    public function test_a_common_table_expression_is_treated_as_a_write(): void
    {
        $readOnly = new ReflectionMethod(ArtisanDatabaseGuard::class, 'isReadOnly');
        $readOnly->setAccessible(true);

        $this->assertFalse($readOnly->invoke(null, 'with x as (select 1) select * from x'));
    }

    // =====================================================================
    // D. L'attente elle-meme
    // =====================================================================

    /** 10. Le garde compare un PILOTE ou un NOM DE BASE, jamais une liste noire. */
    public function test_the_expectation_compares_a_driver_or_a_database_name(): void
    {
        $satisfies = new ReflectionMethod(ArtisanDatabaseGuard::class, 'satisfies');
        $satisfies->setAccessible(true);

        $connection = DB::connection();

        $this->assertTrue($satisfies->invoke(null, $connection, ['driver' => 'sqlite']));
        $this->assertFalse($satisfies->invoke(null, $connection, ['driver' => 'pgsql']));
        $this->assertFalse($satisfies->invoke(null, $connection, ['driver' => 'sqlite', 'database' => 'une-autre-base']));
        $this->assertTrue($satisfies->invoke(null, $connection, ['driver' => 'sqlite', 'database' => $connection->getDatabaseName()]));
    }

    /**
     * 11. Le message dit ce qui etait attendu, ce qui est vise, et comment
     *     sortir.
     *
     * Un refus qui n'explique pas se contourne au hasard, ou fait perdre du
     * temps a quelqu'un dont l'intention etait legitime.
     */
    public function test_the_refusal_explains_itself(): void
    {
        $connection = $this->armedConnection();

        DB::statement('create table task1367_message (id integer)');
        Event::dispatch(new ConnectionEstablished($connection));

        try {
            $connection->insert('insert into task1367_message (id) values (1)');
            $this->fail('Le garde aurait du refuser.');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();

            $this->assertStringContainsString('ai-validation', $message, 'l environnement annonce');
            $this->assertStringContainsString('bouclepro_ai_validation', $message, 'ce qui etait attendu');
            $this->assertStringContainsString($connection->getDatabaseName(), $message, 'la cible reelle');
            $this->assertStringContainsString("Aucune ecriture n'a eu lieu", $message);
            $this->assertStringContainsString('sans APP_ENV', $message, 'la sortie de secours');
        }
    }

    // =====================================================================
    // E. La cible est DERIVEE de la connexion canonique, jamais recopiee
    // =====================================================================

    /**
     * 12. L'attente vient de `database.connections.*`, pas d'un litteral.
     *
     * Arbitrage MASTER, contre ma preference initiale, et il a raison :
     * recopier le nom de la base dans le garde creerait une seconde verite. Un
     * renommage legitime de l'infrastructure se propagerait alors partout SAUF
     * dans le garde-fou — c'est-a-dire a l'endroit ou l'oubli coute le plus.
     *
     * Ce test le PROUVE en renommant la base canonique : l'attente suit.
     */
    public function test_the_expectation_is_derived_from_the_canonical_connection(): void
    {
        $resolve = new ReflectionMethod(ArtisanDatabaseGuard::class, 'resolve');
        $resolve->setAccessible(true);

        $expectation = ['connection' => 'bouclepro_ai_validation'];

        $before = $resolve->invoke(null, $expectation);
        $this->assertIsArray($before);
        $this->assertSame(config('database.connections.bouclepro_ai_validation.database'), $before['database']);

        // On renomme la base canonique : l'attente doit suivre TOUTE SEULE.
        config(['database.connections.bouclepro_ai_validation.database' => 'banc-renomme']);

        $after = $resolve->invoke(null, $expectation);

        $this->assertSame('banc-renomme', $after['database']);
        $this->assertNotSame($before['database'], $after['database']);
    }

    /**
     * 13. Configuration canonique ABSENTE : le garde s'arme quand meme.
     *
     * FAIL-CLOSED. Ne pas savoir ce qu'on devrait viser n'autorise pas a
     * ecrire : une configuration manquante ne doit pas desarmer un controle en
     * silence — precisement au moment ou l'infrastructure est dans un etat
     * qu'on ne comprend pas.
     */
    public function test_a_missing_canonical_connection_arms_the_guard_rather_than_disarming_it(): void
    {
        // La table est creee AVANT d'armer : une fois le garde en place, meme
        // la preparation du test serait refusee — ce qui est le comportement
        // voulu, mais rendrait le test illisible.
        DB::statement('create table task1367_failclosed (id integer)');

        config(['database.connections.bouclepro_ai_validation' => null]);

        $this->app['env'] = 'ai-validation';
        ArtisanDatabaseGuard::arm($this->app);

        $connection = DB::connection();
        Event::dispatch(new ConnectionEstablished($connection));

        $this->assertSame(1, $this->armedCallbacks($connection));

        try {
            $connection->insert('insert into task1367_failclosed (id) values (1)');
            $this->fail('Le garde aurait du refuser.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('introuvable ou incomplete', $exception->getMessage());
            $this->assertStringContainsString('database.connections.bouclepro_ai_validation', $exception->getMessage());
        }
    }

    /** 14. Configuration INCOMPLETE : meme traitement qu'absente. */
    public function test_an_incomplete_canonical_connection_also_arms_the_guard(): void
    {
        $resolve = new ReflectionMethod(ArtisanDatabaseGuard::class, 'resolve');
        $resolve->setAccessible(true);

        foreach ([
            'base vide' => ['driver' => 'pgsql', 'database' => ''],
            'base absente' => ['driver' => 'pgsql'],
            'pilote absent' => ['database' => 'quelque-chose'],
        ] as $label => $broken) {
            config(['database.connections.bouclepro_ai_validation' => $broken]);

            $this->assertNull(
                $resolve->invoke(null, ['connection' => 'bouclepro_ai_validation']),
                $label,
            );
        }
    }

    /**
     * 15. Configuration canonique COMPLETE et cible conforme : rien ne s'arme.
     *
     * Le pendant positif du fail-closed : le banc de validation, utilise
     * normalement, ne doit jamais etre gene.
     */
    public function test_the_canonical_bench_is_never_guarded_when_it_is_the_real_target(): void
    {
        $connection = DB::connection();

        // On declare la connexion canonique COMME etant celle qu'on vise.
        config(['database.connections.bouclepro_ai_validation' => [
            'driver' => $connection->getDriverName(),
            'database' => $connection->getDatabaseName(),
        ]]);

        $this->app['env'] = 'ai-validation';
        ArtisanDatabaseGuard::arm($this->app);

        Event::dispatch(new ConnectionEstablished($connection));

        $this->assertSame(0, $this->armedCallbacks($connection));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** Une connexion sous un environnement qui en attend une AUTRE. */
    private function armedConnection(): Connection
    {
        $this->app['env'] = 'ai-validation';

        ArtisanDatabaseGuard::arm($this->app);

        return DB::connection();
    }

    private function armedCallbacks(Connection $connection): int
    {
        $property = new ReflectionProperty(Connection::class, 'beforeExecutingCallbacks');
        $property->setAccessible(true);

        return count($property->getValue($connection));
    }
}
