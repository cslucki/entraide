<?php

namespace Tests\Feature;

use App\Livewire\MessageThread;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\Review;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1808 — LE CRITERE DE RECETTE BLOQUANT DE MASTER (11/11), en code.
 * TASK-1809 §2 (12/11) — le parcours a DEUX micro-ecrans (etoiles PUIS mot)
 * devient UNE carte compacte (note + commentaire facultatif, une seule
 * soumission) ; « Plus tard » et le renoncement explicite sont retires du
 * parcours visible. Ce fichier est mis a jour pour le dire.
 *
 * « Tester exactement : D1 demande -> D2 accepte -> D1 ouvre le chat.
 *   Resultat obligatoire : conversation ouverte, bouton de cloture visible,
 *   ZERO element d'evaluation. Puis : D1 clique -> cloture et points ->
 *   confirmation -> carte d'evaluation. Verifier que rechargement,
 *   navigation, retour arriere, acces direct aux URL et requetes serveur ne
 *   permettent JAMAIS d'evaluer une transaction non terminee. »
 */
class TASK1808ParcoursFinalEntraideTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $aide;    // D1 — buyer

    private User $aidant;  // D2 — seller

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->aide = User::factory()->create(['organization_id' => $this->organization->id, 'points_balance' => 100]);
        $this->aidant = User::factory()->create(['organization_id' => $this->organization->id, 'points_balance' => 10]);
        app()->instance('current_organization', $this->organization);
    }

    private function entraideAcceptee(array $overrides = []): Transaction
    {
        return Transaction::factory()
            ->forBuyer($this->aide)
            ->forSeller($this->aidant)
            ->accepted()
            ->create(array_merge([
                'organization_id' => $this->organization->id,
                'points_proposed' => 20,
                'points_agreed' => 20,
            ], $overrides));
    }

    // ── Invariant 1 — AVANT la cloture, zero element d'evaluation ──────────

    public function test_avant_la_cloture_le_fil_de_l_aide_ne_porte_aucun_element_d_evaluation(): void
    {
        $entraide = $this->entraideAcceptee();

        $html = Livewire::actingAs($this->aide)
            ->test(MessageThread::class, ['transaction' => $entraide])
            ->html();

        $this->assertStringContainsString('data-entraide-cloturer', $html);
        $this->assertStringContainsString(__('messages.cloturer_bouton'), $html);

        foreach ([
            'data-entraide-evaluation',
            'data-entraide-partager-flux',
            'name="rating"',
            'name="comment"',
            '★', '☆',
        ] as $interdit) {
            $this->assertStringNotContainsString(
                $interdit, $html,
                "« {$interdit} » apparait dans le fil AVANT la cloture — l'evaluation est proposee trop tot.",
            );
        }

        $this->assertStringNotContainsString(__('messages.etoiles_titre', ['prenom' => $this->aidant->first_name]), $html);
    }

    public function test_avant_la_cloture_le_serveur_refuse_toute_evaluation(): void
    {
        $entraide = $this->entraideAcceptee();

        $this->actingAs($this->aide)->post(route('reviews.store', $entraide), ['rating' => 5])
            ->assertForbidden();

        $this->assertSame(0, Review::where('transaction_id', $entraide->id)->count());
    }

    public function test_avant_la_cloture_le_partage_dans_le_flux_est_refuse_aussi(): void
    {
        $entraide = $this->entraideAcceptee();

        $this->actingAs($this->aide)->post(route('transactions.share-in-feed', $entraide))
            ->assertForbidden();
    }

    // ── Invariant 2 — le clic cloture REELLEMENT, sans formulaire ──────────

    public function test_le_bouton_cloture_transfere_et_ferme_sans_rien_demander(): void
    {
        $entraide = $this->entraideAcceptee();

        $this->actingAs($this->aide)
            ->patch(route('transactions.complete', $entraide))
            ->assertRedirect(route('messages.show', $entraide));

        $entraide->refresh();

        $this->assertSame('completed', $entraide->status);
        $this->assertSame(80, $this->aide->fresh()->points_balance);
        $this->assertSame(30, $this->aidant->fresh()->points_balance);
        $this->assertSame(2, PointLedger::where('transaction_id', $entraide->id)->count());

        $this->assertSame(0, Review::where('transaction_id', $entraide->id)->count());
        $this->assertNull($entraide->reviews_skipped_at);

        // Un second clic (retour arriere, double envoi) ne re-transfere rien.
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));
        $this->assertSame(80, $this->aide->fresh()->points_balance);
        $this->assertSame(2, PointLedger::where('transaction_id', $entraide->id)->count());
    }

    public function test_apres_la_cloture_la_messagerie_est_en_lecture_seule_pour_les_deux(): void
    {
        $entraide = $this->entraideAcceptee();
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        foreach ([$this->aide, $this->aidant] as $membre) {
            Livewire::actingAs($membre)
                ->test(MessageThread::class, ['transaction' => $entraide->fresh()])
                ->set('newMessage', 'un message apres la fin')
                ->call('sendMessage');
        }

        $this->assertSame(
            0,
            $entraide->messages()->whereNotNull('sender_id')->where('body', 'un message apres la fin')->count(),
        );
    }

    // ── La carte d'evaluation, apres la confirmation ────────────────────────

    public function test_apres_la_cloture_d1_lit_la_confirmation_puis_la_carte_d_evaluation(): void
    {
        $entraide = $this->entraideAcceptee();
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        $html = Livewire::actingAs($this->aide)
            ->test(MessageThread::class, ['transaction' => $entraide->fresh()])
            ->html();

        $this->assertStringContainsString('data-entraide-cloture-aide', $html);
        $this->assertStringContainsString(__('messages.cloture_aide_titre'), $html);

        $this->assertStringContainsString('data-entraide-evaluation', $html);
        $this->assertStringContainsString('data-entraide-evaluation-commentaire', $html);
        $this->assertStringContainsString('name="comment"', $html);
        $this->assertStringContainsString(__('messages.etoiles_valider'), $html);

        // Aucune preselection : l'etat Alpine demarre a zero.
        $this->assertStringContainsString('note: 0', $html);
    }

    public function test_une_seule_soumission_enregistre_la_note_et_le_commentaire_ensemble(): void
    {
        $entraide = $this->entraideAcceptee();
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        $this->actingAs($this->aide)->post(route('reviews.store', $entraide), [
            'rating' => 4,
            'comment' => 'Merci encore !',
        ])->assertRedirect(route('messages.show', $entraide));

        $this->assertDatabaseHas('reviews', [
            'transaction_id' => $entraide->id,
            'reviewer_id' => $this->aide->id,
            'reviewed_id' => $this->aidant->id,
            'rating' => 4,
            'comment' => 'Merci encore !',
        ]);

        // Points inchanges : evaluer ne re-transfere jamais.
        $this->assertSame(80, $this->aide->fresh()->points_balance);
        $this->assertSame(30, $this->aidant->fresh()->points_balance);

        // La carte ne revient plus une fois l'avis depose.
        $html = Livewire::actingAs($this->aide)
            ->test(MessageThread::class, ['transaction' => $entraide->fresh()])
            ->html();
        $this->assertStringNotContainsString('data-entraide-evaluation', $html);
    }

    public function test_la_note_seule_est_une_evaluation_complete_sans_commentaire(): void
    {
        $entraide = $this->entraideAcceptee();
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        $this->actingAs($this->aide)->post(route('reviews.store', $entraide), ['rating' => 5]);

        $this->assertDatabaseHas('reviews', [
            'transaction_id' => $entraide->id,
            'rating' => 5,
            'comment' => null,
        ]);
    }

    public function test_une_note_est_obligatoire_pour_soumettre(): void
    {
        $entraide = $this->entraideAcceptee();
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        $this->actingAs($this->aide)->post(route('reviews.store', $entraide), ['comment' => 'sans note'])
            ->assertSessionHasErrors('rating');

        $this->assertSame(0, Review::where('transaction_id', $entraide->id)->count());
    }

    /**
     * TASK-1809 §2 — aucune penalite a ne pas evaluer : la carte reste
     * proposee a chaque visite, sans action de renoncement ni de report.
     */
    public function test_aucune_penalite_a_ne_pas_evaluer_la_carte_reste_proposee(): void
    {
        $entraide = $this->entraideAcceptee();
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        foreach (range(1, 2) as $visite) {
            $html = Livewire::actingAs($this->aide)
                ->test(MessageThread::class, ['transaction' => $entraide->fresh()])
                ->html();
            $this->assertStringContainsString('data-entraide-evaluation', $html);
        }

        $this->assertNull($entraide->fresh()->reviews_skipped_at);
    }

    /**
     * TASK-1809 §2 — les anciennes portes (ecran separe, « Plus tard »,
     * renoncement explicite) n'existent plus : aucune route ne les sert.
     */
    public function test_les_anciennes_routes_de_report_et_de_renoncement_n_existent_plus(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('reviews.defer'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('reviews.skip'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('reviews.comment'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('reviews.comment.skip'));
    }

    // ── Cote aidant : la reconnaissance, et une seule pluie de coeurs ──────

    public function test_l_aidant_lit_le_merci_et_recoit_la_pluie_de_coeurs_une_seule_fois(): void
    {
        $entraide = $this->entraideAcceptee();
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        $premiere = Livewire::actingAs($this->aidant)
            ->test(MessageThread::class, ['transaction' => $entraide->fresh()])
            ->html();
        $this->assertStringContainsString('data-entraide-cloture-aidant', $premiere);
        $this->assertStringContainsString(__('messages.cloture_aidant_titre'), $premiere);
        $this->assertStringContainsString('data-entraide-coeurs', $premiere);
        $this->assertNotNull($entraide->fresh()->seller_celebrated_at);

        $seconde = Livewire::actingAs($this->aidant)
            ->test(MessageThread::class, ['transaction' => $entraide->fresh()])
            ->html();
        $this->assertStringNotContainsString('data-entraide-coeurs', $seconde);

        // L'aidant n'evalue pas : il ne voit jamais la carte.
        $this->assertStringNotContainsString('data-entraide-evaluation', $premiere);
    }

    public function test_la_pluie_de_coeurs_n_est_jamais_pour_l_aide(): void
    {
        $entraide = $this->entraideAcceptee();
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        $html = Livewire::actingAs($this->aide)
            ->test(MessageThread::class, ['transaction' => $entraide->fresh()])
            ->html();

        $this->assertStringNotContainsString('data-entraide-coeurs', $html);
        $this->assertNull($entraide->fresh()->seller_celebrated_at);
    }

    /**
     * TASK-1808 (recette navigateur, 11/11) — DEFAUT TROUVE EN CONDITIONS
     * REELLES : `messages/index.blade.php` monte le MEME composant DEUX FOIS
     * (mobile `md:hidden`, desktop `hidden md:flex`), chacune appelant
     * `mount()` independamment. Ce test reproduit le double montage DANS LA
     * MEME REQUETE et prouve que les deux instances s'accordent.
     */
    public function test_les_deux_instances_mobile_et_desktop_s_accordent_sur_la_pluie(): void
    {
        $entraide = $this->entraideAcceptee();
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        $fraiche = $entraide->fresh();

        $premiereInstance = Livewire::actingAs($this->aidant)
            ->test(MessageThread::class, ['transaction' => $fraiche])
            ->html();
        $deuxiemeInstance = Livewire::actingAs($this->aidant)
            ->test(MessageThread::class, ['transaction' => $fraiche])
            ->html();

        $this->assertStringContainsString('data-entraide-coeurs', $premiereInstance);
        $this->assertStringContainsString('data-entraide-coeurs', $deuxiemeInstance);
        $this->assertNotNull($fraiche->fresh()->seller_celebrated_at);
    }
}
