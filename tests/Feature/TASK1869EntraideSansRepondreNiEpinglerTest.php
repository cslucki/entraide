<?php

namespace Tests\Feature;

use App\Livewire\MessageThread;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1869 — « Repondre a » et « Epingler » quittent l'entraide.
 *
 * Ces deux gestes sont ceux d'une CONVERSATION : ChatLoop et messagerie
 * privee. Le fil d'une transaction d'entraide sert a mener un echange de
 * services jusqu'a sa cloture ; ses actions sont metier.
 *
 * Ce que cette suite mesure, et pourquoi chaque cas existe :
 *
 * 1. la VUE ne propose plus les deux gestes sur une entraide, et les propose
 *    toujours sur une conversation directe — la meme bulle partagee, deux
 *    reponses differentes ;
 * 2. le SERVEUR refuse les deux gestes sur une entraide, appele directement,
 *    sans passer par aucun bouton : l'affichage n'a jamais ete une permission ;
 * 3. `replyToMessageId` est une propriete PUBLIQUE Livewire — elle peut etre
 *    posee par le client SANS appeler `replyTo()`. La citation doit tomber la
 *    ou elle deviendrait une ligne en base, pas seulement a l'entree de la
 *    methode ;
 * 4. rien d'historique n'est perdu : un epinglage deja pose reste lisible et
 *    detachable, une citation deja enregistree reste affichee (arbitrage
 *    MASTER 2 du 20/11) ;
 * 5. la messagerie privee ne bouge pas.
 */
