<?php

namespace App\Services\Blog;

/**
 * TASK-1785 — la garde SSRF de l'importateur RSS.
 *
 * ## Ce qu'elle refuse, et pourquoi la chaine ne suffit pas
 *
 * Le CDC l'exige explicitement : refuser localhost, les IP privees, les
 * metadonnees Cloud, les schemas interdits et les redirections dangereuses,
 * **« y compris apres resolution DNS »**.
 *
 * Cette derniere precision est tout le sujet. Un attaquant n'ecrit pas
 * `http://127.0.0.1/` — il publie un nom de domaine bien forme, sous son
 * controle, dont l'enregistrement A pointe sur `169.254.169.254`. Une garde
 * qui ne lit que la CHAINE la laisse passer : le nom ne ressemble a rien
 * d'interdit. C'est pourquoi `estAutorisee()` resout le nom et juge les
 * ADRESSES obtenues, jamais seulement le texte saisi.
 *
 * ## La meme garde s'applique aux redirections
 *
 * Un flux peut repondre `302` vers `http://169.254.169.254/`. Le client HTTP
 * doit donc rappeler cette garde a CHAQUE saut, et non une seule fois sur
 * l'URL de depart. `RssFeedFetcher` ne delegue pas les redirections au
 * transport pour cette raison precise.
 *
 * ## Fail-closed
 *
 * Un nom qui ne resout pas est REFUSE, pas « accepte par defaut ». Une
 * resolution vide ne prouve pas l'innocuite, elle prouve seulement qu'on ne
 * sait pas — et on ne tire pas sur une adresse qu'on ne sait pas qualifier.
 */
class RssFeedUrlGuard
{
    /** Seuls schemas admis. `file://`, `gopher://`, `dict://` et le reste sont refuses. */
    public const SCHEMAS_ADMIS = ['http', 'https'];

    /**
     * Plages interdites, en notation CIDR.
     *
     * `filter_var(FILTER_FLAG_NO_PRIV_RANGE)` ne suffit pas : il ignore
     * `169.254.0.0/16`, qui porte justement les metadonnees d'instance chez
     * AWS, GCP et Azure — la cible la plus rentable d'un SSRF sur une
     * application hebergee. C'est le cas que cette liste existe pour couvrir.
     *
     * @var list<string>
     */
    public const PLAGES_INTERDITES = [
        '0.0.0.0/8',        // « ce reseau »
        '10.0.0.0/8',       // prive
        '100.64.0.0/10',    // CGNAT
        '127.0.0.0/8',      // boucle locale
        '169.254.0.0/16',   // lien-local — METADONNEES CLOUD
        '172.16.0.0/12',    // prive
        '192.0.0.0/24',     // affectations de protocole
        '192.168.0.0/16',   // prive
        '198.18.0.0/15',    // bancs de mesure
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserve
    ];

    /**
     * @return array{autorisee: bool, raison: string|null, adresses: list<string>}
     */
    public function examiner(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'])) {
            return $this->refus('url_malformee');
        }

        // Le SCHEMA d'abord : `file:///etc/passwd` n'a pas d'hote, et le
        // refuser pour « url malformee » dirait une chose fausse sur une URL
        // parfaitement formee — simplement interdite.
        if (! in_array(strtolower($parts['scheme']), self::SCHEMAS_ADMIS, true)) {
            return $this->refus('schema_interdit');
        }

        if (! isset($parts['host']) || $parts['host'] === '') {
            return $this->refus('url_malformee');
        }

        // `parse_url()` rend un littéral IPv6 AVEC ses crochets (`[::1]`), et
        // `filter_var()` les refuse. Sans ce retrait, `http://[::1]/` tombait
        // dans la resolution DNS et n'etait refuse que par le fail-closed, en
        // annoncant « resolution_impossible » — une raison fausse. Pire : un
        // littéral IPv6 PUBLIC et legitime aurait ete refuse pour la meme
        // mauvaise raison.
        $hote = trim($parts['host'], '[]');

