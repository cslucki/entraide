<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Message;
use App\Models\Organization;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1693, bug W — une demande d'aide peut recevoir PLUSIEURS propositions.
 *
 * ## Le defaut
 *
 * « Tant que la demande n'est pas acceptee, elle doit rester dans
 * l'explorateur. Ainsi la personne peut avoir plusieurs propositions »
 * (arbitrage Cyril, 21/10). Elle en quittait l'explorateur des la PREMIERE
 * proposition, et ne pouvait donc en recevoir qu'une, de qui que ce soit.
 *
 * ## Le code avait ete pense pour plusieurs
 *
 * Trois indices concordants, mesures avant correction :
 *
 *   - aucune contrainte d'unicite sur `transactions.request_id` ;
 *   - la garde anti-doublon est conçue PAR PERSONNE (« VOUS avez deja une
 *     transaction en cours »), ce qui n'a de sens qu'avec plusieurs personnes ;
 *   - refuser ou annuler ROUVRAIT deja la demande.
 *
 * Une seule ligne imposait le contraire. Elle est deplacee de la PROPOSITION
 * vers l'ACCEPTATION.
 *
 * ## Les deux verrous tombent d'eux-memes
 *
 * L'explorateur (`scopeOpen`) et le formulaire « Proposer mon aide »
 * (`requests/show.blade.php:111`) s'appuient tous deux sur `status === 'open'`.
 * La demande restant ouverte jusqu'a l'acceptation, aucun des deux n'a eu
 * besoin d'etre touche — ce que ces tests verifient plutot que de le supposer.
 */
