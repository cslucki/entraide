<?php

namespace Tests\Feature;

use App\Livewire\MessageThread;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\Review;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Entraide\CarteEntraide;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1806 — un seul evaluateur (l'aide), un seul destinataire (l'aidant),
 * une evaluation au maximum par transaction, quel que soit le sens du
 * parcours (demande d'aide ou proposition d'aide).
 *
 * Avant ce correctif, `ReviewPolicy`/`ReviewController`/le formulaire de
 * `message-thread.blade.php` traitaient le sujet comme symetrique
 * (« l'autre participant ») : l'aidant pouvait lui aussi evaluer l'aide. Ces
 * tests prouvent le contraire, dans les DEUX sens du parcours, cote serveur
 * ET cote interface, plus la garde DB de derniere ligne.
 */
class TASK1806EntraideEvaluationUniqueTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $buyer;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->buyer = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->seller = User::factory()->create(['organization_id' => $this->organization->id]);
        app()->instance('current_organization', $this->organization);
    }

    private function completedTransaction(array $overrides = []): Transaction
    {
        return Transaction::factory()
            ->forBuyer($this->buyer)
            ->forSeller($this->seller)
            ->completed()
            ->create(array_merge(['organization_id' => $this->organization->id], $overrides));
    }

    // ── Scenario 1 — demande d'aide : l'aide (buyer) evalue, l'aidant (seller) non ──

    public function test_buyer_can_submit_review_via_http(): void
    {
        $transaction = $this->completedTransaction();

        $response = $this->actingAs($this->buyer)->post(route('reviews.store', $transaction), [
            'rating' => 4,
            'comment' => 'Merci beaucoup !',
        ]);

        $response->assertRedirect(route('messages.show', $transaction));
        $this->assertDatabaseHas('reviews', [
            'transaction_id' => $transaction->id,
            'reviewer_id' => $this->buyer->id,
            'reviewed_id' => $this->seller->id,
            'rating' => 4,
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_seller_cannot_submit_review_via_http(): void
    {
        $transaction = $this->completedTransaction();

        $response = $this->actingAs($this->seller)->post(route('reviews.store', $transaction), [
            'rating' => 5,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('reviews', ['transaction_id' => $transaction->id]);
    }

    // ── Scenario 2 — proposition d'aide : memes roles, sens de la demande inverse ──

    public function test_on_a_service_based_proposal_only_the_buyer_can_review(): void
    {
        $service = Service::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->seller->id,
        ]);
        $transaction = Transaction::factory()
            ->forService($service)
            ->forBuyer($this->buyer)
            ->completed()
            ->create(['organization_id' => $this->organization->id]);

        $this->actingAs($this->buyer)
            ->post(route('reviews.store', $transaction), ['rating' => 5])
            ->assertRedirect();

        $this->assertDatabaseHas('reviews', [
            'transaction_id' => $transaction->id,
            'reviewer_id' => $this->buyer->id,
            'reviewed_id' => $this->seller->id,
        ]);

        // Le seller (auteur du Service, donc l'aidant) ne peut pas evaluer,
        // meme si c'est LUI qui a publie l'annonce initiale.
        $autre = $this->completedTransaction();
        $this->actingAs($this->seller)
            ->post(route('reviews.store', $autre), ['rating' => 3])
            ->assertForbidden();
    }

    // ── Scenario 3 — seul l'aide peut evaluer, cote INTERFACE ──

    public function test_the_review_form_only_renders_for_the_aide(): void
    {
        $transaction = $this->completedTransaction();

        // TASK-1808 — les marqueurs ont change avec le parcours final :
        // l'aide voit le micro-ecran A (les etoiles), l'aidant lit la phrase
        // de remerciement et AUCUN formulaire d'evaluation.
        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->assertSeeHtml('data-entraide-etoiles')
            ->assertSeeHtml('data-entraide-cloture-aide')
            ->assertDontSeeHtml('data-entraide-cloture-aidant');

        Livewire::actingAs($this->seller)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->assertDontSeeHtml('data-entraide-etoiles')
            ->assertDontSeeHtml('data-entraide-mot')
            ->assertSeeHtml('data-entraide-cloture-aidant');
    }

    /** TASK-1806 — « aucune note presélectionnée automatiquement ». */
    public function test_the_rating_is_not_preselected(): void
    {
        $transaction = $this->completedTransaction();

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $transaction])
            // TASK-1808 — l'etat Alpine du micro-ecran A s'appelle `note`.
            ->assertSeeHtml('note: 0')
            ->assertDontSeeHtml('note: 5');
    }

    // ── Scenario 4 — une deuxieme evaluation est refusee ──

    public function test_a_second_review_is_refused(): void
    {
        $transaction = $this->completedTransaction();

        $this->actingAs($this->buyer)->post(route('reviews.store', $transaction), ['rating' => 5]);

        $response = $this->actingAs($this->buyer)->post(route('reviews.store', $transaction), ['rating' => 2]);

        $response->assertForbidden();
        $this->assertSame(1, Review::where('transaction_id', $transaction->id)->count());
    }

    /**
     * TASK-1806 — garde DB de derniere ligne : `unique(transaction_id)` seul.
     * Simule une soumission concurrente en ecrivant directement en base, en
     * contournant l'application — c'est exactement ce que la contrainte doit
     * arreter si jamais la garde applicative regressait.
     */
    public function test_a_second_review_row_is_rejected_at_the_database_level(): void
    {
        $transaction = $this->completedTransaction();

        Review::create([
            'transaction_id' => $transaction->id,
            'organization_id' => $this->organization->id,
            'reviewer_id' => $this->buyer->id,
            'reviewed_id' => $this->seller->id,
            'rating' => 5,
        ]);

        $this->expectException(QueryException::class);

        DB::table('reviews')->insert([
            'id' => (string) Str::uuid(),
            'transaction_id' => $transaction->id,
            'organization_id' => $this->organization->id,
            'reviewer_id' => $this->seller->id,
            'reviewed_id' => $this->buyer->id,
            'rating' => 1,
            'created_at' => now(),
        ]);
    }

    // ── Scenario 5 — aucun double transfert de points ──

    public function test_submitting_a_review_never_touches_points_balance(): void
    {
        $this->buyer->update(['points_balance' => 100]);
        $this->seller->update(['points_balance' => 0]);
        $transaction = $this->completedTransaction(['points_agreed' => 20]);

        $this->actingAs($this->buyer)->post(route('reviews.store', $transaction), ['rating' => 5]);

        $this->assertSame(100, $this->buyer->fresh()->points_balance);
        $this->assertSame(0, $this->seller->fresh()->points_balance);
    }

    // ── Scenario 6 — transaction terminee avec evaluation manquante ──

    public function test_completed_without_review_is_evaluation_en_attente(): void
    {
        $transaction = $this->completedTransaction();

        $this->assertTrue($transaction->entraideRealisee());
        $this->assertFalse($transaction->confirmationEnAttente());
        $this->assertTrue($transaction->pointsAttribues());
        $this->assertTrue($transaction->evaluationEnAttente());
        $this->assertFalse($transaction->evaluationDeposeeOuIgnoree());
    }

    public function test_a_deposited_review_closes_the_evaluation_state(): void
    {
        $transaction = $this->completedTransaction();
        Review::create([
            'transaction_id' => $transaction->id,
            'organization_id' => $this->organization->id,
            'reviewer_id' => $this->buyer->id,
            'reviewed_id' => $this->seller->id,
            'rating' => 5,
        ]);

        $this->assertFalse($transaction->evaluationEnAttente());
        $this->assertTrue($transaction->evaluationDeposeeOuIgnoree());
    }

    /**
     * TASK-1806 §3.A — renoncement explicite, encore disponible en DOMAINE
     * (`EvaluationEntraide::enregistrer(..., null)`). TASK-1809 §2 (MASTER,
     * « simplification finale ») a retire la porte HTTP qui l'appelait
     * (`reviews.skip`, route supprimee — plus aucune penalite a ne pas
     * evaluer, la carte reste simplement proposee) ; ce test verifie donc
     * l'invariant au niveau domaine, pas la route disparue.
     */
    public function test_buyer_can_skip_the_review(): void
    {
        $transaction = $this->completedTransaction();

        \App\Support\Entraide\EvaluationEntraide::enregistrer($transaction, null);

        $transaction->refresh();
        $this->assertNotNull($transaction->reviews_skipped_at);
        $this->assertTrue($transaction->evaluationIgnoree());
        $this->assertTrue($transaction->evaluationDeposeeOuIgnoree());
        $this->assertFalse($transaction->evaluationEnAttente());

        // Une fois ignoree, impossible de revenir deposer un avis.
        $this->actingAs($this->buyer)
            ->post(route('reviews.store', $transaction), ['rating' => 5])
            ->assertForbidden();
        $this->assertDatabaseMissing('reviews', ['transaction_id' => $transaction->id]);
    }

    public function test_buyer_done_is_confirmation_en_attente_not_realisee_points(): void
    {
        $transaction = Transaction::factory()
            ->forBuyer($this->buyer)
            ->forSeller($this->seller)
            ->buyerDone()
            ->create(['organization_id' => $this->organization->id]);

        $this->assertTrue($transaction->entraideRealisee());
        $this->assertTrue($transaction->confirmationEnAttente());
        $this->assertFalse($transaction->pointsAttribues());
        $this->assertFalse($transaction->evaluationEnAttente());
        $this->assertFalse($transaction->evaluationDeposeeOuIgnoree());
    }

    /** Un coup de main offert (0 point) n'a rien a « attribuer ». */
    public function test_a_free_donation_has_no_points_attribues(): void
    {
        $transaction = $this->completedTransaction(['points_agreed' => 0]);

        $this->assertFalse($transaction->pointsAttribues());
        $this->assertTrue($transaction->evaluationEnAttente());
    }

    // ── Diagramme §3.C — la 5e etape « Evaluation » ──

    public function test_the_journey_has_exactly_three_visible_steps(): void
    {
        $this->assertSame(['demande', 'accord', 'confirmation'], CarteEntraide::ETAPES);

        $this->assertSame(3, CarteEntraide::etape($this->completedTransaction()));
    }

    public function test_the_stepper_renders_three_steps_and_lights_the_last_one(): void
    {
        $transaction = $this->completedTransaction();

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->assertSeeHtml('data-entraide-etapier="3"')
            ->assertSee(__('messages.etapes.demande'))
            ->assertSee(__('messages.etapes.accord'))
            ->assertSee(__('messages.etapes.confirmation'))
            // Aucune 4e ni 5e etape, meme cachee.
            ->assertDontSee('Évaluation')
            ->assertDontSeeHtml('data-entraide-etapier="5"');
    }

    // ── Le geste unique de la 3e etape (arbitrage du 11/11) ──────────────────

    private function acceptedTransaction(array $overrides = []): Transaction
    {
        return Transaction::factory()
            ->forBuyer($this->buyer)
            ->forSeller($this->seller)
            ->accepted()
            ->create(array_merge(['organization_id' => $this->organization->id], $overrides));
    }

    public function test_confirming_closes_transfers_points_and_records_the_review_in_one_gesture(): void
    {
        $this->buyer->update(['points_balance' => 100]);
        $this->seller->update(['points_balance' => 10]);
        $transaction = $this->acceptedTransaction(['points_proposed' => 20, 'points_agreed' => 20]);

        $this->actingAs($this->buyer)
            ->patch(route('transactions.complete', $transaction), [
                'rating' => 5,
                'comment' => 'Merci beaucoup !',
            ])
            ->assertRedirect();

        $transaction->refresh();
        $this->assertSame('completed', $transaction->status);
        $this->assertSame(80, $this->buyer->fresh()->points_balance);
        $this->assertSame(30, $this->seller->fresh()->points_balance);
        $this->assertDatabaseHas('reviews', [
            'transaction_id' => $transaction->id,
            'reviewer_id' => $this->buyer->id,
            'reviewed_id' => $this->seller->id,
            'rating' => 5,
        ]);
        // Un seul geste : il n'existe plus d'etat intermediaire `buyer_done`.
        $this->assertDatabaseMissing('transactions', [
            'id' => $transaction->id,
            'status' => 'buyer_done',
        ]);
    }

    /** « ou ignorer l'evaluation » — confirmer sans note reste un choix entier. */
    public function test_confirming_without_a_rating_closes_and_leaves_the_evaluation_open(): void
    {
        $transaction = $this->acceptedTransaction(['points_proposed' => 20, 'points_agreed' => 20]);

        $this->actingAs($this->buyer)
            ->patch(route('transactions.complete', $transaction), ['rating' => null])
            ->assertRedirect();

        $transaction->refresh();
        $this->assertSame('completed', $transaction->status);
        $this->assertDatabaseMissing('reviews', ['transaction_id' => $transaction->id]);
        // TASK-1808 (clarification MASTER) — ce test attendait l'INVERSE :
        // T1806 traitait une cloture sans note comme un renoncement et
        // ecrivait `reviews_skipped_at`. Dans le parcours final, l'evaluation
        // vient APRES la cloture : une cloture nue signifie « pas encore
        // proposee », et l'ecran des etoiles doit pouvoir s'ouvrir ensuite.
        $this->assertNull($transaction->reviews_skipped_at);
        $this->assertTrue($transaction->evaluationEnAttente());
        // Aucune note inventee n'a pu entrer dans la moyenne de l'aidant.
        $this->assertNull($this->seller->fresh()->rating);
    }

    /** La confirmation distincte cote aidant a disparu du parcours. */
    public function test_the_helper_cannot_confirm(): void
    {
        $transaction = $this->acceptedTransaction();

        $this->actingAs($this->seller)
            ->patch(route('transactions.complete', $transaction))
            ->assertForbidden();

        $this->assertSame('accepted', $transaction->fresh()->status);
    }

    public function test_a_second_confirmation_never_transfers_points_twice(): void
    {
        $this->buyer->update(['points_balance' => 100]);
        $this->seller->update(['points_balance' => 0]);
        $transaction = $this->acceptedTransaction(['points_proposed' => 20, 'points_agreed' => 20]);

        $this->actingAs($this->buyer)->patch(route('transactions.complete', $transaction));
        // Le second envoi est refuse par la policy : l'entraide n'est plus
        // `accepted`. Ni ledger, ni solde ne bougent une seconde fois.
        $this->actingAs($this->buyer)
            ->patch(route('transactions.complete', $transaction))
            ->assertForbidden();

        $this->assertSame(80, $this->buyer->fresh()->points_balance);
        $this->assertSame(20, $this->seller->fresh()->points_balance);
        $this->assertSame(2, PointLedger::where('transaction_id', $transaction->id)->count());
    }

    /** `seller_confirmed_at` enregistrait un geste qui n'existe plus. */
    public function test_the_new_flow_no_longer_writes_seller_confirmed_at(): void
    {
        $transaction = $this->acceptedTransaction(['points_proposed' => 20, 'points_agreed' => 20]);

        $this->actingAs($this->buyer)->patch(route('transactions.complete', $transaction));

        $transaction->refresh();
        $this->assertNotNull($transaction->buyer_confirmed_at);
        $this->assertNotNull($transaction->completed_at);
        $this->assertNull($transaction->seller_confirmed_at);
    }

    /** Un coup de main OFFERT traverse tout le cycle sans rien transferer. */
    public function test_a_free_help_confirms_without_touching_the_ledger(): void
    {
        $this->buyer->update(['points_balance' => 100]);
        $transaction = $this->acceptedTransaction(['points_proposed' => 0, 'points_agreed' => 0]);

        $this->actingAs($this->buyer)
            ->patch(route('transactions.complete', $transaction), ['rating' => 4]);

        $this->assertSame('completed', $transaction->fresh()->status);
        $this->assertSame(100, $this->buyer->fresh()->points_balance);
        $this->assertSame(0, PointLedger::where('transaction_id', $transaction->id)->count());
        $this->assertDatabaseHas('reviews', ['transaction_id' => $transaction->id, 'rating' => 4]);
    }

    /**
     * VERIFICATION MASTER du 11/11 — un avis qui ne s'ecrit pas ne doit pas
     * passer pour publie.
     *
     * La panne est REELLE, pas simulee par une doublure : la table `reviews`
     * est retiree juste avant la confirmation, donc l'ecriture echoue pour de
     * bon. Trois proprietes sont mesurees d'un coup :
     *
     *   1. l'entraide est bien close et les points bien transferes ;
     *   2. le message rendu ne dit PAS « merci » — il dit que l'avis n'est pas
     *      parti ;
     *   3. l'echec est identifiable dans le journal, avec son evenement nomme.
     *
     * La recuperation sans second transfert est mesuree par le test suivant.
     */
    public function test_a_failed_review_never_passes_for_a_published_one(): void
    {
        Log::spy();

        $this->buyer->update(['points_balance' => 100]);
        $this->seller->update(['points_balance' => 0]);
        $transaction = $this->acceptedTransaction(['points_proposed' => 20, 'points_agreed' => 20]);

        // TASK-1808 §2 (cockpit) — `review_moderation_logs` porte une FK
        // vers `reviews` : en PostgreSQL, `DROP TABLE reviews` refuse tant
        // qu'une table dependante existe encore. On la retire d'abord ; elle
        // est vide a ce stade du test et n'est pas ce que ce test mesure.
        Schema::dropIfExists('review_moderation_logs');
        Schema::drop('reviews');

        $reponse = $this->actingAs($this->buyer)
            ->patch(route('transactions.complete', $transaction), [
                'rating' => 5,
                'comment' => 'Un mot qui ne sera pas enregistre.',
            ]);

        // 1 — la cloture et le transfert, eux, ont bien eu lieu.
        $this->assertSame('completed', $transaction->fresh()->status);
        $this->assertSame(80, $this->buyer->fresh()->points_balance);
        $this->assertSame(20, $this->seller->fresh()->points_balance);

        // 2 — aucune fausse confirmation : le message dit la verite.
        $reponse->assertSessionHas('error', __('entraide.flash.echange_termine_avis_non_enregistre'));
        $reponse->assertSessionMissing('success');

        // 3 — l'echec est identifiable.
        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $contexte) use ($transaction) {
                return $message === 'Evaluation could not be recorded after a successful closure'
                    && $contexte['event'] === 'entraide_evaluation_not_recorded'
                    && $contexte['transaction_id'] === $transaction->id;
            });
    }

    /**
     * VERIFICATION MASTER — et l'avis perdu reste redeposable, SANS que les
     * points repartent une seconde fois.
     *
     * L'etat laisse par une ecriture ratee est exactement celui d'une
     * entraide close sans avis : `evaluationEnAttente()` reste vrai, la carte
     * d'evaluation revient, et `ReviewController` ne touche jamais au ledger.
     */
    public function test_a_lost_review_can_be_posted_again_without_a_second_transfer(): void
    {
        $this->buyer->update(['points_balance' => 100]);
        $this->seller->update(['points_balance' => 0]);
        $transaction = $this->acceptedTransaction(['points_proposed' => 20, 'points_agreed' => 20]);

        $this->actingAs($this->buyer)->patch(route('transactions.complete', $transaction));
        // L'etat d'apres-panne : close, sans avis, sans renoncement.
        $transaction->update(['reviews_skipped_at' => null]);
        $transaction->refresh();

        $this->assertTrue($transaction->evaluationEnAttente(), 'L\'avis doit rester a deposer.');

        // La carte d'evaluation est bien de retour pour l'aide.
        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->assertSeeHtml('data-entraide-evaluation');

        $this->actingAs($this->buyer)
            ->post(route('reviews.store', $transaction), ['rating' => 5, 'comment' => 'Redepose.'])
            ->assertRedirect();

        $this->assertDatabaseHas('reviews', [
            'transaction_id' => $transaction->id,
            'reviewer_id' => $this->buyer->id,
            'reviewed_id' => $this->seller->id,
            'rating' => 5,
        ]);

        // AUCUN second transfert : les soldes et le ledger n'ont pas bouge.
        $this->assertSame(80, $this->buyer->fresh()->points_balance);
        $this->assertSame(20, $this->seller->fresh()->points_balance);
        $this->assertSame(2, PointLedger::where('transaction_id', $transaction->id)->count());
    }

    public function test_the_confirmation_card_renders_only_for_the_aide(): void
    {
        $transaction = $this->acceptedTransaction();

        // TASK-1808 (clarification MASTER) — la cloture est UN BOUTON qui
        // poste directement. Plus de panneau, et le partage dans le Flux a
        // quitte cette etape : il n'existe plus d'ecran avant la cloture.
        // TASK-1809 (correctif Cyril) — le partage est devenu automatique a
        // l'evaluation, le marqueur `data-entraide-partager-flux` n'existe
        // plus nulle part dans le code.
        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->assertSeeHtml('data-entraide-cloturer');

        Livewire::actingAs($this->seller)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->assertDontSeeHtml('data-entraide-cloturer');
    }

    /** Le bouton de cloture n'est JAMAIS desactive : confirmer sans avis est un choix. */
    public function test_the_confirmation_button_is_never_disabled(): void
    {
        $transaction = $this->acceptedTransaction();

        $html = Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->html();

        // TASK-1808 — le bouton de validation du panneau de cloture.
        $bouton = substr($html, strpos($html, 'data-entraide-cloturer-valider') - 400, 500);
        $this->assertStringNotContainsString(':disabled', $bouton);
    }
}
