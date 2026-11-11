<?php

namespace Tests\Feature\Policies;

use App\Models\Organization;
use App\Models\Review;
use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

class ReviewPolicyTest extends TestCase
{
    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        app()->instance('current_organization', $this->org);
    }

    public function test_buyer_can_review_completed_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->completed()->create(['organization_id' => $this->org->id]);
        $this->assertTrue($buyer->can('create-review', $transaction));
    }

    /**
     * TASK-1806 — l'aidant (seller) ne peut JAMAIS evaluer l'aide (buyer).
     *
     * Ce test affirmait l'INVERSE avant ce correctif
     * (`assertTrue($seller->can(...))`) : c'etait la preuve en base du bug —
     * un test vert qui garantissait le comportement que la regle produit
     * interdit.
     */
    public function test_seller_cannot_review_completed_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->completed()->create(['organization_id' => $this->org->id]);
        $this->assertFalse($seller->can('create-review', $transaction));
    }

    /**
     * TASK-1806 scenario 2 — une PROPOSITION d'aide (reponse a un Service,
     * donc `service_id` renseigne) suit la MEME regle qu'une demande : seul
     * l'aide (buyer) evalue, jamais l'aidant (seller, auteur du Service).
     */
    public function test_seller_cannot_review_on_a_service_based_proposal(): void
    {
        $service = \App\Models\Service::factory()->create(['organization_id' => $this->org->id]);
        $buyer = User::factory()->create();
        $transaction = Transaction::factory()->forService($service)->forBuyer($buyer)->completed()->create(['organization_id' => $this->org->id]);

        $this->assertTrue($buyer->can('create-review', $transaction));
        $this->assertFalse($service->user->can('create-review', $transaction));
    }

    public function test_cannot_review_non_completed_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->accepted()->create(['organization_id' => $this->org->id]);
        $this->assertFalse($buyer->can('create-review', $transaction));
    }

    public function test_non_participant_cannot_review(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $other = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->completed()->create(['organization_id' => $this->org->id]);
        $this->assertFalse($other->can('create-review', $transaction));
    }

    public function test_cannot_review_twice(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->completed()->create(['organization_id' => $this->org->id]);
        Review::create([
            'transaction_id' => $transaction->id,
            'reviewer_id' => $buyer->id,
            'reviewed_id' => $seller->id,
            'rating' => 5,
        ]);
        // TASK-1806 — le nom du gate etait faux (`'create'`), l'assertion
        // passait donc sans jamais interroger `ReviewPolicy::create()`.
        $this->assertFalse($buyer->can('create-review', $transaction));
    }

    public function test_cross_organization_denied(): void
    {
        $otherOrg = Organization::factory()->create();
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->completed()->create(['organization_id' => $otherOrg->id]);
        $this->assertFalse($buyer->can('create-review', $transaction));
    }

    /** TASK-1806 §3.A — une evaluation explicitement ignoree n'est plus proposable. */
    public function test_cannot_review_after_skipping(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->completed()->create([
            'organization_id' => $this->org->id,
            'reviews_skipped_at' => now(),
        ]);
        $this->assertFalse($buyer->can('create-review', $transaction));
    }

    public function test_no_organization_resolved_denied(): void
    {
        app()->forgetInstance('current_organization');
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->completed()->create(['organization_id' => $this->org->id]);
        $this->assertFalse($buyer->can('create-review', $transaction));
    }
}
