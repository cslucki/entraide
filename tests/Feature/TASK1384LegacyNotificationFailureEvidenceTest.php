<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\TransactionStatusChanged;
use App\Support\Ops\NotificationCockpitDiagnostics;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * TASK-1384 — un email historique qui echoue laisse desormais une preuve.
 *
 * ## Le constat qui a ouvert cette tranche
 *
 * Mesure du 2026-09-03, sur une vraie `Notification` Laravel avec un transport
 * sabote : `NotificationFailed` est emis UNE fois, `NotificationSent` ZERO fois,
 * et **aucune ligne n'etait ecrite**. L'echec disparaissait — ni dans
 * `email_logs`, ni dans l'historique detaille, ni dans le cockpit.
 *
 * T1383 avait rendu visibles les envois historiques REUSSIS. Leurs echecs, eux,
 * n'existaient nulle part.
 *
 * ## Ce qui garantit « jamais sent ET failed »
 *
 * Ce n'est pas la vigilance de l'ecouteur, c'est la structure de
 * `NotificationSender::sendToNotifiable()` : le `catch` dispatche
 * `NotificationFailed` puis **releve l'exception**, donc la ligne qui
 * dispatcherait `NotificationSent` n'est jamais atteinte. Les deux evenements
 * sont mutuellement exclusifs par construction du framework.
 *
 * On le MESURE quand meme : une garantie qu'on n'observe pas est une garantie
 * qu'on suppose.
 */
