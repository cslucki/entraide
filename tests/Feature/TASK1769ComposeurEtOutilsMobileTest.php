<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1769 — le ChatLoop sur telephone : 227 px de chrome, 27 % de l'ecran.
 *
 * Mesure du 05/11 a 390 x 844, au navigateur : topbar 61 + barre d'outils 63 +
 * rangee des modes 38 + composeur 65. La piste retenue par Cyril ramene le
 * chrome a 108 px en supprimant deux bandes entieres et en raccourcissant la
 * troisieme.
 *
 * Ce que ce test defend, et rien d'autre : les trois bandes qui doivent
 * disparaitre ou maigrir le font VRAIMENT, et le geste qu'elles portaient est
 * recupere ailleurs. Le reste — l'aspect, le centrage reel, le clavier iOS —
 * est une recette navigateur : aucun test serveur ne peut en repondre.
 *
 * Les promesses de produit (porte IA nommee, pastille de mode non vide) sont
 * defendues la ou elles sont nees : `TASK1475LoopMobileAiVisibleTest` et
 * `TASK1621PourContreFlowTest`.
 */
class TASK1769ComposeurEtOutilsMobileTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-1769',
        ]);
        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'preferred_locale' => 'fr',
        ]);

        app()->instance('current_organization', $this->organization);
        $this->loop = (new LoopService)->createLoop($this->membre, 'Entraide sur l\'IA');
    }

    /**
     * Les 63 px de barre d'outils rentrent dans le fil.
     *
     * Elle n'est pas supprimee : elle devient une surface de BUREAU. La
     * constante `TOOLBAR_VISIBLE = 5` n'est pas touchee — c'est elle qui
     * decide ce que le bureau met en avant, et le telephone ne la consulte
     * plus du tout puisque son menu liste tout.
     */
    public function test_la_barre_d_outils_ne_se_rend_plus_sous_768_px(): void
    {
        $html = $this->page();

        // La bande qui enveloppe `chat-tools` porte les deux marqueurs.
        $this->assertSame(1, preg_match(
            '/<div class="(hidden flex-shrink-0 border-b[^"]*)"/', $html, $bande,
        ), 'la bande de la barre d\'outils est rendue');
        $this->assertStringContainsString('md:block', $bande[1],
            'elle doit revenir a partir de 768 px : le bureau ne perd rien');
    }

    /**
     * Le menu ⋮ existe, il se nomme, et il liste TOUS les outils.
     *
     * Pas de coupure a cinq, pas de depliant « N autres », pas de defilement
     * horizontal muet : trois mecanismes pour une seule liste, c'etait le
     * defaut n.2 de l'audit.
     */
    public function test_le_menu_a_trois_points_porte_les_sections_et_tous_les_outils(): void
    {
        $html = $this->page();

        $this->assertSame(1, substr_count($html, 'data-loop-menu-trigger'),
            'un seul declencheur, et il existe');
        $this->assertStringContainsString(e(__('loops.menu_tools_section')), $html);
        $this->assertStringContainsString(e(__('loops.menu_manage_section')), $html);

        // Chaque outil du catalogue est NOMME dans le menu, avec le libelle
        // que le panneau qu'il ouvre affichera — `labelFor`, jamais
        // `__($card['label_key'])` : le depot a deja paye cet ecart (le bouton
        // disait « Roadmap », le panneau « Engagements »).
        $registre = app(\App\Support\Loops\LoopCardRegistry::class);
        $outils = $registre->workspaceCardsFor($this->loop, $this->membre);
        $this->assertNotEmpty($outils, 'la Boucle a des outils, sinon le test ne mesure rien');

        foreach ($outils as $carte) {
            $this->assertStringContainsString(
                e($registre->labelFor($this->loop, $carte['key'])), $html,
                "l'outil {$carte['key']} doit etre nomme dans le menu",
            );
        }
    }

    /**
     * L'appareil photo etait EN DOUBLE, a 40 px d'intervalle : le bouton du
     * composeur et une tuile de la feuille du « + », qui declenchaient le meme
     * `$refs.cameraInput`. La tuile part, la poignee reste — donc le geste
     * devient plus accessible, il ne demande plus d'ouvrir quoi que ce soit.
     */
    public function test_l_appareil_photo_n_est_plus_en_double(): void
    {
        $html = $this->page();

        $this->assertSame(1, substr_count($html, 'data-composer-camera'),
            'une seule porte vers l\'appareil photo');
        $this->assertStringNotContainsString('data-composer-take-photo', $html,
            'la tuile de la feuille du « + » a disparu');
    }

    /**
     * Les fenetres au centre — et celle qui doit RESTER en bas.
     *
     * Un implementeur qui applique mecaniquement « tout au centre » casserait
     * la feuille du « + » : ce n'est pas une fenetre, c'est le prolongement du
     * bouton qui l'ouvre, ancree au bord ou vit le pouce.
     */
    public function test_plus_aucune_fenetre_ancree_en_bas_sauf_la_feuille_du_plus(): void
    {
        $html = $this->page();

        // Le motif « ancree en bas, centree seulement a partir de 640 px ».
        // Aucun telephone n'atteint 640 px : la fenetre s'ouvrait donc
        // toujours collee en bas, sous le pouce plutot que sous l'oeil.
        //
        // Il en reste UNE occurrence, et c'est volontaire :
        // `components/confirm-dialog.blade.php`. L'inventaire du CDC l'avait
        // manquee — il listait Roadmap et Membres — et ce composant est
        // partage bien au-dela des Boucles. Le corriger ici aurait fait bouger
        // des ecrans que personne n'a recettes pour cette TASK.
        // Le compte est verifie EXACTEMENT : si quelqu'un centre enfin la
        // boite de confirmation, ce test rougit et l'on vient retirer cette
        // ligne — plutot qu'un « au plus une » qui laisserait une regression
        // repasser en silence.
        $this->assertSame(1, substr_count($html, 'items-end justify-center p-0 sm:items-center'),
            'seule la boite de confirmation partagee reste ancree en bas, hors perimetre de cette TASK');

        $this->assertStringContainsString('flex items-end justify-center"', $html,
            'la feuille du « + », elle, reste ancree en bas : c\'est le prolongement du bouton, pas une fenetre');
    }

    private function page(): string
    {
        return $this->actingAs($this->membre)
            ->get(route('organization.loops.show', [
                'organization' => $this->organization->slug,
                'loop' => $this->loop->id,
            ]))
            ->assertOk()
            ->getContent();
    }
}
