<?php

namespace Tests\Feature;

use App\Livewire\MessageThread;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Entraide\CarteEntraide;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->assertSeeHtml('data-entraide-merci')
            ->assertDontSeeHtml('data-entraide-contribution');

        Livewire::actingAs($this->seller)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->assertDontSeeHtml('data-entraide-merci')
            ->assertSeeHtml('data-entraide-contribution');
    }

    /** TASK-1806 — « aucune note presélectionnée automatiquement ». */
    public function test_the_rating_is_not_preselected(): void
    {
        $transaction = $this->completedTransaction();

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->assertSeeHtml("rating: 0")
            ->assertDontSeeHtml('{ rating: 5, hovered: 0 }');
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
            'id' => (string) \Illuminate\Support\Str::uuid(),
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

    /** TASK-1806 §3.A — possibilite de ne pas laisser d'avis. */
    public function test_buyer_can_skip_the_review(): void
    {
        $transaction = $this->completedTransaction();

        $response = $this->actingAs($this->buyer)->post(route('reviews.skip', $transaction));

        $response->assertRedirect(route('messages.show', $transaction));
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

    public function test_the_fifth_step_is_evaluation_and_lights_up_on_completion(): void
    {
        $this->assertSame(
            ['proposition', 'accord', 'aide', 'confirmation', 'evaluation'],
            CarteEntraide::ETAPES
        );

        $transaction = $this->completedTransaction();
        $this->assertSame(5, CarteEntraide::etape($transaction));
    }

    public function test_the_stepper_renders_five_steps_including_evaluation(): void
    {
        $transaction = $this->completedTransaction();

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->assertSeeHtml('data-entraide-etapier="5"')
            ->assertSee(__('messages.etapes.evaluation'));
    }
}
