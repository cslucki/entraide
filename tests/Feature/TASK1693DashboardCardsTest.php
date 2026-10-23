<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1693, bug AK — les cards du tableau de bord s'ouvrent, et defilent
 * au-dela de 3 elements.
 *
 * ## Le defaut
 *
 * Trois des quatre cards arrivaient REPLIEES : « Mes propositions d'aide »,
 * « Mes demandes d'aide » et les messages. Il fallait donc trois gestes pour
 * voir ce qu'un tableau de bord est cense montrer d'emblee.
 *
 * ## Pourquoi l'ascenseur vient AVEC
 *
 * Deplier sans borner deplacerait le defaut : quelqu'un qui porte quinze
 * propositions repousserait tout le reste du tableau de bord sous la ligne de
 * flottaison. La hauteur retenue laisse voir un 4e element coupe — c'est ce
 * qui DIT qu'il y a de la suite.
 */
class TASK1693DashboardCardsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693ak',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        app()->instance('current_organization', $this->organization);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function page(): string
    {
        return $this->actingAs($this->membre)
            ->get(route('organization.dashboard', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();
    }

    /** LE defaut signale : les quatre cards arrivent OUVERTES. */
    public function test_every_card_arrives_open(): void
    {
        $html = $this->page();

        foreach (['services', 'requests', 'exchanges', 'messages'] as $card) {
            $this->assertMatchesRegularExpression(
                '/x-data="\{ open: true \}" data-dashboard-card="'.$card.'"/',
                $html,
                "la card {$card} doit arriver ouverte",
            );
        }

        // AUCUNE card repliee. Deux versions precedentes de cette assertion
        // etaient trop larges : « plus aucun `open: false` dans la page »
        // emportait le panneau d'accueil, puis les menus, le FAB et les
        // listes deroulantes du gabarit, qui s'ouvrent legitimement fermes.
        // La mesure porte donc sur l'ATTELAGE `open` + `data-dashboard-card`.
        $this->assertStringNotContainsString('{ open: false }" data-dashboard-card', $html);
    }

    /** Trois elements ou moins : AUCUN ascenseur, la card respire. */
    public function test_three_items_or_fewer_get_no_scroller(): void
    {
        Service::factory()->count(3)->forUser($this->membre)->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->assertStringNotContainsString('max-h-48 overflow-y-auto', $this->page());
    }

    /** Au-dela de 3, l'ascenseur apparait. */
    public function test_more_than_three_items_get_a_scroller(): void
    {
        Service::factory()->count(4)->forUser($this->membre)->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->assertStringContainsString('max-h-48 overflow-y-auto', $this->page());
    }

    /**
     * L'ascenseur est posé card par card.
     *
     * Une seule card chargee ne doit pas en borner une autre : la condition
     * porte sur SA collection, pas sur un compte global.
     */
    public function test_the_scroller_is_per_card(): void
    {
        Service::factory()->count(4)->forUser($this->membre)->create([
            'organization_id' => $this->organization->id,
        ]);
        ServiceRequest::factory()->forUser($this->membre)->create([
            'organization_id' => $this->organization->id,
        ]);

        $html = $this->page();

        $this->assertSame(1, substr_count($html, 'max-h-48 overflow-y-auto'),
            'une seule card est bornee : celle qui porte plus de 3 elements');
    }

    /** Et le contenu reste LA : deplier ne doit rien masquer. */
    public function test_the_items_are_still_rendered(): void
    {
        Service::factory()->forUser($this->membre)->create([
            'organization_id' => $this->organization->id,
            'title' => 'PROPOSITION_VISIBLE_1693AK',
        ]);

        $this->assertStringContainsString('PROPOSITION_VISIBLE_1693AK', $this->page());
    }
}