        // `user:pass@` n'a rien a faire dans un flux, et c'est un vecteur
        // classique de confusion d'hote cote parseurs permissifs.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return $this->refus('identifiants_dans_l_url');
        }

        // Un hote litteral deja interdit : on tranche sans resoudre.
        if (filter_var($hote, FILTER_VALIDATE_IP) !== false) {
            return $this->jugerAdresses([$hote]);
        }

        // Les noms qui ne sortent pas de la machine, refuses avant toute
        // resolution — un resolveur local peut tres bien les servir.
        $nom = strtolower(rtrim($hote, '.'));
        if ($nom === 'localhost' || str_ends_with($nom, '.localhost') || str_ends_with($nom, '.local')) {
            return $this->refus('hote_local');
        }

        // LA resolution. C'est ici que la garde cesse de lire du texte et
        // commence a juger des adresses.
        $adresses = $this->resoudre($nom);

        if ($adresses === []) {
            // Fail-closed : ne pas savoir n'est pas une autorisation.
            return $this->refus('resolution_impossible');
        }

        return $this->jugerAdresses($adresses);
    }

    public function estAutorisee(string $url): bool
    {
        return $this->examiner($url)['autorisee'];
    }

    /**
     * Resolution A et AAAA. Surchargeable en test pour ne dependre d'aucun
     * resolveur reel — le CDC interdit les appels reseau dans les tests, et
     * une resolution DNS en est un.
     *
     * @return list<string>
     */
    protected function resoudre(string $nom): array
    {
        $adresses = [];

        foreach ([DNS_A, DNS_AAAA] as $type) {
            $reponses = @dns_get_record($nom, $type) ?: [];

            foreach ($reponses as $r) {
                $adresses[] = $r['ip'] ?? $r['ipv6'] ?? null;
            }
        }

        return array_values(array_filter($adresses));
    }

    /**
     * @param  list<string>  $adresses
     * @return array{autorisee: bool, raison: string|null, adresses: list<string>}
     */
    private function jugerAdresses(array $adresses): array
    {
        foreach ($adresses as $ip) {
            // UNE seule adresse interdite suffit a refuser l'URL entiere. Un
            // nom qui resout a la fois vers une adresse publique et vers
            // `127.0.0.1` est un nom qu'on ne veut pas appeler : le choix de
            // l'adresse effective ne nous appartient pas.
            if (! $this->adresseAutorisee($ip)) {
                return [
                    'autorisee' => false,
                    'raison' => 'adresse_interdite',
                    'adresses' => $adresses,
                ];
            }
        }

        return ['autorisee' => true, 'raison' => null, 'adresses' => $adresses];
    }

    private function adresseAutorisee(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (self::PLAGES_INTERDITES as $cidr) {
                if ($this->dansLaPlage($ip, $cidr)) {
                    return false;
                }
            }

            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $binaire = inet_pton($ip);

            if ($binaire === false) {
                return false;
            }

            // `::1` (boucle), `fe80::/10` (lien-local), `fc00::/7` (unique
            // local), `::` (non specifiee).
            if ($binaire === inet_pton('::1') || $binaire === inet_pton('::')) {
                return false;
            }

            $premier = ord($binaire[0]);
            $second = ord($binaire[1]);

            if ($premier === 0xfe && ($second & 0xc0) === 0x80) {
                return false; // fe80::/10
            }

            if (($premier & 0xfe) === 0xfc) {
                return false; // fc00::/7
            }

            // Une IPv4 habillee en IPv6 (`::ffff:127.0.0.1`) doit etre jugee
            // comme l'IPv4 qu'elle est, sinon elle contourne toute la liste.
            if (str_starts_with($ip, '::ffff:') || str_contains($ip, '.')) {
                $v4 = substr($ip, strrpos($ip, ':') + 1);

                if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    return $this->adresseAutorisee($v4);
                }
            }

            return true;
        }

        return false;
    }

    private function dansLaPlage(string $ip, string $cidr): bool
    {
        [$reseau, $bits] = explode('/', $cidr);

        $ipLong = ip2long($ip);
        $reseauLong = ip2long($reseau);

        if ($ipLong === false || $reseauLong === false) {
            return false;
        }

        $masque = $bits === '0' ? 0 : (-1 << (32 - (int) $bits)) & 0xFFFFFFFF;

        return ($ipLong & $masque) === ($reseauLong & $masque);
    }

    /** @return array{autorisee: bool, raison: string, adresses: list<string>} */
    private function refus(string $raison): array
    {
        return ['autorisee' => false, 'raison' => $raison, 'adresses' => []];
    }
}
