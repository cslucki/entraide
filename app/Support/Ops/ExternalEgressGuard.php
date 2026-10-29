<?php

namespace App\Support\Ops;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * TASK-1724 — rien ne sort d'un jumeau de PRODUCTION.
 *
 * ## L'incident qui fonde ce garde
 *
 * TASK-1723, reconstruction du jumeau 1.720 : dans les SECONDES suivant sa
 * construction, l'ordonnanceur a enfile trois jobs
 * `DeriveLoopConversationKnowledge` — il voyait de vraies conversations de la
 * PROD a exploiter. Ces jobs appellent l'IA.
 *
 * Les cles par Organization sont neutralisees par `create-prod-baseline.sh`,
 * mais `config/ai.php` lit `OPENROUTER_API_KEY` comme repli de PLATEFORME, et
 * cette cle vit dans `.env` — hors de portee de la baseline. Des conversations
 * reelles seraient parties chez un fournisseur, et facturees : la classe
 * d'incident de TASK-1280, ou treize generations avaient ete reellement
 * payees.
 *
 * Aucun appel n'est parti ce jour-la, mais la protection a tenu a une cle
 * videe A LA MAIN, APRES coup. MASTER : « ne pas considerer l'absence de
 * credentials comme une protection suffisante ».
 *
 * ## Le discriminant est la BASE, jamais `APP_ENV`
 *
 * TASK-1722 l'a mesure : `php artisan test --env=testing` resout `APP_ENV` a
 * **local**. L'environnement est precisement la variable qui ment. On lit donc
 * la base REELLEMENT configuree, `DB_URL` resolu comme Laravel le resoudra.
 *
 * ## Deux surfaces, deux techniques
 *
 * - **HTTP** — un MIDDLEWARE Guzzle unique, pose par `Http::globalMiddleware()`.
 *   Il refuse la destination initiale si elle n'est pas locale, puis
 *   REIMPOSE le controle des redirections quoi que l'appelant ait pose en
 *   options. Voir {@see self::garderHttp()} : le couple
 *   `globalRequestMiddleware` + `globalOptions` du premier jet etait
 *   desactivable par une simple option de requete.
 *   Le tripwire de TASK-1280 (`HttpTransportIsolationTest`) etablit que TOUT
 *   l'egress de `app/` passe par cette facade : la couverture est donc
 *   complete tant qu'il reste vert.
 *
 * - **COURRIEL** — le transport distant est REMPLACE par
 *   {@see RefusingTransport}. Voir son docblock : `MessageSending` ne porte pas
 *   le nom du mailer, un garde pose la ne couvrirait pas
 *   `Mail::mailer('ses')`. La destination est lue comme Laravel la lira,
 *   `MAIL_URL` resolu — voir {@see self::reglageMailEffectif()}.
 *
 * ## Aucun marqueur de desactivation
 *
 * Decision MASTER. Un marqueur general finirait dans un `.env` et desarmerait
 * le garde en permanence — exactement ce qui est arrive au marqueur de
 * TASK-1367. Pour envoyer vraiment, on ne travaille pas sur un jumeau.
 */
final class ExternalEgressGuard
{
    /** Le nom exact du jumeau de travail. */
    private const JUMEAU_EXACT = 'bouclepro_prod_work';

    /**
     * Les familles de jumeaux, par prefixe.
     *
     * Les baselines sont scellees `CONNECTION LIMIT = 0` et ne devraient pas
     * etre servies — mais « ne devrait pas » n'est pas une garde, et une
     * baseline porte exactement les memes donnees reelles.
     *
     * @var list<string>
     */
    private const JUMEAU_PREFIXES = [
        'bouclepro_prod_baseline_',
        'bouclepro_prod_rehearsal_',
    ];

    /**
     * Les transports qui n'atteignent jamais le reseau.
     *
     * `failover-local` a ete RETIRE : il n'existe pas dans Laravel, je l'avais
     * invente. Un nom qui ne correspond a rien aurait pu etre pose un jour
     * dans une configuration en croyant obtenir une garantie.
     *
     * `failover` et `roundrobin` n'y figurent pas non plus : ils DELEGUENT a
     * d'autres mailers, dont la destination depend de leur propre liste. Les
     * traiter comme inoffensifs reviendrait a blanchir ce qu'ils enveloppent.
     */
    private const TRANSPORTS_INOFFENSIFS = ['log', 'array'];

