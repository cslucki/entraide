<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\Service;
use Tests\Concerns\WithTestOrganization;
use Tests\TestCase;

/**
 * TASK-1812, correctif 02 — sur « Mes favoris », le lien « Explorer » renvoyait
 * sur les DEMANDES, jamais les propositions.
 *
 * Signale par Cyril : « le lien 'Explorer les propositions' n'est plus bon, il
 * doit pointer vers les propositions (explorer?tab=services) ».
 *
 * ## La cause
 *
 * Deux liens de `favorites/index.blade.php` appelaient `route('explorer')`
 * sans `tab`. Depuis TASK-1788 correctif 07, l'Entraide s'ouvre par defaut sur
 * les demandes — ces deux liens atterrissaient donc sur le mauvais onglet,
 * alors que cette page n'affiche JAMAIS que des favoris de SERVICES. Le lien
 * de l'etat vide (`favorites.explore`) n'etait en plus pas scope a
 * l'Organization courante.
 */
class TASK1812Correctif02FavorisExplorerTabTest extends TestCase
{
    use WithTestOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_etat_vide_le_lien_explorer_pointe_vers_les_propositions(): void
    {
        $user = $this->orgUser();

        $html = $this->actingAs($user)->get(route('favorites.index'))
            ->assertOk()->getContent();

        $this->assertStringContainsString(
            e(route('explorer', ['tab' => 'services'])),
            $html,
            "Le lien de l'etat vide ne pointe pas vers l'onglet Propositions."
        );
    }

    public function test_avec_des_favoris_le_lien_parcourir_pointe_vers_les_propositions(): void
    {
        $user = $this->orgUser();
        $owner = $this->orgUser();
        $service = Service::factory()->forUser($owner)->create(['organization_id' => $this->testOrganization->id]);

        Favorite::create(['user_id' => $user->id, 'service_id' => $service->id]);

        $html = $this->actingAs($user)->get(route('favorites.index'))
            ->assertOk()->getContent();

        $this->assertStringContainsString(
            e(route('explorer', ['tab' => 'services'])),
            $html,
            "Le lien « Parcourir les services » ne pointe pas vers l'onglet Propositions."
        );
    }
}
