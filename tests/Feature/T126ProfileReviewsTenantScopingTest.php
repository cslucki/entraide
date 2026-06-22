<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Review;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T126 — Profile Reviews Tenant Scoping (P0/P1)
 *
 * Vérifie que reviewsReceived() sur un profil public ne retourne pas de reviews
 * issues de transactions appartenant à une autre Organization.
 *
 * Source du risque : T124 audit — reviewsReceived() est un hasMany(Review::class)
 * sans filtre tenant. Review n'a pas de community_id / organization_id.
 * Un user ayant des reviews d'une org B peut les voir apparaître sur son profil
 * public consulté depuis une org A.
 *
 * Tests verts : profil accessible uniquement depuis la même org (déjà couvert T0757).
 * Tests de risque résiduel : reviews cross-org potentiellement visibles si le user
 * appartient à l'org courante mais a reçu des reviews de transactions d'une autre org.
 *
 * Si test_profile_does_not_show_reviews_from_other_organization_transactions ÉCHOUE,
 * le risque P1 est CONFIRMÉ : les reviews cross-org apparaissent sur le profil.
 */
class T126ProfileReviewsTenantScopingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['name' => 'T126 Profile Org A']);
        $this->orgB = Organization::factory()->create(['name' => 'T126 Profile Org B']);
    }

    // -------------------------------------------------------------------------
    // Comportement normal — accès profil dans la même org
    // -------------------------------------------------------------------------

    public function test_profile_shows_reviews_from_same_organization(): void
    {
        app()->instance('current_organization', $this->orgA);

        $user = User::factory()->create(['organization_id' => $this->orgA->id]);

        $reviewer = User::factory()->create(['organization_id' => $this->orgA->id]);
        $service = Service::factory()->forUser($user)->create(['organization_id' => $this->orgA->id]);
        $transaction = Transaction::factory()
            ->forService($service)
            ->forBuyer($reviewer)
            ->completed()
            ->create(['organization_id' => $this->orgA->id]);

        Review::factory()->forTransaction($transaction)->create([
            'reviewed_id' => $user->id,
            'reviewer_id' => $reviewer->id,
            'comment' => 'T126_REVIEW_SAME_ORG_VISIBLE',
            'rating' => 5,
        ]);

        $this->get(route('profile.show', $user))
            ->assertOk()
            ->assertSee('T126_REVIEW_SAME_ORG_VISIBLE');
    }

    // -------------------------------------------------------------------------
    // Test de risque résiduel — reviews cross-org
    //
    // Scénario : userA appartient à l'org A (profil accessible depuis org A).
    // userA a aussi participé à des transactions dans l'org B et reçu des reviews.
    // Ces reviews cross-org apparaissent-elles sur son profil vue depuis org A ?
    //
    // reviewsReceived() n'a pas de filtre org → risque P1.
    //
    // Si ce test ÉCHOUE, le risque est CONFIRMÉ.
    // Patch recommandé (à valider COCKPIT) :
    // - Filtrer reviewsReceived() par l'org courante dans ProfileController
    //   ex: $user->reviewsReceived()->whereHas('transaction', fn($q) =>
    //       $q->where('organization_id', currentOrganization()->id))
    // -------------------------------------------------------------------------

    public function test_profile_does_not_show_reviews_from_other_organization_transactions(): void
    {
        app()->instance('current_organization', $this->orgA);

        // L'utilisateur profileé appartient à l'org A (profil accessible)
        $profiledUser = User::factory()->create(['organization_id' => $this->orgA->id]);

        // Un reviewer de l'org B a effectué une transaction dans l'org B avec profiledUser
        $reviewerInB = User::factory()->create(['organization_id' => $this->orgB->id]);
        $serviceInB = Service::factory()->forUser($profiledUser)->create([
            'organization_id' => $this->orgB->id,
        ]);
        $transactionInB = Transaction::factory()
            ->forService($serviceInB)
            ->forBuyer($reviewerInB)
            ->completed()
            ->create(['organization_id' => $this->orgB->id]);

        Review::factory()->forTransaction($transactionInB)->create([
            'reviewed_id' => $profiledUser->id,
            'reviewer_id' => $reviewerInB->id,
            'comment' => 'T126_REVIEW_CROSS_ORG_HIDDEN',
            'rating' => 3,
        ]);

        // La review cross-org NE DOIT PAS apparaître sur le profil consulté depuis org A
        $this->get(route('profile.show', $profiledUser))
            ->assertOk()
            ->assertDontSee('T126_REVIEW_CROSS_ORG_HIDDEN');
    }

    public function test_profile_shows_only_reviews_scoped_to_current_organization(): void
    {
        app()->instance('current_organization', $this->orgA);

        $profiledUser = User::factory()->create(['organization_id' => $this->orgA->id]);

        // Review org A (doit être visible)
        $reviewerA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $serviceA = Service::factory()->forUser($profiledUser)->create(['organization_id' => $this->orgA->id]);
        $txA = Transaction::factory()
            ->forService($serviceA)
            ->forBuyer($reviewerA)
            ->completed()
            ->create(['organization_id' => $this->orgA->id]);
        Review::factory()->forTransaction($txA)->create([
            'reviewed_id' => $profiledUser->id,
            'reviewer_id' => $reviewerA->id,
            'comment' => 'T126_REVIEW_ORG_A_VISIBLE',
            'rating' => 5,
        ]);

        // Review org B (ne doit pas être visible)
        $reviewerB = User::factory()->create(['organization_id' => $this->orgB->id]);
        $serviceB = Service::factory()->forUser($profiledUser)->create(['organization_id' => $this->orgB->id]);
        $txB = Transaction::factory()
            ->forService($serviceB)
            ->forBuyer($reviewerB)
            ->completed()
            ->create(['organization_id' => $this->orgB->id]);
        Review::factory()->forTransaction($txB)->create([
            'reviewed_id' => $profiledUser->id,
            'reviewer_id' => $reviewerB->id,
            'comment' => 'T126_REVIEW_ORG_B_HIDDEN',
            'rating' => 1,
        ]);

        $response = $this->get(route('profile.show', $profiledUser))->assertOk();

        $response->assertSee('T126_REVIEW_ORG_A_VISIBLE')
            ->assertDontSee('T126_REVIEW_ORG_B_HIDDEN');
    }
}
