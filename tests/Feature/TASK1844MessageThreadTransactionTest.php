<?php

namespace Tests\Feature;

use App\Livewire\MessageThread;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\NewMessageReceived;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1844 — securisation transactionnelle de `MessageThread::sendMessage()`.
 *
 * Avant cette TASK, `Message::create()` n'etait couverte par AUCUNE
 * transaction explicite (confirme par l'audit TASK-1842, section 1.10.2) :
 * un echec partiel (ex. `$transaction->touch()` ou `markRead()`) pouvait
 * laisser un message cree sans les effets de bord qui l'accompagnent. La
 * notification email, elle, partait de maniere SYNCHRONE immediatement
 * apres la creation, sans aucune garantie de commit.
 *
 * Perimetre strict (mandat MASTER) : aucun evenement Reverb, aucun
 * refactor general. Seules les ecritures SQL de l'envoi (creation du
 * message, `touch()`, `markRead()`) sont desormais dans une transaction,
 * et la notification part via `DB::afterCommit()` — apres le commit REEL,
 * y compris si cette transaction est un jour imbriquee dans celle d'un
 * appelant externe (test dedie ci-dessous).
 *
 * Les deux usages de `MessageThread` (conversation DIRECTE,
 * `points_proposed = 0`, et echange d'ENTRAIDE, `points_proposed > 0` ou
 * `service_id`/`request_id` renseignes) sont tous les deux exerces : le
 * montage par defaut de ce fichier est une entraide (comme
 * `MessageThreadTest`), et un test dedie couvre explicitement la
 * conversation directe.
 */