class TASK1384LegacyNotificationFailureEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        // Aucune livraison du pipeline ne doit partir d'ici. La fausse file le
        // prouve plutot que de l'esperer.
        Queue::fake();

        $this->org = Organization::factory()->create();
    }

    private function transaction(): Transaction
    {
        return Transaction::factory()->create([
            'organization_id' => $this->org->id,
            'buyer_id' => User::factory()->create(['organization_id' => $this->org->id])->id,
            'seller_id' => User::factory()->create(['organization_id' => $this->org->id])->id,
        ]);
    }

    /**
     * Sabote le transport avec un message qui CONTIENT un hote et un
     * identifiant.
     *
     * Ce n'est pas de la couleur locale : c'est exactement la forme d'un vrai
     * message de `TransportException` — « Connection could not be established
     * with host ... ». Si l'ecouteur persistait le message brut, ce test le
     * verrait.
     */
    private function saboterLeTransport(): void
    {
        $mailer = Mockery::mock(MailerContract::class);
        $mailer->shouldReceive('send')->andThrow(new TransportException(
            'Connection could not be established with host "smtp.interne.example:587": '
            .'authentication failed for user operateur@example.test'
        ));

        $usine = Mockery::mock(MailFactory::class);
        $usine->shouldReceive('mailer')->andReturn($mailer);

        $this->app->instance('mail.manager', $usine);
    }

    // =====================================================================
    // Le fait
    // =====================================================================

    /**
     * Un echec historique laisse une ligne `failed`.
     */
    public function test_a_legacy_failure_is_recorded(): void
    {
        $transaction = $this->transaction();
        $destinataire = $transaction->buyer;
        $this->saboterLeTransport();

        try {
            $destinataire->notify(new TransactionStatusChanged($transaction));
        } catch (TransportException) {
            // L'exception continue de remonter : ce n'est pas le sujet ici, et
            // l'etouffer changerait le comportement des appelants.
        }

        $ligne = EmailLog::query()->latest('id')->firstOrFail();

        $this->assertSame(EmailLog::STATUS_FAILED, $ligne->status);
        $this->assertSame($destinataire->id, $ligne->user_id);
        $this->assertSame($this->org->id, $ligne->organization_id);
        $this->assertSame($destinataire->email, $ligne->to_email);
    }

    /**
     * Le succes historique continue de s'enregistrer comme avant.
     *
     * Sans ce contre-exemple, l'ecouteur d'echec pourrait avoir casse celui de
     * succes sans que rien ne le dise.
     */
    public function test_a_legacy_success_still_records_sent(): void
    {
        $transaction = $this->transaction();

        $transaction->buyer->notify(new TransactionStatusChanged($transaction));

        $ligne = EmailLog::query()->latest('id')->firstOrFail();

        $this->assertSame(EmailLog::STATUS_SENT, $ligne->status);
        $this->assertNull($ligne->error_message);
    }

    /**
     * Jamais `sent` ET `failed` pour le meme envoi.
     */
    public function test_a_single_send_never_produces_both_statuses(): void
    {
        $transaction = $this->transaction();
        $this->saboterLeTransport();

        try {
            $transaction->buyer->notify(new TransactionStatusChanged($transaction));
        } catch (TransportException) {
        }

        $this->assertSame(1, EmailLog::count(), 'Un envoi, une ligne.');
        $this->assertSame(0, EmailLog::where('status', EmailLog::STATUS_SENT)->count());
        $this->assertSame(1, EmailLog::where('status', EmailLog::STATUS_FAILED)->count());
    }

    /**
     * L'echec vient de la CONSTRUCTION du message, pas du transport.
     *
     * C'est le cas que la revue adverse a fait apparaitre, et il etait
     * BLOQUANT : `sujet()` rendait `null` quand `toMail()` relevait, or
     * `email_logs.subject` est declaree sans `nullable()`. L'insertion violait
     * donc la contrainte NOT NULL, l'echec etait avale par le `catch`, et la
     * tranche produisait exactement ce qu'elle existe pour supprimer : RIEN.
     *
     * Et ce n'est pas theorique : gabarit casse, cle de traduction absente,
     * relation supprimee — toute la famille de pannes ou le sujet manque est
     * precisement celle dont on veut une preuve. Aucun des autres tests ne
     * l'atteignait : ils sabotent tous le TRANSPORT.
     */
    public function test_a_failure_coming_from_the_message_build_is_still_recorded(): void
    {
        $destinataire = User::factory()->create([
            'organization_id' => $this->org->id,
            'preferred_locale' => 'fr',
        ]);

        // Le gabarit fait prendre a `toMail()` la branche qui construit un lien
        // par `route()` — sinon le repli n'appelle aucune route et ne leve pas.
        SystemEmailTemplate::create([
            'organization_id' => $this->org->id,
            'locale' => 'fr',
            'slug' => 'transaction_status_changed',
            'name' => 'T1384',
            'subject' => 'Sujet {{ status_label }}',
            'content_html' => '<p>{{ url }}</p>',
            'variables' => ['status_label', 'url'],
            'enabled' => true,
        ]);

        // Transaction NON ENREGISTREE : `route('messages.show', $tx)` ne peut
        // pas construire d'URL sans identifiant, et leve.
        $notification = new TransactionStatusChanged(new Transaction);

        event(new NotificationFailed(
            $destinataire,
            $notification,
            'mail',
            ['exception' => new TransportException('peu importe')],
        ));

        $ligne = EmailLog::query()->latest('id')->first();

        $this->assertNotNull($ligne, 'La preuve doit exister MEME sans sujet.');
        $this->assertSame(EmailLog::STATUS_FAILED, $ligne->status);
        $this->assertSame('[subject-unavailable]', $ligne->subject);
    }

    // =====================================================================
    // Ce qui ne doit PAS fuir
    // =====================================================================

    /**
     * LE test de securite de cette tranche.
     *
     * Un message de `TransportException` porte l'hote SMTP et, souvent,
     * l'identifiant utilise. Les persister dans `email_logs` les exposerait dans
     * l'historique detaille de l'administration — exactement le defaut corrige
     * en T1376, ou un bloc publiait l'hote SMTP de production.
     *
     * `error_message` porte donc un CODE STABLE, pas le message brut.
     */
    public function test_the_recorded_error_leaks_neither_host_nor_credential(): void
    {
        $transaction = $this->transaction();
        $this->saboterLeTransport();

        try {
            $transaction->buyer->notify(new TransactionStatusChanged($transaction));
        } catch (TransportException) {
        }

        $ligne = EmailLog::query()->latest('id')->firstOrFail();
        $tout = $ligne->error_message.' '.json_encode($ligne->data);

        $this->assertStringNotContainsString('smtp.interne.example', $tout);
        $this->assertStringNotContainsString('587', $tout);
        $this->assertStringNotContainsString('operateur@example.test', $tout);
        $this->assertStringNotContainsString('authentication failed', $tout);

        // Et il reste DIAGNOSTIQUE : la classe de l'exception suffit a savoir
        // de quoi il s'agit, sans rien exposer.
        $this->assertSame('TransportException', $ligne->error_message);
    }

    // =====================================================================
    // Les frontieres
    // =====================================================================

    /**
     * L'ecouteur d'echec applique les MEMES trois filtres que celui de succes.
     *
     * Ils ecrivent dans la meme table : s'ils divergeaient, l'un tracerait des
     * envois que l'autre ignore, et le total cesserait d'avoir un sens.
     *
     * Ici : un canal qui n'est pas `mail`.
     */
    public function test_a_non_mail_channel_failure_writes_nothing(): void
    {
        $transaction = $this->transaction();

        event(new NotificationFailed(
            $transaction->buyer,
            new TransactionStatusChanged($transaction),
            'database',
            ['exception' => new TransportException('peu importe')],
        ));

        $this->assertSame(0, EmailLog::count());
    }

    /**
     * Et une notification qui n'appartient pas a l'application non plus.
     *
     * La classe est NOMMEE, et il a fallu une mesure pour comprendre pourquoi
     * c'est indispensable. La premiere version utilisait une classe ANONYME :
     * le test passait, mais le sabotage — retirer le filtre de namespace — ne
     * le faisait pas rougir.
     *
     * Raison : un nom de classe anonyme PHP contient un OCTET NUL
     * (`Notification@anonymous\0/chemin.php:12$0`). L'ecriture echouait donc
     * pour cette raison-la, et mon `catch (Throwable)` l'avalait en silence. Le
     * test constatait « aucune ligne » sans jamais avoir mesure le filtre.
     */
    public function test_a_foreign_notification_failure_writes_nothing(): void
    {
        $transaction = $this->transaction();

        event(new NotificationFailed(
            $transaction->buyer,
            new TASK1384ForeignNotification,
            'mail',
            ['exception' => new TransportException('peu importe')],
        ));

        $this->assertSame(0, EmailLog::count());
    }

    // =====================================================================
    // Rien d'autre n'est touche
    // =====================================================================

    /**
     * Le pipeline Notifications n'est ni sollicite ni pollue.
     *
     * Aucun job, sur aucune file — donc rien sur `notifications-email`, et rien
     * sur `default` et ses 201 jobs historiques.
     */
    public function test_nothing_is_queued_and_no_pipeline_row_is_created(): void
    {
        $transaction = $this->transaction();
        $this->saboterLeTransport();

        try {
            $transaction->buyer->notify(new TransactionStatusChanged($transaction));
        } catch (TransportException) {
        }

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('member_notifications', 0);
        $this->assertDatabaseCount('member_notification_deliveries', 0);
    }

    /**
     * Le cockpit garde sa distinction : la ligne d'echec est HORS pipeline.
     *
     * Elle ne porte pas de `notification_id` — et on n'en invente pas. Elle doit
     * donc grossir le compte hors pipeline, jamais celui du pipeline.
     */
    public function test_the_cockpit_keeps_the_pipeline_distinction(): void
    {
        $transaction = $this->transaction();
        $this->saboterLeTransport();

        try {
            $transaction->buyer->notify(new TransactionStatusChanged($transaction));
        } catch (TransportException) {
        }

        $ligne = EmailLog::query()->latest('id')->firstOrFail();
        $this->assertNull($ligne->notification_id);

        $preuves = app(NotificationCockpitDiagnostics::class)->overview()['preuves'];

        $this->assertSame(0, $preuves['total'], 'Le pipeline ne compte pas cet echec.');
        $this->assertSame(1, $preuves['hors_pipeline'], 'Le hors-pipeline le compte.');
    }
}

/**
 * Une notification NOMMEE, hors de `App\Notifications\`.
 *
 * Elle existe parce qu'une classe anonyme ne pouvait pas jouer ce role : son
 * nom porte un octet nul, ce qui fait echouer l'ecriture pour une raison sans
 * rapport avec le filtre qu'on veut mesurer.
 */
class TASK1384ForeignNotification extends Notification
{
    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Etrangere');
    }
}