    private static bool $arme = false;

    /**
     * La base visee est-elle un jumeau de PRODUCTION ?
     *
     * Predicat PUR, et public pour cette raison : c'est la decision du garde,
     * et elle doit pouvoir etre prouvee seule, sans armer quoi que ce soit.
     *
     * `DB_URL` est resolu par le meme `ConfigurationUrlParser` que la fabrique
     * de connexions : une url pointant un jumeau ne peut pas se cacher
     * derriere une cle `database` d'apparence anodine.
     *
     * @param  array<string, mixed>  $reglage
     */
    public static function viseUnJumeau(array $reglage): bool
    {
        $base = (string) ((new ConfigurationUrlParser)->parseConfiguration($reglage)['database'] ?? '');

        if ($base === self::JUMEAU_EXACT) {
            return true;
        }

        foreach (self::JUMEAU_PREFIXES as $prefixe) {
            if (str_starts_with($base, $prefixe)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cette destination est-elle locale ?
     *
     * L'adresse de BOUCLAGE seulement. Une plage privee ne suffit pas : une
     * adresse en 10.x ou 192.168.x designe une autre machine du reseau, et
     * « local » doit vouloir dire « cette machine ». Un collecteur place
     * ailleurs se declare en le rapprochant du bouclage, pas en elargissant ce
     * predicat.
     */
    public static function hoteEstLocal(string $hote): bool
    {
        $hote = strtolower(trim($hote, " \t[]"));

        // `0.0.0.0` a ete RETIRE : c'est l'adresse « toutes les interfaces »,
        // une adresse d'ECOUTE, pas de destination. L'admettre revenait a
        // traiter une cible indeterminee comme locale.
        if (in_array($hote, ['localhost', '::1'], true) || str_ends_with($hote, '.localhost')) {
            return true;
        }

        // Le bloc 127.0.0.0/8 — mais seulement si c'est VRAIMENT une adresse.
        //
        // Un `str_starts_with($hote, '127.')` laissait passer
        // `127.0.0.1.exemple.com`, un nom de domaine que n'importe qui peut
        // faire pointer ou il veut. Trouve par la garde « ruse de prefixe »
        // de TASK-1724, pas par relecture.
        if (filter_var($hote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        return str_starts_with($hote, '127.');
    }

    /**
     * Ce mailer atteint-il uniquement la machine locale ?
     *
     * FAIL-CLOSED : un transport inconnu, ou un `smtp` sans hote, est traite
     * comme distant. « En cas de transport ou de destination incertains :
     * refuser l'emission » (MASTER).
     *
     * @param  array<string, mixed>  $reglage
     */
    public static function mailerEstLocal(array $reglage): bool
    {
        $reglage = self::reglageMailEffectif($reglage);
        $transport = strtolower((string) ($reglage['transport'] ?? ''));

        if (in_array($transport, self::TRANSPORTS_INOFFENSIFS, true)) {
            return true;
        }

        if ($transport !== 'smtp') {
            return false;
        }

        $hote = (string) ($reglage['host'] ?? '');

        return $hote !== '' && self::hoteEstLocal($hote);
    }

    /**
     * Le reglage de mailer tel que LARAVEL l'emploiera, `MAIL_URL` compris.
     *
     * Calque exact de `MailManager::getConfig()` (framework 13.7, lignes
     * 527-543) : quand `url` est fourni, il est fusionne par
     * `ConfigurationUrlParser` et son `driver` devient le `transport`.
     *
     * Sans cela, une configuration portant `host = 127.0.0.1` ET
     * `url = smtp://smtp.exemple.com:587` passait pour locale alors que
     * Laravel aurait joint le serveur EXTERNE. Mesure :
     *
     *     declare=127.0.0.1         ->  EFFECTIF=smtp.exemple.com
     *     declare=smtp.exemple.com  ->  EFFECTIF=127.0.0.1
     *
     * L'url gouverne DANS LES DEUX SENS. Lire `host` seul etait donc faux
     * deux fois : trop permissif, et trop strict.
     *
     * @param  array<string, mixed>  $reglage
     * @return array<string, mixed>
     */
    public static function reglageMailEffectif(array $reglage): array
    {
        if (! isset($reglage['url'])) {
            return $reglage;
        }

        $resolu = array_merge($reglage, (new ConfigurationUrlParser)->parseConfiguration($reglage));

        if (isset($resolu['driver'])) {
            $resolu['transport'] = $resolu['driver'];
            unset($resolu['driver']);
        }

        return $resolu;
    }

    public static function arm(Application $app): void
    {
        if (self::$arme) {
            return;
        }

        $connexion = (string) config('database.default');
        $reglage = config("database.connections.{$connexion}");

        if (! is_array($reglage) || ! self::viseUnJumeau($reglage)) {
            return;
        }

        $base = (string) ((new ConfigurationUrlParser)->parseConfiguration($reglage)['database'] ?? '?');

        self::$arme = true;

        self::garderHttp($base);
        self::garderCourriel($base);
    }

    /**
     * UN SEUL crochet, place en MIDDLEWARE Guzzle.
     *
     * ## Pourquoi pas `globalRequestMiddleware` + `globalOptions`
     *
     * Premier jet, et il etait contournable. `Factory::createPendingRequest()`
     * applique `globalOptions` par `withOptions()`, puis toute option POSEE
     * PAR LA REQUETE repasse par `array_replace_recursive`. Or
     * `allow_redirects` ne figure PAS dans `$mergeableOptions` (framework
     * 13.7, ligne 236) : un simple
     *
     *     Http::withOptions(['allow_redirects' => true])->get(...)
     *
     * REMPLACE tout le tableau et fait disparaitre `on_redirect`. Mesure
     * reproduite hors framework, puis confirmee. Le controle des redirections
     * etait donc desactivable par l'appelant.
     *
     * Un middleware, lui, recoit `$options` APRES toutes les fusions. Il voit
     * donc ce que Guzzle va reellement employer, et peut le reecrire.
     *
     * ## Ce qu'il fait, dans l'ordre
     *
     * 1. refuse la destination initiale si elle n'est pas locale ;
     * 2. REIMPOSE le controle des redirections, quoi que l'appelant ait pose.
     *
     * `allow_redirects === false` est respecte : ne pas suivre de redirection
     * est plus sur que d'en suivre, et le reecrire RE-ACTIVERAIT un
     * comportement que l'appelant a explicitement coupe.
     */
    private static function garderHttp(string $base): void
    {
        Http::globalMiddleware(static function (callable $handler) use ($base) {
            return static function ($requete, array $options) use ($handler, $base) {
                $hote = $requete->getUri()->getHost();

                if (! self::hoteEstLocal($hote)) {
                    throw new RuntimeException(self::messageHttp($hote, $base));
                }

                if (($options['allow_redirects'] ?? false) !== false) {
                    $existantes = is_array($options['allow_redirects'] ?? null)
                        ? $options['allow_redirects']
                        : [];

                    $options['allow_redirects'] = array_merge($existantes, [
                        'max' => min(5, (int) ($existantes['max'] ?? 5)),
                        'protocols' => ['http', 'https'],
                        'on_redirect' => static function ($req, $rep, $uri) use ($base): void {
                            if (! self::hoteEstLocal($uri->getHost())) {
                                throw new RuntimeException(self::messageHttp($uri->getHost(), $base, true));
                            }
                        },
                    ]);
                }

                return $handler($requete, $options);
            };
        });
    }

    private static function garderCourriel(string $base): void
    {
        $mailers = config('mail.mailers');

        if (! is_array($mailers)) {
            return;
        }

        Mail::extend('bouclepro-refused', static fn (array $reglage) => new RefusingTransport(
            (string) ($reglage['bouclepro_mailer'] ?? '?'),
            $base,
        ));

        foreach ($mailers as $nom => $reglage) {
            if (is_array($reglage) && self::mailerEstLocal($reglage)) {
                continue;
            }

            config(["mail.mailers.{$nom}" => [
                'transport' => 'bouclepro-refused',
                'bouclepro_mailer' => $nom,
            ]]);
        }
    }

    private static function messageHttp(string $hote, string $base, bool $redirection = false): string
    {
        $quoi = $redirection ? 'une REDIRECTION vers' : 'un appel sortant vers';

        return "SORTIE REFUSEE — {$quoi} « {$hote} », alors que la base configuree est "
            ."« {$base} », un jumeau de PRODUCTION.\n\n"
            .'Un jumeau porte de vraies conversations et de vraies adresses : un appel reel les '
            ."transmettrait a un tiers, et serait facture (incident TASK-1280).\n\n"
            ."Seule l'adresse de bouclage est admise.\n\n"
            .'Contexte : TASK-1724.';
    }
}
