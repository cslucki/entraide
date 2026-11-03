<?php

namespace App\Support\Pwa;

/**
 * TASK-1737 — a quel point BouclePro insiste pour etre installee.
 *
 * ## Trois modes, et pas de quatrieme
 *
 * - `off`           : rien ne s'affiche. Le KILL-SWITCH.
 * - `banner`        : bandeau bas non bloquant. **Le defaut.**
 * - `install_first` : ecran plein format sur les pages d'entree.
 *
 * Le CDC (section 3.6) en proposait un quatrieme, `experimental_strict`. Il
 * **n'existe pas ici**, et l'arbitrage DEC-03 de Cyril l'a confirme le
 * 31/10/2026. Deux raisons, dans cet ordre :
 *
 * 1. **Aucune API ne dit « cette PWA est installee » depuis un onglet.**
 *    `getInstalledRelatedApps()` ne repond que pour une application NATIVE
 *    apparentee declaree au manifest, pas pour la PWA elle-meme. Le seul fait
 *    observable est « je tourne en standalone MAINTENANT ». Un verrou
 *    bloquerait donc aussi les personnes qui ont installe et ouvrent un lien
 *    dans leur navigateur.
 * 2. **Sur iOS, la PWA et Safari ont des conteneurs de stockage separes.** Le
 *    verrou bloquerait la session ou la personne est connectee, en l'invitant a
 *    en creer une autre. On casserait la seule chose qui marchait.
 *
 * Un drapeau qu'on ne doit jamais activer est du code mort qui porte un
 * risque : il n'est donc pas ecrit.
 *
 * ## Pourquoi `banner` est le defaut et non `install_first`
 *
 * Mesure du 31/10/2026 : `organization.home` est declaree au sitemap en
 * **priorite 1.0, changefreq daily**, et `robots.txt` l'autorise. C'est la
 * surface d'acquisition numero un du produit — et un interstitiel plein ecran
 * sur une page d'atterrissage de recherche mobile est precisement le motif que
 * Google penalise.
 *
 * Une variable d'environnement absente ne doit donc pas poser un interstitiel
 * sur la vitrine publique sans que personne ne l'ait demande. `install_first`
 * se DECLARE, pour la recette et pour la mesure (CDC 3.8).
 *
 * ## On ne compare jamais a une constante en litteral
 *
 * `GuestShellDisplayMode` (TASK-1441/1500) porte la lecon, et elle a coute une
 * TASK : `shell_first_rail` retombait sur le chemin `overlay` partout ou
 * quelqu'un avait ecrit `=== SHELL_FIRST`. Les predicats ci-dessous existent
 * pour que le meme oubli soit impossible ici.
 */
final class PwaInstallPolicy
{
    /** Rien ne s'affiche. Kill-switch. */
    public const OFF = 'off';

    /** Bandeau bas, non bloquant, le contenu reste lisible. */
    public const BANNER = 'banner';

    /** Ecran plein format sur les pages d'entree. */
    public const INSTALL_FIRST = 'install_first';

    public const MODES = [self::OFF, self::BANNER, self::INSTALL_FIRST];

    /** Les modes qui affichent QUELQUE CHOSE. */
    public const MODES_VISIBLES = [self::BANNER, self::INSTALL_FIRST];

    public const DEFAULT = self::BANNER;

    public static function isValid(mixed $mode): bool
    {
        return is_string($mode) && in_array($mode, self::MODES, true);
    }

    /**
     * Le mode effectif, jamais une valeur inconnue.
     *
     * Une variable d'environnement mal orthographiee retombe sur le DEFAUT, pas
     * sur `off` : eteindre la fonction en silence parce qu'on a tape
     * `PWA_INSTALL_POLICY=baner` serait la pire des reponses — personne ne
     * verrait jamais que le reglage ne prend pas.
     */
    public static function resolve(mixed $mode): string
    {
        return self::isValid($mode) ? $mode : self::DEFAULT;
    }

    /** Quelque chose doit-il s'afficher ? */
    public static function isVisible(mixed $mode): bool
    {
        return in_array(self::resolve($mode), self::MODES_VISIBLES, true);
    }

    /**
     * L'ecran plein format est-il demande sur les pages d'ENTREE ?
     *
     * `/installer` ne passe pas par ici : cette route est plein format par
     * nature, c'est sa raison d'etre. Voir `PwaInstallContext`.
     */
    public static function showsFullScreenOnEntry(mixed $mode): bool
    {
        return self::resolve($mode) === self::INSTALL_FIRST;
    }

    /** Le mode courant, lu dans la configuration. */
    public static function current(): string
    {
        return self::resolve(config('pwa.install.policy'));
    }
}
