<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1707 — la barre haute d'un Dossier ouvert.
 *
 * Suite de M5, sur decision de Cyril du 26/10. M5 avait donne a la LISTE des
 * Dossiers un « ‹ » vers l'accueil ; les pages de detail affichaient encore
 * « BouclePro ».
 *
 * ## Aucun titre, sur les DEUX pages — et la seconde raison est la bonne
 *
 * `dossiers/edit.blade.php` porte son propre `<h1>` : evident.
 *
 * `dossiers/show.blade.php`, lui, n'en porte aucun, et mon premier jet faisait
 * donc titrer le Dossier par la barre, comme elle titre la Boucle sur
 * `loops.show`. **Les deux gardes de TASK-1145 ont rougi**, et elles avaient
 * raison : `dossiers.show` rend AUSSI la page d'acces refuse, sur la meme
 * route. Titrer depuis la barre obligeait a charger le Dossier, et le
 * controleur dit en clair ce que cela casse —
 *
 * > La vue ne recoit PAS `$dossier` : elle ne peut donc rien divulguer de ce
 * > qui a ete demande, et `driveSurface()` n'est jamais atteint, donc rien du
 * > Dossier n'est meme charge pour l'ecran.
 *
 * Ma branche affichait le nom du Dossier a qui on venait d'en refuser l'acces.
 * Le nom reste visible dans le CORPS de la page (`show.blade.php:395`), ou il
 * n'est rendu que pour qui y a droit : l'autorisation appartient a la vue que
 * le controleur choisit, pas a une barre commune a toutes les pages.
 *
 * ## Et le retour remonte d'UN cran
 *
 * Vers la liste des Dossiers, pas vers l'accueil : c'est de la liste que l'on
 * rejoint l'accueil, depuis M5. Une fleche qui sauterait un niveau est le
 * defaut que TASK-1693 bug AQ avait nomme sur la Messagerie.
 */
class TASK1707DossierTopbarTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1707-dossier',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        // `owner_id`, pas `user_id` : c'est la colonne que `DossierPolicy::
        // isOwner()` consulte, et mon premier jet rendait un 403 sur la page
        // d'edition faute de proprietaire.
        $this->dossier = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->membre->id,
            'name' => 'Qualiopi 2026',
        ]);
    }

    /**
     * La barre haute du telephone, isolee du reste du document.
     *
     * La mesure porte sur la REGION et non sur un marqueur pose pour la
     * mesurer : c'est la lecon des deux faux verts de cette TASK.
     */
    private function barreHaute(string $chemin): string
    {
        app()->forgetInstance('current_organization');

        $html = $this->actingAs($this->membre)
            ->get('/org/'.$this->organization->slug.$chemin)
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            preg_match('/<header[^>]*class="[^"]*md:hidden fixed top-0[^"]*"[^>]*>(.*?)<\/header>/s', $html, $barre),
            'La barre haute du telephone n\'est pas servie.'
        );

        return $barre[1];
    }

    private function page(string $chemin): string
    {
        app()->forgetInstance('current_organization');

        return $this->actingAs($this->membre)
            ->get('/org/'.$this->organization->slug.$chemin)
            ->assertOk()
            ->getContent();
    }

    private function indexDesDossiers(): string
    {
        return route('organization.dossiers.index', ['organization' => $this->organization->slug]);
    }

    /**
     * « BouclePro » a disparu, et RIEN ne l'a remplace.
     *
     * Les deux moities comptent : sans la premiere, le defaut signale par
     * Cyril reste ; sans la seconde, on le remplace par une fuite.
     */
    public function test_un_dossier_ouvert_ne_porte_ni_bouclepro_ni_son_nom(): void
    {
        $barre = $this->barreHaute('/dossiers/'.$this->dossier->id);

        $this->assertStringNotContainsString(
            config('app.name'),
            $barre,
            'Le nom de la plateforme s\'affiche encore dans la barre haute.'
        );

        $this->assertSame(
            0,
            preg_match('/<h1[\s>]/', $barre),
            'La barre haute titre la page : titrer `dossiers.show` obligeait a charger le Dossier, et cette route rend aussi la page d\'acces refuse.'
        );

        // Le nom reste lisible, mais DANS LA PAGE — la ou le controleur a
        // choisi de le rendre, c'est-a-dire seulement pour qui y a droit.
        $this->assertStringContainsString(
            e($this->dossier->displayName()),
            $this->page('/dossiers/'.$this->dossier->id),
            'Le nom du Dossier n\'est plus visible nulle part.'
        );
    }

    public function test_le_retour_d_un_dossier_ouvert_remonte_a_la_liste(): void
    {
        $this->assertSame(
            1,
            preg_match('/data-bp-topbar-back="([^"]+)"/', $this->barreHaute('/dossiers/'.$this->dossier->id), $cible),
            'La barre haute d\'un Dossier ouvert ne porte aucun retour.'
        );

        $this->assertSame(
            $this->indexDesDossiers(),
            $cible[1],
            'Le retour saute un cran : il doit mener a la liste des Dossiers, pas a l\'accueil.'
        );
    }

    /**
     * La page d'EDITION, elle, ne porte aucun titre dans la barre.
     *
     * Elle a deja son propre `<h1>`. Les deux pages sont donc mesurees
     * separement : un traitement uniforme creerait un doublon sur l'une ou un
     * « BouclePro » sur l'autre.
     */
    public function test_la_page_d_edition_ne_porte_aucun_titre_mais_un_retour(): void
    {
        $barre = $this->barreHaute('/dossiers/'.$this->dossier->id.'/edit');

        $this->assertSame(
            0,
            preg_match('/<h1[\s>]/', $barre),
            'La barre haute titre la page d\'edition, qui porte deja son propre titre : doublon.'
        );

        $this->assertStringNotContainsString(config('app.name'), $barre);

        $this->assertSame(1, preg_match('/data-bp-topbar-back="([^"]+)"/', $barre, $cible));
        $this->assertSame($this->indexDesDossiers(), $cible[1]);
    }
}
