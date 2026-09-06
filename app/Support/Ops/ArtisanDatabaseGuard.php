<?php

namespace App\Support\Ops;

use App\Support\AiValidation\AiValidationDatabaseGuard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * TASK-1367 — refuser une ecriture quand l'ENVIRONNEMENT ANNONCE contredit la
 * CIBLE REELLE.
 *
 * ## L'incident
 *
 * `APP_ENV=testing php artisan migrate --force` a migre `bouclepro`. Il
 * n'existe aucun `.env.testing` : `APP_ENV=testing` ne charge donc rien de
 * particulier et retombe sur `.env` -> `pgsql` -> `bouclepro`. Le
 * `DB_CONNECTION=sqlite force="true"` de `phpunit.xml` ne vaut que pour le
 * runner PHPUnit, jamais pour une commande lancee a la main.
 *
 * ## Ce qu'on protege, et ce qu'on NE protege PAS
 *
 * On ne protege PAS une base par son nom. Interdire `bouclepro` bloquerait un
 * developpeur qui migre son propre poste — usage parfaitement legitime.
 *
 * On protege contre une CONTRADICTION : quelqu'un a ecrit « testing » et la
 * connexion resolue n'est pas une base de test. Cette situation n'a aucun usage
 * legitime, et c'est exactement la classe d'erreur observee.
 *
 * Consequence directe : sans contradiction, ce garde n'existe pas. Un
 * `php artisan migrate` normal ne declare aucun environnement particulier, donc
 * ne se contredit pas, donc n'est jamais gene. PHPUnit annonce `testing` ET
 * force sqlite : cohérent, donc rien ne s'arme — la CI ne change pas d'un
 * octet.
 *
 * ## Pourquoi AUCUNE liste de commandes
 *
 * Une liste de commandes « mutatives » aurait rate l'essentiel : ce depot porte
 * QUINZE commandes maison, dont plusieurs ecrivent (chargement de packs,
 * reinitialisations, indexations, seeds de prompts). Il aurait fallu la tenir a
 * jour a chaque ajout, et une omission serait passee inapercue.
 *
 * On se place donc au seul endroit ou toute ecriture passe forcement :
 * `Connection::run()`, via `beforeExecuting()`. Verifie empiriquement — ce
 * crochet voit `create`, `alter`, `insert`, `update`, `delete` ET `select`,
 * parce que `insert()` delegue a `statement()` et `update()`/`delete()` a
 * `affectingStatement()`, qui passent tous par `run()`.
 *
 * Le garde ne connait donc aucune commande, et les couvre toutes — y compris
 * celles qui n'existent pas encore.
 *
 * ## Fail-CLOSED sur la classification, et c'est sans risque ICI
 *
 * Une instruction non reconnue est traitee comme une ECRITURE. Ce choix serait
 * dangereux dans un garde toujours actif ; il ne l'est pas ici, parce que ce
 * code ne s'execute QUE dans une situation deja anormale. Dans le cas
 * legitime, il n'inspecte jamais la moindre instruction.
 */
final class ArtisanDatabaseGuard
{
    /**
     * Le marqueur d'INTENTION pour une base de test qui n'est pas sqlite.
     *
     * Ce n'est PAS une configuration : c'est un accuse d'execution. Il vit dans
     * l'environnement du processus qui sait ce qu'il fait — le workflow
     * PostgreSQL de la CI, un futur runner local sur serveur — et nulle part
     * ailleurs. Surtout pas dans `.env`, `.env.example` ou `config/` : un
     * marqueur permanent recreerait exactement le desarmement silencieux que ce
     * garde existe pour empecher.
     *
     * C'est aussi la raison pour laquelle il est lu par `env()` et non par
     * `config()`. Le passer par la configuration en ferait une valeur qu'on
     * pose une fois et qu'on oublie.
     */
    public const APPROVAL_MARKER = 'BOUCLEPRO_TEST_DB_APPROVED';

