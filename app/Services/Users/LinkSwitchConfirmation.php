<?php

namespace App\Services\Users;

use App\Models\User;

/**
 * TASK-1731 (A5b) — la confirmation RECENTE du mot de passe du compte source.
 *
 * ## Pourquoi un mecanisme propre, et non `auth.password_confirmed_at`
 *
 * Arbitrage MASTER du 30/10 : **300 secondes**, et non les 10 800 du
 * mecanisme Laravel general — qu'il est explicitement interdit de modifier.
 * Trois heures sont une duree raisonnable pour reconfirmer avant une action
 * sensible dans SON propre compte ; elles sont bien trop larges pour autoriser
 * l'entree dans un AUTRE compte.
 *
 * Reutiliser la cle de Laravel aurait donc oblige a changer sa portee pour
 * tout le monde. On pose la notre, et les deux coexistent sans se gener.
 *
 * ## Deux liens, pas un : l'identite ET la session
 *
 * La confirmation porte l'identifiant du compte qui l'a donnee. Sans cela,
 * elle serait une propriete de la SESSION, et la session change d'occupant a
 * chaque bascule : une confirmation donnee par A autoriserait une bascule
 * depuis B. Le lien a la session, lui, est automatique — la cle vit dans la
 * session, et `session()->invalidate()` la detruit.
 *
 * C'est ce qui rend vraie l'exigence MASTER « non reutilisable apres
 * changement de compte, et invalidee par la transition de session » : apres
 * une bascule reussie, T1730 invalide la session, donc la confirmation
 * disparait et une nouvelle est exigee. Dans la fenetre de cinq minutes, elle
 * ne sert donc qu'a reessayer depuis le MEME compte et la MEME session — par
 * exemple apres un refus.
 */
class LinkSwitchConfirmation
{
    /** Arbitrage MASTER : propre a A5b, independant de `auth.password_timeout`. */
    public const FENETRE_SECONDES = 300;

    private const CLE_INSTANT = 'a5b.link_switch_confirmed_at';

    private const CLE_COMPTE = 'a5b.link_switch_confirmed_for';

    public function poser(User $source): void
    {
        // `now()->timestamp` et non `time()`, et ce n'est pas un detail de
        // style : `time()` est l'horloge du SYSTEME, que l'application ne
        // controle pas. Elle echappe au voyage dans le temps du banc de test,
        // si bien que la fenetre de cinq minutes devenait INVERIFIABLE — un
        // test a tente de la mesurer et la bascule a reussi malgre une
        // confirmation perimee. `now()` est l'horloge de l'application, la
        // meme que celle qu'emploie le middleware `RequirePassword` de
        // Laravel.
        session()->put(self::CLE_INSTANT, now()->timestamp);
        session()->put(self::CLE_COMPTE, (string) $source->getKey());
    }

    public function estRecente(User $source): bool
    {
        $pose = session()->get(self::CLE_INSTANT);
        $pour = session()->get(self::CLE_COMPTE);

        if (! is_int($pose) || ! is_string($pour)) {
            return false;
        }

        if ($pour !== (string) $source->getKey()) {
            // Donnee par un AUTRE compte. Le cas n'est pas theorique : la
            // session survit a une bascule refusee, et devrait survivre a une
            // reussie si T1730 ne l'invalidait pas. On ne s'appuie pas sur
            // cette invalidation pour etre correct — on le verifie ici aussi.
            return false;
        }

        return (now()->timestamp - $pose) <= self::FENETRE_SECONDES;
    }

    /**
     * Oublie la confirmation.
     *
     * Appelee apres une bascule reussie en plus de l'invalidation de session
     * de T1730. Deux ceintures pour la meme idee, et c'est delibere : si un
     * jour la bascule cessait d'invalider la session, cette ligne resterait
     * vraie.
     */
    public function oublier(): void
    {
        session()->forget([self::CLE_INSTANT, self::CLE_COMPTE]);
    }
}
