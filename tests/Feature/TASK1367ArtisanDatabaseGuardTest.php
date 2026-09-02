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
 * ## La regle V1 etait FAUSSE, et c'est la CI qui l'a dit
 *
 * V1 posait « APP_ENV=testing implique sqlite ». La CI PostgreSQL tourne sous
 * `APP_ENV=testing` contre `bouclepro_test` : le garde a bloque ses migrations.
 * Quinze tests verts et huit sabotages n'avaient pas pu le voir, parce qu'ils
 * ENCODAIENT la premisse au lieu de la questionner. Seul un environnement
 * different pouvait la dementir.
 *
 * ## Le contrat V2 : on ne devine plus, on demande
 *
 * `testing` n'a pas de cible unique dans ce depot. On cesse donc de deduire ce
 * qu'est une base de test a partir du pilote ou du nom, et on demande une
 * DECLARATION D'INTENTION :
 *
 *   testing + sqlite                        -> autorise
 *   testing + base serveur, sans marqueur   -> REFUSE
 *   testing + base serveur, avec marqueur   -> autorise
 *
 * La CI PostgreSQL porte le marqueur : elle sait qu'elle ecrit dans une base
 * de test. L'incident qui a cree cette TASK ne le savait pas.
 *
 * ## Ce que ce fichier garde
 *
 * Qu'un garde ne se declenche jamais sur le nom d'une base — cela bloquerait un
 * developpeur qui migre son poste, et un garde-fou qui gene l'usage legitime
 * finit desarme.
 *
 * ## Aucun test ne vise `bouclepro`
 *
 * Contrainte explicite. Les scenarios de refus sont joues en faisant
 * CONTREDIRE une connexion sqlite avec l'attente d'un autre environnement :
 * la contradiction est reelle, la base ne l'est pas.
 */
class TASK1367ArtisanDatabaseGuardTest extends TestCase
{
    /**
     * La configuration de connexion telle qu'elle etait AVANT ce test.
     *
     * `connectionPretendingToBe()` mute la connexion PARTAGEE du processus.
     * Sans restauration, tout test suivant du meme shard heriterait d'une
     * connexion qui se pretend `pgsql` — et le garde, arme sur ce mensonge,
     * refuserait leurs ecritures. C'est exactement ce qui a fait echouer le
     * shard Feature 4/4 en CI : mes tests passaient, et ils empoisonnaient les
     * autres.
     *
     * @var array<string, mixed>|null
     */
    private ?array $originalConnectionConfig = null;

