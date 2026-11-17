<?php

namespace Tests\Feature;

use App\Events\MemberNotificationCreated;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmitter;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1843 — diffusion temps reel du badge de notifications internes.
 *
 * Trois garanties du mandat MASTER, dans l'ordre ou elles comptent :
 *
 * 1. **OFF par defaut, independant du pilote ChatLoop.**
 * 2. **Le payload ne porte NI compte NI donnee personnelle** — le client
 *    relit la source de verite par HTTP (voir TASK1843UnreadCountEndpointTest).
 * 3. **Une panne Reverb ne peut jamais faire echouer la creation d'une
 *    notification** — y compris quand `NotificationEmitter::emit()` est
 *    appelee DEPUIS la transaction d'un producteur (mentions, demandes
 *    d'aide...), jamais seulement au niveau le plus externe.
 */
class TASK1843MemberNotificationBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private const OBJET = 'loop_invitation';

    private Organization $org;

    private User $alice;

    private NotificationEmitter $emetteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->alice = User::factory()->create(['organization_id' => $this->org->id]);
        $this->emetteur = new NotificationEmitter;
    }

    private function emettre(): MemberNotification
    {
        return $this->emetteur->emit(
            notificationKey: NotificationCatalogue::LOOP_INVITATION,
            organization: $this->org,
            recipient: $this->alice,
            eventId: (string) Str::uuid(),
            objectType: self::OBJET,
        );
    }

    // ── 1. OFF par defaut, independant du pilote ChatLoop ──────────────────

    public function test_drapeau_absent_aucune_diffusion(): void
    {
        config()->set('notifications_realtime.enabled', false);
        Event::fake([MemberNotificationCreated::class]);

        $this->emettre();

        Event::assertNotDispatched(MemberNotificationCreated::class);
    }

    public function test_drapeau_chatloop_seul_actif_naffecte_pas_les_notifications(): void
    {
        // TASK-1843 — les deux pilotes sont INDEPENDANTS : celui de ChatLoop
        // (TASK-1839) ne doit jamais, a lui seul, activer la diffusion des
        // notifications internes.
        config()->set('loop_chat.realtime_enabled', true);
        config()->set('notifications_realtime.enabled', false);
        Event::fake([MemberNotificationCreated::class]);

        $this->emettre();

        Event::assertNotDispatched(MemberNotificationCreated::class);
    }

    public function test_drapeau_actif_diffuse_apres_commit_pour_le_bon_destinataire(): void
    {
        config()->set('notifications_realtime.enabled', true);
        Event::fake([MemberNotificationCreated::class]);

        $notification = $this->emettre();

        Event::assertDispatched(
            MemberNotificationCreated::class,
            fn (MemberNotificationCreated $event): bool => $event->recipientId === $notification->recipient_id
        );
    }

    public function test_un_rejeu_idempotent_event_id_identique_ne_diffuse_pas_une_seconde_fois(): void
    {
        // TASK-1843 — `emit()` constate une emission DEJA existante sans
        // repasser par la branche de creation (NotificationEmitter l.117-130) :
        // `diffuserSiActive()` ne doit donc JAMAIS s'executer sur un rejeu.
        config()->set('notifications_realtime.enabled', true);

        $eventId = (string) Str::uuid();

        $premiere = $this->emetteur->emit(
            notificationKey: NotificationCatalogue::LOOP_INVITATION,
            organization: $this->org,
            recipient: $this->alice,
            eventId: $eventId,
            objectType: self::OBJET,
        );

        Event::fake([MemberNotificationCreated::class]);

        $rejoue = $this->emetteur->emit(
            notificationKey: NotificationCatalogue::LOOP_INVITATION,
            organization: $this->org,
            recipient: $this->alice,
            eventId: $eventId,
            objectType: self::OBJET,
        );

        $this->assertSame($premiere->id, $rejoue->id);
        Event::assertNotDispatched(MemberNotificationCreated::class);
    }

    // ── 2. Le payload ne porte rien ─────────────────────────────────────────

    public function test_le_canal_est_prive_et_scope_au_seul_destinataire(): void
    {
        $notification = $this->emettre();

        $event = new MemberNotificationCreated($notification);
        $canaux = $event->broadcastOn();

        $this->assertCount(1, $canaux);
        $this->assertInstanceOf(PrivateChannel::class, $canaux[0]);
        $this->assertSame("private-user.{$this->alice->id}", $canaux[0]->name);
    }

    public function test_le_payload_ne_porte_ni_compte_ni_donnee_personnelle(): void
    {
        $notification = $this->emettre();

        $event = new MemberNotificationCreated($notification);

        $this->assertSame([], $event->broadcastWith());
    }

    public function test_le_nom_d_evenement_broadcast_correspond_a_celui_attendu_par_le_client(): void
    {
        $notification = $this->emettre();

        $this->assertSame('notification.count-changed', (new MemberNotificationCreated($notification))->broadcastAs());
    }

    // ── 3. Une panne Reverb ne casse jamais la creation ─────────────────────

    /**
     * Pointe la connexion Reverb vers un port local FERME : le client Pusher
     * (sous-jacent au driver `reverb`) tente une connexion TCP refusee quasi
     * instantanement — pas de reseau reel, pas de delai, deterministe.
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

    public function test_une_panne_reverb_nempeche_pas_la_creation_de_la_notification(): void
    {
        config()->set('notifications_realtime.enabled', true);
        $this->configurerReverbInjoignable();

        $notification = $this->emettre();

        $this->assertTrue($notification->exists);
        $this->assertDatabaseHas('member_notifications', ['id' => $notification->id]);
    }

    /**
     * TASK-1843 — la garantie qui compte le plus : `NotificationEmitter`
     * est aussi appelee DEPUIS la transaction d'un producteur (mentions,
     * demandes d'aide — voir `LoopMessageService`), jamais seulement au
     * niveau le plus externe. `DB::transaction()` imbriquee n'y ouvre qu'un
     * point de sauvegarde ; sans le `DB::afterCommit()` pose a la main dans
     * `diffuserSiActive()`, une panne Reverb ne se declarerait qu'au commit
     * REEL, hors de portee du `catch` local — et casserait alors la
     * transaction EXTERNE entiere, pas seulement la diffusion.
     */
    public function test_une_panne_reverb_nempeche_pas_le_commit_dune_transaction_externe_qui_appelle_emit(): void
    {
        config()->set('notifications_realtime.enabled', true);
        $this->configurerReverbInjoignable();

        // `emit()` ouvre ICI sa propre `DB::transaction()` — imbriquee dans
        // celle-ci, elle ne pose qu'un POINT DE SAUVEGARDE. Le commit REEL,
        // et donc le callback `afterCommit` qui tente la diffusion, n'arrive
        // qu'a la fin de CE `DB::transaction()` externe. Si la panne Reverb
        // n'etait pas avalee a l'interieur meme du callback, l'exception
        // sortirait ICI et ferait echouer tout ce bloc — pas seulement la
        // diffusion.
        $notification = DB::transaction(function (): MemberNotification {
            $notification = $this->emettre();

            $this->org->touch();

            return $notification;
        });

        $this->assertTrue($notification->exists);
        $this->assertDatabaseHas('member_notifications', ['id' => $notification->id]);
    }
}
