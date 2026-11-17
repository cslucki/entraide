<?php

namespace Tests\Feature;

use App\Events\MessageUnreadCountChanged;
use App\Livewire\MessageThread;
use App\Models\Message;
use App\Models\Organization;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\NewMessageReceived;
use App\Services\ServiceRequestLifecycle;
use App\Support\Entraide\MessageSysteme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1847 — compteur GLOBAL de messages non lus en temps reel.
 *
 * Perimetre de CE fichier : les garanties generiques deja prouvees sur le
 * MEME doctrine `DB::afterCommit()` + `catch(BroadcastException)`
 * (TASK-1843/1845 — apres commit, jamais apres rollback, transaction
 * imbriquee, panne Reverb sous `sync`, canal `user.{userId}` deja durci et
 * teste par `TASK1843UserChannelAuthorizationTest`) ne sont pas
 * re-demontrees dans le detail ici quand elles reposent sur une
 * INFRASTRUCTURE DEJA VERIFIEE a l'identique — seules les regles SPECIFIQUES
 * a CE compteur (determination des destinataires, non-evenement sur
 * `markRead()` sans effet, non-confusion avec `MemberNotification`) sont
 * couvertes en detail.
 */
class TASK1847MessageUnreadCountRealtimeTest extends TestCase
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

        config()->set('messages_unread_realtime.enabled', true);

        // `User::unreadMessagesCount()` passe par `whereHas('transaction', ...)`,
        // une sous-requete sur `transactions` qui porte le meme scope global
        // `BelongsToOrganizationScope` que toute autre requete sur ce modele :
        // sans contexte tenant courant, elle echoue fail-closed (TASK-1670)
        // et rendrait silencieusement 0 quel que soit l'etat reel.
        app()->instance('current_organization', $this->organization);
    }

    private function envoyerViaLivewire(User $acteur, Transaction $transaction, string $corps): void
    {
        Livewire::actingAs($acteur)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->set('newMessage', $corps)
            ->call('sendMessage');
    }

    // ── 1. Message utilisateur : SEUL le destinataire voit son compteur augmenter ──

    public function test_message_utilisateur_diffuse_uniquement_au_destinataire(): void
    {
        Event::fake([MessageUnreadCountChanged::class]);

        $this->envoyerViaLivewire($this->buyer, $this->transaction, 'Bonjour');

        Event::assertDispatchedTimes(MessageUnreadCountChanged::class, 1);
        Event::assertDispatched(
            MessageUnreadCountChanged::class,
            fn (MessageUnreadCountChanged $event): bool => $event->recipientId === $this->seller->id
        );

        $this->assertSame(1, $this->seller->fresh()->unreadMessagesCount());
        $this->assertSame(0, $this->buyer->fresh()->unreadMessagesCount());
    }

    // ── 2. Message systeme : LES DEUX participants sont notifies ────────────

    public function test_message_systeme_diffuse_aux_deux_participants(): void
    {
        Event::fake([MessageUnreadCountChanged::class]);

        MessageSysteme::ecrire($this->transaction, 'echange_accepte');

        Event::assertDispatchedTimes(MessageUnreadCountChanged::class, 2);
        Event::assertDispatched(
            MessageUnreadCountChanged::class,
            fn (MessageUnreadCountChanged $event): bool => $event->recipientId === $this->buyer->id
        );
        Event::assertDispatched(
            MessageUnreadCountChanged::class,
            fn (MessageUnreadCountChanged $event): bool => $event->recipientId === $this->seller->id
        );

        $this->assertSame(1, $this->buyer->fresh()->unreadMessagesCount());
        $this->assertSame(1, $this->seller->fresh()->unreadMessagesCount());
    }

    public function test_message_systeme_de_service_request_lifecycle_diffuse_aux_deux_participants_de_chaque_transaction_concurrente(): void
    {
        $demandeur = User::factory()->create(['organization_id' => $this->organization->id]);
        $aideAcceptee = User::factory()->create(['organization_id' => $this->organization->id]);
        $aideConcurrente = User::factory()->create(['organization_id' => $this->organization->id]);

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

        $concurrente = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'request_id' => $request->id,
            'buyer_id' => $demandeur->id,
            'seller_id' => $aideConcurrente->id,
            'status' => 'pending',
        ]);

        Event::fake([MessageUnreadCountChanged::class]);

        (new ServiceRequestLifecycle)->markHandled($accepted);

        Event::assertDispatchedTimes(MessageUnreadCountChanged::class, 2);
        Event::assertDispatched(
            MessageUnreadCountChanged::class,
            fn (MessageUnreadCountChanged $event): bool => $event->recipientId === $demandeur->id
        );
        Event::assertDispatched(
            MessageUnreadCountChanged::class,
            fn (MessageUnreadCountChanged $event): bool => $event->recipientId === $aideConcurrente->id
        );
    }

    // ── 3 & 4. Lecture : evenement SEULEMENT si au moins une ligne a change ──

    public function test_ouverture_du_fil_diffuse_au_lecteur_quand_des_messages_deviennent_lus(): void
    {
        Message::factory()->forTransaction($this->transaction)->create([
            'sender_id' => $this->buyer->id,
            'organization_id' => $this->organization->id,
        ]);

        $this->assertSame(1, $this->seller->fresh()->unreadMessagesCount());

        Event::fake([MessageUnreadCountChanged::class]);

        Livewire::actingAs($this->seller)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('markRead');

        Event::assertDispatchedTimes(MessageUnreadCountChanged::class, 1);
        Event::assertDispatched(
            MessageUnreadCountChanged::class,
            fn (MessageUnreadCountChanged $event): bool => $event->recipientId === $this->seller->id
        );
        $this->assertSame(0, $this->seller->fresh()->unreadMessagesCount());
    }

    /**
     * REVUE MASTER (correctif avant fusion) — `messages.read_at` est un
     * champ PARTAGE : un message SYSTEME compte comme non lu pour LES DEUX
     * participants (TASK-1693), et le marquer lu (par N'IMPORTE LEQUEL des
     * deux) le rend lu POUR LES DEUX en une seule ecriture. Mary (le
     * lecteur ICI) et John (l'autre participant) doivent donc TOUS LES
     * DEUX recevoir l'evenement, et TOUS LES DEUX voir leur compteur
     * baisser en base — pas seulement Mary.
     */
    public function test_mary_lit_un_message_systeme_les_compteurs_et_evenements_de_mary_et_john_sont_tous_les_deux_actualises(): void
    {
        $mary = $this->buyer;
        $john = $this->seller;

        MessageSysteme::ecrire($this->transaction, 'echange_accepte');

        $this->assertSame(1, $mary->fresh()->unreadMessagesCount());
        $this->assertSame(1, $john->fresh()->unreadMessagesCount());

        Event::fake([MessageUnreadCountChanged::class]);

        Livewire::actingAs($mary)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('markRead');

        Event::assertDispatchedTimes(MessageUnreadCountChanged::class, 2);
        Event::assertDispatched(
            MessageUnreadCountChanged::class,
            fn (MessageUnreadCountChanged $event): bool => $event->recipientId === $mary->id
        );
        Event::assertDispatched(
            MessageUnreadCountChanged::class,
            fn (MessageUnreadCountChanged $event): bool => $event->recipientId === $john->id
        );

        // Les DEUX compteurs ont REELLEMENT baisse en base — pas seulement
        // celui de Mary, puisque `read_at` est partage par la ligne.
        $this->assertSame(0, $mary->fresh()->unreadMessagesCount());
        $this->assertSame(0, $john->fresh()->unreadMessagesCount());
    }

    /**
     * Contre-preuve du test precedent : un message UTILISATEUR ordinaire
     * (jamais partage de la meme facon — il n'est de toute facon JAMAIS
     * compte comme non lu pour son propre expediteur) ne doit JAMAIS
     * notifier que le lecteur, meme quand `markRead()` en marque plusieurs
     * a la fois.
     */
    public function test_lecture_de_plusieurs_messages_utilisateur_ordinaires_ne_notifie_que_le_lecteur(): void
    {
        Message::factory()->forTransaction($this->transaction)->count(3)->create([
            'sender_id' => $this->buyer->id,
            'organization_id' => $this->organization->id,
        ]);

        Event::fake([MessageUnreadCountChanged::class]);

        Livewire::actingAs($this->seller)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('markRead');

        Event::assertDispatchedTimes(MessageUnreadCountChanged::class, 1);
        Event::assertDispatched(
            MessageUnreadCountChanged::class,
            fn (MessageUnreadCountChanged $event): bool => $event->recipientId === $this->seller->id
        );
    }

    public function test_markread_sans_rien_a_lire_ne_diffuse_jamais(): void
    {
        // Rien a lire : aucun message non lu n'existe pour $this->seller.
        Event::fake([MessageUnreadCountChanged::class]);

        Livewire::actingAs($this->seller)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('markRead');

        Event::assertNotDispatched(MessageUnreadCountChanged::class);
    }

    public function test_un_second_markread_immediat_reste_sans_evenement(): void
    {
        Message::factory()->forTransaction($this->transaction)->create([
            'sender_id' => $this->buyer->id,
            'organization_id' => $this->organization->id,
        ]);

        $component = Livewire::actingAs($this->seller)
            ->test(MessageThread::class, ['transaction' => $this->transaction]);

        $component->call('markRead'); // premiere lecture, reelle

        Event::fake([MessageUnreadCountChanged::class]);
        $component->call('markRead'); // rien a relire

        Event::assertNotDispatched(MessageUnreadCountChanged::class);
    }

    // ── 6. Rollback SQL : aucune diffusion ───────────────────────────────────

    public function test_rollback_force_ne_diffuse_jamais_le_changement_de_compteur(): void
    {
        Event::fake([MessageUnreadCountChanged::class]);

        Message::creating(function (): void {
            throw new \RuntimeException('panne SQL simulee — TASK-1847');
        });

        try {
            $this->envoyerViaLivewire($this->buyer, $this->transaction, 'Ne doit jamais exister');
            $this->fail('la panne simulee aurait du se propager');
        } catch (\RuntimeException $e) {
            $this->assertSame('panne SQL simulee — TASK-1847', $e->getMessage());
        } finally {
            Message::flushEventListeners();
        }

        Event::assertNotDispatched(MessageUnreadCountChanged::class);
        $this->assertSame(0, $this->seller->fresh()->unreadMessagesCount());
    }

    // ── 7. Panne Reverb : message ET email preserves ────────────────────────

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

    public function test_une_panne_reverb_nempeche_pas_le_message_ni_lemail(): void
    {
        $this->configurerReverbInjoignable();
        Notification::fake();

        $this->envoyerViaLivewire($this->buyer, $this->transaction, 'Malgre une panne Reverb reelle');

        $this->assertDatabaseHas('messages', ['body' => 'Malgre une panne Reverb reelle']);
        Notification::assertSentToTimes($this->seller, NewMessageReceived::class, 1);
    }

    public function test_une_panne_reverb_nempeche_pas_le_commit_dune_transaction_externe(): void
    {
        $this->configurerReverbInjoignable();

        DB::transaction(function () {
            $this->envoyerViaLivewire($this->buyer, $this->transaction, 'Panne Reverb sous transaction externe');
            $this->transaction->touch();
        });

        $this->assertDatabaseHas('messages', ['body' => 'Panne Reverb sous transaction externe']);
    }

    // ── 9 & 11. Endpoint HTTP : source de verite, zero -> positif -> zero ──

    public function test_endpoint_unread_count_rend_le_compte_exact_de_l_utilisateur_authentifie(): void
    {
        $this->assertSame(0, $this->seller->unreadMessagesCount());

        $this->actingAs($this->seller)
            ->getJson('/messages/unread-count')
            ->assertOk()
            ->assertJson(['count' => 0]);

        Message::factory()->forTransaction($this->transaction)->create([
            'sender_id' => $this->buyer->id,
            'organization_id' => $this->organization->id,
        ]);

        $this->actingAs($this->seller)
            ->getJson('/messages/unread-count')
            ->assertOk()
            ->assertJson(['count' => 1]);

        Livewire::actingAs($this->seller)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('markRead');

        $this->actingAs($this->seller)
            ->getJson('/messages/unread-count')
            ->assertOk()
            ->assertJson(['count' => 0]);
    }

    /**
     * REVUE MASTER (correctif avant fusion) — regression directe du bug
     * reel trouve : AVANT ce correctif, l'endpoint liait l'Organization PAR
     * DEFAUT DE LA PLATEFORME (`ResolveUrlOrganization` classe « messages »
     * comme route de fonctionnalite ordinaire, pas personnelle), jamais
     * celle du membre — divergeant du rendu SSR du rail
     * (`app-side-nav.blade.php`) des que son Organization n'etait pas la
     * par defaut.
     *
     * `app()->forgetInstance('current_organization')` efface ICI la
     * liaison manuelle posee par `setUp()` (necessaire aux AUTRES tests de
     * ce fichier) pour que la requete HTTP suivante reparte a zero, EXACTEMENT
     * comme une requete reelle independante — sans ce nettoyage, le
     * conteneur de test masquerait la divergence (meme piege que la sonde
     * qui a servi a la prouver).
     */
    public function test_endpoint_unread_count_correspond_au_rendu_ssr_meme_quand_l_organization_du_membre_n_est_pas_celle_par_defaut_de_la_plateforme(): void
    {
        // Une Organization DISTINCTE marquee par defaut DE LA PLATEFORME —
        // celle que `resolveDefaultOrganization()` renverrait pour toute
        // route de fonctionnalite ordinaire sans contexte explicite.
        Organization::factory()->create(['is_default' => true, 'is_active' => true]);

        Message::factory()->forTransaction($this->transaction)->create([
            'sender_id' => $this->buyer->id,
            'organization_id' => $this->organization->id,
        ]);

        // Rendu SSR du rail : l'Organization REELLE du membre, jamais celle
        // par defaut de la plateforme.
        app()->instance('current_organization', $this->organization);
        $compteSSR = $this->seller->fresh()->unreadMessagesCount();
        $this->assertSame(1, $compteSSR);

        app()->forgetInstance('current_organization');

        $this->actingAs($this->seller)
            ->getJson('/messages/unread-count')
            ->assertOk()
            ->assertJson(['count' => $compteSSR]);
    }

    public function test_endpoint_unread_count_refuse_un_visiteur_non_authentifie(): void
    {
        $this->getJson('/messages/unread-count')->assertUnauthorized();
    }

    public function test_endpoint_unread_count_ne_peut_jamais_lire_le_compte_dun_autre_utilisateur(): void
    {
        Message::factory()->forTransaction($this->transaction)->create([
            'sender_id' => $this->buyer->id,
            'organization_id' => $this->organization->id,
        ]);

        // Le seul utilisateur authentifie est $this->buyer : meme si un
        // `user_id` arbitraire existait dans la requete, l'endpoint ne lit
        // jamais que `$request->user()`.
        $this->actingAs($this->buyer)
            ->getJson('/messages/unread-count?user_id='.$this->seller->id)
            ->assertOk()
            ->assertJson(['count' => 0]);
    }

    // ── 10. Drapeau OFF : zero diffusion ─────────────────────────────────────

    public function test_drapeau_desactive_aucune_diffusion_sur_creation_ni_lecture(): void
    {
        config()->set('messages_unread_realtime.enabled', false);

        Event::fake([MessageUnreadCountChanged::class]);

        $this->envoyerViaLivewire($this->buyer, $this->transaction, 'Drapeau OFF');

        Livewire::actingAs($this->seller)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('markRead');

        Event::assertNotDispatched(MessageUnreadCountChanged::class);
    }

    // ── 13. Aucune duplication d'email avec la diffusion active ─────────────

    public function test_aucune_duplication_d_email_avec_la_diffusion_du_compteur_active(): void
    {
        Notification::fake();
        Event::fake([MessageUnreadCountChanged::class]);

        $this->envoyerViaLivewire($this->buyer, $this->transaction, 'Verification anti-duplication email');

        Notification::assertSentToTimes($this->seller, NewMessageReceived::class, 1);
        Event::assertDispatchedTimes(MessageUnreadCountChanged::class, 1);
    }

    // ── Forme de l'event : canal, nom, payload vide ─────────────────────────

    public function test_le_canal_est_prive_et_scope_au_destinataire(): void
    {
        $event = new MessageUnreadCountChanged($this->seller->id);
        $canaux = $event->broadcastOn();

        $this->assertCount(1, $canaux);
        $this->assertSame("private-user.{$this->seller->id}", $canaux[0]->name);
    }

    public function test_le_payload_ne_porte_aucun_compte(): void
    {
        $event = new MessageUnreadCountChanged($this->seller->id);
        $this->assertSame([], $event->broadcastWith());
    }

    public function test_le_nom_d_evenement_broadcast_correspond_a_celui_attendu(): void
    {
        $event = new MessageUnreadCountChanged($this->seller->id);
        $this->assertSame('message.unread-count-changed', $event->broadcastAs());
    }

    protected function tearDown(): void
    {
        Message::flushEventListeners();

        parent::tearDown();
    }
}
