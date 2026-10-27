<?php

namespace App\Services\Security;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TASK-1713 — valide un jeton Cloudflare Turnstile aupres de Siteverify.
 *
 * ## Pourquoi la validation serveur n'est pas optionnelle
 *
 * Le widget rend un jeton dans un champ cache `cf-turnstile-response`. Ce
 * jeton, seul, ne prouve RIEN : il voyage dans un POST que n'importe qui peut
 * fabriquer. La seule preuve est l'echange serveur-a-serveur avec Cloudflare,
 * qui dit a la fois « ce jeton est authentique », « il a ete emis pour CE
 * site » et « il n'a pas deja ete depense ». Un captcha dont on ne verifie pas
 * le jeton cote serveur est un ornement.
 *
 * ## Fail-closed, et pourquoi c'est tenable
 *
 * Cloudflare injoignable, delai depasse, HTTP non-2xx, corps illisible, cle
 * secrete absente : la verification **echoue**. Le choix inverse ferait d'une
 * panne de tiers une porte ouverte silencieuse — c'est le trou exact que cette
 * TASK ferme.
 *
 * Ce n'est pas un piege parce qu'un coupe-circuit a UNE variable existe :
 * `TURNSTILE_ENABLED=false` eteint le captcha sans deploiement de code. En
 * incident, on eteint ; on ne laisse pas passer en silence.
 *
 * ## Le drapeau ne se desactive jamais tout seul
 *
 * `enabled()` rend le DRAPEAU, pas `drapeau && cles presentes`. Lecon de
 * TASK-1652 : une garde conditionnee a une configuration CONNUE ne garde rien.
 * Drapeau actif et secret manquant est une erreur d'exploitation, pas une
 * permission de laisser entrer — d'ou `missing-secret` et un journal, jamais un
 * repli silencieux.
 *
 * ## Ce qui n'est jamais journalise
 *
 * Ni la cle secrete, ni le jeton. Un jeton est a usage unique, mais il reste un
 * materiel d'authentification : le poser dans un fichier de log le rend lisible
 * a qui lit les logs. Seuls les `error-codes` rendus par Cloudflare, qui sont
 * des libelles fermes et publics, sont journalises.
 */
class TurnstileVerifier
{
    /**
     * Le captcha est-il actif pour cet environnement ?
     *
     * Rend le drapeau SEUL, deliberement : voir le docblock de classe.
     */
    public function enabled(): bool
    {
        return (bool) config('services.turnstile.enabled', false);
    }

    /**
     * La cle publique a poser dans le widget, ou `null`.
     *
     * Elle n'est pas secrete — Cloudflare la publie dans le HTML — mais une
     * cle absente ne doit pas produire un `data-sitekey=""` : un widget vide
     * echoue sans dire pourquoi. La vue teste donc ce `null`.
     */
    public function siteKey(): ?string
    {
        $cle = config('services.turnstile.site_key');

        return is_string($cle) && $cle !== '' ? $cle : null;
    }

    /**
     * Verifie un jeton. Rend la liste des motifs d'echec, VIDE si le jeton est
     * valide.
     *
     * La forme « liste de motifs » et non un booleen est ce qui permet a la
     * regle de validation de distinguer un captcha REFUSE (l'humain rejoue le
     * defi) d'une panne de verification (l'humain renvoie le formulaire) —
     * deux messages differents, parce que deux gestes differents.
     *
     * @return list<string> codes d'erreur, `[]` si valide
     */
    public function verify(?string $jeton): array
    {
        if (! is_string($jeton) || trim($jeton) === '') {
            return ['missing-input-response'];
        }

        $secret = config('services.turnstile.secret_key');

        if (! is_string($secret) || $secret === '') {
            // Drapeau actif sans secret : erreur d'exploitation. On la rend
            // VISIBLE et on refuse — sans jamais imprimer de valeur de cle.
            Log::error('TASK-1713 Turnstile actif sans cle secrete : inscription refusee (fail-closed).', [
                'turnstile_enabled' => $this->enabled(),
                'site_key_present' => $this->siteKey() !== null,
            ]);

            return ['missing-secret'];
        }

        try {
            $reponse = Http::asForm()
                ->timeout((int) config('services.turnstile.timeout', 5))
                ->post((string) config('services.turnstile.verify_url'), [
                    'secret' => $secret,
                    'response' => $jeton,
                    // `remoteip` est volontairement ABSENT. Optionnel chez
                    // Cloudflare, il impose que l'IP vue par Laravel soit
                    // exactement celle qui a resolu le defi. En local le
                    // navigateur est sur l'hote et le serveur dans WSL derriere
                    // nginx ; en PROD `config/trustedproxy.php` vaut `null` et
                    // Laravel Cloud est auto-detecte. Une mauvaise valeur
                    // produirait des refus d'inscriptions LEGITIMES
                    // indiagnosticables, sans rien ajouter : le jeton est deja
                    // a usage unique et lie au site.
                ]);
        } catch (ConnectionException) {
            // Delai depasse, DNS, TLS. Pas de `$e->getMessage()` dans le
            // journal : il peut porter l'URL complete de la requete.
            Log::warning('TASK-1713 Turnstile injoignable : inscription refusee (fail-closed).');

            return ['verification-unavailable'];
        }

        if (! $reponse->successful()) {
            Log::warning('TASK-1713 Turnstile a rendu un statut inattendu : inscription refusee (fail-closed).', [
                'status' => $reponse->status(),
            ]);

            return ['verification-unavailable'];
        }

        $corps = $reponse->json();

        if (! is_array($corps) || ! array_key_exists('success', $corps)) {
            Log::warning('TASK-1713 Turnstile a rendu un corps illisible : inscription refusee (fail-closed).');

            return ['verification-unavailable'];
        }

        if ($corps['success'] === true) {
            return [];
        }

        $codes = array_values(array_filter(
            (array) ($corps['error-codes'] ?? []),
            fn ($code) => is_string($code) && $code !== '',
        ));

        // Cloudflare peut repondre `success: false` sans aucun code. Rendre un
        // tableau vide signifierait « valide » : on substitue un motif.
        return $codes !== [] ? $codes : ['invalid-input-response'];
    }
}
