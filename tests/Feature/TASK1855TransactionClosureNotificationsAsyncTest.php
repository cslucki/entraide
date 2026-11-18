<?php

namespace Tests\Feature;

use App\Http\Controllers\TransactionController;
use App\Jobs\SendEvaluationInviteEmail;
use App\Jobs\SendTransactionStatusChangedEmail;
use App\Models\EmailLog;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Entraide\EvaluationInviteMailer;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * TASK-1855 — audit TASK-1856 : `TransactionStatusChanged` (acheteur ET
 * vendeur) et `EvaluationInviteMailer::envoyer()` bloquaient encore le
 * thread HTTP de `TransactionController::complete()` sur une conversation
 * SMTP, apres que TASK-1854 ait deja deporte `ReviewReceivedMailer`. Les
 * deux sont desormais deportes vers la Managed Queue `notifications`, via
 * `SendTransactionStatusChangedEmail` (un job par destinataire) et
 * `SendEvaluationInviteEmail` (sequence check/envoi/marquage PRESERVEE a
 * l'identique — voir son docblock).
 *
 * Perimetre : le dispatch (deux destinataires, pas de perte ni de doublon),
 * le statut metier inchange par un echec de livraison ou de mise en file,
 * l'idempotence de `evaluation_invited_at` meme a deux jobs, l'isolation et
 * la restauration du contexte tenant dans chaque job, et la garde anti
 * double-appui du bouton de cloture. Le comportement des MAILERS/
 * notifications eux-memes (gabarits, `email_logs`) reste couvert par
 * `TASK1809EvaluationInviteImmediateEmailTest` / tests existants —
 * revalide, non duplique ici.
 */
class TASK1855TransactionClosureNotificationsAsyncTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $aide;

    private User $aidant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->aide = User::factory()->create(['organization_id' => $this->organization->id, 'points_balance' => 100]);
        $this->aidant = User::factory()->create(['organization_id' => $this->organization->id, 'points_balance' => 10]);
        app()->instance('current_organization', $this->organization);
    }

    private function entraideAcceptee(array $overrides = []): Transaction
    {
        return Transaction::factory()
            ->forBuyer($this->aide)
            ->forSeller($this->aidant)
            ->accepted()
            ->create(array_merge([
                'organization_id' => $this->organization->id,
                'points_proposed' => 20,
                'points_agreed' => 20,
            ], $overrides));
    }

    private function invitesPour(string $email)
    {
        return EmailLog::where('to_email', $email)
            ->get()
            ->filter(fn (EmailLog $log) => ($log->data['source'] ?? null) === 'entraide-evaluation-invite');
    }

    // ── 1. Deux destinataires corrects, un job par destinataire ────────────

    public function test_la_cloture_met_en_file_un_job_de_statut_par_destinataire(): void
    {
        Queue::fake();

        $entraide = $this->entraideAcceptee();

        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        Queue::assertPushed(SendTransactionStatusChangedEmail::class, 2);
        Queue::assertPushed(fn (SendTransactionStatusChangedEmail $job): bool => $job->transactionId === (string) $entraide->id
            && $job->recipientId === (string) $this->aide->id);
        Queue::assertPushed(fn (SendTransactionStatusChangedEmail $job): bool => $job->transactionId === (string) $entraide->id
            && $job->recipientId === (string) $this->aidant->id);

        Queue::assertPushed(SendEvaluationInviteEmail::class, 1);
        Queue::assertPushed(fn (SendEvaluationInviteEmail $job): bool => $job->transactionId === (string) $entraide->id);
    }

    // ── 2. Pas de notification perdue ni dupliquee sur un second appel ─────

    public function test_un_second_appel_de_cloture_ne_met_en_file_aucun_nouveau_job(): void
    {
        Queue::fake();

        $entraide = $this->entraideAcceptee();

        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));
        // Deja `completed` : le controleur ressort sur `deja_traite` AVANT
        // d'atteindre les dispatches — aucun second job ne doit apparaitre.
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        Queue::assertPushed(SendTransactionStatusChangedEmail::class, 2);
        Queue::assertPushed(SendEvaluationInviteEmail::class, 1);
    }

    // ── 3. Statut correct meme si la mise en file echoue ───────────────────

    public function test_le_statut_de_cloture_reste_correct_quand_la_mise_en_file_echoue(): void
    {
        $queueMock = Mockery::mock(QueueFactory::class);
        $queueMock->shouldReceive('connection')->andThrow(new \RuntimeException('Simulated queue connection failure'));
        $this->app->instance('queue', $queueMock);
        $this->app->instance(QueueFactory::class, $queueMock);

        $entraide = $this->entraideAcceptee();

        $this->actingAs($this->aide)
            ->patch(route('transactions.complete', $entraide))
            ->assertRedirect(route('messages.show', $entraide));

        $this->assertSame('completed', $entraide->fresh()->status);
    }

    // ── 4. Statut correct meme si la livraison SMTP echoue ─────────────────

    /**
     * `TransactionStatusChanged` n'a pas de garde `try/catch` propre (a la
     * difference d'`EvaluationInviteMailer`) : un transport qui leve
     * propage jusqu'au `Dispatcher` de notification. En file `sync` (comme
     * en test), ceci remonte au `rescue()` du controleur, qui l'absorbe —
     * exactement le comportement verifie ici, sans toucher a l'invitation
     * (mockee independamment, canal distinct).
     */
    public function test_le_statut_de_cloture_reste_correct_quand_la_livraison_smtp_echoue(): void
    {
        $dispatcher = Mockery::mock(NotificationDispatcher::class);
        $dispatcher->shouldReceive('send')->andThrow(new \RuntimeException('Simulated SMTP failure'));
        $this->app->instance(NotificationDispatcher::class, $dispatcher);

        $entraide = $this->entraideAcceptee();

        $this->actingAs($this->aide)
            ->patch(route('transactions.complete', $entraide))
            ->assertRedirect(route('messages.show', $entraide));

        $this->assertSame('completed', $entraide->fresh()->status);
    }

    // ── 5. Idempotence de evaluation_invited_at, meme a deux jobs planifies ─

    /**
     * Simule le cas signale par le mandat : deux `SendEvaluationInviteEmail`
     * planifies AVANT la premiere livraison (ex. deux dispatches successifs
     * depuis deux requetes concurrentes). La sequence check/envoi/marquage,
     * PRESERVEE a l'identique dans le job (voir son docblock), ne doit
     * produire qu'UNE seule invitation.
     */
    public function test_deux_jobs_d_invitation_planifies_avant_toute_livraison_n_envoient_qu_une_seule_invitation(): void
    {
        $entraide = $this->entraideAcceptee();

        $job1 = new SendEvaluationInviteEmail((string) $entraide->id);
        $job2 = new SendEvaluationInviteEmail((string) $entraide->id);

        $job1->handle(app(EvaluationInviteMailer::class));
        $job2->handle(app(EvaluationInviteMailer::class));

        $this->assertSame(1, $this->invitesPour($this->aide->email)->count());
        $this->assertNotNull($entraide->fresh()->evaluation_invited_at);
    }

    // ── 6. Aucune invitation en double sur une soumission repetee a l'HTTP ──

    public function test_une_soumission_repetee_de_la_cloture_n_envoie_pas_une_seconde_invitation(): void
    {
        $entraide = $this->entraideAcceptee();

        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        $this->assertSame(1, $this->invitesPour($this->aide->email)->count());
    }

    // ── 7. Discussion directe : toujours exclue de l'invitation ────────────

    public function test_une_discussion_directe_ne_met_jamais_en_file_l_invitation(): void
    {
        Queue::fake();

        $discussion = $this->entraideAcceptee([
            'points_proposed' => 0,
            'points_agreed' => 0,
        ]);

        $this->actingAs($this->aide)->patch(route('transactions.complete', $discussion));

        Queue::assertNotPushed(SendEvaluationInviteEmail::class);
        Queue::assertPushed(SendTransactionStatusChangedEmail::class, 2);
    }

    // ── 8. Le job de statut relit et restaure le contexte tenant ───────────

    public function test_le_job_de_statut_envoie_sans_aucun_contexte_de_requete_prealable(): void
    {
        $entraide = $this->entraideAcceptee();

        app()->forgetInstance('current_organization');

        (new SendTransactionStatusChangedEmail((string) $entraide->id, (string) $this->aidant->id))->handle();

        $this->assertDatabaseHas('email_logs', [
            'to_email' => $this->aidant->email,
        ]);
        $this->assertFalse(app()->bound('current_organization'),
            'Le job ne doit laisser aucun tenant residuel quand il n\'y en avait aucun avant son execution.');
    }

    public function test_un_tenant_preexistant_est_restaure_a_l_identique_apres_le_job_de_statut(): void
    {
        $entraide = $this->entraideAcceptee();

        $autreOrganization = Organization::factory()->create();
        app()->instance('current_organization', $autreOrganization);

        (new SendTransactionStatusChangedEmail((string) $entraide->id, (string) $this->aide->id))->handle();

        $this->assertTrue(app()->bound('current_organization'));
        $this->assertSame($autreOrganization->id, app('current_organization')->id,
            'Le tenant precedent doit etre restaure EXACTEMENT, pas celui de la transaction traitee par le job.');
    }

    /**
     * Meme technique que TASK-1854 (`SendReviewReceivedEmail`) : la
     * restauration generique via `finally` est testee directement par
     * Reflection, avec une fermeture qui leve — independamment de la
     * source reelle de l'echec (SMTP, ou autre).
     */
    public function test_la_restauration_du_tenant_est_garantie_meme_si_le_job_de_statut_echoue(): void
    {
        $autreOrganization = Organization::factory()->create();
        app()->instance('current_organization', $autreOrganization);

        $job = new SendTransactionStatusChangedEmail((string) Str::uuid(), (string) Str::uuid());
        $methode = new \ReflectionMethod($job, 'withOrganizationContext');
        $methode->setAccessible(true);

        $cible = Organization::factory()->create();
        $leve = false;

        try {
            $methode->invoke($job, $cible->id, function () {
                throw new \RuntimeException('Simulated handler failure');
            });
        } catch (\RuntimeException) {
            $leve = true;
        }

        $this->assertTrue($leve, 'Precondition non prise : la fermeture doit reellement avoir leve.');
        $this->assertSame($autreOrganization->id, app('current_organization')->id);
    }

    public function test_le_job_de_statut_ignore_silencieusement_une_transaction_deja_supprimee(): void
    {
        $job = new SendTransactionStatusChangedEmail((string) Str::uuid(), (string) $this->aide->id);

        $job->handle();

        $this->assertDatabaseCount('email_logs', 0);
    }

    // ── 9. Routage Managed Queue — meme motif que les jobs existants ───────

    public function test_le_job_de_statut_bascule_sur_la_managed_queue_quand_le_drapeau_est_pose(): void
    {
        config([
            'queue.notifications_managed_queue_connection' => 'cloud',
            'queue.notifications_managed_queue_name' => 'notifications',
        ]);

        $job = new SendTransactionStatusChangedEmail((string) Str::uuid(), (string) Str::uuid());

        $this->assertSame('cloud', $job->connection);
        $this->assertSame('notifications', $job->queue);
    }

    public function test_le_job_d_invitation_bascule_sur_la_managed_queue_quand_le_drapeau_est_pose(): void
    {
        config([
            'queue.notifications_managed_queue_connection' => 'cloud',
            'queue.notifications_managed_queue_name' => 'notifications',
        ]);

        $job = new SendEvaluationInviteEmail((string) Str::uuid());

        $this->assertSame('cloud', $job->connection);
        $this->assertSame('notifications', $job->queue);
    }

    // ── 10. Garde anti double-appui du bouton de cloture ───────────────────

    public function test_le_bouton_de_cloture_porte_la_garde_anti_double_appui(): void
    {
        $entraide = $this->entraideAcceptee();

        $html = $this->actingAs($this->aide)
            ->get(route('messages.show', $entraide))
            ->assertOk()
            ->getContent();

        $position = strpos($html, 'data-entraide-cloturer');
        $this->assertNotFalse($position, 'Le marqueur data-entraide-cloturer est introuvable dans le rendu.');

        $zone = substr($html, max(0, $position - 600), 1000);
        $this->assertStringContainsString('envoiEnCours', $zone);
        $this->assertStringContainsString(':disabled="envoiEnCours"', $zone);
        $this->assertStringContainsString('@submit="envoiEnCours = true"', $zone);
    }

    // ── 11. Arbitrage MASTER (PR #862) — jamais inviter si un avis existe ──

    public function test_une_invitation_n_est_jamais_envoyee_si_un_avis_existe_deja(): void
    {
        $entraide = $this->entraideAcceptee();

        Review::factory()->forTransaction($entraide)->create([
            'reviewer_id' => $this->aide->id,
            'reviewed_id' => $this->aidant->id,
            'organization_id' => $this->organization->id,
        ]);

        (new SendEvaluationInviteEmail((string) $entraide->id))->handle(app(EvaluationInviteMailer::class));

        $this->assertSame(0, $this->invitesPour($this->aide->email)->count());
        $this->assertNull($entraide->fresh()->evaluation_invited_at,
            'Aucun envoi == aucun succes SMTP a marquer, meme regle que partout ailleurs dans ce job.');
    }

    /**
     * `Transaction::hasReviewFrom()` interroge `withTrashed()` — un avis
     * SUPPRIME par moderation occupe toujours son unique emplacement et ne
     * doit pas laisser croire que « personne n'a encore evalue ».
     */
    public function test_une_invitation_n_est_jamais_envoyee_si_l_avis_a_ete_supprime_par_moderation(): void
    {
        $entraide = $this->entraideAcceptee();

        $avis = Review::factory()->forTransaction($entraide)->create([
            'reviewer_id' => $this->aide->id,
            'reviewed_id' => $this->aidant->id,
            'organization_id' => $this->organization->id,
        ]);
        $avis->delete();

        (new SendEvaluationInviteEmail((string) $entraide->id))->handle(app(EvaluationInviteMailer::class));

        $this->assertSame(0, $this->invitesPour($this->aide->email)->count());
    }

    // ── 12. Arbitrage MASTER (PR #862) — isolation du destinataire ─────────

    public function test_le_job_de_statut_refuse_un_destinataire_etranger_a_l_echange(): void
    {
        $entraide = $this->entraideAcceptee();
        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);

        (new SendTransactionStatusChangedEmail((string) $entraide->id, (string) $etranger->id))->handle();

        $this->assertDatabaseMissing('email_logs', ['to_email' => $etranger->email]);
    }

    /**
     * `User::organization_id` est IMMUABLE par conception (TASK-1726) : un
     * compte ne peut jamais changer d'Organization. L'incoherence testee
     * ici vient donc de la TRANSACTION (ex. correction de donnees), pas
     * d'un compte reaffecte — mais la garde verifie les DEUX conditions
     * independamment, et doit refuser meme quand seule celle-ci casse.
     */
    public function test_le_job_de_statut_refuse_le_buyer_si_la_transaction_n_appartient_plus_a_son_organization(): void
    {
        $entraide = $this->entraideAcceptee();
        $autreOrganization = Organization::factory()->create();

        \Illuminate\Support\Facades\DB::table('transactions')
            ->where('id', $entraide->id)
            ->update(['organization_id' => $autreOrganization->id]);

        (new SendTransactionStatusChangedEmail((string) $entraide->id, (string) $this->aide->id))->handle();

        $this->assertDatabaseMissing('email_logs', ['to_email' => $this->aide->email]);
    }

    // ── 13. Arbitrage MASTER (PR #862) — clotures concurrentes ─────────────

    /**
     * Simulation deterministe de deux requetes `complete()` qui
     * franchiraient TOUTES LES DEUX `authorize()` avant que l'une ou
     * l'autre ne commette : chaque "requete" tient son PROPRE modele,
     * resolu pendant que le statut est encore `accepted` — exactement le
     * scenario decrit par MASTER (un seul processus de test, mais deux
     * instances Eloquent independantes, comme le seraient deux requetes
     * HTTP reelles dont le route-model-binding a eu lieu avant l'une et
     * l'autre des deux validations de policy).
     *
     * Avant ce correctif, la seconde "requete" relisait un statut GLOBAL
     * deja `completed` (ecrit par la premiere) et croyait, a tort, l'avoir
     * elle-meme cloture : elle ecrivait un second message systeme et
     * poussait un second lot de notifications/invitation.
     */
    public function test_une_cloture_concurrente_ne_duplique_aucun_effet_secondaire(): void
    {
        Queue::fake();

        $entraide = $this->entraideAcceptee();

        $transactionRequete1 = Transaction::find($entraide->id);
        $transactionRequete2 = Transaction::find($entraide->id);

        $this->actingAs($this->aide);
        $controller = app(TransactionController::class);

        $controller->complete(Request::create('/', 'PATCH'), $transactionRequete1);

        $this->assertSame('completed', $entraide->fresh()->status,
            'Precondition non prise : la premiere requete doit reellement avoir cloture.');

        $messagesAvant = Message::where('transaction_id', $entraide->id)->count();

        // Requete 2 : son modele est reste `accepted` EN MEMOIRE — `authorize()`
        // y passe donc, exactement comme dans une vraie course. Le verrou,
        // lui, voit la verite : `completed`.
        $controller->complete(Request::create('/', 'PATCH'), $transactionRequete2);

        $this->assertSame(
            $messagesAvant,
            Message::where('transaction_id', $entraide->id)->count(),
            'La seconde requete concurrente ne doit ecrire aucun second message systeme.'
        );

        Queue::assertPushed(SendTransactionStatusChangedEmail::class, 2);
        Queue::assertPushed(SendEvaluationInviteEmail::class, 1);
    }
}
