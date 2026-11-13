<?php

namespace Tests\Feature;

use Tests\Concerns\WithTestOrganization;
use Tests\TestCase;

/**
 * TASK-1812, correctif 03 — raccourci « Mes boucles » sur le tableau de bord.
 *
 * Demande de Cyril : « Sur le dashboard on va ajouter un bouton 'Mes boucles'
 * à la droite de 'Profil public' qui pointera vers /org/$slug$/loops et la vue
 * 'Mes boucles' qui existe déjà. »
 *
 * Ajoute dans `components/user-dashboard-nav.blade.php`, au meme niveau que
 * les autres raccourcis (favoris, invitations, points). Reutilise la cle de
 * traduction `loops.my_loops`, laissee en place par TASK-1788 correctif 06
 * bien qu'inutilisee depuis sur le TITRE de la page (devenu « Boucles ») : ce
 * raccourci-ci designe un espace personnel, « Mes boucles » lui convient.
 *
 * Visibilite alignee sur le rail principal et la barre mobile : masque quand
 * `loops_enabled` est faux pour l'Organization, sans quoi le lien menerait a
 * une route qui rend 404 (`CheckLoopsEnabled`).
 */
class TASK1812Correctif03DashboardMesBouclesTest extends TestCase
{
    use WithTestOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_le_raccourci_mes_boucles_pointe_vers_loops_index(): void
    {
        $user = $this->orgUser();

        $html = $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()->getContent();

        $this->assertStringContainsString(__('loops.my_loops'), $html);
        $this->assertStringContainsString(
            e(route('organization.loops.index', ['organization' => $this->testOrganization->slug])),
            $html,
            "Le raccourci « Mes boucles » ne pointe pas vers l'Entraide des Boucles de cette Organization."
        );
    }

    public function test_le_raccourci_disparait_si_les_boucles_sont_desactivees(): void
    {
        $this->testOrganization->update(['loops_enabled' => false]);
        $user = $this->orgUser();

        $html = $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'data-user-dashboard-nav-link="loops"',
            $html,
            "Le raccourci « Mes boucles » reste visible alors que les Boucles sont desactivees."
        );
    }
}
