<?php

namespace Tests\Feature;

use App\Events\ConversationMessageCreated;
use App\Livewire\MessageThread;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Reaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1846 — reception instantanee des messages de conversation privee.
 *
 * Perimetre de CE fichier (les autres garanties — emission apres commit,
 * aucune emission apres rollback, transaction imbriquee, panne Reverb sous
 * `sync`, canal prive, payload vide — sont deja prouvees par
 * `TASK1845ConversationBroadcastTest`/`TASK1845ConversationChannelAuthorizationTest`
 * sur le MEME point d'entree partage, `ConversationBroadcaster::
 * diffuserApresCommit()`, reutilise ICI a l'identique) :
 *
 * 1. Les trois chemins de mutation NOUVEAUX pour cette TASK (edition,
 *    epinglage/desepinglage, reaction) declenchent desormais aussi la
 *    diffusion — le « point critique » du mandat MASTER (ne pas degrader
 *    silencieusement leur fraicheur une fois le polling adaptatif actif).
 * 2. Le gabarit Blade cable `wire:poll.3000ms` UNIQUEMENT pour les
 *    echanges d'ENTRAIDE, jamais pour une conversation DIRECTE — et pose
 *    le bon composant Alpine (`messageThreadAdaptivePolling` vs
 *    `messageThreadRealtimeNudge`) dans chaque cas.
 */
class TASK1846MessageThreadRealtimeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $buyer;

    private User $seller;

    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->buyer = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->seller = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->transaction = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
            'status' => 'accepted',
            'points_proposed' => 50,
        ]);

        config()->set('conversation_realtime.enabled', true);
    }

    // ── 1. Les trois mutations nouvellement couvertes ───────────────────────

    public function test_editer_un_message_diffuse_apres_commit(): void
    {
        $message = Message::factory()->forTransaction($this->transaction)->create([
            'sender_id' => $this->buyer->id,
            'organization_id' => $this->organization->id,
        ]);

        Event::fake([ConversationMessageCreated::class]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('editMessage', $message->id)
            ->set('editingBody', 'Corps corrige')
            ->call('saveEdit');

        Event::assertDispatched(
            ConversationMessageCreated::class,
            fn (ConversationMessageCreated $event): bool => $event->transactionId === $this->transaction->id
        );
    }

    public function test_epingler_un_message_diffuse_apres_commit(): void
    {
        $message = Message::factory()->forTransaction($this->transaction)->create([
            'sender_id' => $this->seller->id,
        ]);

        Event::fake([ConversationMessageCreated::class]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('pinMessage', $message->id);

        Event::assertDispatchedTimes(ConversationMessageCreated::class, 1);
        $this->assertNotNull($message->fresh()->pinned_at);
    }

    public function test_desepingler_diffuse_apres_commit_et_ne_fait_rien_si_rien_n_etait_epingle(): void
    {
        $message = Message::factory()->forTransaction($this->transaction)->create([
            'sender_id' => $this->seller->id,
            'pinned_at' => now(),
            'pinned_by_id' => $this->buyer->id,
        ]);

        Event::fake([ConversationMessageCreated::class]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('unpinMessage');

        Event::assertDispatchedTimes(ConversationMessageCreated::class, 1);
        $this->assertNull($message->fresh()->pinned_at);

        // Un second depinglage, alors que rien n'est plus epingle : no-op
        // identique a avant cette TASK, donc AUCUNE diffusion supplementaire.
        Event::fake([ConversationMessageCreated::class]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('unpinMessage');

        Event::assertNotDispatched(ConversationMessageCreated::class);
    }

    public function test_toggleReaction_diffuse_sur_ses_trois_branches_ajout_changement_suppression(): void
    {
        $message = Message::factory()->forTransaction($this->transaction)->create([
            'sender_id' => $this->seller->id,
        ]);

        // Branche 1 : ajout.
        Event::fake([ConversationMessageCreated::class]);
        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('toggleReaction', $message->id, 'heart');
        Event::assertDispatchedTimes(ConversationMessageCreated::class, 1);
        $this->assertSame('heart', Reaction::where('reactionable_id', $message->id)->first()->reaction_type);

        // Branche 2 : changement de type (meme reaction, type different).
        Event::fake([ConversationMessageCreated::class]);
        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('toggleReaction', $message->id, 'thumbs_up');
        Event::assertDispatchedTimes(ConversationMessageCreated::class, 1);
        $this->assertSame('thumbs_up', Reaction::where('reactionable_id', $message->id)->first()->reaction_type);

        // Branche 3 : suppression (meme type envoye a nouveau -> toggle off).
        Event::fake([ConversationMessageCreated::class]);
        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('toggleReaction', $message->id, 'thumbs_up');
        Event::assertDispatchedTimes(ConversationMessageCreated::class, 1);
        $this->assertNull(Reaction::where('reactionable_id', $message->id)->first());
    }

    // ── 2. Cablage Blade : wire:poll + x-data selon le type de conversation ─

    public function test_conversation_directe_n_a_plus_de_wire_poll_et_porte_le_composant_adaptatif(): void
    {
        $direct = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
            'status' => 'accepted',
            'points_proposed' => 0,
        ]);

        $html = Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $direct])
            ->html();

        $this->assertStringNotContainsString('wire:poll', $html, 'une conversation DIRECTE ne doit plus porter wire:poll.3000ms : le minuteur devient JS, pilote par messageThreadAdaptivePolling()');
        $this->assertStringContainsString("messageThreadAdaptivePolling('{$direct->id}')", $html);
        $this->assertStringNotContainsString('messageThreadRealtimeNudge', $html);
    }

    public function test_echange_d_entraide_garde_wire_poll_et_porte_le_declencheur_immediat(): void
    {
        $html = Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->html();

        $this->assertStringContainsString('wire:poll.3000ms="pollThread"', $html, 'un echange d ENTRAIDE doit garder exactement le meme minuteur qu avant cette TASK');
        $this->assertStringContainsString("messageThreadRealtimeNudge('{$this->transaction->id}')", $html);
        $this->assertStringNotContainsString('messageThreadAdaptivePolling', $html);
    }

    // ── 3. Drapeau OFF : zero diffusion sur les trois mutations ─────────────

    public function test_drapeau_desactive_aucune_diffusion_sur_les_trois_mutations(): void
    {
        config()->set('conversation_realtime.enabled', false);

        $message = Message::factory()->forTransaction($this->transaction)->create([
            'sender_id' => $this->buyer->id,
        ]);

        Event::fake([ConversationMessageCreated::class]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('editMessage', $message->id)
            ->set('editingBody', 'Corps corrige, drapeau OFF')
            ->call('saveEdit')
            ->call('pinMessage', $message->id)
            ->call('toggleReaction', $message->id, 'heart');

        Event::assertNotDispatched(ConversationMessageCreated::class);
    }
}
