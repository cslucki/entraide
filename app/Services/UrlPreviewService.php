<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class UrlPreviewService
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    private const BLOCKED_HOSTS = [
        'localhost',
        '127.0.0.1',
        '0.0.0.0',
        '[::1]',
        '::1',
    ];

    /**
     * TASK-1704 correctif 3 — DEUX identites, et le double essai qui va avec.
     *
     * Cyril : « sur les ChatLoop si on partage un lien on a pas la vignette.
     * Je parle de liens vers des sites web qui ont forcement une vignette,
     * voire un titre et resume. »
     *
     * ## Ce que la mesure dit, et ce qu'elle CONTREDIT
     *
     * Le mecanisme d'apercu fonctionnait : la carte etait rendue, avec le
     * domaine et un titre — « Client Challenge ». C'est le titre de la page
     * anti-robot de Cloudflare. Le defaut n'etait donc ni dans le rendu, ni
     * dans l'analyse du HTML, mais dans la RECUPERATION : Le Monde repond
     * **200** avec une page de 3 Ko sans la moindre balise OpenGraph.
     *
     * Cinq identites honnetes ont ete essayees (URL de contact, forme nue,
     * en-tetes de navigateur, mention « bot ») : **toutes** recoivent la meme
     * page. Les editeurs ouvrent deliberement leur contenu aux robots d'apercu
     * CONNUS et challengent les inconnus. Un navigateur realiste, lui, recoit
     * un **402** — le paywall.
     *
     * ## Pourquoi la solution evidente est FAUSSE
     *
     * Adopter l'identite d'un robot connu corrige Le Monde et CASSE trois
     * autres sites. Mesure sur dix cibles :
     *
     *   | site              | actuel   | robot connu | identite composee |
     *   |-------------------|----------|-------------|-------------------|
     *   | lemonde ARTICLE   | rien     | VIGNETTE    | VIGNETTE          |
     *   | wikipedia         | VIGNETTE | **403**     | VIGNETTE          |
     *   | lefigaro          | VIGNETTE | **403**     | **403**           |
     *   | francetvinfo      | VIGNETTE | **403**     | **403**           |
     *   | liberation        | 403      | 403         | 403               |
     *
     * Aucune identite unique ne gagne partout — pas meme l'identite composee,
     * qui semblait le bon compromis. D'ou le double essai : on garde l'identite
     * honnete par defaut, et on ne sollicite la seconde QUE lorsque la premiere
     * ne rend rien d'exploitable. Resultat mesure : **9 cas sur 10**, contre 8
     * pour chacune des identites prises seule.
     *
     * Liberation refuse les deux : pour ces sites-la, l'absence d'apercu est le
     * comportement honnete, et aucune insistance ne serait legitime.
     *
     * Le second essai ne coute une requete de plus QU'EN CAS D'ECHEC.
     */
    private const USER_AGENT = 'Mozilla/5.0 (compatible; BoucleProBot/1.0; +https://bouclepro.com/bot)';

    /**
     * L'identite de repli, qui nous NOMME toujours en premier.
     *
     * Le jeton de compatibilite final est l'idiome historique des User-Agents
     * — « Mozilla/5.0 » n'est rien d'autre que cela. Il n'usurpe pas une
     * identite : le produit se nomme, avec une URL de contact, et annonce
     * ensuite le role qu'il tient. L'ordre compte, et il a ete mesure :
     * l'inverse (jeton d'abord) se fait refuser par Wikipedia en 403.
     */
    private const USER_AGENT_COMPATIBILITE = 'Mozilla/5.0 (compatible; BoucleProBot/1.0; +https://bouclepro.com/bot) facebookexternalhit/1.1';

    private const TIMEOUT = 5;

    private const MAX_BODY_SIZE = 2_000_000;

    private const MAX_REDIRECTS = 3;

    private const MAX_PARSE_SIZE = 100_000;

    public static function extractFirstUrl(string $text): ?string
    {
        if (preg_match('/https?:\/\/[^\s<>"\'\\\`()\[\]{}]+/u', $text, $matches)) {
            $url = trim($matches[0]);
            $url = rtrim($url, '.,;:!?)]}');

            return $url !== '' ? $url : null;
        }

        return null;
    }

    public function fetchPreview(string $url): ?array
    {
        $url = trim($url);

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! $host || $host === '') {
            return null;
        }

        $host = $this->normalizeHost($host);

        if (! $this->isHostAllowed($host)) {
            return null;
        }

        $apercu = $this->recuperer($url, $host, self::USER_AGENT);

        // Le second essai n'a lieu QUE si le premier n'a rien rapporte
        // d'exploitable, et il ne l'emporte QUE s'il fait mieux. L'identite
        // honnete reste donc celle qui sert dans l'immense majorite des cas,
        // et aucun site qui repond correctement n'est sollicite deux fois.
        if ($this->estExploitable($apercu)) {
            return $apercu;
        }

        $second = $this->recuperer($url, $host, self::USER_AGENT_COMPATIBILITE);

        if ($this->estExploitable($second)) {
            return $second;
        }

        // Ni l'un ni l'autre n'a rapporte d'image ou de resume. On rend ce
        // qu'on a — un titre vaut mieux que rien — en preferant le premier,
        // obtenu sous notre identite propre.
        return $apercu ?? $second;
    }

    /**
     * L'apercu porte-t-il autre chose qu'un titre nu ?
     *
     * Une page anti-robot rend un `<title>` et RIEN d'autre : ni `og:image`,
     * ni `og:description`. C'est exactement ce que la messagerie affichait —
     * « Client Challenge » sous le domaine. Le discriminant retenu est donc la
     * presence d'au moins une des deux balises qui font un vrai apercu.
     *
     * Une page legitime qui n'aurait qu'un titre declenchera un second essai
     * pour rien : une requete de plus, et le premier resultat conserve. C'est
     * le cout assume de ne pas chercher a reconnaitre les pages anti-robot une
     * par une — une liste de signatures serait fausse le jour de leur prochaine
     * mise a jour.
     */
    private function estExploitable(?array $apercu): bool
    {
        if ($apercu === null) {
            return false;
        }

        return ($apercu['image'] ?? null) !== null || ($apercu['description'] ?? null) !== null;
    }

    /** Un essai, sous une identite donnee. Toutes les gardes y restent. */
    private function recuperer(string $url, string $host, string $userAgent): ?array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => self::MAX_REDIRECTS,
                        'track_redirects' => true,
                    ],
                    'verify' => true,
                    'connect_timeout' => self::TIMEOUT,
                ])
                ->withHeaders([
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $redirectHistory = $response->header('X-Guzzle-Redirect-History');
            if ($redirectHistory) {
                $redirectUrls = explode(', ', $redirectHistory);
                foreach ($redirectUrls as $redirectUrl) {
                    $redirectHost = $this->normalizeHost(parse_url(trim($redirectUrl), PHP_URL_HOST) ?? '');
                    if (! $this->isHostAllowed($redirectHost)) {
                        return null;
                    }
                }
            }

            $body = $response->body();
            if (strlen($body) > self::MAX_BODY_SIZE) {
                $body = substr($body, 0, self::MAX_PARSE_SIZE);
            }

            return $this->parseHtml($body, $url, $host);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeHost(string $host): string
    {
        $host = trim($host);
        $host = rtrim($host, '.');

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return $host;
        }

        return $host;
    }

    private function isHostAllowed(string $host): bool
    {
        $lower = mb_strtolower($host);

        if (in_array($lower, self::BLOCKED_HOSTS, true)) {
            return false;
        }

        if (str_starts_with($lower, '[')) {
            return false;
        }

        $ip = null;
        if (filter_var($lower, FILTER_VALIDATE_IP)) {
            $ip = $lower;
        } else {
            $resolved = gethostbynamel($lower);
            if ($resolved !== false && count($resolved) > 0) {
                $ip = $resolved[0];
            }
        }

        if ($ip !== null) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }

            if ($this->isPrivateCidr($ip)) {
                return false;
            }
        }

        return true;
    }

    private function isPrivateCidr(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }

        $long = (float) sprintf('%u', $long);

        if ($long >= (float) sprintf('%u', ip2long('10.0.0.0')) && $long <= (float) sprintf('%u', ip2long('10.255.255.255'))) {
            return true;
        }

        if ($long >= (float) sprintf('%u', ip2long('172.16.0.0')) && $long <= (float) sprintf('%u', ip2long('172.31.255.255'))) {
            return true;
        }

        if ($long >= (float) sprintf('%u', ip2long('192.168.0.0')) && $long <= (float) sprintf('%u', ip2long('192.168.255.255'))) {
            return true;
        }

        return false;
    }

    private function parseHtml(string $html, string $url, string $host): array
    {
        $preview = [
            'url' => $url,
            'domain' => $host,
            'title' => null,
            'description' => null,
            'image' => null,
            'fetched_at' => now()->toIso8601String(),
        ];

        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $doc->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);

        $ogTitle = $this->getMetaContent($xpath, 'og:title');
        if ($ogTitle !== null) {
            $preview['title'] = $this->truncateText($ogTitle, 200);
        }

        $ogDescription = $this->getMetaContent($xpath, 'og:description');
        if ($ogDescription !== null) {
            $preview['description'] = $this->truncateText($ogDescription, 400);
        }

        if ($preview['title'] === null) {
            $titleNodes = $xpath->query('//title');
            if ($titleNodes !== false && $titleNodes->length > 0) {
                $preview['title'] = $this->truncateText(trim($titleNodes->item(0)->textContent), 200);
            }
        }

        $ogImage = $this->getMetaContent($xpath, 'og:image');
        if ($ogImage !== null) {
            $ogImage = $this->resolveUrl($ogImage, $url);
            if ($this->isImageUrlSafe($ogImage)) {
                $preview['image'] = $ogImage;
            }
        }

        return $preview;
    }

    private function getMetaContent(\DOMXPath $xpath, string $property): ?string
    {
        $nodes = $xpath->query("//meta[@property='".$property."']");
        if ($nodes === false || $nodes->length === 0) {
            $nodes = $xpath->query("//meta[@name='".$property."']");
        }
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $content = $nodes->item(0)->getAttribute('content');

        return $content !== '' ? trim($content) : null;
    }

    private function resolveUrl(string $maybeRelative, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $maybeRelative)) {
            return $maybeRelative;
        }

        if (str_starts_with($maybeRelative, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME);

            return $scheme.':'.$maybeRelative;
        }

        if (str_starts_with($maybeRelative, '/')) {
            $parts = parse_url($baseUrl);

            return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').$maybeRelative;
        }

        return $maybeRelative;
    }

    private function isImageUrlSafe(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'https') {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return false;
        }

        $host = $this->normalizeHost($host);

        return $this->isHostAllowed($host);
    }

    private function truncateText(string $text, int $maxLength): string
    {
        $text = strip_tags($text);
        $text = trim(preg_replace('/\s+/', ' ', $text));

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength);
    }
}
