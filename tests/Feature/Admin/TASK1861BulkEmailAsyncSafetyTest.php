<?php

namespace Tests\Feature\Admin;

use App\Jobs\SendBulkEmailToRecipient;
use App\Models\BulkEmailSend;
use App\Models\BulkEmailSendRecipient;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\BulkEmailSendService;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1861 — audit prealable : `BulkEmailSendService::sendNextBatch()`
 * appelait `Mail::html()/raw()` EN LIGNE dans la requete HTTP, sans aucun
 * verrou — deux declenchements concurrents sur le meme lot pouvaient
 * envoyer DEUX FOIS au meme destinataire. `claimRecipient()` reprend
 * l'idiome de `NotificationEmailDeliverer::prendre()` (TASK-1377) : une
 * UNIQUE requete UPDATE conditionnee sur l'etat attendu reserve chaque
 * destinataire AVANT tout envoi, deporte vers `SendBulkEmailToRecipient`
 * (meme famille que TASK-1854/1855/1857/1858/1860).
 *
 * Perimetre : la reservation atomique elle-meme, le comportement sous deux
 * declenchements successifs sur le meme lot, un dispatch qui echoue, une
 * interruption qui laisse un destinataire `queued` sans reprise
 * automatique, un resultat SMTP ambigu, le comptage exact et la
 * journalisation sur un lot mixte, et la transition `completed` qui
 * attend desormais la fin du TRAVAIL, pas seulement la fin de la
 * reservation. La compatibilite avec les 15 tests TASK-1803 existants est
 * revalidee SANS modification (QUEUE_CONNECTION=sync en test : les jobs
 * s'executent en ligne, exactement comme avant), non dupliquee ici.
 *
 * Note methodologique : un seul processus de test ne peut pas faire
 * collision deux threads reels. La preuve d'atomicite porte directement
 * sur la clause SQL de `claimRecipient()` (deux tentatives sur la MEME
 * ligne, la seconde doit echouer) — c'est la ou vit la garantie, pas dans
 * une simulation de timing.
 */
class TASK1861BulkEmailAsyncSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Organization $organization;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
            'organization_id' => null,
        ]);
        $this->organization = Organization::factory()->create();
    }

    private function member(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ], $overrides));
    }

    private function send(array $overrides = []): BulkEmailSend
    {
        return BulkEmailSend::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'status' => BulkEmailSend::STATUS_SENDING,
            'content_format' => BulkEmailSend::CONTENT_FORMAT_PLAIN,
            'content_raw' => 'Un message de test.',
            'created_by' => $this->superAdmin->id,
        ], $overrides));
    }

    private function recipient(BulkEmailSend $send, ?User $user = null): BulkEmailSendRecipient
    {
        return BulkEmailSendRecipient::factory()->create([
            'bulk_email_send_id' => $send->id,
            'user_id' => ($user ?? $this->member())->id,
            'status' => BulkEmailSendRecipient::STATUS_PENDING,
        ]);
    }

    // ── 1. Reservation atomique ─────────────────────────────────────────────

    public function test_claim_recipient_est_atomique_sous_deux_tentatives_sur_la_meme_ligne(): void
    {
        $send = $this->send();
        $recipient = $this->recipient($send);
        $service = app(BulkEmailSendService::class);

        $premiere = $service->claimRecipient((string) $recipient->id);
        $seconde = $service->claimRecipient((string) $recipient->id);

        $this->assertTrue($premiere, 'La premiere tentative doit reussir.');
        $this->assertFalse($seconde, 'Un destinataire deja reserve ne doit jamais etre reserve une seconde fois.');
        $this->assertSame(BulkEmailSendRecipient::STATUS_QUEUED, $recipient->fresh()->status);
    }

    // ── 2. Deux declenchements successifs sur le meme lot ──────────────────

    public function test_sendNextBatch_appele_deux_fois_de_suite_ne_traite_jamais_deux_fois_le_meme_destinataire(): void
    {
        $send = $this->send();
        $this->recipient($send);
        $this->recipient($send);

        $service = app(BulkEmailSendService::class);

        $premier = $service->sendNextBatch($send, 50);
        $this->assertSame(2, $premier['queued']);
        $this->assertSame(0, $premier['remaining']);

        // Un second declenchement sur le MEME lot (double-clic, deux
        // onglets) ne doit plus rien trouver a reserver : les deux lignes
        // sont deja `sent` (file synchrone en test).
        $second = $service->sendNextBatch($send, 50);
        $this->assertSame(0, $second['queued'], 'Rien ne doit etre re-reserve au second declenchement.');

        $this->assertSame(2, EmailLog::count(), 'Aucun destinataire ne doit recevoir un second courriel.');
        $this->assertSame(2, BulkEmailSendRecipient::where('bulk_email_send_id', $send->id)->where('status', BulkEmailSendRecipient::STATUS_SENT)->count());
    }

    // ── 3. Un dispatch echoue ────────────────────────────────────────────────

    public function test_un_dispatch_echoue_remet_le_destinataire_en_attente_sans_perte(): void
    {
        $send = $this->send();
        $recipient = $this->recipient($send);

        $queueMock = Mockery::mock(QueueFactory::class);
        $queueMock->shouldReceive('connection')->andThrow(new RuntimeException('Simulated queue connection failure'));
        $this->app->instance('queue', $queueMock);
        $this->app->instance(QueueFactory::class, $queueMock);

        $tally = app(BulkEmailSendService::class)->sendNextBatch($send, 50);

        // On SAIT avec certitude que rien n'a ete tente au transport (le
        // dispatch a echoue avant tout envoi) : revenir a `pending` est sur,
        // contrairement a une panne APRES le transport.
        $this->assertSame(BulkEmailSendRecipient::STATUS_PENDING, $recipient->fresh()->status);
        $this->assertSame(0, EmailLog::count());
        $this->assertSame(0, $tally['queued']);
        $this->assertSame(1, $tally['remaining'], 'Le destinataire redevient disponible pour un prochain lot.');
    }

    // ── 4. Interruption : un destinataire `queued` n'est jamais repris automatiquement ──

    public function test_un_destinataire_reste_queued_apres_une_interruption_n_est_jamais_repris_automatiquement(): void
    {
        $send = $this->send();
        $recipient = $this->recipient($send);

        // Simule un worker qui a reserve la ligne puis est tombe AVANT tout
        // appel SMTP (crash, timeout) : la ligne reste `queued`, pas `pending`.
        app(BulkEmailSendService::class)->claimRecipient((string) $recipient->id);
        $this->assertSame(BulkEmailSendRecipient::STATUS_QUEUED, $recipient->fresh()->status);

        $tally = app(BulkEmailSendService::class)->sendNextBatch($send, 50);

        $this->assertSame(0, $tally['queued'], 'Un destinataire `queued` ne doit JAMAIS etre re-selectionne automatiquement.');
        $this->assertSame(BulkEmailSendRecipient::STATUS_QUEUED, $recipient->fresh()->status, 'Son statut reste observable, pas silencieusement perdu.');
        $this->assertSame(0, EmailLog::count());
    }

    public function test_le_job_ignore_un_destinataire_qui_n_est_pas_reserve(): void
    {
        $send = $this->send();
        $recipient = $this->recipient($send); // reste `pending`, jamais reserve

        app(BulkEmailSendService::class)->processRecipient((string) $recipient->id);

        $this->assertSame(BulkEmailSendRecipient::STATUS_PENDING, $recipient->fresh()->status);
        $this->assertSame(0, EmailLog::count());
    }

    public function test_le_job_ignore_un_destinataire_deja_traite_livraison_dupliquee_du_worker(): void
    {
        $send = $this->send();
        $recipient = $this->recipient($send);

        app(BulkEmailSendService::class)->claimRecipient((string) $recipient->id);
        app(BulkEmailSendService::class)->processRecipient((string) $recipient->id);
        $this->assertSame(BulkEmailSendRecipient::STATUS_SENT, $recipient->fresh()->status);
        $this->assertSame(1, EmailLog::count());

        // Une redelivraison du MEME job (semantique "at-least-once" d'une
        // Managed Queue) ne doit jamais renvoyer un second courriel.
        app(BulkEmailSendService::class)->processRecipient((string) $recipient->id);

        $this->assertSame(1, EmailLog::count(), 'Aucun second envoi sur une redelivraison du job.');
        $this->assertSame(1, $send->fresh()->sent_count, 'Le compteur ne doit pas etre incremente deux fois.');
    }

    // ── 5. Resultat SMTP ambigu apres tentative ─────────────────────────────

    public function test_un_resultat_smtp_ambigu_n_est_ni_envoye_ni_echoue(): void
    {
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('transport coupe'));

        $send = $this->send();
        $recipient = $this->recipient($send);

        app(BulkEmailSendService::class)->claimRecipient((string) $recipient->id);
        app(BulkEmailSendService::class)->processRecipient((string) $recipient->id);

        $recipient->refresh();
        $send->refresh();

        $this->assertSame(BulkEmailSendRecipient::STATUS_AMBIGUOUS, $recipient->status);
        $this->assertSame(EmailLog::STATUS_AMBIGUOUS, EmailLog::sole()->status);
        $this->assertSame(0, $send->sent_count, 'Ambigu ne doit jamais grossir le compteur envoye.');
        $this->assertSame(0, $send->failed_count, 'Ambigu ne doit jamais grossir le compteur echoue : on ignore, on n\'affirme pas.');
    }

    // ── 6. Comptage exact et journalisation sur un lot mixte ────────────────

    public function test_comptage_exact_des_statuts_sur_un_lot_mixte_envoye_echoue_ambigu(): void
    {
        Mail::shouldReceive('raw')->once()->andReturn(null); // le reussi
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('transport coupe')); // l'ambigu

        $send = $this->send();

        $okUser = $this->member();
        $okRecipient = $this->recipient($send, $okUser);

        // Simule un membre supprime pendant l'envoi : la ligne reste comme
        // trace, mais `user_id` ne pointe plus vers personne — mis a NULL
        // directement plutot que de dependre du comportement `nullOnDelete()`
        // de la FK (deterministe, independant du moteur de test).
        $deletedUserRecipient = $this->recipient($send);
        $deletedUserRecipient->update(['user_id' => null]);

        $ambiguousUser = $this->member();
        $ambiguousRecipient = $this->recipient($send, $ambiguousUser);

        $service = app(BulkEmailSendService::class);
        foreach ([$okRecipient, $deletedUserRecipient, $ambiguousRecipient] as $r) {
            $service->claimRecipient((string) $r->id);
            $service->processRecipient((string) $r->id);
        }

        $send->refresh();
        $this->assertSame(1, $send->sent_count);
        $this->assertSame(1, $send->failed_count);
        $this->assertSame(BulkEmailSendRecipient::STATUS_SENT, $okRecipient->fresh()->status);
        $this->assertSame(BulkEmailSendRecipient::STATUS_FAILED, $deletedUserRecipient->fresh()->status);
        $this->assertSame(BulkEmailSendRecipient::STATUS_AMBIGUOUS, $ambiguousRecipient->fresh()->status);

        // Le membre supprime n'a JAMAIS de journal (aucun envoi tente) ; les
        // deux autres si (succes ET tentative ambigue, meme discipline que
        // `NotificationEmailDeliverer`).
        $this->assertSame(2, EmailLog::count());
    }

    // ── 7. `completed` attend la fin du TRAVAIL, pas seulement la reservation ──

    public function test_l_envoi_ne_passe_termine_que_lorsque_plus_rien_n_est_pending_ni_queued(): void
    {
        $send = $this->send();
        $r1 = $this->recipient($send);
        $r2 = $this->recipient($send);

        $service = app(BulkEmailSendService::class);
        $service->claimRecipient((string) $r1->id);
        $service->processRecipient((string) $r1->id);

        // r2 reste `pending` : l'envoi ne doit PAS etre termine.
        $this->assertSame(BulkEmailSend::STATUS_SENDING, $send->fresh()->status);

        $service->claimRecipient((string) $r2->id);
        $service->processRecipient((string) $r2->id);

        $this->assertSame(BulkEmailSend::STATUS_COMPLETED, $send->fresh()->status);
    }

    // ── 8. Dispatch reel depuis le controleur (compatibilite HTTP) ─────────

    public function test_sendBatch_http_dispatche_un_job_par_destinataire_reserve(): void
    {
        Queue::fake();

        $send = $this->send();
        $r1 = $this->recipient($send);
        $r2 = $this->recipient($send);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.email-bulksending.batch', $send))
            ->assertRedirect();

        Queue::assertPushed(SendBulkEmailToRecipient::class, 2);
        Queue::assertPushed(fn (SendBulkEmailToRecipient $job): bool => in_array($job->recipientId, [(string) $r1->id, (string) $r2->id], true));

        $this->assertSame(BulkEmailSendRecipient::STATUS_QUEUED, $r1->fresh()->status);
        $this->assertSame(BulkEmailSendRecipient::STATUS_QUEUED, $r2->fresh()->status);
    }

    // ── 9. Correctif MASTER avant fusion — prise de travail ATOMIQUE du job ──

    /**
     * Deux workers (ou deux redelivraisons "at-least-once" d'une Managed
     * Queue) qui ramassent le MEME destinataire `queued` : une simple
     * lecture de `status` ne suffit pas, seule la transition `queued ->
     * processing` conditionnee sur l'etat attendu tranche qui envoie.
     */
    public function test_deux_workers_concurrents_sur_le_meme_destinataire_un_seul_obtient_le_droit_d_envoyer(): void
    {
        $send = $this->send();
        $recipient = $this->recipient($send);
        app(BulkEmailSendService::class)->claimRecipient((string) $recipient->id);

        $service = app(BulkEmailSendService::class);
        $premier = $service->claimForProcessing((string) $recipient->id);
        $second = $service->claimForProcessing((string) $recipient->id);

        $this->assertTrue($premier, 'Le premier worker doit obtenir le droit de traiter.');
        $this->assertFalse($second, 'Un second worker ne doit JAMAIS obtenir le meme droit sur la MEME ligne.');
        $this->assertSame(BulkEmailSendRecipient::STATUS_PROCESSING, $recipient->fresh()->status);
    }

    /**
     * Une redelivraison du job PENDANT qu'un traitement est deja en cours
     * (ligne `processing`, pas encore resolue) ne doit jamais declencher un
     * second envoi — distinct du cas "deja `sent`" deja couvert plus haut.
     */
    public function test_une_redelivrance_pendant_un_traitement_en_cours_n_envoie_jamais_un_second_courriel(): void
    {
        Mail::shouldReceive('raw')->once()->andReturn(null);

        $send = $this->send();
        $recipient = $this->recipient($send);
        app(BulkEmailSendService::class)->claimRecipient((string) $recipient->id);

        // Simule un traitement DEJA en cours (worker lent, pas encore conclu).
        $recipient->update(['status' => BulkEmailSendRecipient::STATUS_PROCESSING]);

        // La redelivraison du MEME job arrive PENDANT ce traitement : elle
        // ne doit rien pouvoir reserver, et donc n'appeler Mail:: a aucun
        // moment (l'attente `->once()` ci-dessus porte sur l'appel qui
        // suivra, pas sur celui-ci).
        app(BulkEmailSendService::class)->processRecipient((string) $recipient->id);
        $this->assertSame(BulkEmailSendRecipient::STATUS_PROCESSING, $recipient->fresh()->status, 'La redelivraison ne doit pas toucher une ligne deja en cours.');
        $this->assertSame(0, EmailLog::count());

        // Le worker "legitime" conclut ensuite normalement : UN SEUL appel
        // Mail:: au total sur ce test, prouve par le `->once()` ci-dessus.
        $recipient->update(['status' => BulkEmailSendRecipient::STATUS_QUEUED]);
        app(BulkEmailSendService::class)->processRecipient((string) $recipient->id);
        $this->assertSame(BulkEmailSendRecipient::STATUS_SENT, $recipient->fresh()->status);
        $this->assertSame(1, EmailLog::count());
    }

    // ── 10. Frontiere SMTP / journalisation ─────────────────────────────────

    /**
     * `EmailLog::create()` echoue APRES un transport REUSSI : jamais
     * `failed`, jamais silencieusement perdu. La table est masquee le temps
     * de l'appel — sabotage de la PERSISTANCE, pas du code de production,
     * meme trick que `TASK1377NotificationEmailAsyncTest`.
     */
    public function test_un_echec_de_journalisation_apres_un_transport_reussi_laisse_une_trace_exploitable(): void
    {
        Mail::shouldReceive('raw')->once()->andReturn(null);

        $send = $this->send();
        $recipient = $this->recipient($send);
        app(BulkEmailSendService::class)->claimRecipient((string) $recipient->id);

        Schema::rename('email_logs', 'email_logs_masquee_par_le_test');

        try {
            app(BulkEmailSendService::class)->processRecipient((string) $recipient->id);
        } finally {
            Schema::rename('email_logs_masquee_par_le_test', 'email_logs');
        }

        $this->assertSame(BulkEmailSendRecipient::STATUS_AMBIGUOUS, $recipient->fresh()->status, 'Jamais failed apres un transport reussi, meme si la preuve n a pas pu s ecrire.');
        $this->assertNotSame(BulkEmailSendRecipient::STATUS_FAILED, $recipient->fresh()->status);
        $this->assertNull($recipient->fresh()->email_log_id, 'Aucun journal n a pu etre cree : rien a referencer.');

        // Aucune preuve n'a pu s'ecrire — l'incident etait dans CETTE
        // ecriture — et c'est attendu : on ne fabrique pas une preuve a
        // partir d'un sabotage de la preuve elle-meme. La table est
        // restauree, la requete porte donc bien sur l'etat reel.
        $this->assertSame(0, EmailLog::query()->count());

        // Ambigu ne grossit AUCUN des deux compteurs : on ignore, on
        // n'affirme pas.
        $send->refresh();
        $this->assertSame(0, $send->sent_count);
        $this->assertSame(0, $send->failed_count);
    }

    // ── 11. Isolation multi-Organizations ────────────────────────────────────

    /**
     * Une campagne `organization_id = null` (« toutes les Organizations »)
     * traite des destinataires de plusieurs Organizations DIFFERENTES : le
     * contexte tenant doit rester COHERENT avec chaque destinataire pendant
     * son propre traitement, et le tenant ambiant anterieur au dispatch
     * doit etre restaure a l'identique ensuite — sans fuite d'un
     * destinataire vers l'autre.
     */
    public function test_une_campagne_multi_organizations_isole_le_tenant_par_destinataire_et_le_restaure(): void
    {
        Mail::shouldReceive('raw')->twice()->andReturn(null);

        $autreOrganization = Organization::factory()->create();
        $autreMembre = User::factory()->create([
            'organization_id' => $autreOrganization->id,
            'email_verified_at' => now(),
        ]);

        // `organization_id = null` : cible TOUTES les Organizations.
        $send = $this->send(['organization_id' => null]);
        $r1 = $this->recipient($send); // membre de $this->organization
        $r2 = $this->recipient($send, $autreMembre); // membre d'une AUTRE Organization

        // Un tenant ambiant PREALABLE, etranger aux deux Organizations
        // ci-dessus — simule un worker de Managed Queue qui aurait traite
        // un AUTRE job juste avant, et dont le contexte doit etre restaure
        // a l'identique, jamais laisse en place pour celui-ci.
        $tenantPrealable = Organization::factory()->create();
        app()->instance('current_organization', $tenantPrealable);

        $service = app(BulkEmailSendService::class);
        $service->claimRecipient((string) $r1->id);
        $service->processRecipient((string) $r1->id);

        $service->claimRecipient((string) $r2->id);
        $service->processRecipient((string) $r2->id);

        $this->assertSame(BulkEmailSendRecipient::STATUS_SENT, $r1->fresh()->status);
        $this->assertSame(BulkEmailSendRecipient::STATUS_SENT, $r2->fresh()->status);
        $this->assertSame(2, EmailLog::count());

        // Le tenant PREALABLE est restaure a l'identique apres CHAQUE
        // traitement — ni celui de $r1, ni celui de $r2, ne doit avoir
        // fui en dehors de son propre `processRecipient()`.
        $this->assertTrue(app()->bound('current_organization'));
        $this->assertSame($tenantPrealable->id, app('current_organization')->id,
            'Le tenant ambiant anterieur doit etre restaure EXACTEMENT, jamais celui d un des destinataires traites.');
    }

    // ── 12. Statuts et compteurs apres interruption ─────────────────────────

    /**
     * Un destinataire bloque en `processing` (worker tombe APRES la prise
     * de travail mais AVANT tout envoi) ne doit ni etre repris
     * automatiquement, ni faire passer l'envoi `completed`, ni grossir un
     * compteur quelconque — il reste observable, point.
     */
    public function test_statuts_et_compteurs_restent_coherents_apres_une_interruption_en_cours_de_traitement(): void
    {
        $send = $this->send();
        $bloque = $this->recipient($send);
        $autre = $this->recipient($send);

        $service = app(BulkEmailSendService::class);
        $service->claimRecipient((string) $bloque->id);
        $service->claimForProcessing((string) $bloque->id); // worker tombe ICI, avant tout envoi SMTP

        // L'AUTRE destinataire est traite normalement, en entier.
        Mail::shouldReceive('raw')->once()->andReturn(null);
        $service->claimRecipient((string) $autre->id);
        $service->processRecipient((string) $autre->id);

        $send->refresh();
        $this->assertSame(BulkEmailSendRecipient::STATUS_PROCESSING, $bloque->fresh()->status, 'Reste observable, jamais silencieusement perdu.');
        $this->assertSame(1, $send->sent_count);
        $this->assertSame(0, $send->failed_count);
        $this->assertSame(BulkEmailSend::STATUS_SENDING, $send->status, 'Ne doit JAMAIS passer `completed` tant qu une ligne reste `processing`.');

        // Un nouveau lot ne doit RIEN re-selectionner pour la ligne
        // bloquee : seul `pending` est jamais re-choisi.
        $tally = $service->sendNextBatch($send, 50);
        $this->assertSame(0, $tally['queued']);
        $this->assertSame(BulkEmailSendRecipient::STATUS_PROCESSING, $bloque->fresh()->status);
    }
}
