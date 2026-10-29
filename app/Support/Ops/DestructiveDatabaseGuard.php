<?php

namespace App\Support\Ops;

use App\Console\Commands\AiValidationResetCommand;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;

/**
 * TASK-1722 — aucune reconstruction de base, nulle part, sauf sur une base de
 * test ou une intention declaree.
 *
 * ## L'incident du 28/10, explique
 *
 * La base de developpement `bouclepro` a ete reconstruite : 320 migrations en
 * batch 1, toutes les donnees metier perdues. Le geste declencheur a ete
 * mesure, pas suppose :
 *
 *   php artisan test --env=testing   ->  APP_ENV=local | pgsql | bouclepro
 *   php artisan test                 ->  APP_ENV=testing | sqlite | :memory:
 *
 * Passer `--env=testing` fait retomber `APP_ENV` sur **local**. C'est
 * contre-intuitif, et c'est ce qui a fait tomber les TROIS protections d'un
 * seul coup :
 *
 * 1. `phpunit.xml` et son `DB_CONNECTION=sqlite force="true"` ne sont plus
 *    appliques — la cible resolue est `pgsql` / `bouclepro` ;
 * 2. {@see ArtisanDatabaseGuard} ne connait AUCUNE attente pour `local` : son
 *    modele est la CONTRADICTION entre un environnement annonce et sa cible.
 *    Ici rien n'est annonce, donc rien ne se contredit, donc il ne s'arme
 *    jamais. Il reste juste — il ne couvre simplement pas ce cas ;
 * 3. le garde de `Tests\TestCase::refreshDatabase()` est ECRASE des que la
 *    classe de test ecrit elle-meme `use RefreshDatabase;` — le trait insere
 *    sa methode dans l'enfant, qui l'emporte sur celle du parent. Mesure :
 *    **683 des 849 classes** de ce depot sont dans ce cas, dont celle qui a
 *    ete lancee 25 fois ce jour-la.
 *
 * ## Le modele de CE garde : la CIBLE, pas l'environnement
 *
 * L'autre garde demande « ce que tu annonces correspond-il a ce que tu
 * vises ? ». Celui-ci ne demande rien a l'environnement, qui s'est revele
 * etre precisement la variable qui ment. Il regarde la BASE VISEE, et rien
 * d'autre. Un environnement ne peut donc plus le desarmer en se taisant.
 *
 * ## Un seul point d'interception, qui couvre les trois vecteurs
 *
 * `RefreshDatabase` ET `DatabaseMigrations` reconstruisent tous deux en
 * appelant `artisan('migrate:fresh')`. Un appel direct
 * `Artisan::call('db:wipe')` passe par la meme porte. Ecouter
 * `CommandStarting` les attrape donc tous les trois — y compris depuis un
 * test, ou le trait de l'enfant a deja neutralise le garde de `TestCase`.
 *
 * ## Ce qui N'EST PAS garde, et pourquoi
 *
 * `migrate` et `migrate:rollback` ne figurent PAS dans la liste. Ce sont les
 * gestes ordinaires du deploiement et des workflows de jumeau : les bloquer
 * casserait la PROD et la procedure de release. Seule la famille « on repart
 * de zero » est visee.
 *
 * La liste est donc COURTE et FERMEE a dessein. Elle ne cherche pas a couvrir
 * toute ecriture — c'est le role de {@see ArtisanDatabaseGuard}, qui garde au
 * niveau de `Connection::run()`. Les deux politiques sont complementaires :
 * l'une attrape la contradiction d'environnement, l'autre la destruction de
 * masse quel que soit l'environnement.
 */
final class DestructiveDatabaseGuard
{
    /**
     * L'accuse d'INTENTION pour reconstruire une base qui n'est pas une base
     * de test.
     *
     * Il vit dans l'environnement du processus qui sait ce qu'il fait — la
     * reconstruction deliberee d'un jumeau jetable — et nulle part ailleurs.
     * Surtout pas dans `.env` : un marqueur permanent recreerait exactement le
     * desarmement silencieux que ce garde existe pour empecher. Meme
     * discipline que `ArtisanDatabaseGuard::APPROVAL_MARKER`.
     */
    public const APPROVAL_MARKER = 'BOUCLEPRO_DESTRUCTIVE_DB_APPROVED';

