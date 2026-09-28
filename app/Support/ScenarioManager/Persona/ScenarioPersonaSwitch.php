<?php

namespace App\Support\ScenarioManager\Persona;

use App\Models\ScenarioManifestVersion;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * TASK-1654 — le geste d'identite : entrer, sortir, et sortir DE FORCE.
 *
 * ## Pourquoi entree et sortie vivent dans le MEME fichier
 *
 * Parce qu'elles sont une seule symetrie, et qu'une symetrie separee en deux
 * fichiers finit par boiter. La sortie doit defaire exactement ce que l'entree
 * a fait — ni moins (un reliquat de session), ni plus (un logout la ou une
 * restauration suffisait).
 *
 * Deux appelants s'en servent : la sortie explicite demandee par l'operateur,
 * et la sortie FORCEE par la revalidation a chaque requete. Les deux doivent se
 * comporter pareil, et c'est pour cela qu'elles appellent le meme code.
 *
 * ## Le persona devient REELLEMENT `Auth::user()`
 *
 * Aucun « utilisateur effectif » parallele, aucun second guard, aucun cookie
 * supplementaire. Les policies, les services et les scopes doivent voir le
 * persona sans rien savoir du mode : c'est la seule facon de garantir que ce
 * qu'on observe est bien le produit tel que ce persona le vit.
 *
 * L'acteur d'origine ne sert qu'a deux choses — restaurer, et rendre compte.
 * Jamais a contourner un droit.
 *
 * ## La session est regeneree aux DEUX bascules
 *
 * `Auth::login()` migre deja la session ; la regeneration explicite qui suit
 * ferme la question de la fixation de session dans les deux sens, y compris si
 * une version future du framework changeait ce detail. Les DONNEES de session
 * survivent a la migration — c'est ce qui permet d'ecrire le contexte avant la
 * bascule et de le relire apres.
 *
 * ## Aucun credential, nulle part
 *
 * Aucun mot de passe n'est lu, ecrit, compare, genere ou transmis. Aucun jeton
 * n'est emis. Aucune URL ne porte d'identite. La bascule se fait par la session
 * serveur, exactement comme une connexion ordinaire.
 */
final class ScenarioPersonaSwitch
{
    /**
     * Entre dans le monde du persona.
     *
     * L'appelant a DEJA prouve l'eligibilite via {@see ScenarioPersonaAccess}.
     * Cette methode ne refait pas la preuve : elle exige seulement les deux
     * conditions qui portent sur l'ACTEUR et sur l'etat de la session, que
     * l'eligibilite du persona ne dit pas.
     */
    public function entrer(
        User $acteur,
        User $persona,
        ScenarioManifestVersion $version,
    ): void {
        if (! $acteur->is_admin) {
            throw ScenarioPersonaRefused::actorNotPlatformAdmin();
        }

        if (ScenarioPersonaAccess::uneIdentiteEstDejaEmpruntee()) {
            throw ScenarioPersonaRefused::alreadyImpersonating();
        }

        $acces = new ScenarioPersonaAccess;
        $sandbox = $acces->sandboxVivante($version);
        $chargement = $acces->chargementVivant($version);

        // Ecrit AVANT la bascule : les donnees de session survivent a la
        // migration que `login()` declenche, et l'ordre inverse perdrait
        // l'identite de l'acteur au moment ou elle devient irrecuperable.
        ScenarioPersonaAccess::memoriser($acteur, $persona, $sandbox, $version, $chargement);

        Auth::login($persona);
        session()->regenerate();
    }

    /**
     * Quitte le mode persona.
     *
     * Rend `true` si l'acteur d'origine a ete restaure, `false` si la seule
     * issue sure etait la deconnexion complete.
     *
     * ## Pourquoi le privilege est REVALIDE
     *
     * L'etat de depart n'est pas une autorisation conservee. Entre l'entree et
     * la sortie, l'acteur a pu etre desadminise, banni, ou supprime — parfois
     * precisement parce que quelqu'un a decide qu'il ne devait plus avoir ces
     * droits. Restaurer « ce qu'il avait » reviendrait alors a annuler cette
     * decision depuis une session ouverte avant elle.
     *
     * On ne restaure donc pas un privilege memorise : on reverifie un privilege
     * PRESENT. Faute de quoi, la seule issue sure est de tout fermer.
     */
    public function sortir(): bool
    {
        $contexte = ScenarioPersonaAccess::contexte();

        if ($contexte === null) {
            throw ScenarioPersonaRefused::noActivePersonaMode();
        }

        $acteur = User::query()->find($contexte['original_admin_id']);

        // La purge precede la decision : quelle que soit la suite, l'etat
        // persona ne doit plus exister. Le laisser en place pendant qu'on
        // deconnecte fabriquerait une session anonyme qui se croit encore en
        // mode persona.
        ScenarioPersonaAccess::purger();

        if ($acteur === null || ! $acteur->is_admin || $acteur->banned_at !== null) {
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();

            return false;
        }

        Auth::login($acteur);
        session()->regenerate();

        return true;
    }
}
