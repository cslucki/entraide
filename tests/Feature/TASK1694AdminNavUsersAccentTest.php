<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1694, bug AM — « Utilisateurs » : un repère visuel, et à sa place.
 *
 * **Demande de Cyril** : « mets une couleur différente pour Utilisateurs car
 * j'y vais souvent et ainsi j'aurais un repère visuel. Et le mettre juste après
 * "Organisations" et avant "Page d'accueil de la plateforme". »
 *
 * ## Pourquoi mesurer la POSITION dans la page
 *
 * L'ordre du tableau source ne prouve rien : c'est l'ordre **rendu** qui se
 * parcourt à l'œil. Le test compare donc les positions des trois liens dans le
 * HTML, pas dans le code.
 *
 * ## L'état actif reste commun
 *
 * Une entrée marquée garde sa couleur propre tant qu'elle n'est **pas** active.
 * Active, elle reprend le fond commun : l'indication « vous êtes ici » doit
 * rester la même partout, sinon deux entrées actives ne se ressembleraient
 * plus.
 */
class TASK1694AdminNavUsersAccentTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::factory()->create(['slug' => 'org-1694am', 'is_active' => true]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $organization->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function html(string $route = 'admin.organizations'): string
    {
        return $this->actingAs($this->superAdmin)
            ->get(route($route))
            ->assertOk()
            ->getContent();
    }

    /** LE repère : l'entrée porte sa marque. */
    public function test_the_users_entry_carries_its_own_accent(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('data-admin-nav-accent="admin.users"', $html);
        $this->assertStringContainsString('text-amber-300', $html);
    }

    /**
     * Et elle est la SEULE — sinon ce n'est plus un repère.
     *
     * Une couleur que plusieurs entrées partagent ne distingue rien : c'est la
     * moitié qui donne son sens à la demande.
     */
    public function test_it_is_the_only_accented_entry(): void
    {
        $this->assertSame(1, substr_count($this->html(), 'data-admin-nav-accent='));
    }

    /**
     * LA position demandée, mesurée dans le HTML rendu.
     *
     * Juste après « Organisations », juste avant « Page d'accueil de la
     * plateforme ». L'ordre du tableau source ne prouverait rien : c'est
     * l'ordre rendu qui se parcourt à l'œil.
     */
    public function test_it_sits_between_organizations_and_the_root_destination(): void
    {
        $html = $this->html();

        $utilisateurs = strpos($html, 'data-admin-nav-accent="admin.users"');
        $this->assertNotFalse($utilisateurs);

        // Les positions sont ANCREES sur l'entree « Utilisateurs », pas prises
        // au premier `strpos` venu : mesure faite, `route('admin.homepage')`
        // apparait des le debut de la page, HORS du rail. Un `strpos` naif
        // comparait donc deux endroits sans rapport — il a rougi pour la
        // mauvaise raison avant d'etre corrige.
        $organisations = strrpos(substr($html, 0, $utilisateurs), route('admin.organizations'));
        $accueil = strpos($html, route('admin.homepage'), $utilisateurs);

        $this->assertNotFalse($organisations, '« Organisations » doit preceder « Utilisateurs » dans le rail');
        $this->assertNotFalse($accueil, '« Page d\'accueil » doit suivre « Utilisateurs » dans le rail');

        // « JUSTE apres » et « JUSTE avant » : aucune AUTRE entree de rail entre
        // les deux. Sans cette mesure, le test passerait avec cinq entrees
        // intercalees — l'ordre serait bon, la demande non.
        //
        // On compte entre les CLASSES des deux entrees, pas entre leurs `href` :
        // le `class` d'un lien vient apres son `href`, donc une fenetre bornee
        // aux href embarque la classe de « Organisations » elle-meme. Premiere
        // version du test, rouge pour cette raison — « 2 au lieu de 1 » : mon
        // attendu etait faux, pas le code.
        $marqueur = 'pl-7 rounded-lg';
        $classeOrganisations = strpos($html, $marqueur, $organisations);
        $classeAccueil = strpos($html, $marqueur, $accueil);

        $this->assertNotFalse($classeOrganisations);
        $this->assertNotFalse($classeAccueil);

        $entre = substr(
            $html,
            $classeOrganisations + strlen($marqueur),
            $classeAccueil - $classeOrganisations - strlen($marqueur),
        );

        $this->assertSame(
            1,
            substr_count($entre, $marqueur),
            'une seule entree de rail doit separer « Organisations » de « Page d\'accueil » : « Utilisateurs »',
        );
    }

    /**
     * Sur l'écran lui-même, l'entrée prend le fond ACTIF, pas sa couleur.
     *
     * Sans cette moitié, on aurait un repère permanent mais plus aucune
     * indication de l'endroit où l'on est — on aurait gagné un repère en
     * perdant l'autre.
     */
    public function test_on_its_own_screen_it_takes_the_common_active_background(): void
    {
        $html = $this->html('admin.users');

        $position = strpos($html, 'data-admin-nav-accent="admin.users"');
        $this->assertNotFalse($position);

        // La classe active est posée sur le MEME élément : on lit l'attribut
        // `class` de ce lien, pas la page entière — « bg-indigo-600 » existe
        // ailleurs.
        $debut = strrpos(substr($html, 0, $position), '<a ');
        $lien = substr($html, $debut, $position - $debut + 60);

        $this->assertStringContainsString('bg-indigo-600', $lien);
        $this->assertStringNotContainsString('text-amber-300', $lien);
    }
}