class TASK1693MultipleHelpOffersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $demandeur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693w',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->demandeur = $this->membre(500);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function membre(int $points = 500): User
    {
        return User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
            'points_balance' => $points,
        ]);
    }

    private function demande(): ServiceRequest
    {
        return ServiceRequest::factory()->create([
            'user_id' => $this->demandeur->id,
            'organization_id' => $this->organization->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->organization->id])->id,
            'status' => 'open',
            'title' => 'Demande ouverte aux propositions',
        ]);
    }

    private function proposer(ServiceRequest $demande, User $aidant, int $points = 20): Transaction
    {
        $this->actingAs($aidant)
            ->post(route('organization.transactions.store', ['organization' => $this->organization->slug]), [
                'request_id' => $demande->id,
                'points_proposed' => $points,
            ])
            ->assertRedirect()
            ->assertSessionMissing('error');

        return Transaction::where('request_id', $demande->id)->where('seller_id', $aidant->id)->sole();
    }

    private function accepter(Transaction $transaction): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->demandeur)
            ->patch(route('organization.transactions.approve', [
                'organization' => $this->organization->slug,
                'transaction' => $transaction,
            ]));
    }

    /** LA demande : la demande reste OUVERTE apres une premiere proposition. */
    public function test_a_request_stays_open_after_the_first_offer(): void
    {
        $demande = $this->demande();

        $this->proposer($demande, $this->membre());

        $this->assertSame('open', $demande->fresh()->status,
            'elle doit pouvoir recevoir d\'autres propositions');
    }

    /** Et elle reste dans l'explorateur, qui n'a pas eu besoin d'etre touche. */
    public function test_it_stays_in_the_explorer_after_the_first_offer(): void
    {
        $demande = $this->demande();
        $this->proposer($demande, $this->membre());

        $this->assertTrue(
            ServiceRequest::open()->where('id', $demande->id)->exists(),
            'le filtre de l\'explorateur s\'appuie sur `open` : il suffit que la demande le reste',
        );
    }

    /** Et son formulaire « Proposer mon aide » reste offert. */
    public function test_the_offer_form_stays_available_after_the_first_offer(): void
    {
        $demande = $this->demande();
        $this->proposer($demande, $this->membre());

        $html = $this->actingAs($this->membre())
            ->get(route('organization.requests.show', [
                'organization' => $this->organization->slug,
                'request' => $demande,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e(__('requests.show.propose_help')), $html);
    }

    /** Trois personnes proposent : les trois propositions existent. */
    public function test_three_people_can_offer_their_help(): void
    {
        $demande = $this->demande();

        foreach ([20, 25, 30] as $points) {
            $this->proposer($demande, $this->membre(), $points);
        }

        $this->assertSame(3, Transaction::where('request_id', $demande->id)->count());
        $this->assertSame('open', $demande->fresh()->status);
    }

    /** L'ACCEPTATION pourvoit la demande, et elle quitte alors l'explorateur. */
    public function test_accepting_is_what_takes_the_request_out_of_the_explorer(): void
    {
        $demande = $this->demande();
        $retenue = $this->proposer($demande, $this->membre());

        $this->accepter($retenue)->assertRedirect();

        $this->assertSame('in_progress', $demande->fresh()->status);
        $this->assertFalse(ServiceRequest::open()->where('id', $demande->id)->exists());
    }

    /**
     * Les propositions CONCURRENTES sont closes, et on le DIT a chacun.
     *
     * Les laisser vivre sur une demande deja pourvue recreerait le mensonge
     * d'interface corrige au bug L : des boutons « Accepter / Refuser » sans
     * objet, pour des gens a qui personne n'a rien dit.
     */
    public function test_competing_offers_are_closed_and_their_authors_told(): void
    {
        $demande = $this->demande();
        $retenue = $this->proposer($demande, $this->membre(), 20);
        $ecartee = $this->proposer($demande, $this->membre(), 30);

        $this->accepter($retenue)->assertRedirect();

        $this->assertSame('accepted', $retenue->fresh()->status);
        $this->assertSame('refused', $ecartee->fresh()->status);

        $this->assertDatabaseHas('messages', [
            'transaction_id' => $ecartee->id,
            'type' => 'system',
            'body' => __('requests.lifecycle.another_offer_accepted'),
        ]);
    }

    /** Le fil de la proposition RETENUE ne recoit pas ce message. */
    public function test_the_accepted_thread_is_not_told_it_was_set_aside(): void
    {
        $demande = $this->demande();
        $retenue = $this->proposer($demande, $this->membre());
        $this->proposer($demande, $this->membre(), 30);

        $this->accepter($retenue)->assertRedirect();

        $this->assertSame(
            0,
            Message::where('transaction_id', $retenue->id)
                ->where('body', __('requests.lifecycle.another_offer_accepted'))
                ->count(),
        );
    }

    /**
     * Relacher une CONCURRENTE ne rouvre pas une demande deja pourvue.
     *
     * Sans cette condition, refuser l'une des offres ecartees ferait
     * reapparaitre dans l'explorateur une demande dont quelqu'un s'occupe deja.
     * C'etait sans danger tant qu'une seule proposition pouvait exister.
     */
    public function test_releasing_a_competing_offer_does_not_reopen_a_handled_request(): void
    {
        $demande = $this->demande();
        $retenue = $this->proposer($demande, $this->membre());
        $ecartee = $this->proposer($demande, $this->membre(), 30);

        $this->accepter($retenue)->assertRedirect();

        // On force l'ecartee a revenir en attente, puis on l'annule : le
        // chemin « relacher » doit rester sans effet sur la demande.
        //
        // `refresh()` est INDISPENSABLE : `markHandled()` vient de passer cette
        // ligne a `refused` en base, mais l'instance locale porte encore
        // `pending`. Un `forceFill(['status' => 'pending'])` sur elle ne rend
        // donc AUCUN attribut sale, et `save()` n'ecrit rien — le test
        // mesurait un refus d'autorisation (403) au lieu du chemin visé.
        $ecartee->refresh();
        $ecartee->forceFill(['status' => 'pending'])->save();

        $this->actingAs($this->demandeur)
            ->patch(route('organization.transactions.cancel', [
                'organization' => $this->organization->slug,
                'transaction' => $ecartee,
            ]))
            ->assertRedirect();

        $this->assertSame('in_progress', $demande->fresh()->status,
            'quelqu\'un s\'occupe deja de cette demande');
    }

    /** Mais relacher la SEULE proposition rouvre bien la demande. */
    public function test_releasing_the_only_offer_reopens_the_request(): void
    {
        $demande = $this->demande();
        $seule = $this->proposer($demande, $this->membre());

        $this->accepter($seule)->assertRedirect();
        $this->assertSame('in_progress', $demande->fresh()->status);

        $this->actingAs($this->demandeur)
            ->patch(route('organization.transactions.cancel', [
                'organization' => $this->organization->slug,
                'transaction' => $seule,
            ]))
            ->assertRedirect();

        $this->assertSame('open', $demande->fresh()->status,
            'plus rien ne la tient : elle repart a la recherche d\'aide');
    }

    /**
     * LE risque cree par le multi-proposition, et ferme ici.
     *
     * Le solde n'etait verifie qu'a la PROPOSITION, et rien n'est reserve
     * entre les deux — les points ne bougent qu'a la fin de la prestation.
     * Trois offres de 20 points a quelqu'un qui en a 25 passent donc toutes,
     * et accepter la deuxieme mettrait son compte a decouvert. L'acceptation
     * est le moment ou l'engagement devient reel.
     */
    public function test_accepting_is_refused_when_the_balance_no_longer_covers_it(): void
    {
        $demande = $this->demande();
        $premiere = $this->proposer($demande, $this->membre(), 20);
        $seconde = $this->proposer($demande, $this->membre(), 20);

        // Le solde fond entre la proposition et la decision.
        $this->demandeur->forceFill(['points_balance' => 10])->save();

        $this->accepter($premiere)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('pending', $premiere->fresh()->status);
        $this->assertSame('pending', $seconde->fresh()->status,
            'un refus d\'acceptation ne doit emporter aucune concurrente');
        $this->assertSame('open', $demande->fresh()->status);
    }

    /** Une offre de service n'est pas concernee : rien n'y change. */
    public function test_a_service_exchange_is_untouched_by_the_lifecycle(): void
    {
        $vendeur = $this->membre();
        $acheteur = $this->membre();

        $service = \App\Models\Service::factory()->create([
            'user_id' => $vendeur->id,
            'organization_id' => $this->organization->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->organization->id])->id,
            'status' => 'active',
        ]);

        $this->actingAs($acheteur)
            ->post(route('organization.transactions.store', ['organization' => $this->organization->slug]), [
                'service_id' => $service->id,
                'points_proposed' => 20,
            ])
            ->assertRedirect();

        $transaction = Transaction::where('service_id', $service->id)->sole();

        // TASK-1693 bug S : sur une offre de service, c'est le vendeur qui decide.
        $this->actingAs($vendeur)
            ->patch(route('organization.transactions.approve', [
                'organization' => $this->organization->slug,
                'transaction' => $transaction,
            ]))
            ->assertRedirect();

        $this->assertSame('accepted', $transaction->fresh()->status);
        $this->assertNull($transaction->fresh()->request_id);
    }
}
