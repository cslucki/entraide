<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1749 (bug signale par Cyril, 04/11) — le RETOUR ARRIERE laissait
 * « BouclePro » dans le menu d'Organization.
 *
 * ## Ce que ce test peut mesurer, et ce qu'il ne peut PAS
 *
 * Le defaut ne vient PAS du rendu : sans `?org=`, le serveur marque bien
 * `selected` sur le libelle neutre. C'est le NAVIGATEUR qui restaure l'etat des
 * formulaires en revenant en arriere, et qui reecrit par-dessus ce que le
 * serveur a envoye.
 *
 * Un test HTTP ne rejoue pas un retour arriere : il n'y a ni historique, ni
 * cache de navigation. **Ce fichier ne prouve donc pas que le bug est
 * corrige** — il prouve les deux conditions SANS LESQUELLES le correctif ne
 * peut pas fonctionner, et qui sont celles qui regresseraient en silence :
 *
 *  1. le serveur marque `selected` sur la BONNE option dans les deux etats —
 *     c'est la seule verite dont le script se sert (`defaultSelected`) ;
 *  2. le script de restauration est bien EMIS dans la page.
 *
 * La verification du comportement reel du navigateur appartient a la recette.
 */
class TASK1749LoginSelectRestoredOnBackTest extends TestCase
{
    use RefreshDatabase;

    private Organization $porte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->porte = Organization::factory()->create([
            'slug' => 'porte-retour-1749',
            'name' => 'Porte Retour',
            'is_active' => true,
            'is_public' => true,
        ]);

        // Une seconde Organization : sans alternative, le menu ne sort pas.
        Organization::factory()->create([
            'slug' => 'autre-retour-1749',
            'name' => 'Autre Retour',
            'is_active' => true,
            'is_public' => true,
        ]);
    }

    private function menu(string $url): string
    {
        $html = $this->get($url)->assertOk()->getContent();

        $debut = strpos($html, 'id="organization-nav"');
        $this->assertNotFalse($debut, 'Le menu de navigation d\'Organization est absent.');

        $fin = strpos($html, '</select>', $debut);

        return substr($html, $debut, $fin - $debut);
    }

    /** L'option portant `selected` dans un fragment de menu. */
    private function optionSelectionnee(string $menu): string
    {
        $this->assertSame(1, preg_match_all('/<option[^>]*\bselected\b[^>]*>([^<]*)/', $menu, $trouvees),
            'Le menu doit porter UNE et une seule option selectionnee.');

        return trim(html_entity_decode($trouvees[1][0]));
    }

    /**
     * SANS `?org=` : le libelle neutre. C'est l'etat que le retour arriere
     * doit retrouver.
     */
    public function test_a_l_arrivee_le_serveur_selectionne_le_libelle_neutre(): void
    {
        $menu = $this->menu(route('organization.login', $this->porte));

        $this->assertSame(
            trim(__('auth.login_organization_nav_placeholder')),
            $this->optionSelectionnee($menu),
        );
    }

    /** AVEC `?org=` : l'Organization choisie, et c'est la confirmation du geste. */
    public function test_apres_un_choix_le_serveur_selectionne_l_organization(): void
    {
        $menu = $this->menu(route('organization.login', $this->porte).'?org='.$this->porte->slug);

        $this->assertSame($this->porte->name, $this->optionSelectionnee($menu));
    }

    /**
     * Le script de restauration est emis.
     *
     * L'assertion vise le CODE et pas le commentaire qui l'explique : les deux
     * contiennent le mot `defaultSelected`, et compter le mot seul aurait passe
     * meme si le script avait disparu en ne laissant que sa note.
     */
    public function test_le_script_de_restauration_est_emis(): void
    {
        $html = $this->get(route('organization.login', $this->porte))->assertOk()->getContent();

        $this->assertStringContainsString('option.defaultSelected', $html,
            'Le script qui restaure la selection du serveur a disparu.');

        // `pageshow` et non `DOMContentLoaded` : un retour depuis le cache de
        // navigation ne rejoue pas le second.
        $this->assertStringContainsString("addEventListener('pageshow'", $html);
    }
}
