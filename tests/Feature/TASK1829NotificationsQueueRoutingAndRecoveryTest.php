<?php

namespace Tests\Feature;

use App\Console\Commands\RecoverStaleNotificationDeliveries;
use App\Jobs\SendNotificationEmail;
use App\Jobs\SendNotificationWebPush;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Services\Push\PushSubscriptionStore;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationDeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1829 — routage conditionnel vers la Managed Queue, bornes de duree,
 * recuperation des orphelines, et comportement a l'echelle (`@tous`).
 *
 * Audit accepte (Report/261107-13h52-Task1778-report2.md,
 * Report/261110-09h10-Task1798-report7.md) : `QUEUE_CONNECTION=sync` est
 * l'etat de PROD aujourd'hui, et `$timeout` ne protege RIEN sous `sync` —
 * seul ce fichier verifie que le CODE livre ici reste sans effet tant que
 * la Managed Queue n'existe pas (etape 3, hors mandat), tout en etant deja
 * correct pour le jour ou elle existera.
 *
 * Aucun envoi reel : `Http::fake()` systematique sur le push, `MAIL_MAILER`
 * de test = `array` (voir phpunit.xml) pour l'email.
 */
class TASK1829NotificationsQueueRoutingAndRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->proprietaire = User::factory()->complete()->create(['organization_id' => $this->org->id]);
        app()->instance('current_organization', $this->org);
    }

    // =====================================================================
    // A — routage conditionnel : vide par defaut, inchange
    // =====================================================================

    public function test_sans_drapeau_le_job_email_n_appelle_aucune_connexion_explicite(): void
    {
        config(['queue.notifications_managed_queue_connection' => null]);
        Queue::fake();

        SendNotificationEmail::dispatch('fake-id');

        Queue::assertPushed(SendNotificationEmail::class, fn (SendNotificationEmail $job) => $job->connection === null);
    }

    public function test_sans_drapeau_le_job_push_n_appelle_aucune_connexion_explicite(): void
    {
        config(['queue.notifications_managed_queue_connection' => null]);
        Queue::fake();

        SendNotificationWebPush::dispatch('fake-id');

        Queue::assertPushed(SendNotificationWebPush::class, fn (SendNotificationWebPush $job) => $job->connection === null);
    }

    public function test_avec_le_drapeau_pose_le_job_email_bascule_sur_la_connexion_indiquee(): void
    {
        config(['queue.notifications_managed_queue_connection' => 'cloud']);
        Queue::fake();

        SendNotificationEmail::dispatch('fake-id');

        Queue::assertPushed(SendNotificationEmail::class, fn (SendNotificationEmail $job) => $job->connection === 'cloud');
    }

    public function test_avec_le_drapeau_pose_le_job_push_bascule_sur_la_connexion_indiquee(): void
    {
        config(['queue.notifications_managed_queue_connection' => 'cloud']);
        Queue::fake();

        SendNotificationWebPush::dispatch('fake-id');

        Queue::assertPushed(SendNotificationWebPush::class, fn (SendNotificationWebPush $job) => $job->connection === 'cloud');
    }

    // =====================================================================
    // B — bornes de duree (plafond Flex Starter : 90 s)
    // =====================================================================

    public function test_le_timeout_du_job_push_respecte_le_plafond_flex_90s(): void
    {
        $job = new SendNotificationWebPush('fake-id');

        $this->assertLessThanOrEqual(90, $job->timeout);
    }

    public function test_le_timeout_du_job_email_reste_sous_le_plafond_flex_90s(): void
    {
        $job = new SendNotificationEmail('fake-id');

        $this->assertLessThanOrEqual(90, $job->timeout);
    }

    public function test_aucun_des_deux_jobs_ne_reprend_automatiquement(): void
    {
        $this->assertSame(1, (new SendNotificationEmail('fake-id'))->tries);
        $this->assertSame(1, (new SendNotificationWebPush('fake-id'))->tries);
    }

    // =====================================================================
    // C — a l'echelle : 10/20/50 destinataires, email + push, tout reussit
    // =====================================================================

    /** @return array<string, array{0: int}> */
    public static function taillesHumaines(): array
    {
        return [
            '10 destinataires' => [10],
            '20 destinataires' => [20],
            '50 destinataires' => [50],
        ];
    }

    #[DataProvider('taillesHumaines')]
    public function test_le_tous_fonctionne_pour_n_destinataires_email_et_push(int $n): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        [$loop, $membres] = $this->creerBoucleAvecMembres($n, abonnerPush: true);

        $message = app(LoopMessageService::class)->sendUserMessage(
            $loop,
            $this->proprietaire,
            'Bonjour @tous',
            ['mention_all' => true],
            null,
            null,
            $membres->pluck('id')->all(),
        );

        $this->assertNotNull($message->getKey(), 'Le message doit etre publie.');

        $notifications = MemberNotification::query()
            ->where('notification_key', NotificationCatalogue::LOOP_MENTION_CREATED)
            ->get();

        $this->assertCount($n, $notifications, 'Un destinataire = une notification, l auteur excepte.');

        foreach ($notifications as $notification) {
            $email = MemberNotificationDelivery::query()
                ->where('notification_id', $notification->id)
                ->forChannel(NotificationCatalogue::CHANNEL_EMAIL)
                ->first();
            $push = MemberNotificationDelivery::query()
                ->where('notification_id', $notification->id)
                ->forChannel(NotificationCatalogue::CHANNEL_WEB_PUSH)
                ->first();

            $this->assertSame(NotificationDeliveryStatus::SENT, $email?->status);
            $this->assertSame(NotificationDeliveryStatus::SENT, $push?->status);
        }
    }

    // =====================================================================
    // D — Push injoignable a l'echelle : jamais d'exception, jamais de
    //     livraison bloquee en `sending`
    // =====================================================================

    public function test_push_injoignable_a_vingt_destinataires_n_empeche_ni_la_publication_ni_l_email(): void
    {
        // Aucun reseau simule : la connexion elle-meme echoue, exactement le
        // cas « fournisseur injoignable » du mandat.
        Http::fake(function () {
            throw new ConnectionException('fournisseur de push injoignable (banc)');
        });

        [$loop, $membres] = $this->creerBoucleAvecMembres(20, abonnerPush: true);

        $message = app(LoopMessageService::class)->sendUserMessage(
            $loop,
            $this->proprietaire,
            'Bonjour @tous',
            ['mention_all' => true],
            null,
            null,
            $membres->pluck('id')->all(),
        );

        $this->assertNotNull($message->getKey(), 'Un push injoignable ne doit jamais empecher la publication.');

        $notifications = MemberNotification::query()
            ->where('notification_key', NotificationCatalogue::LOOP_MENTION_CREATED)
            ->get();

        $this->assertCount(20, $notifications);

        foreach ($notifications as $notification) {
            $email = MemberNotificationDelivery::query()
                ->where('notification_id', $notification->id)
                ->forChannel(NotificationCatalogue::CHANNEL_EMAIL)
                ->first();
            $push = MemberNotificationDelivery::query()
                ->where('notification_id', $notification->id)
                ->forChannel(NotificationCatalogue::CHANNEL_WEB_PUSH)
                ->first();

            $this->assertSame(
                NotificationDeliveryStatus::SENT,
                $email?->status,
                'Un incident push ne doit jamais degrader l email, livre independamment.'
            );

            $this->assertNotSame(
                NotificationDeliveryStatus::SENDING,
                $push?->status,
                'Une livraison push ne doit JAMAIS rester bloquee en sending : prendre() ne la reprendrait plus.'
            );
            $this->assertContains($push?->status, [
                NotificationDeliveryStatus::FAILED,
                NotificationDeliveryStatus::AMBIGUOUS,
            ]);
        }
    }

    // =====================================================================
    // E — recuperation des livraisons orphelines
    // =====================================================================

    public function test_une_livraison_sending_ancienne_devient_ambiguous(): void
    {
        $livraison = $this->livraisonSendingAgeeDe(20);

        $this->artisan('notifications:recover-stale-deliveries')->assertExitCode(0);

        $this->assertSame(NotificationDeliveryStatus::AMBIGUOUS, $livraison->refresh()->status);
        $this->assertSame('stale_sending_recovered', $livraison->diagnostic);
    }

    public function test_une_livraison_sending_recente_n_est_jamais_touchee(): void
    {
        $livraison = $this->livraisonSendingAgeeDe(2);

        $this->artisan('notifications:recover-stale-deliveries')->assertExitCode(0);

        $this->assertSame(
            NotificationDeliveryStatus::SENDING,
            $livraison->refresh()->status,
            'Un worker legitimement en cours ne doit jamais etre touche.'
        );
    }

    public function test_le_dry_run_n_ecrit_rien(): void
    {
        $livraison = $this->livraisonSendingAgeeDe(30);

        $this->artisan('notifications:recover-stale-deliveries --dry-run')->assertExitCode(0);

        $this->assertSame(NotificationDeliveryStatus::SENDING, $livraison->refresh()->status);
    }

    public function test_une_livraison_deja_terminale_n_est_jamais_touchee(): void
    {
        $notification = $this->notificationEmail();
        $livraison = MemberNotificationDelivery::query()
            ->where('notification_id', $notification->id)
            ->forChannel(NotificationCatalogue::CHANNEL_EMAIL)
            ->firstOrFail();

        // Livraison REELLE, deja conclue `sent` par le vrai chemin
        // synchrone (`notificationEmail()`, aucun `Queue::fake()` ici).
        $this->assertSame(NotificationDeliveryStatus::SENT, $livraison->refresh()->status);
        $livraison->forceFill(['claimed_at' => now()->subMinutes(30)])->saveQuietly();

        $this->artisan('notifications:recover-stale-deliveries')->assertExitCode(0);

        $this->assertSame(NotificationDeliveryStatus::SENT, $livraison->refresh()->status);
    }

    public function test_la_commande_est_bien_planifiee_toutes_les_cinq_minutes(): void
    {
        $evenement = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'notifications:recover-stale-deliveries'));

        $this->assertNotNull($evenement, 'la commande doit etre planifiee dans routes/console.php');
        $this->assertSame('*/5 * * * *', $evenement->expression);
    }

    // ── Outillage ────────────────────────────────────────────────────────

    /**
     * @return array{0: Loop, 1: \Illuminate\Support\Collection<int, User>}
     */
    private function creerBoucleAvecMembres(int $n, bool $abonnerPush): array
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->org->id,
            'created_by' => $this->proprietaire->id,
            'status' => 'active',
        ]);

        LoopMember::create([
            'loop_id' => $loop->id,
            'user_id' => $this->proprietaire->id,
            'organization_id' => $this->org->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $membres = User::factory()->count($n)->create(['organization_id' => $this->org->id]);

        foreach ($membres as $membre) {
            LoopMember::create([
                'loop_id' => $loop->id,
                'user_id' => $membre->id,
                'organization_id' => $this->org->id,
                'role' => 'member',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            if ($abonnerPush) {
                $this->abonnerUnAppareil($membre);
            }
        }

        return [$loop, $membres];
    }

    /** Un appareil avec des clefs P-256 REELLES : le chiffrement refuse une valeur bidon. */
    private function abonnerUnAppareil(User $membre): void
    {
        $paire = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $detail = openssl_pkey_get_details($paire);
        $publique = "\x04"
            .str_pad($detail['ec']['x'], 32, "\0", STR_PAD_LEFT)
            .str_pad($detail['ec']['y'], 32, "\0", STR_PAD_LEFT);

        config()->set('webpush.vapid.subject', 'mailto:banc@exemple.test');
        if (! config('webpush.vapid.public_key')) {
            $serveur = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            $detailServeur = openssl_pkey_get_details($serveur);
            config()->set('webpush.vapid.public_key', self::base64Url(
                "\x04".str_pad($detailServeur['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($detailServeur['ec']['y'], 32, "\0", STR_PAD_LEFT)
            ));
            config()->set('webpush.vapid.private_key', self::base64Url(
                str_pad($detailServeur['ec']['d'], 32, "\0", STR_PAD_LEFT)
            ));
        }

        app(PushSubscriptionStore::class)->revendiquer(
            $membre,
            'https://fcm.googleapis.com/fcm/send/t1829-'.bin2hex(random_bytes(6)),
            self::base64Url($publique),
            self::base64Url(random_bytes(16)),
            'Android',
        );
    }

    private static function base64Url(string $binaire): string
    {
        return rtrim(strtr(base64_encode($binaire), '+/', '-_'), '=');
    }

    /** Une notification email ordinaire (mention individuelle), sans push. */
    private function notificationEmail(): MemberNotification
    {
        $destinataire = User::factory()->create(['organization_id' => $this->org->id]);

        $loop = Loop::factory()->create([
            'organization_id' => $this->org->id,
            'created_by' => $this->proprietaire->id,
            'status' => 'active',
        ]);

        foreach ([$this->proprietaire, $destinataire] as $personne) {
            LoopMember::create([
                'loop_id' => $loop->id,
                'user_id' => $personne->id,
                'organization_id' => $this->org->id,
                'role' => $personne->is($this->proprietaire) ? 'owner' : 'member',
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        app(LoopMessageService::class)->sendUserMessage(
            $loop,
            $this->proprietaire,
            'Bonjour @'.$destinataire->name,
            null,
            null,
            null,
            [$destinataire->id],
        );

        return MemberNotification::query()
            ->where('recipient_id', $destinataire->id)
            ->firstOrFail();
    }

    /**
     * Une livraison forcee en `sending`, avec `claimed_at` vieux de N minutes
     * — l'etat EXACT d'un worker tue avant de conclure.
     *
     * `Queue::fake()` empeche le job de s'executer : sous `sync` (banc de
     * test, comme la PROD aujourd'hui), `notificationEmail()` livrerait sinon
     * REELLEMENT l'email et la ligne passerait directement en `sent` —
     * `sent -> sending` est une transition interdite, a raison. Figer la
     * ligne en `pending` (le vrai etat qu'un job jamais execute laisse) rend
     * ensuite `pending -> sending` legitime, exactement le pas que `prendre()`
     * franchit dans les deux livreurs.
     */
    private function livraisonSendingAgeeDe(int $minutes): MemberNotificationDelivery
    {
        Queue::fake();
        $notification = $this->notificationEmail();

        $livraison = MemberNotificationDelivery::query()
            ->where('notification_id', $notification->id)
            ->forChannel(NotificationCatalogue::CHANNEL_EMAIL)
            ->firstOrFail();

        $livraison->forceFill(['status' => NotificationDeliveryStatus::SENDING])->save();
        $livraison->forceFill(['claimed_at' => now()->subMinutes($minutes)])->saveQuietly();

        return $livraison;
    }
}