    /**
     * Les commandes qui DETRUISENT l'ensemble du schema.
     *
     * @var list<string>
     */
    private const DESTRUCTIVE = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
    ];

    /**
     * Le suffixe qui DESIGNE une base de test.
     *
     * `bouclepro_test` est la base de la CI PostgreSQL, et c'est deja le seul
     * nom que `Tests\TestCase` accepte. On reconnait la famille par son
     * suffixe plutot que par une liste de noms : un futur `xxx_test` est
     * couvert sans qu'on ait a y penser, et aucune base de travail ne porte ce
     * suffixe.
     */
    private const TEST_SUFFIX = '_test';

    public static function arm(Application $app): void
    {
        if (! $app->runningInConsole()) {
            return;
        }

        self::armerLeBanc();

        Event::listen(CommandStarting::class, static function (CommandStarting $event) use ($app): void {
            if (! in_array((string) $event->command, self::DESTRUCTIVE, true)) {
                return;
            }

            $connexion = self::connexionVisee($app, $event->input);
            $reglage = self::reglageResolu($connexion);
            $base = (string) ($reglage['database'] ?? '');
            $pilote = (string) ($reglage['driver'] ?? '');

            if (self::estAutorisee($pilote, $base)) {
                return;
            }

            if (in_array($connexion, self::$intentions, true)) {
                return;
            }

            if (self::approuve()) {
                return;
            }

            throw new RuntimeException(self::message((string) $event->command, $connexion, $pilote, $base));
        });
    }

    /**
     * LA couche qui ferme l'incident du 28/10 : sous PHPUnit, aucune ecriture
     * n'atteint une base qui n'est pas une base de test.
     *
     * ## Pourquoi celle-ci, en plus de l'ecoute des commandes
     *
     * Mesure : ni `Artisan::call()` ni `$this->artisan()` ne diffusent
     * `CommandStarting` en processus. L'ecoute des commandes couvre donc la
     * ligne de commande, pas un `migrate:fresh` declenche DEPUIS un test —
     * c'est-a-dire precisement `RefreshDatabase` et `DatabaseMigrations`.
     *
     * On se place donc la ou toute ecriture passe forcement, comme
     * {@see ArtisanDatabaseGuard} : `Connection::beforeExecuting()`.
     *
     * ## Le declencheur n'est PAS `APP_ENV`
     *
     * `php artisan test --env=testing` resout `APP_ENV` a **local** : toute
     * detection fondee sur l'environnement rate exactement le cas qui a
     * detruit la base. `runningUnitTests()` lit `environment('testing')` et
     * rate donc lui aussi.
     *
     * On detecte la PRESENCE DE PHPUNIT dans le processus, qui ne depend
     * d'aucune configuration et qu'aucune option de ligne de commande ne peut
     * faire mentir.
     */
    private static function armerLeBanc(): void
    {
        if (! self::sousPhpunit()) {
            return;
        }

        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event): void {
            $reglage = (new ConfigurationUrlParser)->parseConfiguration($event->connection->getConfig());
            $pilote = (string) ($reglage['driver'] ?? '');
            $base = (string) ($reglage['database'] ?? '');

            if (self::estAutorisee($pilote, $base)) {
                return;
            }

            $event->connection->beforeExecuting(static function (string $requete) use ($base, $pilote): void {
                if (self::estLecture($requete)) {
                    return;
                }

                throw new RuntimeException(
                    "ECRITURE DE BANC REFUSEE — un test vient d'essayer d'ecrire dans « {$base} » "
                    ."(pilote « {$pilote} »), qui n'est pas une base de test.\n\n"
                    .'Un banc ecrit dans sqlite, ou dans une base suffixee « '.self::TEST_SUFFIX." ».\n\n"
                    .'Rappel TASK-1722 : `php artisan test --env=testing` resout APP_ENV a `local` et '
                    .'fait viser la base de TRAVAIL. Employez `bin/test`, qui refuse cette option.'
                );
            });
        });
    }

    /**
     * PHPUnit est-il dans ce processus ?
     *
     * La constante est definie par le binaire PHPUnit lui-meme : elle ne
     * depend ni de `.env`, ni de `phpunit.xml`, ni d'une option de ligne de
     * commande. C'est la seule forme de detection que l'incident du 28/10 ne
     * sait pas contourner.
     */
    private static function sousPhpunit(): bool
    {
        return defined('PHPUNIT_COMPOSER_INSTALL') || class_exists(TestCase::class, false);
    }

    /**
     * Les instructions qui ne mutent RIEN. Meme liste que
     * {@see ArtisanDatabaseGuard}, et pour les memes raisons : le controle de
     * transaction doit passer, sans quoi une simple lecture enveloppee
     * echouerait.
     */
    private static function estLecture(string $requete): bool
    {
        $premier = strtolower(strtok(ltrim($requete), " \t\n\r("));

        return in_array($premier, [
            'select', 'show', 'describe', 'desc', 'explain', 'pragma',
            'set', 'begin', 'start', 'commit', 'rollback', 'savepoint', 'release',
            'use', 'analyze', 'analyse',
        ], true);
    }

    /**
     * La connexion REELLEMENT visee : celle de l'option `--database` quand la
     * commande en porte une, la connexion par defaut sinon.
     *
     * Sans cette lecture, `migrate:fresh --database=pgsql` lance depuis un
     * banc sqlite passerait pour inoffensif.
     */
    private static function connexionVisee(Application $app, ?InputInterface $input): string
    {
        $demandee = null;

        if ($input !== null && $input->hasParameterOption('--database')) {
            $demandee = $input->getParameterOption('--database');
        }

        if (is_string($demandee) && $demandee !== '') {
            return $demandee;
        }

        return (string) config('database.default');
    }

    /**
     * Le reglage de connexion tel que LARAVEL le resoudra, `DB_URL` compris.
     *
     * Lire `config(...database)` seul serait une faille : quand `url` est
     * fourni, c'est lui qui gouverne, et la cle `database` peut designer une
     * tout autre base — voire etre vide. On passe donc par le meme
     * `ConfigurationUrlParser` que la fabrique de connexions, pour garder
     * exactement ce que la base recevra.
     *
     * @return array<string, mixed>
     */
    private static function reglageResolu(string $connexion): array
    {
        $reglage = config("database.connections.{$connexion}");

        if (! is_array($reglage)) {
            // FAIL-CLOSED : une connexion inconnue n'est pas une base de test.
            return [];
        }

        return (new ConfigurationUrlParser)->parseConfiguration($reglage);
    }

    /**
     * sqlite est toujours sur : `:memory:` ne survit pas au processus, et un
     * fichier sqlite de banc est jetable par construction.
     */
    private static function estAutorisee(string $pilote, string $base): bool
    {
        if ($pilote === 'sqlite') {
            return true;
        }

        // `:memory:` ne designe JAMAIS une base serveur, quel que soit le
        // pilote declare. Un reglage qui combine `pgsql` et `:memory:` ne
        // joint rien de reel — c'est l'ecriture d'un banc, ou une connexion
        // fabriquee par un test.
        if ($base === ':memory:') {
            return true;
        }

        return $base !== '' && str_ends_with($base, self::TEST_SUFFIX);
    }

    /**
     * Les connexions pour lesquelles une intention a ete DECLAREE DANS LE
     * CODE, par un workflow officiel qui porte deja sa propre garde.
     *
     * @var list<string>
     */
    private static array $intentions = [];

    /**
     * Declarer qu'une reconstruction est VOULUE sur cette connexion.
     *
     * Reservee aux commandes du depot qui detruisent a dessein et ont deja
     * prouve leur cible — aujourd'hui la seule
     * {@see AiValidationResetCommand}, qui appelle
     * `AiValidationDatabaseGuard::assertSafe()` et demande confirmation a
     * l'operateur avant d'en arriver la.
     *
     * Une methode plutot qu'une liste en dur : l'exception vit alors a cote du
     * geste qui la justifie, et disparait avec lui. Une liste de noms dans ce
     * fichier aurait survecu a la commande qu'elle couvrait.
     */
    public static function declarerIntention(string $connexion): void
    {
        self::$intentions[] = $connexion;
    }

    private static function approuve(): bool
    {
        $valeur = env(self::APPROVAL_MARKER);

        return $valeur === '1' || $valeur === 1 || $valeur === true || $valeur === 'true';
    }

    private static function message(string $commande, string $connexion, string $pilote, string $base): string
    {
        return "RECONSTRUCTION REFUSEE — « {$commande} » viserait la base « {$base} » "
            ."(connexion « {$connexion} », pilote « {$pilote} »), qui n'est pas une base de test.\n\n"
            .'Une base de test porte le suffixe « '.self::TEST_SUFFIX." » ou emploie sqlite.\n\n"
            .'Si cette destruction est VOULUE — reconstruction d\'un jumeau jetable — declarez-le pour '
            ."cette commande seulement :\n\n"
            .'    '.self::APPROVAL_MARKER."=1 php artisan {$commande} ...\n\n"
            ."Ne posez JAMAIS ce marqueur dans `.env` : il desarmerait ce garde en permanence.\n"
            .'Contexte : TASK-1722, apres la reconstruction accidentelle de `bouclepro` le 28/10.';
    }
}
