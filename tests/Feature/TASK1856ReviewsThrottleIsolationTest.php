<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1856 — incident PROD 1.855 : `reviews.store` partageait le compteur
 * BRUT `throttle:5,1` avec sept autres routes (`reports.*`,
 * `bug-reports.store`, `loops.store`, `loops.join-requests.store`,
 * `loops.ai`, `loops.knowledge.ask`, `dossiers.insights`) —
 * `ThrottleRequests::resolveRequestSignature()` ne hache que
 * l'identifiant de l'utilisateur, jamais le nom de la route ni du
 * limiteur. Un cinquieme appel sur N'IMPORTE LAQUELLE de ces huit routes,
 * par le MEME utilisateur, dans la MEME minute, faisait 429 sur les
 * autres.
 *
 * Correctif : un limiteur NOMME dedie (`RateLimiter::for('reviews', ...)`,
 * `AppServiceProvider`), applique via `throttle:reviews` aux DEUX routes
 * (`reviews.store` globale et `organization.reviews.store`). Verifie dans
 * le vendor : `ThrottleRequests::handleRequestUsingNamedLimiter()` hache
 * `$limiterName.$limit->key` — le nom du limiteur fait partie de la cle,
 * l'isolation est donc automatique. Meme limite EXACTE qu'avant : 5
 * requetes / 60 secondes, par utilisateur authentifie.
 *
 * Perimetre de ce fichier : l'ISOLATION du compteur et sa LIMITE exacte.
 * Le comportement metier de `ReviewController::store()` lui-meme
 * (destinataire, unicite, idempotence) reste couvert par
 * `TASK1806EntraideEvaluationUniqueTest` — inchange, non duplique ici.
 */
