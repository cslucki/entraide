<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1754 (§9.3) — le coup de main OFFERT, a 0 minute.
 *
 * C'est LA difference philosophique avec une place de marche : le don y est
 * impossible, ici il doit etre un bouton. `min:1` l'interdisait.
 *
 * Arbitrage Q7 de Cyril : partout, pas seulement en reponse a une demande —
 * une proposition d'aide peut aussi s'offrir.
 */
class TASK1754CoupDeMainOffertTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $aidant;

    private User $aide;

    private Service $annonce;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'org-don-1754', 'is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->aidant = User::factory()->create(['organization_id' => $this->organization->id, 'points_balance' => 100]);
        $this->aide = User::factory()->create(['organization_id' => $this->organization->id, 'points_balance' => 40]);

        $this->annonce = Service::factory()->create([
            'user_id' => $this->aidant->id,
            'organization_id' => $this->organization->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->organization->id])->id,
            'status' => 'active',
            'points_cost' => 30,
        ]);
    }

    /**
     * Critere d'acceptation n°5 du CDC : un don traverse TOUTE la choregraphie
     * sans erreur ET sans ecriture de ledger a la cloture.
     *
     * Le test suit le cycle complet plutot que d'appeler `confirm()` sur une
     * ligne fabriquee a la main : c'est le seul moyen de verifier qu'aucune des
     * quatre etapes ne refuse le zero au passage.
     */
    public function test_un_don_traverse_toute_la_choregraphie_sans_toucher_le_ledger(): void
    {
        $soldesAvant = [$this->aidant->points_balance, $this->aide->points_balance];

        $this->actingAs($this->aide)
            ->post(route('transactions.store'), ['service_id' => $this->annonce->id, 'points_proposed' => 0])
            ->assertSessionMissing('errors');

        $echange = Transaction::where('service_id', $this->annonce->id)->firstOrFail();
        $this->assertSame(0, (int) $echange->points_proposed, 'Le don a ete refuse par la validation.');

        $this->actingAs($this->aidant)->patch(route('transactions.approve', $echange));
        $this->actingAs($this->aide)->patch(route('transactions.complete', $echange));
        $this->actingAs($this->aidant)->patch(route('transactions.confirm', $echange));

        $this->assertSame('completed', $echange->fresh()->status,
            'Le don n\'est pas alle au bout du cycle.');

        $this->assertSame(0, PointLedger::where('transaction_id', $echange->id)->count(),
            'Un don a ecrit des mouvements de points — du bruit comptable a zero.');

        $this->assertSame($soldesAvant, [
            $this->aidant->fresh()->points_balance,
            $this->aide->fresh()->points_balance,
        ], 'Les soldes ont bouge alors que rien n\'etait du.');
    }

    /**
     * ⚠ LE PIEGE du §9.3 — un don n'est PAS une discussion directe.
     *
     * `isDirectConversation()` exige `service_id` ET `request_id` nuls EN PLUS
     * de zero point. Un don lie a une annonce garde son `service_id`, donc il
     * reste un echange : sa carte s'affiche, ses boutons d'etape aussi.
     *
     * Tout code qui testerait `points_proposed == 0` SEUL se tromperait, et la
     * consequence serait silencieuse — un don perdrait ses boutons et resterait
     * bloque au premier statut, sans que rien ne le dise.
     */
    public function test_un_don_lie_a_une_annonce_n_est_pas_une_discussion_directe(): void
    {
        $this->actingAs($this->aide)
            ->post(route('transactions.store'), ['service_id' => $this->annonce->id, 'points_proposed' => 0]);

        $echange = Transaction::where('service_id', $this->annonce->id)->firstOrFail();

        $this->assertFalse($echange->isDirectConversation(),
            'Un don a ete confondu avec une discussion directe : il perdrait ses boutons d\'etape.');

        // Et la reciproque : une VRAIE discussion directe, elle, en est une.
        $discussion = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->aide->id,
            'seller_id' => $this->aidant->id,
            'service_id' => null,
            'request_id' => null,
            'points_proposed' => 0,
        ]);

        $this->assertTrue($discussion->isDirectConversation());
    }

    /** Le don s'affiche « Offert », jamais « 0 min ». */
    public function test_le_don_est_annonce_comme_offert(): void
    {
        $this->actingAs($this->aide)
            ->post(route('transactions.store'), ['service_id' => $this->annonce->id, 'points_proposed' => 0]);

        $echange = Transaction::where('service_id', $this->annonce->id)->firstOrFail();

        $html = $this->actingAs($this->aide)
            ->get(route('messages.show', $echange))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(__('entraide.temps.offert'), $html);
        $this->assertStringNotContainsString('0 min', $html);
    }

    /** Un montant negatif reste refuse : `min:0` n'est pas « pas de borne ». */
    public function test_un_temps_negatif_est_toujours_refuse(): void
    {
        $this->actingAs($this->aide)
            ->post(route('transactions.store'), ['service_id' => $this->annonce->id, 'points_proposed' => -5])
            ->assertSessionHasErrors('points_proposed');

        $this->assertSame(0, Transaction::where('service_id', $this->annonce->id)->count());
    }
}