class TASK1869EntraideSansRepondreNiEpinglerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $buyer;

    private User $seller;

    private Transaction $entraide;

    private Transaction $conversationDirecte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->buyer = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->seller = User::factory()->create(['organization_id' => $this->organization->id]);

        // Une entraide : des points proposes, donc PAS une conversation
        // directe au sens de `Transaction::isDirectConversation()`.
        $this->entraide = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
            'status' => 'accepted',
            'points_proposed' => 50,
        ]);

        // Une messagerie privee : les trois conditions reunies.
        $this->conversationDirecte = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
            'status' => 'accepted',
            'points_proposed' => 0,
            'service_id' => null,
            'request_id' => null,
        ]);

        app()->instance('current_organization', $this->organization);
    }

    private function messageDans(Transaction $transaction, ?User $auteur = null, string $corps = 'Un message'): Message
    {
        return Message::create([
            'transaction_id' => $transaction->id,
            'sender_id' => ($auteur ?? $this->seller)->id,
            'body' => $corps,
            'type' => 'user',
        ]);
    }

    /* ---------- 1. LA VUE ---------- */

    public function test_le_fil_d_entraide_ne_rend_aucun_geste_repondre_ni_epingler(): void
    {
        $message = $this->messageDans($this->entraide);

        $rendu = Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->entraide])
            ->assertStatus(200)
            ->html();

        // Les deux ancres sont celles du composant partage lui-meme, pas une
        // condition re-derivee : le bouton d'epinglage porte son
        // `wire:click`, celui de reponse publie l'evenement avec
        // l'identifiant du message.
        $this->assertStringNotContainsString("pinMessage('{$message->id}')", $rendu);
        $this->assertStringNotContainsString("{ messageId: '{$message->id}' }", $rendu);
    }

    public function test_la_meme_bulle_garde_les_deux_gestes_en_messagerie_privee(): void
    {
        $message = $this->messageDans($this->conversationDirecte);

        $rendu = Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->conversationDirecte])
            ->assertStatus(200)
            ->html();

        // Le controle en miroir du precedent : sans lui, un rendu casse
        // ferait passer le test d'entraide pour la mauvaise raison.
        $this->assertStringContainsString("pinMessage('{$message->id}')", $rendu);
        $this->assertStringContainsString("{ messageId: '{$message->id}' }", $rendu);
    }

    /* ---------- 2. LE SERVEUR ---------- */

    public function test_repondre_est_refuse_par_le_serveur_sur_une_entraide(): void
    {
        $parent = $this->messageDans($this->entraide);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->entraide])
            ->call('replyTo', $parent->id)
            ->assertSet('replyToMessageId', null)
            ->assertSet('replyingTo', null);
    }

    public function test_epingler_est_refuse_par_le_serveur_sur_une_entraide(): void
    {
        $message = $this->messageDans($this->entraide);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->entraide])
            ->call('pinMessage', $message->id);

        $this->assertNull($message->fresh()->pinned_at);
        $this->assertNull($message->fresh()->pinned_by_id);
    }

    /* ---------- 3. L'INJECTION DIRECTE DE L'ETAT LIVEWIRE ---------- */

    public function test_une_citation_injectee_dans_l_etat_livewire_n_est_pas_enregistree_sur_une_entraide(): void
    {
        $parent = $this->messageDans($this->entraide);

        // Le client n'appelle PAS `replyTo()` : il pose directement la
        // propriete publique, exactement ce qu'une requete forgee ferait.
        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->entraide])
            ->set('replyToMessageId', $parent->id)
            ->set('newMessage', 'Reponse forgee')
            ->call('sendMessage');

        $envoye = Message::where('body', 'Reponse forgee')->first();

        // Le message part — rien n'est refuse a l'utilisateur — mais sans
        // citation.
        $this->assertNotNull($envoye, 'le message aurait du etre envoye normalement');
        $this->assertNull($envoye->reply_to_id);
    }

    public function test_la_meme_injection_reste_legitime_en_messagerie_privee(): void
    {
        $parent = $this->messageDans($this->conversationDirecte);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->conversationDirecte])
            ->set('replyToMessageId', $parent->id)
            ->set('newMessage', 'Reponse legitime')
            ->call('sendMessage');

        $envoye = Message::where('body', 'Reponse legitime')->first();

        $this->assertNotNull($envoye);
        $this->assertSame($parent->id, $envoye->reply_to_id);
    }

    /* ---------- 4. L'HISTORIQUE ---------- */

    public function test_un_epinglage_historique_reste_lisible_et_detachable_sur_une_entraide(): void
    {
        // Pose directement en base : l'etat qu'une entraide d'avant cette
        // TASK a pu laisser derriere elle.
        $epingle = Message::create([
            'transaction_id' => $this->entraide->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Rendez-vous samedi 10h',
            'type' => 'user',
            'pinned_at' => now(),
            'pinned_by_id' => $this->buyer->id,
        ]);

        $composant = Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->entraide]);

        $composant->assertSee(__('messages.pinned_message'))
            ->assertSee('Rendez-vous samedi 10h');

        // Et le seul geste conserve fonctionne : sans lui, ces messages
        // resteraient epingles pour toujours.
        $composant->call('unpinMessage');

        $this->assertNull($epingle->fresh()->pinned_at);
        $this->assertNull($epingle->fresh()->pinned_by_id);
    }

    public function test_aucun_nouvel_epinglage_ne_remplace_un_epinglage_historique(): void
    {
        $historique = Message::create([
            'transaction_id' => $this->entraide->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Epingle avant TASK-1869',
            'type' => 'user',
            'pinned_at' => now(),
            'pinned_by_id' => $this->buyer->id,
        ]);

        $autre = $this->messageDans($this->entraide, $this->seller, 'Message plus recent');

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->entraide])
            ->call('pinMessage', $autre->id);

        // `pinMessage()` detache TOUT avant d'epingler : un refus pose trop
        // bas dans la methode aurait donc fait disparaitre l'epinglage
        // historique sans en poser aucun autre.
        $this->assertNotNull($historique->fresh()->pinned_at, 'l\'epinglage historique a ete perdu');
        $this->assertNull($autre->fresh()->pinned_at);
    }

    public function test_une_citation_deja_enregistree_reste_affichee_sur_une_entraide(): void
    {
        $parent = $this->messageDans($this->entraide, $this->seller, 'Question posee avant');

        $reponse = Message::create([
            'transaction_id' => $this->entraide->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Reponse ecrite avant TASK-1869',
            'type' => 'user',
            'reply_to_id' => $parent->id,
        ]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->entraide])
            ->assertSee('Reponse ecrite avant TASK-1869')
            ->assertSee('Question posee avant');

        $this->assertSame($parent->id, $reponse->fresh()->reply_to_id);
    }

    /* ---------- 5. CE QUI NE BOUGE PAS DANS L'ENTRAIDE ---------- */

    public function test_les_reactions_et_l_envoi_restent_disponibles_dans_une_entraide(): void
    {
        $message = $this->messageDans($this->entraide);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->entraide])
            ->call('toggleReaction', $message->id, 'thumbs_up')
            ->set('newMessage', 'Je suis disponible samedi')
            ->call('sendMessage')
            ->assertSet('newMessage', '');

        $this->assertDatabaseHas('reactions', [
            'reactionable_id' => $message->id,
            'reaction_type' => 'thumbs_up',
        ]);

        $this->assertDatabaseHas('messages', [
            'transaction_id' => $this->entraide->id,
            'body' => 'Je suis disponible samedi',
        ]);
    }
}
