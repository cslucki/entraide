<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * TASK-1772 (recette Cyril du 06/11, telephone) — trois defauts du mode
 * selection de T1771, et le modele WhatsApp qui les tranche.
 *
 *   1. « si on clique en dehors du message, le message est deselectionne » ;
 *   2. « les outils du haut sont toujours relatifs au premier message » :
 *      la barre doit dire DE QUI est le message selectionne ;
 *   3. « le bouton repondre ne fonctionne pas » : la citation se posait a
 *      l'autre bout de l'ecran, sans clavier. Repondre = le champ prend le
 *      focus, DANS le geste (hors du geste, Android n'ouvre pas le clavier).
 */
class TASK1772ModeSelectionRecetteTest extends TestCase
{
    private function bulle(array $vars = []): string
    {
        return $this->blade(
            '<x-conversation.message-bubble type="received" :message-id="$id" :name="$nom" :show-reply-button="true">Bonjour</x-conversation.message-bubble>',
            ['id' => 'msg-1772', 'nom' => 'Habiba Ounaha'] + $vars,
        )->__toString();
    }

    public function test_un_appui_hors_de_toute_rangee_sort_de_la_selection(): void
    {
        $barre = $this->blade('<x-conversation.selection-bar />')->__toString();
        $bulle = $this->bulle();

        $this->assertStringContainsString('x-on:click.window="$store.bpSelection.fermerSiDehors($event.target)"', $barre,
            'la barre ecoute la FENETRE : un appui qui ne vient ni d\'une rangee ni d\'elle ferme');
        $this->assertStringContainsString('data-selection-bar', $barre);
        $this->assertStringContainsString('data-bp-selectable', $bulle,
            'la rangee se declare selectionnable : le store ignore les appuis qui en viennent');

        $js = file_get_contents(base_path('resources/js/app.js'));
        $this->assertStringContainsString("closest('[data-bp-selectable], [data-selection-bar]')", $js);
    }

    public function test_la_barre_nomme_l_auteur_du_message_selectionne(): void
    {
        $barre = $this->blade('<x-conversation.selection-bar />')->__toString();
        $bulle = $this->bulle();

        $this->assertMatchesRegularExpression('/data-selection-author[^>]*x-text="\$store\.bpSelection\.auteur/', $barre);
        // La bulle TRANSMET son nom au store, en 4e argument de `basculer`.
        $this->assertMatchesRegularExpression('/basculer\(\s*this\.id,.*?\'Habiba Ounaha\'/s', $bulle,
            'sans le nom, passer d\'un message a l\'autre ne change que l\'anneau');
    }

    public function test_repondre_depuis_la_barre_donne_le_focus_au_composeur_dans_le_geste(): void
    {
        $bulle = $this->bulle();

        $this->assertMatchesRegularExpression(
            "/if \(action === 'reply'\) \{.*?\\\$dispatch\('reply-to-message'.*?document\.querySelector\('\[data-composer-input\]'\)\?\.focus\(\)/s",
            $bulle,
            'le focus est SYNCHRONE dans la branche reply, pas dans un $nextTick'
        );
        // Et la selection est refermee AVANT l'action : un echec en route ne
        // laisse plus la barre « scotchee ».
        $corps = substr($bulle, strpos($bulle, 'executerAction(action) {'));
        $this->assertLessThan(
            strpos($corps, "if (action === 'reply')"),
            strpos($corps, '$store.bpSelection.fermer()'),
            'fermer() vient AVANT la premiere branche d\'action'
        );

        $composeur = file_get_contents(resource_path('views/components/conversation/composer.blade.php'));
        $this->assertStringContainsString('data-composer-input', $composeur, 'le champ que le focus vise existe');
    }

    /**
     * Recette telephone : « on peut scroller horizontalement, ce n'est pas
     * normal ». Le bouton de reaction, en absolu a 1,75 rem hors de la bulle,
     * depasse du bord sur une bulle recue a sa largeur maximale (402 px de
     * contenu pour 390). Le fil ne defile que verticalement, par contrat.
     */
    public function test_le_fil_ne_defile_pas_horizontalement(): void
    {
        $html = $this->blade('<x-conversation.message-list :has-messages="true" :auto-scroll="false">x</x-conversation.message-list>')->__toString();

        $this->assertMatchesRegularExpression('/class="[^"]*\boverflow-y-auto\b[^"]*\boverflow-x-hidden\b/', $html);
    }

    /**
     * Recette (7) : « agrandir la police, reduire l'interlignage, aerer les
     * bulles ». Le corps passe a 16 px / 20 px au telephone (WhatsApp), le
     * bureau garde `text-sm`. Et `whitespace-pre-wrap` disparait : il doublait
     * les retours a la ligne du Markdown (`<br />\n`) et laissait une ligne
     * blanche de 20 px au bas de CHAQUE bulle (le `\n` final de CommonMark).
     */
    public function test_le_corps_de_la_bulle_est_a_16_px_sur_20_au_telephone_et_sans_pre_wrap(): void
    {
        $bulle = $this->bulle();
        $corps = substr($bulle, strpos($bulle, 'bp-bulle-markdown') - 200, 500);

        $this->assertMatchesRegularExpression('/class="[^"]*\bbp-bulle-markdown\b[^"]*\btext-\[16px\] leading-5 md:text-sm\b/', $corps);
        $this->assertStringNotContainsString('whitespace-pre-wrap', $corps,
            'le Markdown porte deja ses <br> et ses <p> : pre-wrap les doublait');
    }
}