class TASK1844MessageThreadTransactionTest extends TestCase
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
        // TASK-1460 — meme hygiene : un listener `creating()` pose par un
        // test ne doit jamais atteindre les suivants.
        Message::flushEventListeners();

        parent::tearDown();
    }

    // ── 1. Envoi normal, notification unique ────────────────────────────────

    public function test_envoi_normal_cree_le_message_touche_la_conversation_et_notifie_une_seule_fois(): void
    {
        Notification::fake();

        $avant = $this->transaction->updated_at;

        // TASK-1844 — `touch()` et la creation du message partagent la
        // MEME seconde dans une suite rapide : sans cet ecart explicite,
        // `greaterThan()` comparerait deux horodatages EGAUX a la seconde
        // et rendrait ce test aveugle (meme piege que les `ORDER BY
        // created_at` deja documentes dans ce depot).
        $this->travel(1)->seconds();

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', 'Bonjour, message normal')
            ->call('sendMessage')
            ->assertSet('newMessage', '');

        $message = Message::where('body', 'Bonjour, message normal')->first();
        $this->assertNotNull($message);
        $this->assertSame($this->buyer->id, $message->sender_id);

        $this->assertTrue($this->transaction->fresh()->updated_at->greaterThan($avant), '`touch()` doit avoir eu lieu DANS la meme transaction que la creation');

        Notification::assertSentToTimes($this->seller, NewMessageReceived::class, 1);
    }

    // ── 2 & 3. Rollback force, aucun effet secondaire ───────────────────────

    public function test_rollback_force_ne_laisse_ni_message_ni_touch_ni_notification(): void
    {
        Notification::fake();

        $avant = $this->transaction->updated_at;

        Message::creating(function (): void {
            throw new RuntimeException('panne SQL simulee — TASK-1844');
        });

        try {
            Livewire::actingAs($this->buyer)
                ->test(MessageThread::class, ['transaction' => $this->transaction])
                ->set('newMessage', 'Ce message ne doit jamais exister')
                ->call('sendMessage');
            $this->fail('la panne simulee aurait du se propager');
        } catch (RuntimeException $e) {
            $this->assertSame('panne SQL simulee — TASK-1844', $e->getMessage());
        }

        $this->assertDatabaseMissing('messages', ['body' => 'Ce message ne doit jamais exister']);

        // TASK-1844 — LA garantie la plus sensible : `touch()` et
        // `markRead()`, pourtant APRES `Message::create()` dans le code,
        // n'ont jamais pu s'executer : la transaction entiere est annulee,
        // pas seulement l'insertion qui a echoue.
        $this->assertTrue($this->transaction->fresh()->updated_at->equalTo($avant), 'touch() ne doit avoir AUCUN effet si la transaction echoue');

        Notification::assertNothingSent();
    }

    // ── 4. Transaction externe, commit differe ──────────────────────────────

    public function test_transaction_externe_differe_la_notification_jusqu_au_commit_reel(): void
    {
        Notification::fake();

        // TASK-1844 — c'est EXACTEMENT le scenario que le mandat vise :
        // `sendMessage()` est appelee ICI depuis la transaction d'un
        // appelant externe (aucun aujourd'hui dans ce depot, mais la
        // garantie doit tenir si un jour il en existe un). Avec un simple
        // `rescue(fn () => $recipient->notify(...))` (etat pre-TASK-1844),
        // la notification serait partie DEDANS, avant que CETTE transaction
        // externe n'ait elle-meme commit — exactement le defaut corrige.
        DB::transaction(function () {
            Livewire::actingAs($this->buyer)
                ->test(MessageThread::class, ['transaction' => $this->transaction])
                ->set('newMessage', 'Message sous transaction externe')
                ->call('sendMessage');

            $this->assertDatabaseHas('messages', ['body' => 'Message sous transaction externe']);

            // Le message existe deja (visible dans la MEME transaction),
            // mais le commit REEL n'a pas encore eu lieu : la notification
            // ne doit pas etre partie.
            Notification::assertNothingSent();
        });

        // Le bloc externe vient de commit (reellement, au sens du banc de
        // test — `Illuminate\Foundation\Testing\DatabaseTransactionsManager`
        // traite la fin de CE bloc comme le commit racine, puisqu'il
        // n'existe rien de plus externe que l'enveloppe de `RefreshDatabase`
        // elle-meme) : la notification part maintenant, UNE SEULE fois.
        Notification::assertSentToTimes($this->seller, NewMessageReceived::class, 1);
    }

    // ── 5. Conversation cloturee (statut revalide au moment de l'ecriture) ──

    public function test_conversation_cloturee_entre_le_montage_et_l_envoi_refuse_l_ecriture(): void
    {
        Notification::fake();

        $component = Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction]);

        // Changement CONCURRENT, hors du composant — simule un autre acteur
        // qui cloture l'echange entre le montage et l'envoi.
        Transaction::whereKey($this->transaction->id)->update(['status' => 'completed']);

        $component->set('newMessage', 'Trop tard, deja cloturee')
            ->call('sendMessage');

        $this->assertDatabaseMissing('messages', ['body' => 'Trop tard, deja cloturee']);
        Notification::assertNothingSent();
    }

    // ── 6. Acces revoque / isolation Organization ───────────────────────────

    public function test_un_transactionId_d_une_autre_organization_est_refuse_sans_aucune_ecriture(): void
    {
        Notification::fake();

        $autreOrganization = Organization::factory()->create();
        $autreTransaction = Transaction::factory()->create([
            'organization_id' => $autreOrganization->id,
            'buyer_id' => User::factory()->create(['organization_id' => $autreOrganization->id])->id,
            'seller_id' => User::factory()->create(['organization_id' => $autreOrganization->id])->id,
            'status' => 'accepted',
        ]);

        try {
            Livewire::actingAs($this->buyer)
                ->test(MessageThread::class, ['transaction' => $autreTransaction])
                ->set('newMessage', 'Tentative inter-organization')
                ->call('sendMessage');
            $this->fail('un membre d\'une autre Organization a pu monter ce fil');
        } catch (ModelNotFoundException) {
            // refus attendu — meme garde que test_outsider_cannot_send_message
        }

        $this->assertDatabaseMissing('messages', ['body' => 'Tentative inter-organization']);
        Notification::assertNothingSent();
    }

    // ── 7. Pieces jointes : l'atomicite SQL n'affecte pas le fichier ────────

    public function test_message_avec_piece_jointe_reste_atomique_et_notifie_une_fois(): void
    {
        Storage::fake('public');
        Notification::fake();

        $image = UploadedFile::fake()->image('photo.jpg', 200, 200);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', 'Avec une image')
            ->set('photo', $image)
            ->call('sendMessage')
            ->assertSet('photo', null);

        $message = Message::where('body', 'Avec une image')->first();
        $this->assertNotNull($message);
        $this->assertNotNull($message->image_path);
        Storage::disk('public')->assertExists($message->image_path);

        Notification::assertSentToTimes($this->seller, NewMessageReceived::class, 1);
    }

    // ── Les deux usages : conversation DIRECTE en plus de l'entraide ───────

    public function test_conversation_directe_suit_le_meme_chemin_transactionnel(): void
    {
        Notification::fake();

        $direct = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
            'status' => 'accepted',
            'points_proposed' => 0,
        ]);
        $this->assertTrue($direct->isDirectConversation());

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $direct])
            ->set('newMessage', 'Message en conversation directe')
            ->call('sendMessage');

        $this->assertDatabaseHas('messages', [
            'transaction_id' => $direct->id,
            'body' => 'Message en conversation directe',
        ]);
        Notification::assertSentToTimes($this->seller, NewMessageReceived::class, 1);
    }

    // ── 8. Aucune regression sur pollThread()/markRead() ────────────────────

    /**
     * Couverture EXHAUSTIVE deja assuree par
     * `TASK1836MessageThreadPollFingerprintTest` (19 tests, tous verts
     * apres cette TASK) : court-circuit, soupape 15s, revocation d'acces,
     * empreinte de reaction/epinglage. Ce test-ci verifie seulement que le
     * chemin AJOUTE par TASK-1844 (la transaction de `sendMessage()`) ne
     * casse pas l'enchainement normal poll -> envoi -> poll.
     */
    public function test_pollThread_apres_un_envoi_rend_integralement_sans_erreur(): void
    {
        Notification::fake();

        $component = Livewire::actingAs($this->seller)
            ->test(MessageThread::class, ['transaction' => $this->transaction]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', 'Nouveau message avant poll')
            ->call('sendMessage');

        // `actingAs()` bascule l'utilisateur AUTHENTIFIE GLOBALEMENT pour
        // tout le test — l'appel ci-dessus l'a laisse sur le buyer. Il faut
        // explicitement revenir au seller avant de reprendre `$component`,
        // sans quoi `markRead()` lirait `auth()->id()` du buyer et
        // exclurait son propre message par la garde `sender_id != $userId`.
        $this->actingAs($this->seller);

        // Cet echange est une ENTRAIDE (`points_proposed = 50`) : `pollThread()`
        // rend tot (`! isDirectConversation()`), mais `render()` s'execute
        // quand meme juste apres — et `render()` appelle DEJA `markRead()`
        // inconditionnellement (l.947). Le nouveau message du buyer est donc
        // marque lu par CE SEUL rendu, sans appel explicite supplementaire.
        $component->call('pollThread')
            ->assertSee('Nouveau message avant poll');

        $apresPoll = Message::where('body', 'Nouveau message avant poll')->first();
        $this->assertNotNull($apresPoll->read_at, 'le rendu declenche par pollThread() doit avoir marque lu, comme avant TASK-1844');

        // Rejoue explicitement `markRead()` — idempotent, ne doit jamais
        // lever ni modifier une seconde fois une ligne deja lue.
        $component->call('markRead');

        $this->assertSame(
            $apresPoll->read_at->toDateTimeString(),
            Message::where('body', 'Nouveau message avant poll')->first()->read_at->toDateTimeString(),
            'un second markRead() ne doit jamais rouvrir ni redater une ligne deja lue'
        );
    }
}
