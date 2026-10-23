<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1693, bug T — UNE seule card « Échanges » sur le tableau de bord.
 *
 * ## Le defaut
 *
 * Deux cards aux noms voisins, « Mes échanges en cours » et « Échanges en
 * cours », se CHEVAUCHAIENT :
 *
 *   - la 1re : `buyer_id = moi`, statuts pending + accepted + buyer_done ;
 *   - la 2nde : moi des DEUX cotes, statuts accepted + buyer_done.
 *
 * Un echange dont j'etais acheteur et qui etait `accepted` figurait donc dans
 * les deux, meme titre, meme lien — constate en recette, « Titre Titre Titre ·
 * 20 pts » affiche deux fois sur le meme ecran.
 *
 * Pire : la 1re se MASQUAIT quand elle etait vide. Le tableau de bord changeait
 * donc de forme selon la personne — deux cards pour qui achete, une seule pour
 * qui vend. C'est exactement ce que Cyril a vu en comparant user2 et user.
 *
 * ## Pourquoi l'UNION et non la suppression de l'une
 *
 * Chacune montrait quelque chose que l'autre taisait : la 1re seule portait les
 * propositions encore EN ATTENTE, la 2nde seule ce dont je suis VENDEUR.
 * Supprimer l'une ou l'autre aurait donc perdu de l'information. L'union ne
 * perd rien, et c'est deja le perimetre exact de « Messages récents ».
 */
class TASK1693DashboardSingleExchangeCardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $moi;

    private User $autre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693t',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->moi = $this->membre();
        $this->autre = $this->membre();

        Http::preventStrayRequests();
        Http::fake();
    }

    private function membre(): User
    {
        return User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
            'points_balance' => 500,
        ]);
    }

    private function echange(string $status, bool $jeSuisAcheteur, ?int $proposes = 20, ?int $convenus = null): Transaction
    {
        return Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $jeSuisAcheteur ? $this->moi->id : $this->autre->id,
            'seller_id' => $jeSuisAcheteur ? $this->autre->id : $this->moi->id,
            'status' => $status,
            'points_proposed' => $proposes,
            'points_agreed' => $convenus,
        ]);
    }

    private function tableauDeBord(): string
    {
        return $this->actingAs($this->moi)
            ->get(route('organization.dashboard', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();
    }

    /** Il n'y a plus qu'UNE card, et les deux anciens titres ont disparu. */
    public function test_there_is_exactly_one_exchange_card(): void
    {
        $this->echange('accepted', jeSuisAcheteur: true);

        $html = $this->tableauDeBord();

        $this->assertSame(1, substr_count($html, 'data-dashboard-exchanges'));
        $this->assertStringNotContainsString(e(__('dashboard.my_current_exchanges')), $html);
        $this->assertStringContainsString(e(__('dashboard.exchanges')), $html);
    }

    /**
     * LE defaut signale : un echange ne s'affiche plus qu'UNE fois.
     *
     * Acheteur + `accepted` etait le cas qui tombait dans les deux cards.
     */
    public function test_an_exchange_appears_only_once(): void
    {
        $tx = $this->echange('accepted', jeSuisAcheteur: true, convenus: 20);

        $this->assertSame(
            1,
            substr_count($this->tableauDeBord(), 'data-dashboard-exchange="'.$tx->id.'"'),
            'acheteur + accepte tombait dans les DEUX cards',
        );
    }

    /**
     * L'union ne perd RIEN : les quatre situations sont listees.
     *
     * C'est la garantie centrale de ce correctif — supprimer l'une des deux
     * cards aurait fait disparaitre deux de ces quatre lignes.
     */
    public function test_the_union_loses_nothing(): void
    {
        $situations = [
            'ma proposition en attente' => $this->echange('pending', jeSuisAcheteur: true),
            'ce que j\'ai accepte comme acheteur' => $this->echange('accepted', jeSuisAcheteur: true, convenus: 20),
            'ce que je vends, en attente' => $this->echange('pending', jeSuisAcheteur: false),
            'ce que je vends, accepte' => $this->echange('buyer_done', jeSuisAcheteur: false, convenus: 20),
        ];

        $html = $this->tableauDeBord();

        foreach ($situations as $quoi => $tx) {
            $this->assertStringContainsString(
                'data-dashboard-exchange="'.$tx->id.'"',
                $html,
                "la card unique doit montrer : {$quoi}",
            );
        }
    }

    /**
     * La forme du tableau de bord ne depend plus de la personne.
     *
     * C'est ce que Cyril a vu : deux cards chez l'acheteur, une seule chez le
     * vendeur. La card est desormais la, pleine ou vide.
     */
    public function test_the_card_is_present_even_with_nothing_to_show(): void
    {
        $html = $this->tableauDeBord();

        $this->assertStringContainsString('data-dashboard-exchanges', $html);
        $this->assertStringContainsString(e(__('dashboard.no_exchange')), $html);
    }

    /**
     * Les points en attente ne s'affichent plus « 0 pts ».
     *
     * Tant que rien n'est accepte, `points_agreed` est NULL — la card d'origine
     * allait le chercher au mauvais endroit.
     */
    public function test_a_pending_exchange_shows_the_proposed_amount_not_zero(): void
    {
        $enAttente = $this->echange('pending', jeSuisAcheteur: false, proposes: 37, convenus: null);
        $accepte = $this->echange('accepted', jeSuisAcheteur: false, proposes: 11, convenus: 44);

        $html = $this->tableauDeBord();

        // Viser la LIGNE, pas la page : « 0 pts » figure ailleurs sur le
        // tableau de bord (solde, parrainage), et une assertion globale
        // rougirait pour une raison etrangere au correctif.
        $this->assertStringContainsString('37 pts', $this->ligne($html, $enAttente->id),
            'un montant convenu inexistant ne doit pas devenir « 0 » : on montre le PROPOSE');

        $this->assertStringContainsString('44 pts', $this->ligne($html, $accepte->id),
            'une fois accepte, c\'est le montant CONVENU qui fait foi');
        $this->assertStringNotContainsString('11 pts', $this->ligne($html, $accepte->id));
    }

    /** Le fragment de la ligne d'un echange donne, et lui seul. */
    private function ligne(string $html, string $transactionId): string
    {
        $debut = strpos($html, 'data-dashboard-exchange="'.$transactionId.'"');

        $this->assertNotFalse($debut, "la ligne de l'echange {$transactionId} est absente");

        return substr($html, $debut, (int) (strpos($html, '</a>', $debut) - $debut));
    }

    /** L'etat est porte par la ligne, plus par le titre de la card. */
    public function test_each_line_carries_its_own_state(): void
    {
        $this->echange('pending', jeSuisAcheteur: true);
        $this->echange('accepted', jeSuisAcheteur: false, convenus: 20);

        $html = $this->tableauDeBord();

        $this->assertStringContainsString('En attente', $html);
        $this->assertStringContainsString('Accepté', $html);
    }

    /**
     * Ce qui reste DEHORS : les echanges termines, refuses, annules.
     *
     * Ils n'y etaient pas non plus avant. Cette card parle de ce sur quoi on a
     * encore quelque chose a faire — l'elargir en passant serait un autre
     * produit, non demande.
     */
    public function test_finished_refused_and_cancelled_exchanges_stay_out(): void
    {
        $html = $this->tableauDeBord();

        foreach (['completed', 'refused', 'cancelled'] as $status) {
            $tx = $this->echange($status, jeSuisAcheteur: true, convenus: 20);
            $this->assertStringNotContainsString(
                'data-dashboard-exchange="'.$tx->id.'"',
                $this->tableauDeBord(),
                "[{$status}] ne doit pas entrer dans la card",
            );
        }

        $this->assertStringContainsString('data-dashboard-exchanges', $html);
    }

    /** Un echange entre deux AUTRES personnes ne me concerne pas. */
    public function test_an_exchange_between_other_people_is_not_listed(): void
    {
        $tiers = $this->membre();

        $tx = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->autre->id,
            'seller_id' => $tiers->id,
            'status' => 'accepted',
            'points_proposed' => 20,
            'points_agreed' => 20,
        ]);

        $this->assertStringNotContainsString(
            'data-dashboard-exchange="'.$tx->id.'"',
            $this->tableauDeBord(),
        );
    }
}
