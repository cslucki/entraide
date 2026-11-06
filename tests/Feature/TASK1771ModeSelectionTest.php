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

    /** « On clique sur un message, il change de couleur. » */
    public function test_le_message_selectionne_change_de_couleur(): void
    {
        $html = $this->blade(
            '<x-conversation.message-bubble type="received" :message-id="$id" :show-reply-button="true">Bonjour</x-conversation.message-bubble>',
            ['id' => 'msg-1771'],
        )->__toString();

        $this->assertStringContainsString('bg-[var(--bp-primary)]/10', $html,
            'la RANGEE entiere se teinte, comme WhatsApp');
        $this->assertStringContainsString('ring-2 ring-[var(--bp-primary)]', $html,
            'et la bulle porte l\'anneau, pour dire lequel exactement');
    }
}
