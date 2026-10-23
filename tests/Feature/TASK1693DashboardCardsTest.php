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

    // ── Les boutons « Nouveau » (bugs AL et AM) ──────────────────────────────

    /**
     * TASK-1693 bug AL — « Nouveau » cesse d'etre un lien souligne.
     *
     * C'est le geste le plus utile de la card ; il portait exactement le meme
     * poids visuel que « Voir tout », en 12 px souligne.
     */
    public function test_the_new_buttons_are_real_buttons(): void
    {
        $html = $this->page();

        foreach (['services', 'requests'] as $card) {
            $this->assertMatchesRegularExpression(
                '/data-dashboard-new="'.$card.'"\s+class="[^"]*bg-indigo-600[^"]*"/',
                $html,
                "le bouton « Nouveau » de la card {$card} doit porter un fond, pas un soulignement",
            );
        }
    }

    /**
     * TASK-1693 bug AM — les cards Echanges et Messagerie en recoivent un.
     *
     * Un echange NAIT d'une rencontre : son geste « nouveau » mene a
     * l'Explorer, il n'existe pas de formulaire de creation directe.
     */
    public function test_exchanges_and_messages_get_their_own_new_button(): void
    {
        $html = $this->page();
        $slug = $this->organization->slug;

        $this->assertMatchesRegularExpression(
            '#href="[^"]*/org/'.$slug.'/explorer"\s+data-dashboard-new="exchanges"#',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '#href="[^"]*/org/'.$slug.'/messages"\s+data-dashboard-new="messages"#',
            $html,
        );
    }

    /**
     * Le bouton est POSE A COTE du repli, jamais dedans.
     *
     * Un lien dans un bouton est invalide en HTML : le navigateur le deplace
     * hors de son parent, et aucun test serveur ne le voit (lecon du bug AA).
     */
    public function test_the_new_button_is_never_inside_the_toggle(): void
    {
        $html = $this->page();

        foreach (['services', 'requests', 'exchanges', 'messages'] as $card) {
            $position = strpos($html, 'data-dashboard-new="'.$card.'"');
            $this->assertNotFalse($position);

            // Le dernier `<button` AVANT le lien doit etre deja referme.
            $avant = substr($html, 0, $position);
            $this->assertGreaterThan(
                strrpos($avant, '<button'),
                strrpos($avant, '</button>'),
                "le bouton « Nouveau » de la card {$card} est imbrique dans le repli",
            );
        }
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
