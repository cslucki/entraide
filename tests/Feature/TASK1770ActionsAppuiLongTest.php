<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * TASK-1770 — les actions d'un message passent derriere un appui long.
 *
 * Mesure du 06/11 a 390 x 844, au navigateur : le pied de bulle qui porte les
 * cinq boutons coute **28 px par message** (24 de rangee + 4 de `mt-1`), soit
 * **840 px** sur les trente messages charges — plus qu'un ecran entier, le fil
 * n'en faisant que 799. Premier poste de gaspillage vertical de l'ecran.
 *
 * Le CDC annoncait « ~44 px » : la mesure l'a corrige. On ne code pas sur un
 * chiffre deduit.
 *
 * Ce test rend le COMPOSANT, pas une page : c'est lui la surface partagee
 * entre le ChatLoop et la Messagerie, et c'est lui qui doit se comporter
 * pareil des deux cotes.
 */
class TASK1770ActionsAppuiLongTest extends TestCase
{
    private function bulle(string $props = ''): string
    {
        return $this->blade(
            '<x-conversation.message-bubble type="received" :message-id="$id" :show-reply-button="true"
                :show-copy-button="true" :show-pin-button="true" :show-reactions="true" '.$props.'>Bonjour</x-conversation.message-bubble>',
            ['id' => 'msg-1770'],
        )->__toString();
    }

    /**
     * La rangee permanente ne se rend plus sur telephone — et le pied qui ne
     * portait qu'elle disparait ENTIER. Le masquer a moitie laisserait ses
     * 4 px de `mt-1` sur chacun des trente messages.
     */
    public function test_la_rangee_permanente_est_une_surface_de_bureau(): void
    {
        $html = $this->bulle();

        $this->assertSame(1, preg_match('/<div class="(ml-auto[^"]*)"/', $html, $rangee),
            'la rangee des actions est rendue');
        $this->assertStringContainsString('hidden', $rangee[1],
            'elle ne doit plus couter de hauteur sur telephone');
        $this->assertStringContainsString('md:flex', $rangee[1],
            'et elle revient a partir de 768 px : le bureau ne perd rien');

        $this->assertSame(1, preg_match('/<div class="mt-1 ([^"]*)"/', $html, $pied));
        $this->assertStringContainsString('hidden md:flex', $pied[1],
            'un pied qui ne porte que les actions disparait entier');
    }

    /**
     * La feuille existe, elle se nomme, et chaque action y est en TOUTES
     * LETTRES. La rangee qu'elle remplace etait cinq icones muettes de 24 px.
     */
    /**
     * TASK-1771 a remplace la feuille basse par la BARRE DU HAUT, sur le
     * modele du « contextual action bar » d'Android que WhatsApp applique.
     *
     * Ce que ce test defend n'a pas bouge : la bulle doit DECLARER ce qu'elle
     * permet, sinon la barre n'a rien a proposer. Le MOYEN, lui, a change —
     * et c'est la bulle qui porte desormais la declaration, pas un menu.
     */
    public function test_la_bulle_declare_ce_qu_elle_permet(): void
    {
        $html = $this->bulle();

        // `@js()` echappe les guillemets en `\u0022` : chercher `&quot;`
        // passerait A VIDE et ce test annoncerait vert sans rien mesurer.
        foreach (['reply', 'copy', 'pin'] as $action) {
            $this->assertStringContainsString($action.'\u0022:true', $html,
                "la bulle doit declarer l'action « {$action} » a la barre");
        }
        $this->assertStringContainsString('edit\u0022:false', $html,
            'et declarer FAUX ce qu\'elle ne permet pas, plutot que de l\'omettre');

        $this->assertStringContainsString('selectionner()', $html,
            'l\'appui long entre en mode selection');
    }

    /**
     * Les quatre gardes de l'appui long, et la cinquieme qui compte autant.
     *
     * `touchmove` est celle dont l'absence se paierait le plus cher : sans
     * elle, un defilement un peu lent ouvre la feuille toutes les deux
     * secondes. Et la garde `closest(...)` laisse un lien du message etre un
     * lien — sans elle, un appui long dessus ouvrirait notre feuille au lieu
     * du menu du navigateur.
     */
    public function test_l_appui_long_porte_toutes_ses_gardes(): void
    {
        $html = $this->bulle();

        foreach (['x-on:touchstart', 'x-on:touchend', 'x-on:touchmove', 'x-on:touchcancel'] as $garde) {
            $this->assertStringContainsString($garde, $html, "la garde {$garde} manque");
        }

        $this->assertStringContainsString("closest('button,a,input,textarea,select')", $html,
            'un appui long sur un lien du message doit rester un appui long sur un lien');
    }

    /**
     * Les deux gardes nees de la RECETTE du 06/11.
     *
     * Constat de Cyril : « quand on scrolle, sans faire expres, on appuie sur
     * les messages et on declenche le menu ». Un minuteur annule par
     * `touchmove` ne suffisait pas, pour deux raisons distinctes :
     *
     *  1. le navigateur emet `contextmenu` DE LUI-MEME sur un appui prolonge,
     *     et ce handler n'avait aucune condition : il ouvrait la feuille meme
     *     quand le doigt avait bouge ;
     *  2. le fil defile dans un conteneur — une fois l'inertie lancee, le
     *     defilement ne passe plus par `touchmove` du tout. Et `scroll` ne
     *     remonte pas : il faut la phase de CAPTURE pour l'attraper.
     *
     * Les deux chemins repondent au meme drapeau, `pressAnnule`.
     */
    public function test_un_defilement_annule_l_appui_en_cours(): void
    {
        $html = $this->bulle();

        $this->assertStringContainsString('x-on:scroll.window.capture', $html,
            'sans la phase de capture, un defilement de conteneur n\'est jamais vu');

        $this->assertStringContainsString('if (! pressAnnule) selectionner()', $html,
            '`contextmenu` ne doit selectionner que si l\'appui est encore valide');
        $this->assertStringContainsString('contextmenu.prevent', $html,
            'mais il reste TOUJOURS neutralise : sinon le menu natif s\'ouvre par-dessus le notre');

        // Le seuil de mouvement : en dessous c'est un doigt pose qui tremble,
        // au-dessus c'est une intention de defiler.
        $this->assertStringContainsString('> 10 || Math.abs', $html,
            'le seuil de 10 px doit exister : annuler au moindre pixel rendrait l\'appui long inatteignable');
    }

    /** Une bulle sans aucune action n'embarque aucun geste. */
    public function test_une_bulle_sans_action_n_embarque_rien(): void
    {
        $html = $this->blade(
            '<x-conversation.message-bubble type="received">Bonjour</x-conversation.message-bubble>',
        )->__toString();

        // `selectionner()` reste DEFINIE dans le `x-data` — une methode qui
        // existe ne coute rien. Ce qui compte, c'est qu'aucun evenement ne
        // l'appelle : ni geste, ni ecoute de la barre.
        $this->assertStringNotContainsString('x-on:touchstart', $html);
        $this->assertStringNotContainsString('bp-selection-action.window', $html);
    }
}
