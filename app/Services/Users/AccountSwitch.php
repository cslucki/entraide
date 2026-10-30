<?php

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * TASK-1731 (A5b) — la bascule de session, EXTRAITE de T1730 pour etre
 * partagee.
 *
 * ## Pourquoi une extraction plutot qu'une seconde ecriture
 *
 * A5a bascule apres un mot de passe ; A5b bascule apres un lien et une
 * confirmation recente. Les conditions d'ENTREE different, le geste est le
 * meme — et c'est exactement le geste qu'il ne faut pas ecrire deux fois. Il
 * porte quatre subtilites dont chacune a une raison, et une copie les aurait
 * perdues une par une :
 *
 *  1. `invalidate()` avant tout, et pas seulement `regenerate()`.
 *     `regenerate()` ne change que l'IDENTIFIANT de session ; le contenu
 *     survit. Deux cles le montrent : `auth.password_confirmed_at` ferait
 *     heriter le compte d'arrivee d'une confirmation obtenue sur celui qu'on
 *     quitte, et `url.intended` renverrait vers une page du tenant PRECEDENT.
 *  2. `login()` PUIS `regenerate()` : l'ordre inverse perd la migration de
 *     session que `login()` declenche.
 *  3. `regenerateToken()` : la session videe doit retrouver un jeton CSRF.
 *  4. `canonicalHome()` comme unique autorite de destination — trois branches,
 *     dont l'Organization par defaut servie a la racine SANS prefixe.
 *
 * Verdict MASTER : « reutilisation des controles et de l'isolation de T1730 ».
 * Cette classe EST cette reutilisation.
 */
class AccountSwitch
{
    /**
     * Bascule reellement, et rend l'URL du tenant d'arrivee.
     *
     * A n'appeler qu'APRES avoir autorise la bascule : cette methode ne
     * controle rien, elle execute. Toute condition — mot de passe, lien,
     * confirmation recente, etat du compte cible — doit etre tranchee avant,
     * parce qu'a partir de la premiere ligne la session d'origine n'existe
     * plus.
     */
    public function versLeCompte(User $cible): string
    {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        Auth::login($cible);
        session()->regenerate();

        return canonicalHome($cible->organization);
    }
}
