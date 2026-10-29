<?php

namespace App\Support\Ops;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Psr\Http\Message\RequestInterface;
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
 * - **HTTP** — `Http::globalRequestMiddleware()` refuse toute destination non
 *   locale AVANT emission, et `globalOptions()` valide chaque REDIRECTION :
 *   sans cela, un hote local renvoyant un 302 vers l'exterieur ferait sortir
 *   la requete par le suivi de redirection de Guzzle, hors du middleware.
 *   Le tripwire de TASK-1280 (`HttpTransportIsolationTest`) etablit que TOUT
 *   l'egress de `app/` passe par cette facade : la couverture est donc
 *   complete tant qu'il reste vert.
 *
 * - **COURRIEL** — le transport distant est REMPLACE par
 *   {@see RefusingTransport}. Voir son docblock : `MessageSending` ne porte pas
 *   le nom du mailer, un garde pose la ne couvrirait pas
 *   `Mail::mailer('ses')`.
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

    /** Les transports qui n'atteignent jamais le reseau. */
    private const TRANSPORTS_INOFFENSIFS = ['log', 'array', 'failover-local'];

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

        if (in_array($hote, ['localhost', '::1', '0.0.0.0'], true) || str_ends_with($hote, '.localhost')) {
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

    private static function garderHttp(string $base): void
    {
        Http::globalRequestMiddleware(static function (RequestInterface $requete) use ($base) {
            $hote = $requete->getUri()->getHost();

            if (! self::hoteEstLocal($hote)) {
                throw new RuntimeException(self::messageHttp($hote, $base));
            }

            return $requete;
        });

        // Une redirection sort du middleware : Guzzle la suit lui-meme. Sans
        // ce controle, un hote local repondant 302 vers l'exterieur ferait
        // sortir la requete malgre tout.
        Http::globalOptions([
            'allow_redirects' => [
                'max' => 5,
                'strict' => true,
                'referer' => false,
                'protocols' => ['http', 'https'],
                'track_redirects' => false,
                'on_redirect' => static function ($requete, $reponse, $uri) use ($base): void {
                    if (! self::hoteEstLocal($uri->getHost())) {
                        throw new RuntimeException(self::messageHttp($uri->getHost(), $base, true));
                    }
                },
            ],
        ]);
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
