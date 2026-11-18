<?php

namespace Tests\Feature;

use App\Jobs\SendNewMessageReceivedEmail;
use App\Livewire\MessageThread;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\NewMessageReceived;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * TASK-1858 — audit TASK-1857 : `NewMessageReceived`, dispatchee depuis
 * `MessageThread::notifierApresCommit()`, restait SYNCHRONE (un
 * `DB::afterCommit()` BRUT ne met rien en file, il differe seulement
 * l'appel direct) — a CHAQUE message prive echange, plus frequent que
 * les transitions de transaction deja deportees (TASK-1855/1857).
 * `SendNewMessageReceivedEmail` reprend A L'IDENTIQUE la famille de
 * jobs existante : charge utile minimale, relecture tenant, isolation
 * du destinataire.
 *
 * Perimetre : le dispatch (destinataire correct, jamais l'expediteur),
 * l'absence de notification synchrone dans la requete, l'isolation
 * destinataire/Organization dans le job, la restauration du contexte
 * tenant, et l'integrite de la messagerie si la mise en file echoue.
 * Le choix de gabarit (prive vs echange) et les comportements
 * transactionnels de `sendMessage()` restent couverts par
 * `TASK1795PrivateMessageNotificationTest` / `TASK1844MessageThreadTransactionTest`
 * — revalides (77 tests, zero regression), non dupliques ici.
 */
class TASK1858NewMessageReceivedAsyncTest extends TestCase
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
        app()->instance('current_organization', $this->organization);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── 1. Dispatch vers le bon destinataire, jamais l'expediteur ───────────

    public function test_l_envoi_met_en_file_un_job_vers_le_destinataire_jamais_l_expediteur(): void
    {
        Queue::fake();

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', 'Bonjour')
            ->call('sendMessage');

        $message = Message::where('body', 'Bonjour')->firstOrFail();

        Queue::assertPushed(SendNewMessageReceivedEmail::class, 1);
        Queue::assertPushed(fn (SendNewMessageReceivedEmail $job): bool => $job->transactionId === (string) $this->transaction->id
            && $job->messageId === (string) $message->id
            && $job->recipientId === (string) $this->seller->id);
    }

    // ── 2. Aucune notification synchrone dans la requete elle-meme ─────────

    public function test_aucune_notification_synchrone_dans_la_requete(): void
    {
        Queue::fake();
        Notification::fake();

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', 'Bonjour')
            ->call('sendMessage');

        // Seul un job est mis en file (verifie ci-dessus). Un envoi
        // synchrone aurait ete enregistre par `Notification::fake()`.
        Notification::assertNothingSent();
    }

    // ── 3. Isolation destinataire/Organization dans le job ─────────────────

    public function test_le_job_refuse_un_destinataire_etranger_a_la_conversation(): void
    {
        $message = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Bonjour',
            'type' => 'user',
            'organization_id' => $this->organization->id,
        ]);
        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);

        $job = new SendNewMessageReceivedEmail((string) $this->transaction->id, (string) $message->id, (string) $etranger->id);
        $job->handle();

        $this->assertDatabaseMissing('email_logs', ['to_email' => $etranger->email]);
    }

    public function test_le_job_envoie_normalement_au_destinataire_legitime(): void
    {
        $message = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Bonjour',
            'type' => 'user',
            'organization_id' => $this->organization->id,
        ]);

        $job = new SendNewMessageReceivedEmail((string) $this->transaction->id, (string) $message->id, (string) $this->seller->id);
        $job->handle();

        $this->assertDatabaseHas('email_logs', ['to_email' => $this->seller->email]);
    }

    // ── 3bis. Coherence du MESSAGE (arbitrage MASTER, revue PR #867) ───────

    /**
     * `Message` n'a aucun scope global tenant : `Message::find()` rendrait,
     * sans garde, n'importe quel message portant cet identifiant — y
     * compris un message d'une AUTRE transaction si la charge utile etait
     * corrompue ou rejouee.
     */
    public function test_le_job_refuse_un_message_appartenant_a_une_autre_transaction(): void
    {
        $autreTransaction = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
            'status' => 'accepted',
            'points_proposed' => 50,
        ]);
        $messageDAilleurs = Message::create([
            'transaction_id' => $autreTransaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message sur une autre transaction',
            'type' => 'user',
            'organization_id' => $this->organization->id,
        ]);

        // La charge utile pointe vers $this->transaction, mais le message
        // retrouve appartient a $autreTransaction.
        $job = new SendNewMessageReceivedEmail((string) $this->transaction->id, (string) $messageDAilleurs->id, (string) $this->seller->id);
        $job->handle();

        $this->assertDatabaseMissing('email_logs', ['to_email' => $this->seller->email]);
    }

    public function test_le_job_refuse_un_message_appartenant_a_une_autre_organization(): void
    {
        $autreOrganization = Organization::factory()->create();

        // Incoherence simulee directement en base : le message porte le
        // BON transaction_id mais une Organization DIFFERENTE de celle de
        // la transaction — exactement le cas qu'aucun scope global ne peut
        // intercepter sur `Message`.
        $messageIncoherent = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message a l\'Organization incoherente',
            'type' => 'user',
            'organization_id' => $autreOrganization->id,
        ]);

        $job = new SendNewMessageReceivedEmail((string) $this->transaction->id, (string) $messageIncoherent->id, (string) $this->seller->id);
        $job->handle();

        $this->assertDatabaseMissing('email_logs', ['to_email' => $this->seller->email]);
    }

    public function test_le_job_refuse_un_message_dont_l_expediteur_est_illegitime(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);
        $messageExpediteurIllegitime = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $etranger->id,
            'body' => 'Message d\'un expediteur etranger a la conversation',
            'type' => 'user',
            'organization_id' => $this->organization->id,
        ]);

        $job = new SendNewMessageReceivedEmail((string) $this->transaction->id, (string) $messageExpediteurIllegitime->id, (string) $this->seller->id);
        $job->handle();

        $this->assertDatabaseMissing('email_logs', ['to_email' => $this->seller->email]);
    }

    public function test_le_job_refuse_un_destinataire_identique_a_l_expediteur(): void
    {
        $message = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Bonjour',
            'type' => 'user',
            'organization_id' => $this->organization->id,
        ]);

        // Le destinataire passe dans la charge utile est l'EXPEDITEUR
        // lui-meme — ne doit jamais se notifier de son propre message.
        $job = new SendNewMessageReceivedEmail((string) $this->transaction->id, (string) $message->id, (string) $this->buyer->id);
        $job->handle();

        $this->assertDatabaseMissing('email_logs', ['to_email' => $this->buyer->email]);
    }

    // ── 4. Restauration du contexte tenant ──────────────────────────────────

    public function test_le_job_envoie_sans_aucun_contexte_de_requete_prealable(): void
    {
        $message = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Bonjour',
            'type' => 'user',
            'organization_id' => $this->organization->id,
        ]);

        app()->forgetInstance('current_organization');

        (new SendNewMessageReceivedEmail((string) $this->transaction->id, (string) $message->id, (string) $this->seller->id))->handle();

        $this->assertDatabaseHas('email_logs', ['to_email' => $this->seller->email]);
        $this->assertFalse(app()->bound('current_organization'),
            'Le job ne doit laisser aucun tenant residuel quand il n\'y en avait aucun avant son execution.');
    }

    public function test_un_tenant_preexistant_est_restaure_a_l_identique_apres_le_job(): void
    {
        $message = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Bonjour',
            'type' => 'user',
            'organization_id' => $this->organization->id,
        ]);

        $autreOrganization = Organization::factory()->create();
        app()->instance('current_organization', $autreOrganization);

        (new SendNewMessageReceivedEmail((string) $this->transaction->id, (string) $message->id, (string) $this->seller->id))->handle();

        $this->assertSame($autreOrganization->id, app('current_organization')->id,
            'Le tenant precedent doit etre restaure EXACTEMENT, pas celui de la conversation traitee par le job.');
    }

    // ── 5. Integrite de la messagerie si la mise en file echoue ────────────

    public function test_un_echec_de_mise_en_file_n_empeche_pas_la_creation_du_message(): void
    {
        $queueMock = Mockery::mock(QueueFactory::class);
        $queueMock->shouldReceive('connection')->andThrow(new \RuntimeException('Simulated queue connection failure'));
        $this->app->instance('queue', $queueMock);
        $this->app->instance(QueueFactory::class, $queueMock);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', 'Bonjour malgre la panne de file')
            ->call('sendMessage')
            ->assertSet('newMessage', '');

        $this->assertDatabaseHas('messages', ['body' => 'Bonjour malgre la panne de file']);
    }

    public function test_le_job_ignore_silencieusement_un_message_deja_supprime(): void
    {
        $job = new SendNewMessageReceivedEmail((string) $this->transaction->id, (string) \Illuminate\Support\Str::uuid(), (string) $this->seller->id);

        $job->handle();

        $this->assertDatabaseCount('email_logs', 0);
    }

    // ── 6. Routage Managed Queue — meme motif que les jobs existants ───────

    public function test_le_job_bascule_sur_la_managed_queue_quand_le_drapeau_est_pose(): void
    {
        config([
            'queue.notifications_managed_queue_connection' => 'cloud',
            'queue.notifications_managed_queue_name' => 'notifications',
        ]);

        $job = new SendNewMessageReceivedEmail((string) $this->transaction->id, (string) \Illuminate\Support\Str::uuid(), (string) $this->seller->id);

        $this->assertSame('cloud', $job->connection);
        $this->assertSame('notifications', $job->queue);
    }

    public function test_le_job_reste_sur_la_file_dediee_par_defaut_sans_le_drapeau(): void
    {
        config(['queue.notifications_managed_queue_connection' => null]);

        $job = new SendNewMessageReceivedEmail((string) $this->transaction->id, (string) \Illuminate\Support\Str::uuid(), (string) $this->seller->id);

        $this->assertNull($job->connection);
        $this->assertSame(SendNewMessageReceivedEmail::DEDICATED_QUEUE, $job->queue);
    }
}
