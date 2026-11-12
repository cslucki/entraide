<?php

namespace App\Support\Pwa;

/**
 * TASK-1805 — le nom de la PWA depend de l'ENVIRONNEMENT, jamais du hostname.
 *
 * ## Pourquoi pas le hostname
 *
 * `https://test.laravel` ET n'importe quel tunnel temporaire
 * `https://<sous-domaine-aleatoire>.trycloudflare.com` doivent tous les deux
 * afficher « BoucleDev », sans qu'une configuration soit touchee a chaque
 * redemarrage du tunnel (le sous-domaine change a chaque fois). Un test par
 * hostname (`str_ends_with($host, 'trycloudflare.com')`) serait en plus un
 * test sur une donnee qui arrive AVEC la requete : meme derriere
 * `TRUSTED_PROXIES` (voir `config/trustedproxy.php`), ce n'est jamais plus
 * sur que le signal que le SERVEUR porte deja et qu'aucune requete ne peut
 * influencer — `APP_ENV`.
 *
 * `App\Models\OrganizationInvitation::hostOverrideAllowed()` tranche deja
 * « suis-je en local/testing » exactement de la meme facon, par
 * environnement — meme doctrine, contexte different.
 *
 * ## Pourquoi `resolve()` est une fonction PURE
 *
 * Meme idiome que `App\Support\Pwa\PwaInstallPolicy::resolve()` : prendre
 * l'environnement en PARAMETRE plutot que d'appeler `app()->environment()`
 * a l'interieur rend la fonction testable pour les trois cas (local,
 * production, un environnement futur) sans jongler avec l'environnement reel
 * du processus de test.
 */
final class PwaAppName
{
    public const PRODUCTION_NAME = 'BouclePro';

    public const LOCAL_NAME = 'BoucleDev';

    /**
     * `$override` vient de `PWA_APP_NAME` (`config('pwa.app_name_override')`)
     * — la porte de sortie pour un environnement de preproduction qui
     * voudrait un troisieme nom (ex. `BouclePro STAGING`) sans toucher au
     * code : une valeur non vide gagne, quel que soit `$environment`.
     */
    public static function resolve(?string $override, string $environment): string
    {
        if (filled($override)) {
            return $override;
        }

        return $environment === 'local' ? self::LOCAL_NAME : self::PRODUCTION_NAME;
    }

    /** Le nom effectif, lu dans la configuration et l'environnement reels. */
    public static function current(): string
    {
        return self::resolve(config('pwa.app_name_override'), app()->environment());
    }

    /**
     * TASK-1805 correctif (demande Cyril) — l'icone MARRON
     * (`design/brand/bouclepro-symbol-512_marron.png`) n'est posee QUE sur
     * l'environnement reellement `local`, jamais sur un override de nom
     * (`PWA_APP_NAME`) : une preprod qui se nommerait differemment n'a pas
     * demande une icone differente, et coupler les deux aurait ete une
     * supposition, pas une decision. Le signal reste donc `app()->environment()`
     * directement, pas `current() === self::LOCAL_NAME`.
     */
    public static function isLocal(): bool
    {
        return app()->environment('local');
    }
}
