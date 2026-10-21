<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1693, bug S — decide celui qui n'a PAS propose.
 *
 * ## Le defaut, vu en recette
 *
 * Sur l'echange ne d'une demande d'aide, l'ecran de l'AUTEUR DE LA DEMANDE
 * n'offrait que « Ajuster » et « Annuler » : aucun bouton « Accepter ». Celui-ci
 * s'affichait chez la personne qui venait de PROPOSER son aide — elle devait
 * donc accepter sa propre offre, pendant que la personne concernee regardait
 * sans pouvoir repondre. Aucun echange ne d'une demande d'aide ne pouvait
 * aboutir normalement.
 *
 * ## La racine
 *
 * Le modele sous-jacent est « l'acheteur propose, le vendeur accepte », ce qui
 * colle a une OFFRE DE SERVICE. Sur une DEMANDE D'AIDE, `TransactionController`
 * inverse les roles economiques — celui qui rend le service encaisse les
 * points, donc il est « vendeur » — et le role DECISIONNEL n'avait pas suivi.
 *
 * ## Trois symptomes, une cause
 *
 * 1. le bouton « Accepter » chez le proposant ;
 * 2. la policy `approve`/`refuse` en dur sur `seller_id` — y compris sa COPIE
 *    dans l'API, qui ne passait meme pas par la policy ;
 * 3. la garde anti-doublon, qui visait l'acheteur : le SECOND aidant se voyait
 *    repondre « Vous avez deja une transaction en cours pour cette annonce »
 *    alors qu'il n'avait rien.
 *
 * La regle vit desormais a UN endroit, `Transaction::decisionMakerId()`.
 */