class TASK1856ReviewsThrottleIsolationTest extends TestCase
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

    /** Une transaction COMPLETED distincte par appel — l'unicite (transaction_id) d'un avis interdit d'en reposer un sur la meme ligne. */
    private function completedTransaction(array $overrides = []): Transaction
    {
        return Transaction::factory()
            ->forBuyer($this->buyer)
            ->forSeller($this->seller)
            ->completed()
            ->create(array_merge(['organization_id' => $this->organization->id], $overrides));
    }

    // ── 1. La limite EXACTE de reviews.store est preservee : 5/60s ─────────

    public function test_cinq_avis_distincts_passent_un_sixieme_est_limite(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $transaction = $this->completedTransaction();

            $reponse = $this->actingAs($this->buyer)->post(route('reviews.store', $transaction), [
                'rating' => 5,
            ]);

            $reponse->assertStatus(302, "L'appel #{$i} (sous la limite) ne doit jamais etre limite.");
        }

        $sixieme = $this->completedTransaction();

        $reponse = $this->actingAs($this->buyer)->post(route('reviews.store', $sixieme), [
            'rating' => 5,
        ]);

        $reponse->assertStatus(429, 'Le 6e appel dans la meme minute doit etre limite : la limite reste exactement 5/60s.');
    }

    // ── 2. Consommer le compteur PARTAGE (bug-reports.store) ne limite pas reviews.store ──

    public function test_consommer_le_compteur_partage_bug_reports_ne_limite_pas_reviews_store(): void
    {
        // 5 appels sur UNE AUTRE route qui porte encore le throttle BRUT
        // `5,1` — avant ce correctif, ces 5 appels auraient AUSSI rempli
        // le compteur de `reviews.store` pour ce meme utilisateur.
        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($this->buyer)->post('/report', []);
        }

        // Sanity : le compteur BRUT partage fonctionne toujours comme avant
        // (regression), un 6e appel SUR CETTE MEME route reste limite.
        $this->actingAs($this->buyer)->post('/report', [])
            ->assertStatus(429, 'Precondition non prise : le compteur brut partage doit toujours limiter apres 5 appels.');

        // Le fait reel teste : reviews.store, pour ce MEME utilisateur, n'a
        // RIEN consomme de son propre compteur — son premier appel passe.
        $transaction = $this->completedTransaction();

        $this->actingAs($this->buyer)->post(route('reviews.store', $transaction), ['rating' => 5])
            ->assertStatus(302, 'reviews.store ne doit jamais etre limite par un compteur consomme sur une AUTRE route.');
    }

    // ── 3. Inverse : consommer reviews.store ne limite pas le compteur partage ──

    public function test_consommer_reviews_store_ne_limite_pas_le_compteur_partage_bug_reports(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $transaction = $this->completedTransaction();

            $this->actingAs($this->buyer)->post(route('reviews.store', $transaction), ['rating' => 5])
                ->assertStatus(302);
        }

        // Le compteur BRUT partage (`bug-reports.store` et les six autres
        // routes `throttle:5,1`) n'a RIEN consomme : son premier appel pour
        // ce meme utilisateur passe encore.
        $this->actingAs($this->buyer)->post('/report', [])
            ->assertStatus(302, "bug-reports.store ne doit jamais etre limite par les 5 appels consommes sur reviews.store.");
    }

    // ── 4. La variante /org/{organization} porte le MEME limiteur nomme, isole de la meme facon ──

    public function test_la_variante_organization_porte_le_meme_limiteur_isole(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $transaction = $this->completedTransaction();

            $reponse = $this->actingAs($this->buyer)->post(
                route('organization.reviews.store', ['organization' => $this->organization->slug, 'transaction' => $transaction]),
                ['rating' => 5]
            );

            $reponse->assertStatus(302, "L'appel #{$i} (variante organization, sous la limite) ne doit jamais etre limite.");
        }

        $sixieme = $this->completedTransaction();

        $this->actingAs($this->buyer)->post(
            route('organization.reviews.store', ['organization' => $this->organization->slug, 'transaction' => $sixieme]),
            ['rating' => 5]
        )->assertStatus(429, 'La variante organization doit, elle aussi, limiter exactement au 6e appel.');
    }

    /**
     * Les DEUX routes (globale et `/org/{organization}`) partagent le MEME
     * limiteur NOMME `reviews` — c'est voulu (meme utilisateur, meme geste
     * metier, juste deux points d'entree). Preuve directe : consommer la
     * limite sur l'une limite bien l'AUTRE pour ce meme utilisateur —
     * sinon un utilisateur pourrait doubler sa limite reelle en alternant
     * les deux chemins.
     */
    public function test_les_deux_variantes_partagent_intentionnellement_le_meme_compteur(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $transaction = $this->completedTransaction();

            $this->actingAs($this->buyer)->post(route('reviews.store', $transaction), ['rating' => 5])
                ->assertStatus(302);
        }

        $sixieme = $this->completedTransaction();

        $this->actingAs($this->buyer)->post(
            route('organization.reviews.store', ['organization' => $this->organization->slug, 'transaction' => $sixieme]),
            ['rating' => 5]
        )->assertStatus(429, 'Les deux variantes doivent partager le meme compteur par utilisateur, pour eviter un contournement de la limite.');
    }

    // ── 5. Deux utilisateurs distincts ont des compteurs independants ──────

    public function test_deux_utilisateurs_distincts_ont_des_compteurs_independants(): void
    {
        $autreBuyer = User::factory()->create(['organization_id' => $this->organization->id]);
        $autreSeller = User::factory()->create(['organization_id' => $this->organization->id]);

        for ($i = 1; $i <= 5; $i++) {
            $transaction = $this->completedTransaction();

            $this->actingAs($this->buyer)->post(route('reviews.store', $transaction), ['rating' => 5])
                ->assertStatus(302);
        }

        $transactionAutreBuyer = Transaction::factory()
            ->forBuyer($autreBuyer)
            ->forSeller($autreSeller)
            ->completed()
            ->create(['organization_id' => $this->organization->id]);

        $this->actingAs($autreBuyer)->post(route('reviews.store', $transactionAutreBuyer), ['rating' => 5])
            ->assertStatus(302, 'Un autre utilisateur, jamais touche par la limite du premier, doit pouvoir soumettre son premier avis.');
    }
}
