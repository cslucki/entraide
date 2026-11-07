<?php

namespace App\Services\Blog;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * TASK-1785 — recuperer le XML d'un flux, sans se faire conduire ailleurs.
 *
 * ## Pourquoi la validation DNS seule ne suffit pas (precaution MASTER n°2)
 *
 * Un garde qui resout le nom, le juge sûr, puis laisse le client HTTP
 * **re-resoudre** au moment de se connecter, laisse une fenetre : entre les
 * deux resolutions, l'enregistrement DNS peut changer. C'est le *DNS
 * rebinding*, et c'est un TOCTOU classique — on verifie une chose, on en
 * contacte une autre.
 *
 * La reponse n'est donc pas « resoudre mieux », c'est **ne pas re-resoudre** :
 * on EPINGLE l'adresse qui vient d'etre validee (`CURLOPT_RESOLVE`) et on s'y
 * connecte, en envoyant le `Host:` d'origine pour que le serveur serve le bon
 * hote virtuel. L'adresse effectivement contactee est alors, par construction,
 * celle qui a ete jugee.
 *
 * ## Les redirections sont suivies A LA MAIN
 *
 * `allow_redirects => false`, et chaque saut repasse par
 * `RssFeedUrlGuard::examiner()` AVANT d'etre suivi. Deleguer les redirections
 * au transport reviendrait a laisser le serveur distant choisir la prochaine
 * adresse contactee — exactement ce que la garde existe pour empecher. Un
 * `302` vers `http://169.254.169.254/` est un scenario, pas une hypothese.
 *
 * ## Borne de taille, et pourquoi elle est lue en FLUX
 *
 * `Content-Length` est declaratif : un serveur hostile annonce 2 Ko et en
 * envoie deux gigaoctets. La limite est donc appliquee sur les octets
 * REELLEMENT recus, en lisant le corps par morceaux, et la lecture s'arrete
 * des que le plafond est franchi.
 *
 * ## Testabilite
 *
 * Tout passe par le client HTTP de Laravel, donc `Http::fake()` intercepte
 * avant tout reseau. Le CDC interdit les appels HTTP reels en test, et la
 * resolution DNS est surchargeable dans la garde pour la meme raison.
 */
class RssFeedFetcher
{
    /** Au-dela, on considere qu'on tourne en rond. */
    public const SAUTS_MAX = 3;

    /** Secondes. Court, comme demande : un flux lent n'est pas une urgence. */
    public const DELAI_CONNEXION = 5;

    public const DELAI_TOTAL = 15;

    /** 5 Mio. Un flux WordPress honnete tient tres largement dedans. */
    public const OCTETS_MAX = 5 * 1024 * 1024;

    public function __construct(
        private readonly RssFeedUrlGuard $garde,
    ) {}

    /**
     * @return array{xml: string, url_finale: string, sauts: list<string>}
     *
     * @throws RuntimeException avec un code stable, jamais un message de transport
     */
    public function recuperer(string $url): array
    {
        $sauts = [];
        $courante = $url;

        for ($saut = 0; $saut <= self::SAUTS_MAX; $saut++) {
            $examen = $this->garde->examiner($courante);

            if (! $examen['autorisee']) {
                // La raison est un CODE STABLE, jamais un message d'exception :
                // il part a l'ecran et doit rester traduisible.
                throw new RuntimeException($examen['raison'] ?? 'url_refusee');
            }

            $sauts[] = $courante;

            $reponse = $this->appeler($courante, $examen['adresses']);
            $statut = $reponse->status();

            if (in_array($statut, [301, 302, 303, 307, 308], true)) {
                $cible = $reponse->header('Location');

                if ($cible === null || $cible === '') {
                    throw new RuntimeException('redirection_sans_cible');
                }

                // Une `Location` relative est resolue contre l'URL courante,
                // sinon elle echapperait a la garde faute de schema et d'hote.
                $courante = $this->absolutiser($courante, $cible);

                continue;
            }

            if ($statut < 200 || $statut >= 300) {
                throw new RuntimeException('flux_injoignable');
            }

            $xml = $this->corpsBorne($reponse);

            return ['xml' => $xml, 'url_finale' => $courante, 'sauts' => $sauts];
        }

        throw new RuntimeException('trop_de_redirections');
    }

    /**
     * @param  list<string>  $adressesVerifiees
     */
    protected function appeler(string $url, array $adressesVerifiees)
    {
        $parts = parse_url($url);
        $hote = trim($parts['host'] ?? '', '[]');
        $port = $parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80);

        $options = [
            // La garde a juge CES adresses : on s'y connecte, sans laisser
            // curl re-resoudre le nom. C'est ce qui ferme la fenetre de
            // rebinding.
            'curl' => [
                CURLOPT_RESOLVE => array_map(
                    fn (string $ip) => "{$hote}:{$port}:{$ip}",
                    $adressesVerifiees,
                ),
                // Ceinture : meme si une redirection echappait au suivi
                // manuel, curl n'en suivrait aucune.
                CURLOPT_FOLLOWLOCATION => false,
            ],
        ];

        return Http::withOptions($options)
            ->withHeaders([
                'Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml',
                'User-Agent' => 'BouclePro-RSS-Import/1.0',
            ])
            ->connectTimeout(self::DELAI_CONNEXION)
            ->timeout(self::DELAI_TOTAL)
            ->withoutRedirecting()
            ->get($url);
    }

    /**
     * Lit le corps en s'arretant au plafond.
     *
     * `Content-Length` n'est PAS cru : il est declaratif, et un serveur
     * hostile annonce peu puis envoie beaucoup. Seuls les octets recus
     * comptent.
     */
    private function corpsBorne($reponse): string
    {
        $flux = $reponse->toPsrResponse()->getBody();
        $morceaux = '';

        while (! $flux->eof()) {
            $morceaux .= $flux->read(8192);

            if (strlen($morceaux) > self::OCTETS_MAX) {
                throw new RuntimeException('flux_trop_volumineux');
            }
        }

        if (trim($morceaux) === '') {
            throw new RuntimeException('flux_vide');
        }

        return $morceaux;
    }

    private function absolutiser(string $base, string $cible): string
    {
        if (parse_url($cible, PHP_URL_SCHEME) !== null) {
            return $cible;
        }

        $parts = parse_url($base);
        $racine = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($cible, '/')) {
            return $racine.$cible;
        }

        $chemin = $parts['path'] ?? '/';

        return $racine.rtrim(dirname($chemin), '/').'/'.$cible;
    }
}
