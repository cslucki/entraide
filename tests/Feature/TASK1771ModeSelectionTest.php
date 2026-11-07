<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * TASK-1771 — le mode selection, a la WhatsApp.
 *
 * Recette du 06/11 : la feuille basse de T1770 n'etait pas le bon modele.
 * WhatsApp applique le « contextual action bar » d'Android — un appui long
 * SELECTIONNE, le message change de couleur, et la barre du haut est
 * REMPLACEE par une barre d'actions.
 *
 * Regles retenues de la documentation Android :
 *   - l'appui long entre en selection ;
 *   - une fois dedans, un SIMPLE appui suffit ;
 *   - le retour, ou la touche Echap, en sortent ;
 *   - l'element selectionne est surligne, et le surlignage part avec la barre.
 *
 * Arbitrage Cyril : UNE seule selection a la fois. Le compteur et les actions
 * par lot demanderaient un etat serveur dans les deux fils.
 */
class TASK1771ModeSelectionTest extends TestCase
{
    private function barre(): string
    {
        return $this->blade('<x-conversation.selection-bar />')->__toString();
    }

    /**
     * La barre ne sait RIEN faire, et c'est ce qui lui permet de servir les
     * deux fils.
     *
     * Les composants Livewire du ChatLoop et de la Messagerie n'exposent pas
     * les memes methodes — `deleteMessage` n'existe que sur `LoopChat`. Une
     * barre qui appellerait `$wire` directement aurait exige de les aligner,
     * ou de rendre deux barres differentes.
     */
    public function test_la_barre_delegue_tout_a_la_bulle(): void
    {
        $html = $this->barre();

        $this->assertStringNotContainsString('wire:click', $html,
            'la barre n\'appelle aucune methode Livewire');
        $this->assertStringNotContainsString('$wire.', $html);
        $this->assertStringContainsString("\$dispatch('bp-selection-action'", $html,
            'elle emet, et la bulle selectionnee execute avec ses propres gestionnaires');
    }

    /**
     * Elle est en HAUT, elle remplace la barre existante, et elle sort du
     * conteneur qui defile.
     */
    public function test_la_barre_remplace_celle_du_haut_sur_telephone(): void
    {
        $html = $this->barre();

        $this->assertStringContainsString('x-teleport="body"', $html,
            'un panneau en absolu place dans une bande qui defile est rogne (T1124)');
        $this->assertStringContainsString('fixed inset-x-0 top-0', $html,
            'elle recouvre la barre du haut, elle ne s\'ajoute pas a elle');
        $this->assertStringContainsString('md:hidden', $html,
            'au-dessus de 768 px la rangee permanente de la bulle est toujours la : '
            .'deux surfaces pour un meme geste rouvriraient le defaut de T1621');

        $this->assertStringContainsString('$store.bpSelection.fermer()', $html);
        $this->assertStringContainsString('keydown.escape.window', $html);
        $this->assertStringContainsString('data-selection-exit', $html);
    }

    /**
     * Aucune action n'est affichee d'office : la barre lit ce que la bulle a
     * declare. C'est la bulle qui connait les droits.
     */
    public function test_chaque_action_est_conditionnee_a_ce_que_la_bulle_declare(): void
    {
        $html = $this->barre();

        foreach (['reply', 'pin', 'copy', 'edit', 'delete'] as $action) {
            $this->assertStringContainsString('$store.bpSelection.actions.'.$action, $html,
                "l'action « {$action} » doit etre conditionnee a la declaration de la bulle");
        }
    }

    /**
     * Le geste est RUDIMENTAIRE, et c'est tout l'interet.
     *
     * Trois recettes ont echoue sur l'appui long, chaque correctif fermant le
     * defaut du precedent et en ouvrant un autre : le defilement declenchait
     * le menu ; puis 500 ms courait contre la selection de texte d'iOS, qui
     * emet `touchcancel` et gagnait ; puis le `click` de synthese qui suit un
     * appui long deselectionnait aussitot.
     *
     * Un `click` n'a aucun de ces problemes : le navigateur ne l'emet pas
     * apres un defilement. Ce test interdit le retour de l'appareillage.
     */
    public function test_aucun_appareillage_d_appui_long_ne_revient(): void
    {
        $html = $this->blade(
            '<x-conversation.message-bubble type="received" :message-id="$id" :show-reply-button="true">Bonjour</x-conversation.message-bubble>',
            ['id' => 'msg-1771'],
        )->__toString();

        // Pas `setTimeout` en general : la copie en emploie un legitimement
        // (le « Copie ! » qui s'efface). On nomme les pieces de l'appui long.
        foreach (['touchstart', 'touchmove', 'touchcancel', 'contextmenu', 'pressTimer'] as $piece) {
            $this->assertStringNotContainsString($piece, $html,
                "« {$piece} » appartient a l'appui long : il ne doit pas revenir");
        }

        $this->assertStringContainsString('x-on:click=', $html, 'la selection se fait au clic');
        $this->assertStringContainsString("closest('a,button,input,textarea,select,[role=button]')", $html,
            'un lien dans un message doit rester un lien');
    }

    /** « On clique sur un message, il change de couleur. » */
    public function test_le_message_selectionne_change_de_couleur(): void
    {
        $html = $this->blade(
            '<x-conversation.message-bubble type="received" :message-id="$id" :show-reply-button="true">Bonjour</x-conversation.message-bubble>',
            ['id' => 'msg-1771'],
        )->__toString();

        // En STYLE EN LIGNE, et c'est le coeur du test : une classe Tailwind
        // batie sur un jeton (`bg-[var(--bp-primary)]/10`) rend une couleur
        // INVALIDE — donc transparente — sans lever la moindre erreur. Elle
        // avait ete livree telle quelle et le surlignage ne se voyait pas.
        // TASK-1772 : la teinte a quitte la RANGEE pour la bulle (halo
        // exterieur), parce que la gouttiere teintee faisait partie de la
        // cible et qu'un appui a cote reselectionnait au lieu de sortir.
        $this->assertStringContainsString('color-mix(in srgb, var(--bp-primary)', $html,
            'la marque passe par un style en ligne, hors de portee de la purge');
        $this->assertStringContainsString('box-shadow: inset 0 0 0 2px var(--bp-primary)', $html,
            'anneau interieur : il dit LEQUEL des deux messages colles est pris');
        $this->assertStringNotContainsString('bg-[var(--bp-primary)]/', $html,
            'aucune opacite Tailwind sur un jeton : elle ne peint rien (T1475)');
    }
}
