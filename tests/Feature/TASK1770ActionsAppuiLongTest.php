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