    /**
     * Le marqueur d'approbation tel qu'il etait AVANT ce test, dans les TROIS
     * sources que lit `env()` : `getenv()`, `$_ENV`, `$_SERVER`.
     *
     * Ce fichier efface deliberement le marqueur pour eprouver le refus. En
     * local c'est sans consequence : la suite tourne sur sqlite, l'environnement
     * est coherent, le garde ne s'arme jamais. **En CI PostgreSQL, non.**
     * `ci-postgresql.yml` pose `APP_ENV: testing`, `DB_CONNECTION: pgsql` et le
     * marqueur ; effacer le marqueur sans le rendre laissait le shard avec une
     * contradiction non approuvee, et le garde refusait alors les ecritures de
     * TOUS les tests suivants du meme processus.
     *
     * C'est la cause du shard Feature 4/4 rouge : mes 19 tests passaient, et ils
     * empoisonnaient leurs voisins. D'ou la restauration systematique — un test
     * qui salit l'etat global du processus doit le rendre tel qu'il l'a trouve,
     * meme quand il echoue en cours de route.
     *
     * @var array{env: string|false, superglobal_env: mixed, superglobal_server: mixed}|null
     */
    private ?array $originalMarker = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalMarker = [
            'env' => getenv(ArtisanDatabaseGuard::APPROVAL_MARKER),
            'superglobal_env' => $_ENV[ArtisanDatabaseGuard::APPROVAL_MARKER] ?? null,
            'superglobal_server' => $_SERVER[ArtisanDatabaseGuard::APPROVAL_MARKER] ?? null,
        ];
    }

    protected function tearDown(): void
    {
        $this->restoreConnectionConfig();
        $this->restoreMarker();

        parent::tearDown();
    }

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

    /**
     * 2. Environnement coherent : aucun garde.
     *
     * La coherence est CONSTRUITE, pas empruntee a l'environnement : ce test
     * asserait « le pre-requis : la suite tourne bien sur sqlite », ce qui est
     * vrai sur mon poste et FAUX en CI PostgreSQL. Il rejouait donc, dans son
     * pre-requis, la premisse exacte qui avait rendu la V1 du garde fausse.
     *
     * On fabrique donc la situation coherente au lieu de la supposer, et on
     * retire le marqueur : ainsi zero garde arme ne peut s'expliquer QUE par la
     * coherence, jamais par une approbation ambiante.
     */
    public function test_no_guard_when_the_environment_matches_its_target(): void
    {
        $this->app['env'] = 'testing';
        $this->forgetMarker();

        ArtisanDatabaseGuard::arm($this->app);

        $connection = $this->connectionPretendingToBe('sqlite');
        Event::dispatch(new ConnectionEstablished($connection));

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
    // A bis. L'INTENTION DECLAREE, le coeur du contrat V2
    // =====================================================================

    /**
     * 3 bis. `testing` + base serveur SANS marqueur : le garde s'arme.
     *
     * C'est l'incident d'origine : `APP_ENV=testing php artisan migrate`
     * contre `bouclepro`. Rien ne declarait que cette base serveur etait une
     * cible de test — parce qu'elle ne l'etait pas.
     */
    public function test_a_server_database_under_testing_is_guarded_without_the_marker(): void
    {
        $this->app['env'] = 'testing';
        $this->forgetMarker();

        ArtisanDatabaseGuard::arm($this->app);

        // On simule une cible serveur : le pilote ne satisfait pas `sqlite`.
        $connection = $this->connectionPretendingToBe('pgsql');
        Event::dispatch(new ConnectionEstablished($connection));

        $this->assertWriteRefused($connection);
    }

    /**
     * 3 ter. Le MEME couple, avec l'intention declaree : autorise.
     *
     * C'est la CI PostgreSQL. Elle ecrit deliberement dans `bouclepro_test`,
     * et elle le dit. Sans ce cas, le garde casserait la CI de tout le monde —
     * ce qu'il a effectivement fait en V1.
     */
    public function test_the_same_target_is_allowed_once_the_intent_is_declared(): void
    {
        $this->app['env'] = 'testing';
        $this->declareMarker('1');

        ArtisanDatabaseGuard::arm($this->app);

        $connection = $this->connectionPretendingToBe('pgsql');
        Event::dispatch(new ConnectionEstablished($connection));

        $this->assertSame(0, $this->armedCallbacks($connection));

    }

    /** 3 quater. Un marqueur qui ne declare RIEN ne leve pas le garde. */
    public function test_a_falsy_marker_declares_nothing(): void
    {
        foreach (['0', 'false', ''] as $value) {
            $this->refreshApplication();
            $this->app['env'] = 'testing';
            $this->declareMarker($value);

            ArtisanDatabaseGuard::arm($this->app);

            $connection = $this->connectionPretendingToBe('pgsql');
            Event::dispatch(new ConnectionEstablished($connection));

            $this->assertWriteRefused($connection, 'marqueur = '.var_export($value, true));
        }

    }

    /**
     * 3 quinquies. `ai-validation` n'a PAS de porte de sortie.
     *
     * Le marqueur ne concerne que `testing`, qui n'a pas de cible unique.
     * `ai-validation` en a une, canonique : rien ne justifie de pouvoir la
     * contourner par une variable d'environnement.
     */
    public function test_the_marker_does_not_unlock_ai_validation(): void
    {
        $this->app['env'] = 'ai-validation';
        $this->declareMarker('1');

        ArtisanDatabaseGuard::arm($this->app);

        $connection = DB::connection();
        Event::dispatch(new ConnectionEstablished($connection));

        $this->assertWriteRefused($connection);

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

        // Le pilote attendu est DERIVE de la connexion, jamais ecrit en dur :
        // ce test tournait sur sqlite chez moi et sur pgsql en CI, et un
        // litteral y aurait ete faux dans l'un des deux environnements.
        $driver = $connection->getDriverName();
        $autreDriver = $driver === 'sqlite' ? 'pgsql' : 'sqlite';

        $this->assertTrue($satisfies->invoke(null, $connection, ['driver' => $driver]));
        $this->assertFalse($satisfies->invoke(null, $connection, ['driver' => $autreDriver]));
        $this->assertFalse($satisfies->invoke(null, $connection, ['driver' => $driver, 'database' => 'une-autre-base']));
        $this->assertTrue($satisfies->invoke(null, $connection, ['driver' => $driver, 'database' => $connection->getDatabaseName()]));
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

    /**
     * Une connexion qui se PRESENTE comme un autre pilote.
     *
     * Aucun test ne vise `bouclepro` — contrainte explicite. On teste donc la
     * DECISION du garde sur une connexion sqlite dont on ne change que ce que
     * le garde regarde : le nom du pilote.
     */
    private function connectionPretendingToBe(string $driver): Connection
    {
        $connection = DB::connection();

        $property = new ReflectionProperty(Connection::class, 'config');
        $property->setAccessible(true);

        $config = $property->getValue($connection);

        // Memorise UNE SEULE FOIS : un test peut appeler cette methode
        // plusieurs fois, et c'est l'etat d'origine qu'il faut rendre.
        $this->originalConnectionConfig ??= $config;

        $config['driver'] = $driver;
        $property->setValue($connection, $config);

        return $connection;
    }

    private function restoreConnectionConfig(): void
    {
        if ($this->originalConnectionConfig === null) {
            return;
        }

        $property = new ReflectionProperty(Connection::class, 'config');
        $property->setAccessible(true);
        $property->setValue(DB::connection(), $this->originalConnectionConfig);

        $this->originalConnectionConfig = null;
    }

    /**
     * Le garde refuse-t-il REELLEMENT une ecriture sur cette connexion ?
     *
     * On asserte le COMPORTEMENT, pas le nombre de callbacks armes : ce nombre
     * est un artefact de test. `AppServiceProvider::boot()` appelle deja
     * `arm()`, donc un appel explicite dans un test en enregistre un SECOND.
     * En production `boot()` ne tourne qu'une fois — mais compter aurait fait
     * dependre le test d'un detail qui n'est pas le contrat.
     */
    private function assertWriteRefused(Connection $connection, string $context = ''): void
    {
        try {
            $connection->statement('create table task1367_should_not_exist (id integer)');
            $this->fail('Le garde aurait du refuser cette ecriture. '.$context);
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ECRITURE REFUSEE', $exception->getMessage(), $context);
        }
    }

    /**
     * Declare le marqueur dans les TROIS sources que consulte `env()`.
     *
     * Ecrire seulement `putenv()` et `$_ENV` laissait `$_SERVER` porter la
     * valeur ambiante — celle que `ci-postgresql.yml` pose a `1`. Les tests qui
     * declarent un marqueur FAUX etaient alors silencieusement contredits par
     * l'environnement, et ne pouvaient passer que sur un poste ou le marqueur
     * n'existe pas. C'est le meme angle mort que la premiere version de cette
     * TASK : une propriete vraie dans mon seul environnement.
     */
    private function declareMarker(string $value): void
    {
        putenv(ArtisanDatabaseGuard::APPROVAL_MARKER.'='.$value);
        $_ENV[ArtisanDatabaseGuard::APPROVAL_MARKER] = $value;
        $_SERVER[ArtisanDatabaseGuard::APPROVAL_MARKER] = $value;
    }

    private function forgetMarker(): void
    {
        putenv(ArtisanDatabaseGuard::APPROVAL_MARKER);
        unset($_ENV[ArtisanDatabaseGuard::APPROVAL_MARKER], $_SERVER[ArtisanDatabaseGuard::APPROVAL_MARKER]);
    }

    /**
     * Rend le marqueur exactement tel que le processus le portait a l'entree —
     * y compris « absent », qui est un etat a part entiere et non un `null`.
     */
    private function restoreMarker(): void
    {
        if ($this->originalMarker === null) {
            return;
        }

        $marker = ArtisanDatabaseGuard::APPROVAL_MARKER;
        $value = $this->originalMarker['env'];

        $value === false ? putenv($marker) : putenv($marker.'='.$value);

        if ($this->originalMarker['superglobal_env'] === null) {
            unset($_ENV[$marker]);
        } else {
            $_ENV[$marker] = $this->originalMarker['superglobal_env'];
        }

        if ($this->originalMarker['superglobal_server'] === null) {
            unset($_SERVER[$marker]);
        } else {
            $_SERVER[$marker] = $this->originalMarker['superglobal_server'];
        }

        $this->originalMarker = null;
    }

    private function armedCallbacks(Connection $connection): int
    {
        $property = new ReflectionProperty(Connection::class, 'beforeExecutingCallbacks');
        $property->setAccessible(true);

        return count($property->getValue($connection));
    }
}
