<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1693, bug U — « Mes demandes d'aide » montre aussi les demandes PRISES
 * EN CHARGE.
 *
 * ## Le defaut
 *
 * Des qu'une personne propose son aide, la demande passe de `open` a
 * `in_progress` (`TransactionController:106`). La card du tableau de bord ne
 * listant que `open`, la demande DISPARAISSAIT de la vue de son auteur a la
 * seconde ou elle devenait vivante.
 *
 * Mesure en recette : la demande « Deamnde d'aide de user2 » etait absente de
 * la card de user2, alors qu'une personne venait d'y repondre.
 *
 * ## Ce qui n'etait PAS en cause
 *
 * Ni le statut `pending` de l'echange, ni un defaut de donnee. Le mecanisme est
 * coherent : un refus ou une annulation ROUVRE la demande
 * (`TransactionController:169,213`), verifie ci-dessous. Et la page « Voir
 * tout » n'a jamais filtre sur le statut : l'information n'etait pas perdue,
 * elle etait absente de l'ecran d'accueil. La card mentait par omission.
 *
 * ## Le titre dit « mes demandes »
 *
 * Pas « mes demandes que personne n'a encore vues ». Les deux etats sont donc
 * listes, et c'est la PASTILLE qui dit lequel. `closed` reste dehors : son
 * auteur l'a ferme deliberement, comme les echanges termines restent hors de la
 * card « Échanges ».
 */
