<?php

namespace Tests\Feature;

use App\Events\ConversationMessageCreated;
use App\Livewire\MessageThread;
use App\Models\Message;
use App\Models\Organization;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\NewMessageReceived;
use App\Services\ServiceRequestLifecycle;
use App\Support\Entraide\MessageSysteme;
use App\Support\Messaging\ConversationBroadcaster;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1845 — diffusion Reverb des messages prives, apres commit SQL
 * reel, sans aucune reception navigateur (hors perimetre du mandat).
 *
 * ## Les QUATRE chemins d'ecriture couverts
 *
 * L'audit prealable (section 1 du mandat) a identifie quatre endroits ou
 * une ligne `messages` nait pour la messagerie privee :
 * `MessageThread::sendMessage()` (TASK-1844), `MessageSysteme::ecrire()`
 * (messages systeme d'entraide), `ServiceRequestLifecycle::markHandled()`
 * (un message systeme PAR transaction concurrente refusee) et
 * `MessageController` (premiere ligne d'une conversation directe). Les
 * quatre passent par le MEME point d'entree,
 * `ConversationBroadcaster::diffuserApresCommit()` — ce fichier verifie
 * donc le GUARD du drapeau une seule fois au niveau de ce point d'entree
 * partage (`test_le_drapeau_desactive_bloque_la_diffusion_...`), puis
 * verifie chacun des quatre chemins separement pour l'emission reelle.
 */
class TASK1845ConversationBroadcastTest extends TestCase
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
    }

    private function envoyerViaLivewire(User $acteur, Transaction $transaction, string $corps): void
    {
        Livewire::actingAs($acteur)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->set('newMessage', $corps)
            ->call('sendMessage');
    }

    // ── 0. Le point d'entree partage respecte le drapeau, OFF par defaut ───

    public function test_le_drapeau_desactive_bloque_la_diffusion_au_point_d_entree_partage(): void
    {
        config()->set('conversation_realtime.enabled', false);
        Event::fake([ConversationMessageCreated::class]);

        $message = Message::factory()->forTransaction($this->transaction)->create();
        ConversationBroadcaster::diffuserApresCommit($message);

        Event::assertNotDispatched(ConversationMessageCreated::class);
    }

    // ── 1. Chemin principal : MessageThread::sendMessage() ──────────────────

    public function test_drapeau_absent_aucune_diffusion_depuis_sendmessage(): void
    {
        config()->set('conversation_realtime.enabled', false);
        Event::fake([ConversationMessageCreated::class]);

        $this->envoyerViaLivewire($this->buyer, $this->transaction, 'Jamais diffuse, drapeau absent');

        Event::assertNotDispatched(ConversationMessageCreated::class);
    }

    public function test_drapeau_actif_diffuse_apres_commit_pour_la_bonne_conversation(): void
    {
        config()->set('conversation_realtime.enabled', true);
        Event::fake([ConversationMessageCreated::class]);

        $this->envoyerViaLivewire($this->buyer, $this->transaction, 'Diffusion attendue');

        Event::assertDispatched(
            ConversationMessageCreated::class,
            fn (ConversationMessageCreated $event): bool => $event->transactionId === $this->transaction->id
        );
    }

    public function test_rollback_force_ne_diffuse_jamais(): void
    {
        config()->set('conversation_realtime.enabled', true);
        Event::fake([ConversationMessageCreated::class]);

        Message::creating(function (): void {
            throw new \RuntimeException('panne SQL simulee — TASK-1845');
        });

        try {
            $this->envoyerViaLivewire($this->buyer, $this->transaction, 'Ne doit jamais exister');
            $this->fail('la panne simulee aurait du se propager');
        } catch (\RuntimeException $e) {
            $this->assertSame('panne SQL simulee — TASK-1845', $e->getMessage());
        } finally {
            Message::flushEventListeners();
        }

        Event::assertNotDispatched(ConversationMessageCreated::class);
    }

    /**
     * TASK-1845 — la garantie qui compte le plus : `sendMessage()` peut un
     * jour etre appelee depuis la transaction d'un appelant externe.
     * `DB::transaction()` imbrique n'y ouvre qu'un POINT DE SAUVEGARDE ; le
     * commit REEL, et donc le callback `afterCommit` qui diffuse
     * l'evenement, n'arrive qu'a la fin de CE bloc externe.
     */
    public function test_transaction_externe_differe_la_diffusion_jusqu_au_commit_reel(): void
    {
        config()->set('conversation_realtime.enabled', true);
        Event::fake([ConversationMessageCreated::class]);

        DB::transaction(function () {
            $this->envoyerViaLivewire($this->buyer, $this->transaction, 'Message sous transaction externe');

            // Le commit REEL n'a pas encore eu lieu : rien n'est diffuse.
            Event::assertNotDispatched(ConversationMessageCreated::class);
        });

        Event::assertDispatched(ConversationMessageCreated::class);
    }

    public function test_exactement_un_evenement_par_message_envoye(): void
    {
        config()->set('conversation_realtime.enabled', true);
        Event::fake([ConversationMessageCreated::class]);

        $this->envoyerViaLivewire($this->buyer, $this->transaction, 'Un seul message, un seul evenement');

        Event::assertDispatchedTimes(ConversationMessageCreated::class, 1);
    }

    public function test_message_avec_piece_jointe_declenche_aussi_la_diffusion(): void
    {
        config()->set('conversation_realtime.enabled', true);
        Storage::fake('public');
        Event::fake([ConversationMessageCreated::class]);

        $image = UploadedFile::fake()->image('photo.jpg', 200, 200);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', 'Avec une image')
            ->set('photo', $image)
            ->call('sendMessage')
            ->assertSet('photo', null);

        Event::assertDispatchedTimes(ConversationMessageCreated::class, 1);
    }

    public function test_conversation_directe_diffuse_aussi(): void
    {
        config()->set('conversation_realtime.enabled', true);
        Event::fake([ConversationMessageCreated::class]);

        $direct = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
            'status' => 'accepted',
            'points_proposed' => 0,
        ]);

        $this->envoyerViaLivewire($this->buyer, $direct, 'Message en conversation directe');

        Event::assertDispatched(
            ConversationMessageCreated::class,
            fn (ConversationMessageCreated $event): bool => $event->transactionId === $direct->id
        );
    }

    public function test_aucune_duplication_d_email_avec_la_diffusion_active(): void
    {
        config()->set('conversation_realtime.enabled', true);
        Notification::fake();
        Event::fake([ConversationMessageCreated::class]);

        $this->envoyerViaLivewire($this->buyer, $this->transaction, 'Verification anti-duplication email');

        Notification::assertSentToTimes($this->seller, NewMessageReceived::class, 1);
        Event::assertDispatchedTimes(ConversationMessageCreated::class, 1);
    }

    // ── 2. Messages systeme d'entraide : MessageSysteme::ecrire() ───────────

    public function test_messagesysteme_ecrire_diffuse_aussi(): void
    {
        config()->set('conversation_realtime.enabled', true);
        Event::fake([ConversationMessageCreated::class]);

        $message = MessageSysteme::ecrire($this->transaction, 'echange_accepte');

        $this->assertSame($this->transaction->id, $message->transaction_id);
        Event::assertDispatched(
            ConversationMessageCreated::class,
            fn (ConversationMessageCreated $event): bool => $event->transactionId === $this->transaction->id
        );
    }

    public function test_messagesysteme_ecrire_sans_drapeau_ne_diffuse_pas(): void
    {
        config()->set('conversation_realtime.enabled', false);
        Event::fake([ConversationMessageCreated::class]);

        MessageSysteme::ecrire($this->transaction, 'echange_decline');

        Event::assertNotDispatched(ConversationMessageCreated::class);
    }

    // ── 3. Offres concurrentes refusees : ServiceRequestLifecycle ──────────

    /**
     * TASK-1845 — `markHandled()` ecrit UN message systeme PAR transaction
     * concurrente refusee, dans une boucle : la diffusion doit suivre
     * exactement ce decompte, une fois par CONVERSATION distincte, jamais
     * une fois pour tout le lot.
     */
    public function test_markhandled_diffuse_une_fois_par_transaction_concurrente_refusee(): void
    {
        config()->set('conversation_realtime.enabled', true);

        // `ServiceRequestLifecycle::markHandled()` relit les transactions
        // concurrentes via une requete scopee (`BelongsToOrganizationScope`) :
        // sans contexte tenant courant, elle echoue fail-closed (`0 = 1`,
        // TASK-1670) et ne trouverait silencieusement AUCUNE concurrente.
        app()->instance('current_organization', $this->organization);

        $demandeur = User::factory()->create(['organization_id' => $this->organization->id]);
        $aideAcceptee = User::factory()->create(['organization_id' => $this->organization->id]);
        $aideConcurrente1 = User::factory()->create(['organization_id' => $this->organization->id]);
        $aideConcurrente2 = User::factory()->create(['organization_id' => $this->organization->id]);

        $request = ServiceRequest::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $demandeur->id,
            'status' => 'open',
        ]);

        $accepted = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'request_id' => $request->id,
            'buyer_id' => $demandeur->id,
            'seller_id' => $aideAcceptee->id,
            'status' => 'accepted',
        ]);

        $concurrente1 = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'request_id' => $request->id,
            'buyer_id' => $demandeur->id,
            'seller_id' => $aideConcurrente1->id,
            'status' => 'pending',
        ]);

        $concurrente2 = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'request_id' => $request->id,
            'buyer_id' => $demandeur->id,
            'seller_id' => $aideConcurrente2->id,
            'status' => 'pending',
        ]);

        Event::fake([ConversationMessageCreated::class]);

        (new ServiceRequestLifecycle)->markHandled($accepted);

        Event::assertDispatchedTimes(ConversationMessageCreated::class, 2);
        Event::assertDispatched(
            ConversationMessageCreated::class,
            fn (ConversationMessageCreated $event): bool => $event->transactionId === $concurrente1->id
        );
        Event::assertDispatched(
            ConversationMessageCreated::class,
            fn (ConversationMessageCreated $event): bool => $event->transactionId === $concurrente2->id
        );
    }

    // ── 4. Premiere ligne d'une conversation directe : MessageController ───

    public function test_demarrer_une_conversation_directe_diffuse_apres_commit(): void
    {
        config()->set('conversation_realtime.enabled', true);
        Event::fake([ConversationMessageCreated::class]);

        $expediteur = User::factory()->create(['organization_id' => $this->organization->id]);
        $destinataire = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($expediteur)->get(route('messages.with', $destinataire));

        $transaction = Transaction::withoutGlobalScopes()
            ->where('buyer_id', $expediteur->id)
            ->where('seller_id', $destinataire->id)
            ->first();

        $this->assertNotNull($transaction);
        Event::assertDispatchedTimes(ConversationMessageCreated::class, 1);
        Event::assertDispatched(
            ConversationMessageCreated::class,
            fn (ConversationMessageCreated $event): bool => $event->transactionId === $transaction->id
        );
    }

    // ── 5. Forme de l'event : canal, nom, payload vide ──────────────────────

    public function test_le_canal_est_prive_et_scope_a_la_conversation(): void
    {
        $message = Message::factory()->forTransaction($this->transaction)->create();
        $event = new ConversationMessageCreated($message);
        $canaux = $event->broadcastOn();

        $this->assertCount(1, $canaux);
        $this->assertInstanceOf(PrivateChannel::class, $canaux[0]);
        $this->assertSame("private-conversation.{$this->transaction->id}", $canaux[0]->name);
    }

    public function test_le_payload_ne_porte_aucun_contenu_de_message(): void
    {
        $message = Message::factory()->forTransaction($this->transaction)->create();
        $event = new ConversationMessageCreated($message);

        $this->assertSame([], $event->broadcastWith());
    }

    public function test_le_nom_d_evenement_broadcast_correspond_a_celui_attendu(): void
    {
        $message = Message::factory()->forTransaction($this->transaction)->create();

        $this->assertSame('conversation.message.created', (new ConversationMessageCreated($message))->broadcastAs());
    }

    // ── 6. Une panne Reverb, sous `sync`, ne casse jamais rien ──────────────

    /**
     * Pointe la connexion Reverb vers un port local FERME : le client
     * Pusher (sous-jacent au driver `reverb`) tente une connexion TCP
     * refusee quasi instantanement — pas de reseau reel, pas de delai,
     * deterministe. Meme motif que `TASK1843MemberNotificationBroadcastTest`.
     */
    private function configurerReverbInjoignable(): void
    {
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.key', 'test-key');
        config()->set('broadcasting.connections.reverb.secret', 'test-secret');
        config()->set('broadcasting.connections.reverb.app_id', 'test-app');
        config()->set('broadcasting.connections.reverb.options.host', '127.0.0.1');
        config()->set('broadcasting.connections.reverb.options.port', 1);
        config()->set('broadcasting.connections.reverb.options.scheme', 'http');
        config()->set('broadcasting.connections.reverb.options.useTLS', false);
        config()->set('broadcasting.connections.reverb.client_options', [
            'timeout' => 1,
            'connect_timeout' => 1,
        ]);
    }

    public function test_une_panne_reverb_nempeche_pas_la_creation_du_message_ni_lemail(): void
    {
        config()->set('conversation_realtime.enabled', true);
        $this->configurerReverbInjoignable();
        Notification::fake();

        $this->envoyerViaLivewire($this->buyer, $this->transaction, 'Malgre une panne Reverb reelle');

        $this->assertDatabaseHas('messages', ['body' => 'Malgre une panne Reverb reelle']);
        Notification::assertSentToTimes($this->seller, NewMessageReceived::class, 1);
    }

    public function test_une_panne_reverb_nempeche_pas_le_commit_dune_transaction_externe(): void
    {
        config()->set('conversation_realtime.enabled', true);
        $this->configurerReverbInjoignable();

        DB::transaction(function () {
            $this->envoyerViaLivewire($this->buyer, $this->transaction, 'Panne Reverb sous transaction externe');

            $this->transaction->touch();
        });

        $this->assertDatabaseHas('messages', ['body' => 'Panne Reverb sous transaction externe']);
    }

    protected function tearDown(): void
    {
        Message::flushEventListeners();

        parent::tearDown();
    }
}
