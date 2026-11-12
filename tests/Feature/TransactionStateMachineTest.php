<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

class TransactionStateMachineTest extends TestCase
{
    private User $buyer;

    private User $seller;

    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = User::factory()->create(['points_balance' => 200]);
        $this->seller = User::factory()->create(['points_balance' => 100]);
        $this->transaction = Transaction::factory()
            ->forBuyer($this->buyer)
            ->forSeller($this->seller)
            ->create();
    }

    public function test_initial_state_is_pending(): void
    {
        $this->assertEquals('pending', $this->transaction->status);
    }

    public function test_pending_to_accepted(): void
    {
        $this->transaction->update(['status' => 'accepted', 'points_agreed' => 50]);
        $this->assertEquals('accepted', $this->transaction->fresh()->status);
    }

    public function test_pending_to_refused(): void
    {
        $this->transaction->update(['status' => 'refused']);
        $this->assertEquals('refused', $this->transaction->fresh()->status);
    }

    public function test_pending_to_cancelled(): void
    {
        $this->transaction->update(['status' => 'cancelled']);
        $this->assertEquals('cancelled', $this->transaction->fresh()->status);
    }

    public function test_accepted_to_buyer_done(): void
    {
        $this->transaction->update(['status' => 'accepted']);
        $this->transaction->update(['status' => 'buyer_done', 'buyer_confirmed_at' => now()]);
        $this->assertEquals('buyer_done', $this->transaction->fresh()->status);
    }

    public function test_buyer_done_to_completed(): void
    {
        $this->transaction->update([
            'status' => 'buyer_done',
            'buyer_confirmed_at' => now(),
            'points_agreed' => 50,
        ]);
        $this->transaction->update([
            'status' => 'completed',
            'seller_confirmed_at' => now(),
            'completed_at' => now(),
        ]);
        $this->assertEquals('completed', $this->transaction->fresh()->status);
    }

    public function test_buyer_done_to_contested_returns_to_accepted(): void
    {
        $this->transaction->update([
            'status' => 'buyer_done',
            'buyer_confirmed_at' => now(),
        ]);
        $this->transaction->update(['status' => 'accepted']);
        $this->assertEquals('accepted', $this->transaction->fresh()->status);
    }

    public function test_completed_is_terminal_state(): void
    {
        $this->transaction->update([
            'status' => 'completed',
            'points_agreed' => 50,
            'buyer_confirmed_at' => now(),
            'seller_confirmed_at' => now(),
            'completed_at' => now(),
        ]);
        $t = $this->transaction->fresh();
        $this->assertEquals('completed', $t->status);
        $this->assertNotNull($t->completed_at);
    }

    public function test_refused_is_terminal_state(): void
    {
        $this->transaction->update(['status' => 'refused']);
        $this->assertEquals('refused', $this->transaction->fresh()->status);
    }

    public function test_cancelled_is_terminal_state(): void
    {
        $this->transaction->update(['status' => 'cancelled']);
        $this->assertEquals('cancelled', $this->transaction->fresh()->status);
    }

    public function test_completed_transaction_has_both_confirmation_timestamps(): void
    {
        $this->transaction->update([
            'status' => 'completed',
            'points_agreed' => 50,
            'buyer_confirmed_at' => now()->subHour(),
            'seller_confirmed_at' => now(),
            'completed_at' => now(),
        ]);
        $t = $this->transaction->fresh();
        $this->assertNotNull($t->buyer_confirmed_at);
        $this->assertNotNull($t->seller_confirmed_at);
        $this->assertNotNull($t->completed_at);
    }

    /**
     * TASK-1754 (§3.1/§3.2) — les badges disent l'ENTRAIDE, plus la marketplace.
     *
     * Les litteraux sont GARDES a dessein : ce sont eux que le membre lit, et
     * c'est le seul endroit ou une reecriture involontaire du lexique rougira.
     * Les remplacer par `__('messages.status.…')` rendrait ce test
     * tautologique — il repeterait l'implementation au lieu de la mesurer.
     *
     * « Terminé (acheteur) » etait le cas le plus visible du vocabulaire
     * e-commerce : il sortait a la fois dans le badge, dans le message systeme
     * et dans le toast.
     */
    public function test_les_badges_portent_le_lexique_d_entraide(): void
    {
        $transaction = Transaction::factory()->forBuyer($this->buyer)->forSeller($this->seller)->create();
        $this->assertEquals('Proposition', $transaction->status_label);

        // TASK-1806 — « À confirmer » nommait une etape qui n'existe plus
        // cote membre, et ces libelles partent AUSSI dans l'objet des
        // courriels de statut.
        foreach ([
            'accepted' => 'Aide acceptée',
            'buyer_done' => 'Entraide en cours',
            'completed' => 'Entraide terminée',
            'refused' => 'Déclinée',
            'cancelled' => 'Annulé',
        ] as $statut => $attendu) {
            $transaction->update(['status' => $statut]);
            $this->assertEquals($attendu, $transaction->fresh()->status_label, "Badge du statut [{$statut}].");
        }
    }

    /**
     * TASK-1754 (§3.4, obstacle n°1) — le defaut que l'ancien test NE VOYAIT PAS.
     *
     * Les libelles etaient ecrits en francais EN DUR dans le modele : un
     * anglophone lisait « En attente » dans son export CSV et dans ses
     * notifications. L'ancien test, qui n'assertait que le francais, passait au
     * vert sur ce defaut.
     */
    public function test_le_badge_est_traduit_et_non_fige_en_francais(): void
    {
        $transaction = Transaction::factory()->forBuyer($this->buyer)->forSeller($this->seller)->create();

        $this->app->setLocale('en');

        $this->assertEquals('Proposal', $transaction->status_label);
    }

    /**
     * Un statut hors des six du `CHECK` rend le statut BRUT, jamais une clef.
     *
     * Sans ce repli, `__()` renverrait « messages.status.xxx », et c'est cette
     * chaine-la qui s'afficherait a l'ecran — pire qu'un mot technique.
     */
    public function test_un_statut_hors_catalogue_ne_sort_pas_une_clef_de_traduction(): void
    {
        $transaction = new Transaction(['status' => 'statut_invente']);

        $this->assertSame('statut_invente', $transaction->status_label);
    }

    public function test_status_colors(): void
    {
        $transaction = Transaction::factory()->forBuyer($this->buyer)->forSeller($this->seller)->create();
        $this->assertEquals('orange', $transaction->status_color);

        $transaction->update(['status' => 'accepted']);
        $this->assertEquals('blue', $transaction->fresh()->status_color);

        $transaction->update(['status' => 'completed']);
        $this->assertEquals('green', $transaction->fresh()->status_color);

        $transaction->update(['status' => 'refused']);
        $this->assertEquals('red', $transaction->fresh()->status_color);
    }
}