    /**
     * Ce que chaque environnement ANNONCE viser.
     *
     * Un environnement absent de cette table ne promet rien : il ne peut donc
     * pas se contredire, et n'est jamais garde. C'est le cas de `local`, de
     * `production` et de toute commande lancee sans `APP_ENV`.
     *
     * Deux formes d'attente :
     *
     * - `driver` : la cible doit utiliser ce pilote. C'est tout ce qu'on peut
     *   exiger de `testing`, qui n'a pas de base nommee — PHPUnit lui donne un
     *   sqlite en memoire.
     *
     * - `connection` : la cible doit etre CELLE QUE LA CONFIGURATION DECRIT
     *   deja sous ce nom. On ne recopie donc aucun nom de base ici : un
     *   renommage legitime de l'infrastructure se propage tout seul, et il n'y
     *   a pas de litteral cache a se rappeler. C'est la meme discipline que
     *   partout ailleurs — une seule verite, a un seul endroit.
     *
     * Le nom de la connexion de validation n'est pas retape ici : il vient de
     * `AiValidationDatabaseGuard` (TASK-1201), qui le porte deja comme
     * identite canonique. Ces deux gardes sont des politiques DIFFERENTES —
     * l'autre est un allowlist opt-in, appele explicitement par la commande de
     * reinitialisation avant ses operations destructrices ; celui-ci est
     * automatique et protege l'operateur. Mais ils parlent de la meme
     * infrastructure, et elle n'a qu'un seul nom.
     *
     * `approval` designe les environnements ou une cible non conforme peut
     * etre RENDUE LEGITIME par une declaration d'intention explicite. Seul
     * `testing` en a une : il n'a pas de cible unique dans ce depot.
     *
     * @var array<string, array{driver?: string, connection?: string, approval?: string}>
     */
    private const EXPECTATIONS = [
        'testing' => ['driver' => 'sqlite', 'approval' => self::APPROVAL_MARKER],
        'ai-validation' => ['connection' => AiValidationDatabaseGuard::ALLOWED_CONNECTION],
    ];

    /**
     * Les prefixes qui ne mutent RIEN.
     *
     * `set`, `begin`, `commit`, `rollback`, `savepoint` et `release` sont du
     * controle de transaction : les bloquer casserait jusqu'aux commandes de
     * lecture, qui s'enveloppent parfois dans une transaction.
     *
     * `with` n'y figure PAS a dessein : une expression de table commune peut
     * preceder un `INSERT`. Dans le doute, on refuse.
     *
     * @var list<string>
     */
    private const READ_ONLY_PREFIXES = [
        'select', 'show', 'describe', 'desc', 'explain', 'pragma',
        'set', 'begin', 'start', 'commit', 'rollback', 'savepoint', 'release',
        'use', 'analyze', 'analyse',
    ];

