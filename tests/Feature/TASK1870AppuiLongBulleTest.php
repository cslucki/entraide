<?php

namespace Tests\Feature;

use App\Livewire\LoopChat;
use App\Livewire\MessageThread;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1870 — l'appui long entre en mode selection, le simple appui non.
 *
 * ## Ce qu'un test PHP peut prouver ici, et ce qu'il ne peut pas
 *
 * Il ne peut RIEN dire du geste lui-meme : ni qu'un appui de 500 ms
 * selectionne, ni qu'un defilement l'annule. Cela se mesure au doigt, sur un
 * telephone, et MASTER en a fait un critere bloquant avant la fusion.
 *
 * Ce qu'il prouve, et qui a de la valeur, c'est le **contrat de rendu** : quelle
 * surface recoit le nouveau geste, laquelle garde l'ancien, et que les deux ne
 * cohabitent jamais sur une meme bulle — c'est precisement la cohabitation qui
 * avait fait echouer T1770/T1771 (« le menu s'affiche et repart »).
 *
 * Les ancres utilisees sont celles que le composant ecrit lui-meme :
 * `data-bp-appui-long`, `touch-action: pan-y`, `demarrerPression($event)` pour
 * le nouveau geste ; `selectionner()` pose sur `x-on:click` pour l'ancien.
 */
class TASK1870AppuiLongBulleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $buyer;

    private User $seller;

    private Transaction $entraide;

    private Transaction $conversationDirecte;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->buyer = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->seller = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->entraide = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
            'status' => 'accepted',
            'points_proposed' => 50,
        ]);

        $this->conversationDirecte = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
            'status' => 'accepted',
            'points_proposed' => 0,
            'service_id' => null,
            'request_id' => null,
        ]);

        $this->loop = (new LoopService)->createLoop($this->buyer, 'Boucle TASK-1870', type: 'project');

        app()->instance('current_organization', $this->organization);
    }

    private function messageDans(Transaction $transaction): Message
    {
        return Message::create([
            'transaction_id' => $transaction->id,
            'sender_id' => $this->seller->id,
            'body' => 'Un message',
            'type' => 'user',
        ]);
    }

    private function rendreFil(Transaction $transaction): string
    {
        $this->messageDans($transaction);

        return Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->assertStatus(200)
            ->html();
    }

    /* ---------- LA MESSAGERIE PRIVEE RECOIT LE GESTE ---------- */

    public function test_la_messagerie_privee_porte_le_geste_d_appui_long(): void
    {
        $rendu = $this->rendreFil($this->conversationDirecte);

        $this->assertStringContainsString('data-bp-appui-long', $rendu);
        $this->assertStringContainsString('touch-action: pan-y', $rendu);
        $this->assertStringContainsString('demarrerPression($event)', $rendu);
    }

    public function test_la_messagerie_privee_n_entre_plus_en_selection_au_simple_appui(): void
    {
        $rendu = $this->rendreFil($this->conversationDirecte);

        // L'ancien declencheur — `selectionner()` pose directement sur le
        // `click` de la colonne — ne doit plus exister ici : c'est
        // `clicSurBulle()` qui arbitre, et il ne selectionne au telephone que
        // si la barre est deja ouverte.
        $this->assertStringContainsString('clicSurBulle($event)', $rendu);
        $this->assertStringNotContainsString(
            "x-on:click=\"if (! \$event.target.closest('a,button,input,textarea,select,[role=button]')) selectionner()\"",
            $rendu,
        );
    }

    /* ---------- L'ENTRAIDE NE CHANGE PAS ---------- */

    public function test_une_transaction_d_entraide_garde_le_simple_appui(): void
    {
        $rendu = $this->rendreFil($this->entraide);

        // Correction MASTER n°2 : le nouveau geste ne l'atteint pas.
        $this->assertStringNotContainsString('data-bp-appui-long', $rendu);
        $this->assertStringNotContainsString('demarrerPression($event)', $rendu);

        // Et l'ancien declencheur y est toujours : la barre sert encore a
        // copier, modifier et supprimer.
        $this->assertStringContainsString('selectionner()', $rendu);
    }

    public function test_l_entraide_n_a_toujours_ni_repondre_ni_epingler_non_regression_t1869(): void
    {
        $message = $this->messageDans($this->entraide);

        $rendu = Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->entraide])
            ->html();

        $this->assertStringNotContainsString("pinMessage('{$message->id}')", $rendu);
        $this->assertStringNotContainsString("{ messageId: '{$message->id}' }", $rendu);
    }

    /* ---------- LE CHATLOOP RECOIT LE GESTE ---------- */

    public function test_le_chatloop_porte_le_geste_d_appui_long(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'user_id' => $this->buyer->id,
            'organization_id' => $this->organization->id,
            'body' => 'Message de Boucle',
            'type' => 'user',
        ]);

        $rendu = Livewire::actingAs($this->buyer)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertStatus(200)
            ->html();

        $this->assertStringContainsString('data-bp-appui-long', $rendu);
        $this->assertStringContainsString('demarrerPression($event)', $rendu);
        $this->assertStringContainsString('clicSurBulle($event)', $rendu);
    }

    /* ---------- LES DEUX GESTES NE COHABITENT JAMAIS ---------- */

    public function test_une_bulle_ne_porte_jamais_les_deux_declencheurs_a_la_fois(): void
    {
        foreach ([$this->conversationDirecte, $this->entraide] as $fil) {
            $rendu = $this->rendreFil($fil);

            $appuiLong = str_contains($rendu, 'demarrerPression($event)');
            $ancienClic = str_contains(
                $rendu,
                "x-on:click=\"if (! \$event.target.closest('a,button,input,textarea,select,[role=button]')) selectionner()\"",
            );

            // C'est leur COHABITATION qui avait fait echouer T1770/T1771 : les
            // deux appelaient `basculer()` avec le meme identifiant, et le
            // second annulait le premier.
            $this->assertFalse(
                $appuiLong && $ancienClic,
                'une bulle porte a la fois l\'appui long et l\'ancien declencheur de selection',
            );
            $this->assertTrue($appuiLong || $ancienClic, 'une bulle ne porte aucun declencheur de selection');
        }
    }
}
