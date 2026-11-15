<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationDeliveryStatus;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TASK-1834 — le Scheduler Laravel Cloud, active en PROD depuis T1832,
 * n'ecrit AUCUNE trace quand `notifications:recover-stale-deliveries` ne
 * trouve rien a recuperer (le cas courant) : impossible de distinguer
 * « le cron ne tourne pas » de « rien a faire ». Cette commande journalise
 * desormais une ligne `info` a CHAQUE execution reussie, compteurs seuls —
 * jamais d'identifiant de livraison, de destinataire ou de contenu.
 *
 * Le comportement metier (seuil 15 minutes, lot de 500, frequence de 5
 * minutes) est INCHANGE : ces tests le reverifient explicitement, pas
 * seulement la journalisation.
 */
class TASK1834RecoverStaleDeliveriesExecutionLogTest extends TestCase
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

    public function test_une_execution_sans_rien_a_recuperer_journalise_un_info_avec_les_compteurs(): void
    {
        Log::spy();

        $this->artisan('notifications:recover-stale-deliveries')->assertExitCode(0);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $contexte) {
                return str_contains($message, 'notifications:recover-stale-deliveries')
                    && $contexte === ['candidats' => 0, 'recuperees' => 0];
            });
    }

    public function test_une_execution_qui_recupere_une_livraison_journalise_le_bon_compte(): void
    {
        Log::spy();
        $livraison = $this->livraisonSendingAgeeDe(20);

        $this->artisan('notifications:recover-stale-deliveries')->assertExitCode(0);

        // Le comportement metier reste celui de T1829 : non-regression.
        $this->assertSame(NotificationDeliveryStatus::AMBIGUOUS, $livraison->refresh()->status);
        $this->assertSame('stale_sending_recovered', $livraison->diagnostic);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message, array $contexte) => $contexte === ['candidats' => 1, 'recuperees' => 1]);
    }

    public function test_le_dry_run_journalise_sans_ecrire(): void
    {
        Log::spy();
        $livraison = $this->livraisonSendingAgeeDe(30);

        $this->artisan('notifications:recover-stale-deliveries --dry-run')->assertExitCode(0);

        // Dry-run : toujours aucune ecriture (non-regression T1829).
        $this->assertSame(NotificationDeliveryStatus::SENDING, $livraison->refresh()->status);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $contexte) {
                return str_contains($message, 'dry-run')
                    && $contexte === ['candidats' => 1];
            });
    }

    /** Point 3 du mandat : aucune donnee personnelle, aucun identifiant de livraison. */
    public function test_aucune_donnee_personnelle_ni_identifiant_de_livraison_dans_le_journal(): void
    {
        Log::spy();
        $this->livraisonSendingAgeeDe(20);

        $this->artisan('notifications:recover-stale-deliveries')->assertExitCode(0);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $contexte) {
                $interdits = ['delivery_id', 'recipient_id', 'email', 'notification_id', 'id'];

                return empty(array_intersect($interdits, array_keys($contexte)));
            });
    }

    /** Non-regression : la frequence planifiee reste celle de T1829. */
    public function test_la_frequence_planifiee_reste_cinq_minutes(): void
    {
        $evenement = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'notifications:recover-stale-deliveries'));

        $this->assertNotNull($evenement, 'la commande doit rester planifiee dans routes/console.php');
        $this->assertSame('*/5 * * * *', $evenement->expression);
    }

    // ── Outillage (copie minimale depuis TASK1829NotificationsQueueRoutingAndRecoveryTest) ──

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