    /**
     * Arme le garde si, et seulement si, l'environnement annonce quelque chose
     * qu'il ne tient pas.
     *
     * Hors console, rien : ce garde traite une erreur de ligne de commande, pas
     * le trafic applicatif.
     */
    public static function arm(Application $app): void
    {
        if (! $app->runningInConsole()) {
            return;
        }

        $expectation = self::EXPECTATIONS[$app->environment()] ?? null;

        if ($expectation === null) {
            return;
        }

        $environment = (string) $app->environment();

        // Les connexions sont resolues PARESSEUSEMENT : on ne peut pas les
        // inspecter maintenant. On ecoute donc leur etablissement — ce qui
        // garde aussi les connexions secondaires, pas seulement la connexion
        // par defaut.
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($expectation, $environment): void {
            $expected = self::resolve($expectation);

            // FAIL-CLOSED : la configuration canonique manque ou est
            // incomplete alors que cet environnement a ete explicitement
            // demande. On ne sait donc pas ce qu'on devrait viser — et ne pas
            // savoir n'autorise pas a ecrire.
            if ($expected !== null && self::satisfies($event->connection, $expected)) {
                return;
            }

            // L'INTENTION DECLAREE rend la cible legitime.
            //
            // `APP_ENV=testing` ne designe PAS une base unique dans ce depot :
            // la suite locale tourne sur sqlite en memoire, et la CI PostgreSQL
            // sur `bouclepro_test`. La CI ne se contredit donc pas — elle sait
            // parfaitement qu'elle cree et utilise une base serveur de test.
            //
            // On cesse donc de DEVINER ce qu'est une base de test a partir du
            // pilote ou du nom. On demande seulement : si tu declares un
            // environnement de test et que tu vas ecrire dans une vraie base
            // serveur, dis-le. La CI le dit ; l'incident qui a cree cette TASK
            // ne le disait pas.
            if (isset($expectation['approval']) && self::approved($expectation['approval'])) {
                return;
            }

            $event->connection->beforeExecuting(static function (string $query) use ($event, $expected, $expectation, $environment): void {
                if (self::isReadOnly($query)) {
                    return;
                }

                throw new RuntimeException(self::message($event->connection, $expected, $expectation, $environment));
            });
        });
    }

    /**
     * L'attente RESOLUE, ou `null` si la configuration canonique manque.
     *
     * `null` n'est pas « pas d'attente » : c'est un refus de repondre, et
     * l'appelant le traite en FAIL-CLOSED. Une configuration absente ou
     * incomplete desarmerait sinon le controle en silence — c'est-a-dire au
     * moment exact ou l'infrastructure est dans un etat qu'on ne comprend pas.
     *
     * @param  array{driver?: string, connection?: string}  $expectation
     * @return array{driver: string, database: string}|array{driver: string}|null
     */
    private static function resolve(array $expectation): ?array
    {
        if (! isset($expectation['connection'])) {
            return isset($expectation['driver']) ? ['driver' => $expectation['driver']] : null;
        }

        $config = config('database.connections.'.$expectation['connection']);

        if (! is_array($config)) {
            return null;
        }

        $driver = $config['driver'] ?? null;
        $database = $config['database'] ?? null;

        if (! is_string($driver) || $driver === '' || ! is_string($database) || $database === '') {
            return null;
        }

        return ['driver' => $driver, 'database' => $database];
    }

    /**
     * L'intention a-t-elle ete declaree pour CETTE execution ?
     *
     * Tout ce qui n'est pas explicitement vrai vaut non : un marqueur absent,
     * vide, `0` ou `false` ne declare rien.
     */
    private static function approved(string $marker): bool
    {
        return filter_var(env($marker), FILTER_VALIDATE_BOOL);
    }

    /** @param  array{driver: string, database?: string}  $expected */
    private static function satisfies(Connection $connection, array $expected): bool
    {
        if ($connection->getDriverName() !== $expected['driver']) {
            return false;
        }

        return ! isset($expected['database']) || $connection->getDatabaseName() === $expected['database'];
    }

    private static function isReadOnly(string $query): bool
    {
        // Les commentaires SQL en tete masqueraient le verbe : on les retire
        // avant de lire le premier mot.
        $normalized = ltrim(preg_replace('#^\s*(/\*.*?\*/|--[^\n]*\n)+#s', '', $query) ?? $query);

        $verb = mb_strtolower((string) strtok($normalized, " \t\n\r("));

        return in_array($verb, self::READ_ONLY_PREFIXES, true);
    }

    /**
     * Le message dit ce qu'on croyait viser, ce qu'on vise reellement, et
     * comment sortir — sinon le garde deplace le probleme au lieu de le
     * resoudre.
     *
     * @param  array{driver?: string, database?: string}  $expectation
     */
    private static function message(Connection $connection, ?array $expected, array $expectation, string $environment): string
    {
        // La configuration canonique manque : on ne sait pas ce que cet
        // environnement DEVRAIT viser, et ne pas savoir n'autorise pas a ecrire.
        if ($expected === null) {
            $name = $expectation['connection'] ?? '?';

            return "ECRITURE REFUSEE — la connexion canonique de cet environnement est introuvable ou incomplete.\n"
                ."  APP_ENV        : {$environment}\n"
                ."  attendait      : database.connections.{$name} (pilote + base)\n"
                ."  cible reelle   : {$connection->getDatabaseName()} (pilote {$connection->getDriverName()})\n"
                ."\n"
                ."Aucune ecriture n'a eu lieu. Une configuration manquante ne doit pas desarmer\n"
                ."un controle en silence.\n"
                ."\n"
                ."Completez cette connexion, ou lancez la commande sans APP_ENV si la cible est\n"
                .'VOULUE.';
        }

        $wanted = isset($expected['database'])
            ? $expected['database'].' (pilote '.$expected['driver'].')'
            : 'un pilote '.$expected['driver'];

        return "ECRITURE REFUSEE — l'environnement annonce ne correspond pas a la base visee.\n"
            ."  APP_ENV        : {$environment}\n"
            ."  attendu        : {$wanted}\n"
            ."  cible reelle   : {$connection->getDatabaseName()} (pilote {$connection->getDriverName()}, connexion {$connection->getName()})\n"
            ."\n"
            ."Aucune ecriture n'a eu lieu. Cela arrive typiquement quand APP_ENV=testing est\n"
            ."pose en croyant viser une base jetable : il n'existe pas de .env.testing, donc\n"
            ."la configuration retombe sur .env.\n"
            ."\n"
            ."Si la cible est VOULUE, lancez la commande sans APP_ENV, ou avec l'APP_ENV qui\n"
            ."correspond reellement a cette base.\n"
            .(isset($expectation['approval'])
                ? "\nSi vous testez DELIBEREMENT contre une base serveur (c'est le cas de la CI\n"
                    .'PostgreSQL), declarez-le : '.$expectation['approval']."=1\n"
                    .'Ne posez JAMAIS ce marqueur dans .env : il doit rester un accuse d execution.'
                : '');
    }
}
