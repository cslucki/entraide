<?php

namespace App\Support\Ops;

use Illuminate\Support\Facades\Http;

/**
 * TASK-1376 — dire ou partent reellement les emails, sans jamais rien divulguer.
 *
 * ## Le probleme que cette classe resout
 *
 * Depuis l'ecran d'administration, on ne pouvait savoir qu'une chose : le nom du
 * driver. Or « smtp » ne dit pas si l'on parle a un serveur de capture local ou
 * a un vrai relais qui enverra pour de bon. C'est precisement la question qu'on
 * se pose avant de cliquer sur « envoyer ».
 *
 * ## Ce qui n'est JAMAIS expose
 *
 * Ni `MAIL_USERNAME`, ni `MAIL_PASSWORD`, ni aucune cle d'API, **meme masques**.
 * Un masque dit deja qu'un secret existe et quelle est sa longueur ; et un
 * masque se retire par accident au refactor suivant. Cette classe ne lit ces
 * valeurs que pour repondre OUI ou NON a « une authentification est-elle
 * configuree ? », et ne rend jamais la valeur elle-meme.
 *
 * Rien n'est stocke en base non plus : le transport se lit dans la
 * configuration, il ne se recopie pas.
 *
 * ## La sonde est bornee par construction
 *
 * L'URL de MailHog est une CONSTANTE. Aucun fragment ne vient d'une entree
 * utilisateur, d'un parametre de requete ou de la base — sinon cet ecran
 * deviendrait un moyen de faire emettre au serveur des requetes arbitraires
 * depuis l'interieur du reseau.
 *
 * Et la sonde ne part que si deux conditions tiennent ENSEMBLE : l'hote de mail
 * est une adresse de boucle locale, et l'application tourne en environnement
 * local. L'une sans l'autre ne suffit pas.
 */
class MailDiagnostics
{
    /** L'adresse de l'API MailHog. CONSTANTE, jamais construite. */
    private const MAILHOG_API = 'http://127.0.0.1:8025/api/v2/messages';

    /**
     * L'interface, PAR DEFAUT sur la boucle locale.
     *
     * Distincte de l'API ci-dessus, et c'est le point : la sonde part du
     * SERVEUR, l'interface est ouverte par un HUMAIN. Sous WSL, les deux ne
     * sont pas a la meme adresse — `127.0.0.1` vu de Windows designe Windows.
     */
    private const MAILHOG_UI_DEFAUT = 'http://127.0.0.1:8025';

    /** Les hotes consideres comme locaux. */
    private const LOOPBACK = ['127.0.0.1', 'localhost', '::1', '[::1]'];

    /**
     * L'etat du transport, tel qu'un administrateur a besoin de le lire.
     *
     * @return array{environment: string, mailer: string, host: ?string, port: ?int, scheme: ?string, from_address: ?string, from_name: ?string, authenticated: bool, is_local_capture: bool, badge: string}
     */
    public function transport(): array
    {
        $mailer = (string) config('mail.default');
        $host = config('mail.mailers.'.$mailer.'.host');
        $port = config('mail.mailers.'.$mailer.'.port');

        return [
            'environment' => app()->environment(),
            'mailer' => $mailer,
            'host' => $host !== null ? (string) $host : null,
            'port' => $port !== null ? (int) $port : null,
            'scheme' => config('mail.mailers.'.$mailer.'.scheme') ?: null,
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),

            // On ne rend QUE le fait qu'une authentification existe. Jamais la
            // valeur, jamais un masque : un masque revele la longueur, et se
            // retire par accident au refactor suivant.
            'authenticated' => filled(config('mail.mailers.'.$mailer.'.username'))
                || filled(config('mail.mailers.'.$mailer.'.password')),

            'is_local_capture' => $this->isLocalCapture($mailer, $host),
            'badge' => $this->badge($mailer, $host),
        ];
    }

    /**
     * Combien de messages MailHog detient — ou `null` s'il n'est pas joignable.
     *
     * `null` n'est pas une erreur : MailHog eteint est un etat parfaitement
     * normal. L'ecran le dit, il ne s'en alarme pas.
     */
    public function mailhogMessageCount(): ?int
    {
        if (! $this->probeAllowed()) {
            return null;
        }

        try {
            $reponse = Http::timeout(2)->get(self::MAILHOG_API);

            return $reponse->successful() ? (int) ($reponse->json('total') ?? 0) : null;
        } catch (\Throwable) {
            // Injoignable, refuse, trop lent : la reponse est la meme, et elle
            // n'a pas a faire tomber une page d'administration.
            return null;
        }
    }

    /** L'adresse de l'interface MailHog, seulement quand la sonde est permise. */
    public function mailhogUrl(): ?string
    {
        if (! $this->probeAllowed()) {
            return null;
        }

        $url = (string) config('services.mailhog.ui_url', self::MAILHOG_UI_DEFAUT);

        // La valeur vient de la CONFIGURATION, pas d'une requete — mais une
        // configuration se mistype. On refuse tout ce qui n'est pas une adresse
        // http(s) plausible plutot que de rendre un `href` douteux.
        return filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, 'http')
            ? $url
            : self::MAILHOG_UI_DEFAUT;
    }

    /**
     * La sonde est-elle permise ?
     *
     * Les DEUX conditions doivent tenir : environnement local ET hote de mail
     * en boucle locale. Sonder depuis un serveur distant reviendrait a lui faire
     * emettre des requetes vers son propre reseau interne.
     */
    private function probeAllowed(): bool
    {
        return app()->environment('local')
            && $this->isLoopback(config('mail.mailers.'.config('mail.default').'.host'));
    }

    private function isLocalCapture(string $mailer, mixed $host): bool
    {
        return $mailer === 'log' || $this->isLoopback($host);
    }

    private function isLoopback(mixed $host): bool
    {
        return is_string($host) && in_array(strtolower($host), self::LOOPBACK, true);
    }

    /**
     * Le verdict, en trois mots.
     *
     * C'est la seule chose qu'un administrateur presse regardera avant de
     * cliquer, donc elle doit etre juste et sans nuance : soit rien ne sortira
     * de cette machine, soit un vrai relais est actif.
     */
    private function badge(string $mailer, mixed $host): string
    {
        if ($mailer === 'log') {
            return 'SAFE LOCAL / LOG';
        }

        return $this->isLoopback($host) ? 'SAFE LOCAL / MAILHOG' : 'SMTP EXTERNE ACTIF';
    }
}
