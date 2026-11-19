<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionStatusChangedEmail;
use App\Models\Organization;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TASK-1857 — audit TASK-1856 : la trace PHP-FPM lente de 6,46 s capturee
 * le 18/11 en recette reelle (figee dans `Symfony\Mailer\SmtpTransport`)
 * appartenait a `TransactionController::approve()`, toujours synchrone
 * apres que TASK-1855 ait deja deporte `complete()`. `approve()` et
 * `refuse()` reutilisent desormais `SendTransactionStatusChangedEmail`
 * (TASK-1855) SANS le modifier — meme mecanisme d'envoi, meme isolation
 * de destinataire.
 *
 * Nouveaute de cette TASK : `$expectedStatus` (facultatif, `null` pour
 * `complete()` — comportement inchange) protege contre le risque de
 * statut PERIME identifie par l'audit — un job mis en file depuis
 * `approve()` (statut `accepted`, TRANSITOIRE) pourrait s'executer apres
 * que la transaction ait deja ete cloturee.
 *
 * Perimetre : dispatch (destinataire unique, le buyer), absence de
 * blocage SMTP synchrone dans la requete HTTP, garde `expectedStatus`
 * dans les deux sens (statut perime -> rien envoye ; statut conforme ->
 * envoi normal), retro-compatibilite du job pour `complete()` (sans
 * `expectedStatus`), isolation destinataire/Organization (deja testee en
 * TASK-1855, revalidee ici pour les deux nouveaux appelants). `confirm()`
 * reste HORS perimetre — chemin herite, aucune anomalie demontree a son
 * sujet (audit TASK-1857).
 */
class TASK1857ApproveRefuseNotificationsAsyncTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $buyer;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->buyer = User::factory()->create(['organization_id' => $this->organization->id, 'points_balance' => 200]);
        $this->seller = User::factory()->create(['organization_id' => $this->organization->id]);
        app()->instance('current_organization', $this->organization);
    }

    private function transactionPendante(array $overrides = []): Transaction
    {
        $service = Service::factory()->forUser($this->seller)->create(['organization_id' => $this->organization->id]);

        return Transaction::factory()
            ->forBuyer($this->buyer)
            ->forSeller($this->seller)
            ->create(array_merge([
                'organization_id' => $this->organization->id,
                'service_id' => $service->id,
                'status' => 'pending',
                'points_proposed' => 50,
            ], $overrides));
    }

    // ── 1 & 2. approve()/refuse() mettent en file un job, buyer uniquement ──

    public function test_approve_met_en_file_un_job_vers_le_buyer_uniquement(): void
    {
        Queue::fake();

        $transaction = $this->transactionPendante();

        $this->actingAs($this->seller)->patch(route('transactions.approve', $transaction))
            ->assertRedirect();

        Queue::assertPushed(SendTransactionStatusChangedEmail::class, 1);
        Queue::assertPushed(fn (SendTransactionStatusChangedEmail $job): bool => $job->transactionId === (string) $transaction->id
            && $job->recipientId === (string) $this->buyer->id
            && $job->expectedStatus === 'accepted');
    }

    public function test_refuse_met_en_file_un_job_vers_le_buyer_uniquement(): void
    {
        Queue::fake();

        $transaction = $this->transactionPendante();

        $this->actingAs($this->seller)->patch(route('transactions.refuse', $transaction))
            ->assertRedirect();

        Queue::assertPushed(SendTransactionStatusChangedEmail::class, 1);
        Queue::assertPushed(fn (SendTransactionStatusChangedEmail $job): bool => $job->transactionId === (string) $transaction->id
            && $job->recipientId === (string) $this->buyer->id
            && $job->expectedStatus === 'refused');
    }

    // ── 3. Aucun envoi SMTP synchrone dans la requete HTTP elle-meme ───────

    public function test_approve_n_envoie_jamais_de_notification_synchrone(): void
    {
        Queue::fake();
        Notification::fake();

        $transaction = $this->transactionPendante();

        $this->actingAs($this->seller)->patch(route('transactions.approve', $transaction))
            ->assertRedirect();

        // La notification N'EST JAMAIS envoyee DANS la requete HTTP : seul
        // un job est mis en file (verifie ci-dessus). `Notification::fake()`
        // aurait enregistre un envoi synchrone s'il avait eu lieu.
        Notification::assertNothingSent();
    }

    public function test_refuse_n_envoie_jamais_de_notification_synchrone(): void
    {
        Queue::fake();
        Notification::fake();

        $transaction = $this->transactionPendante();

        $this->actingAs($this->seller)->patch(route('transactions.refuse', $transaction))
            ->assertRedirect();

        Notification::assertNothingSent();
    }

    // ── 4. Statut perime (approve -> complete avant execution du job) ──────

    /**
     * Simulation deterministe de la course decrite par l'audit : le job
     * est construit avec `expectedStatus: 'accepted'` (ce qu'`approve()`
     * passerait), mais la transaction est DEJA `completed` au moment ou
     * `handle()` s'execute (cloture survenue avant que le worker ne
     * traite ce job).
     */
    public function test_un_statut_perime_n_envoie_aucune_notification(): void
    {
        $transaction = $this->transactionPendante(['status' => 'completed', 'points_agreed' => 50]);

        $job = new SendTransactionStatusChangedEmail((string) $transaction->id, (string) $this->buyer->id, 'accepted');
        $job->handle();

        $this->assertDatabaseMissing('email_logs', ['to_email' => $this->buyer->email]);
    }

    // ── 5. Statut conforme : envoi normal ───────────────────────────────────

    public function test_un_statut_conforme_envoie_normalement(): void
    {
        $transaction = $this->transactionPendante(['status' => 'accepted', 'points_agreed' => 50]);

        $job = new SendTransactionStatusChangedEmail((string) $transaction->id, (string) $this->buyer->id, 'accepted');
        $job->handle();

        $this->assertDatabaseHas('email_logs', ['to_email' => $this->buyer->email]);
    }

    // ── 6. Retro-compatibilite : sans expectedStatus, comportement inchange ──

    /**
     * `complete()` (TASK-1855) ne passe jamais `expectedStatus` — la garde
     * doit rester totalement INERTE dans ce cas, quel que soit le statut
     * reel au moment de l'execution.
     */
    public function test_sans_expected_status_le_comportement_reste_inchange(): void
    {
        $transaction = $this->transactionPendante(['status' => 'completed', 'points_agreed' => 50]);

        $job = new SendTransactionStatusChangedEmail((string) $transaction->id, (string) $this->buyer->id);
        $job->handle();

        $this->assertNull($job->expectedStatus);
        $this->assertDatabaseHas('email_logs', ['to_email' => $this->buyer->email]);
    }

    // ── 7. Isolation destinataire/Organization (revalidee pour approve/refuse) ──

    public function test_le_job_refuse_un_destinataire_etranger_meme_avec_expected_status(): void
    {
        $transaction = $this->transactionPendante(['status' => 'accepted', 'points_agreed' => 50]);
        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);

        $job = new SendTransactionStatusChangedEmail((string) $transaction->id, (string) $etranger->id, 'accepted');
        $job->handle();

        $this->assertDatabaseMissing('email_logs', ['to_email' => $etranger->email]);
    }
}