class TASK1693DashboardHandledRequestsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $demandeur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693u',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->demandeur = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
            'points_balance' => 500,
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function demande(string $status, string $titre): ServiceRequest
    {
        return ServiceRequest::factory()->create([
            'user_id' => $this->demandeur->id,
            'organization_id' => $this->organization->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->organization->id])->id,
            'status' => $status,
            'title' => $titre,
        ]);
    }

    private function tableauDeBord(): string
    {
        return $this->actingAs($this->demandeur)
            ->get(route('organization.dashboard', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();
    }

    /** LE defaut signale : une demande prise en charge reste visible. */
    public function test_a_request_being_handled_stays_on_the_dashboard(): void
    {
        $priseEnCharge = $this->demande('in_progress', 'Demande a laquelle on a repondu');

        $html = $this->tableauDeBord();

        $this->assertStringContainsString('data-dashboard-request="'.$priseEnCharge->id.'"', $html);
        $this->assertStringContainsString(e($priseEnCharge->title), $html);
    }

    /** Et une demande encore ouverte n'a pas bouge. */
    public function test_an_open_request_is_still_listed(): void
    {
        $ouverte = $this->demande('open', 'Demande encore ouverte');

        $this->assertStringContainsString(
            'data-dashboard-request="'.$ouverte->id.'"',
            $this->tableauDeBord(),
        );
    }

    /** La pastille distingue les deux etats — sinon la card melangerait tout. */
    public function test_the_pill_tells_the_two_states_apart(): void
    {
        $this->demande('open', 'Ouverte');
        $this->demande('in_progress', 'Prise en charge');

        $html = $this->tableauDeBord();

        $this->assertStringContainsString('data-dashboard-request-state="open"', $html);
        $this->assertStringContainsString('data-dashboard-request-state="in_progress"', $html);
        $this->assertStringContainsString(e(__('dashboard.request_open')), $html);
        $this->assertStringContainsString(e(__('dashboard.request_in_progress')), $html);
    }

    /**
     * Une demande FERMEE reste dehors.
     *
     * Son auteur l'a close deliberement : la faire revenir serait defaire son
     * geste.
     */
    public function test_a_closed_request_stays_out(): void
    {
        $fermee = $this->demande('closed', 'Demande fermee');

        $this->assertStringNotContainsString(
            'data-dashboard-request="'.$fermee->id.'"',
            $this->tableauDeBord(),
        );
    }

    /**
     * « Fermer » n'est offert que sur une demande encore OUVERTE.
     *
     * Fermer une demande deja prise en charge laisserait une proposition en
     * attente pointant sur une demande close, sans rien dire a la personne qui
     * l'a faite — un geste dont la consequence n'est pas nommee. Le bouton
     * n'existait pas sur ces lignes avant ce correctif, puisqu'elles n'etaient
     * pas affichees : ne pas l'y creer est la continuite, pas un retrait.
     */
    public function test_the_close_button_is_offered_on_open_requests_only(): void
    {
        $ouverte = $this->demande('open', 'Ouverte');
        $priseEnCharge = $this->demande('in_progress', 'Prise en charge');

        $html = $this->tableauDeBord();

        // Viser le MARQUEUR du formulaire, pas son URL : `requests.destroy` et
        // `requests.show` partagent la meme adresse (DELETE contre GET), et le
        // titre de la ligne porte deja ce lien. Une assertion sur l'URL ne
        // pouvait donc pas distinguer les deux — premier jet, faux rouge.
        $this->assertStringContainsString('data-dashboard-request-close="'.$ouverte->id.'"', $html);

        $this->assertStringNotContainsString(
            'data-dashboard-request-close="'.$priseEnCharge->id.'"',
            $html,
            'fermer une demande deja prise en charge laisserait une proposition orpheline',
        );
    }

    /** La demande de quelqu'un d'AUTRE n'est pas la mienne. */
    public function test_another_members_request_is_not_listed(): void
    {
        $autre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);

        $sienne = ServiceRequest::factory()->create([
            'user_id' => $autre->id,
            'organization_id' => $this->organization->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->organization->id])->id,
            'status' => 'in_progress',
            'title' => 'La demande de quelqu\'un d\'autre',
        ]);

        $this->assertStringNotContainsString(
            'data-dashboard-request="'.$sienne->id.'"',
            $this->tableauDeBord(),
        );
    }

    /**
     * Le mecanisme amont n'est PAS en cause, et ce test le fixe.
     *
     * Accepter pourvoit la demande, relacher la rouvre. Si ce comportement
     * disparaissait, une demande relachee resterait « prise en charge » a vie — et le present correctif la
     * montrerait alors dans un etat faux au lieu de la cacher dans un etat
     * faux. Le gain serait nul.
     */
    public function test_accepting_then_releasing_an_offer_reopens_the_request(): void
    {
        $aidant = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
            'points_balance' => 500,
        ]);

        $demande = $this->demande('open', 'Demande a rouvrir');

        $this->actingAs($aidant)
            ->post(route('organization.transactions.store', ['organization' => $this->organization->slug]), [
                'request_id' => $demande->id,
                'points_proposed' => 20,
            ])
            ->assertRedirect();

        // TASK-1693 bug W — la demande reste OUVERTE pendant la phase de
        // proposition : elle ne quitte l'explorateur qu'a l'ACCEPTATION. Ce
        // test, ecrit au bug U, mesurait le comportement d'alors ; il vise
        // desormais la vraie transition.
        $this->assertSame('open', $demande->fresh()->status);

        $transaction = \App\Models\Transaction::where('request_id', $demande->id)->sole();

        // TASK-1693 bug S : decide celui qui n'a PAS propose.
        $this->actingAs($this->demandeur)
            ->patch(route('organization.transactions.approve', [
                'organization' => $this->organization->slug,
                'transaction' => $transaction,
            ]))
            ->assertRedirect();

        $this->assertSame('in_progress', $demande->fresh()->status,
            'accepter pourvoit la demande');

        $this->actingAs($this->demandeur)
            ->patch(route('organization.transactions.cancel', [
                'organization' => $this->organization->slug,
                'transaction' => $transaction->fresh(),
            ]))
            ->assertRedirect();

        $this->assertSame('open', $demande->fresh()->status,
            'relacher la rouvre : le mecanisme amont reste coherent');
    }
}