class TASK1693ExchangeDecisionMakerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $demandeur;

    private User $aidant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693s',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->demandeur = $this->membre(points: 500);
        $this->aidant = $this->membre(points: 500);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function membre(int $points = 100): User
    {
        return User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
            'points_balance' => $points,
        ]);
    }

    private function categorie(): Category
    {
        return Category::factory()->create(['organization_id' => $this->organization->id]);
    }

    /** Une demande d'aide : c'est l'aidant qui PROPOSE. */
    private function demandeDAide(): ServiceRequest
    {
        return ServiceRequest::factory()->create([
            'user_id' => $this->demandeur->id,
            'organization_id' => $this->organization->id,
            'category_id' => $this->categorie()->id,
            'status' => 'open',
        ]);
    }

    /** Une offre de service : c'est l'acheteur qui PROPOSE. */
    private function offreDeService(User $vendeur): Service
    {
        return Service::factory()->create([
            'user_id' => $vendeur->id,
            'organization_id' => $this->organization->id,
            'category_id' => $this->categorie()->id,
            'status' => 'active',
        ]);
    }

    private function proposerSurDemande(ServiceRequest $demande, ?User $par = null): Transaction
    {
        $this->actingAs($par ?? $this->aidant)
            ->post(route('organization.transactions.store', ['organization' => $this->organization->slug]), [
                'request_id' => $demande->id,
                'points_proposed' => 20,
            ])
            ->assertRedirect();

        return Transaction::where('request_id', $demande->id)
            ->where('seller_id', ($par ?? $this->aidant)->id)
            ->sole();
    }

    // ── Demande d'aide : le monde ou le defaut vivait ────────────────────────

    /**
     * LA mesure du defaut : les roles sont inverses, et le decideur est
     * l'auteur de la demande.
     */
    public function test_on_a_help_request_the_decision_belongs_to_the_requester(): void
    {
        $transaction = $this->proposerSurDemande($this->demandeDAide());

        // Les roles economiques restent ceux d'avant : l'aidant encaisse.
        $this->assertSame($this->demandeur->id, $transaction->buyer_id);
        $this->assertSame($this->aidant->id, $transaction->seller_id);

        // Mais la DECISION revient a celui qui n'a pas propose.
        $this->assertSame($this->aidant->id, $transaction->proposerId());
        $this->assertSame($this->demandeur->id, $transaction->decisionMakerId());

        $this->assertTrue($this->demandeur->can('approve', $transaction));
        $this->assertFalse($this->aidant->can('approve', $transaction),
            'personne ne doit accepter sa propre proposition');
    }

    /** Et le geste ABOUTIT pour le demandeur — c'est le fait, pas la policy. */
    public function test_the_requester_can_actually_accept_the_offer(): void
    {
        $transaction = $this->proposerSurDemande($this->demandeDAide());

        $this->actingAs($this->demandeur)
            ->patch(route('organization.transactions.approve', [
                'organization' => $this->organization->slug,
                'transaction' => $transaction,
            ]))
            ->assertRedirect();

        $this->assertSame('accepted', $transaction->fresh()->status);
    }

    /** Tandis que l'aidant se voit refuser l'acceptation de sa propre offre. */
    public function test_the_helper_cannot_accept_their_own_offer(): void
    {
        $transaction = $this->proposerSurDemande($this->demandeDAide());

        $this->actingAs($this->aidant)
            ->patch(route('organization.transactions.approve', [
                'organization' => $this->organization->slug,
                'transaction' => $transaction,
            ]))
            ->assertForbidden();

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    /** Le bouton suit la regle : il s'affiche chez le demandeur, pas chez l'aidant. */
    public function test_the_accept_button_is_shown_to_the_requester_only(): void
    {
        $transaction = $this->proposerSurDemande($this->demandeDAide());
        $url = route('organization.messages.show', [
            'organization' => $this->organization->slug,
            'transaction' => $transaction,
        ]);

        $chezLeDemandeur = $this->actingAs($this->demandeur)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString(
            e(route('organization.transactions.approve', [
                'organization' => $this->organization->slug,
                'transaction' => $transaction,
            ])),
            $chezLeDemandeur,
            'le demandeur doit pouvoir repondre a l\'offre qu\'on lui fait',
        );

        $chezLAidant = $this->actingAs($this->aidant)->get($url)->assertOk()->getContent();
        $this->assertStringNotContainsString(
            e(route('organization.transactions.approve', [
                'organization' => $this->organization->slug,
                'transaction' => $transaction,
            ])),
            $chezLAidant,
            'l\'aidant ne doit pas se voir offrir d\'accepter sa propre proposition',
        );
    }

    /** Refuser suit la meme regle : c'est l'autre face de la decision. */
    public function test_refusing_follows_the_same_rule(): void
    {
        $transaction = $this->proposerSurDemande($this->demandeDAide());

        $this->assertTrue($this->demandeur->can('refuse', $transaction));
        $this->assertFalse($this->aidant->can('refuse', $transaction));
    }

    // ── Offre de service : ce qui ne doit PAS bouger ─────────────────────────

    /**
     * LA non-regression. Sur une offre de service, le proposant est l'acheteur
     * et le decideur le vendeur — comportement historique, a l'identique.
     */
    public function test_on_a_service_offer_the_decision_still_belongs_to_the_seller(): void
    {
        $vendeur = $this->membre();
        $acheteur = $this->membre(points: 500);
        $service = $this->offreDeService($vendeur);

        $this->actingAs($acheteur)
            ->post(route('organization.transactions.store', ['organization' => $this->organization->slug]), [
                'service_id' => $service->id,
                'points_proposed' => 20,
            ])
            ->assertRedirect();

        $transaction = Transaction::where('service_id', $service->id)->sole();

        $this->assertSame($acheteur->id, $transaction->proposerId());
        $this->assertSame($vendeur->id, $transaction->decisionMakerId());

        $this->assertTrue($vendeur->can('approve', $transaction));
        $this->assertFalse($acheteur->can('approve', $transaction));
    }

    /**
     * La choregraphie de FIN n'est pas touchee.
     *
     * L'acheteur declare la prestation terminee, le vendeur confirme : c'est
     * juste dans les deux sens — celui qui a recu l'aide declare, celui qui l'a
     * rendue confirme, et les points vont de l'acheteur au vendeur. Seule la
     * decision sur la PROPOSITION etait inversee.
     */
    public function test_the_completion_choreography_is_untouched(): void
    {
        $transaction = $this->proposerSurDemande($this->demandeDAide());
        $transaction->forceFill(['status' => 'buyer_done'])->save();

        $this->assertTrue($this->aidant->can('confirm', $transaction),
            'celui qui a RENDU le service confirme : inchange');
        $this->assertFalse($this->demandeur->can('confirm', $transaction));
    }

    // ── La garde anti-doublon ────────────────────────────────────────────────

    /**
     * Le troisieme symptome : « VOUS avez deja une transaction en cours »
     * parlait de l'acheteur, donc de l'auteur de la demande.
     *
     * Un SECOND aidant se voyait donc refuser sa proposition avec un message
     * faux pour lui — il n'avait rien. La demande d'aide ne pouvait recevoir
     * qu'une seule offre, de qui que ce soit.
     */
    public function test_a_second_helper_is_no_longer_blocked_by_the_first(): void
    {
        $demande = $this->demandeDAide();
        $this->proposerSurDemande($demande);

        $secondAidant = $this->membre();

        $this->actingAs($secondAidant)
            ->post(route('organization.transactions.store', ['organization' => $this->organization->slug]), [
                'request_id' => $demande->id,
                'points_proposed' => 25,
            ])
            ->assertRedirect()
            ->assertSessionMissing('error');

        $this->assertSame(
            2,
            Transaction::where('request_id', $demande->id)->count(),
            'deux personnes differentes peuvent proposer leur aide',
        );
    }

    /** Mais la MEME personne ne propose toujours pas deux fois. */
    public function test_the_same_helper_still_cannot_propose_twice(): void
    {
        $demande = $this->demandeDAide();
        $this->proposerSurDemande($demande);

        $this->actingAs($this->aidant)
            ->post(route('organization.transactions.store', ['organization' => $this->organization->slug]), [
                'request_id' => $demande->id,
                'points_proposed' => 30,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, Transaction::where('request_id', $demande->id)->count());
    }

    /** Et sur une offre de service, la garde reste identique a elle-meme. */
    public function test_the_same_buyer_still_cannot_propose_twice_on_a_service(): void
    {
        $vendeur = $this->membre();
        $acheteur = $this->membre(points: 500);
        $service = $this->offreDeService($vendeur);

        $payload = ['service_id' => $service->id, 'points_proposed' => 20];
        $url = route('organization.transactions.store', ['organization' => $this->organization->slug]);

        $this->actingAs($acheteur)->post($url, $payload)->assertRedirect();
        $this->actingAs($acheteur)->post($url, $payload)->assertRedirect()->assertSessionHas('error');

        $this->assertSame(1, Transaction::where('service_id', $service->id)->count());
    }
}
