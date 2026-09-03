<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationEmail;
use App\Models\EmailLog;
use App\Models\Loop;
use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopInvitationService;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationDeliveryPlanner;
use App\Support\Notifications\NotificationDeliveryStatus;
use App\Support\Notifications\NotificationEmailDeliverer;
use App\Support\Notifications\NotificationPreferenceResolver;
use App\Support\Notifications\NotificationTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

/**
 * TASK-1377 — la livraison EMAIL asynchrone.
 *
 * ## Ce que cette tranche livre, et ce qu'elle N'ACTIVE PAS
 *
 * P5 livre la mecanique : table d'etat, prise de travail atomique, worker,
 * preuve dans `email_logs`. Elle n'active EMAIL sur AUCUNE cle — c'est P6. Les
 * deux premiers tests fixent cette frontiere pour qu'elle ne bouge pas par
 * accident.
 *
 * ## Pourquoi la preference est substituee dans les tests d'envoi
 *
 * Le catalogue n'autorise EMAIL nulle part, donc `allows()` rend `false` et le
 * chemin d'envoi serait INATTEIGNABLE. Un test qui ne peut pas atteindre son
 * sujet ne mesure rien. On substitue donc le resolveur de preferences — un
 * collaborateur injecte, pas une garde contournee — et la frontiere du catalogue
 * est mesuree separement, la ou elle vit.
 */
class TASK1377NotificationEmailAsyncTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $expediteur;

    private User $membre;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['name' => 'T1377 Organization A', 'is_active' => true]);
        $this->orgB = Organization::factory()->create(['name' => 'T1377 Organization B', 'is_active' => true]);

        $this->expediteur = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->membre = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'preferred_locale' => 'fr',
        ]);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->orgA->id,
            'created_by' => $this->expediteur->id,
            'status' => 'active',
        ]);
    }

    // =====================================================================
    // 1. La frontiere P5 / P6
    // =====================================================================

    /**
     * **Le catalogue n'autorise EMAIL sur aucune cle — c'est le contrat de P5.**
     *
     * Fil-piege deliberé. Le jour ou quelqu'un ajoutera `email` a
     * `loop.invitation`, ce test rougira et rappellera que l'activation
     * appartient a P6, avec ses propres gardes et son propre contenu.
     */
    public function test_the_catalogue_activates_email_on_nothing(): void
    {
        $this->assertTrue(
            NotificationCatalogue::allowsChannel(NotificationCatalogue::LOOP_INVITATION, NotificationCatalogue::CHANNEL_IN_APP),
            'in_app reste obligatoire sur l\'invitation.'
        );

        foreach (NotificationCatalogue::keys() as $cle) {
            $this->assertFalse(
                NotificationCatalogue::allowsChannel($cle, NotificationCatalogue::CHANNEL_EMAIL),
                "[{$cle}] ne doit pas activer EMAIL en P5 — cela appartient a P6."
            );
        }
    }

    /** Une invitation reelle ne planifie donc AUCUNE livraison email. */
    public function test_a_real_invitation_plans_no_email_delivery(): void
    {
        Queue::fake();

        app(LoopInvitationService::class)->invite($this->loop, $this->expediteur, $this->membre->email);

        $this->assertSame(1, MemberNotification::query()->count(), 'La notification in_app est bien emise.');
        $this->assertSame(0, MemberNotificationDelivery::query()->count(), 'Aucune livraison email en P5.');

        Queue::assertNotPushed(SendNotificationEmail::class);
    }

    // =====================================================================
    // 2. Seule la CREATION planifie
    // =====================================================================

    /**
     * **Un rejeu ne planifie rien de neuf, et n'envoie donc pas un second email.**
     *
     * C'est la propriete que `wasRecentlyCreated` ne saurait pas garantir : la
     * garantie est une garantie de CHEMIN — le planificateur n'est appele que
     * dans la branche de creation de l'emetteur.
     */
    public function test_a_replay_plans_nothing_new(): void
    {
        Queue::fake();

        $service = app(LoopInvitationService::class);

        $service->invite($this->loop, $this->expediteur, $this->membre->email);
        $service->invite($this->loop, $this->expediteur, $this->membre->email);

        $this->assertSame(1, MemberNotification::query()->count(), 'Le rejeu ne cree pas de seconde notification.');
        $this->assertSame(0, MemberNotificationDelivery::query()->count());
    }

    /**
     * Et la contrainte tranche meme si le planificateur est rappele directement.
     *
     * L'unicite ne repose pas sur la discipline de l'appelant : elle vit dans
     * `UNIQUE(notification_id, channel)`.
     */
    public function test_the_unique_constraint_prevents_a_second_delivery(): void
    {
        Queue::fake();

        $notification = $this->notificationAvecInvitation();

        // Un planificateur qui livre EMAIL, pour atteindre la logique de rejeu.
        // Sans cela le filtre du catalogue s'arreterait AVANT, et ce test
        // passerait sans jamais exercer son sujet — verifie par sabotage.
        $planificateur = new class extends NotificationDeliveryPlanner
        {
            protected function canauxALivrer(MemberNotification $notification): array
            {
                return [NotificationCatalogue::CHANNEL_EMAIL];
            }
        };

        $planificateur->plan($notification);
        $planificateur->plan($notification);

        $this->assertSame(1, MemberNotificationDelivery::query()->count(), 'La CONTRAINTE tranche le rejeu.');

        // Et surtout : le rejeu ne remet AUCUN job en file. Remettre un job ici
        // reintroduirait exactement le double envoi que la contrainte empeche.
        Queue::assertPushed(SendNotificationEmail::class, 1);
    }

    // =====================================================================
    // 3. Le job
    // =====================================================================

    /** La charge utile est un identifiant, la file est dediee, aucune reprise. */
    public function test_the_job_carries_only_an_identifier(): void
    {
        $job = new SendNotificationEmail('11111111-2222-3333-4444-555555555555');

        $this->assertSame('11111111-2222-3333-4444-555555555555', $job->notificationId);
        $this->assertSame(1, $job->tries, 'Aucune reprise automatique en V1-A.');
        $this->assertTrue($job->afterCommit, 'La connexion de queue a after_commit=false : le drapeau du job est ce qui compte.');
        $this->assertSame('notifications-email', $job->queue);
        $this->assertNotSame('default', $job->queue, 'La file default porte un historique a ne pas consommer.');

        // Aucun modele serialise : la charge utile ne doit contenir que la chaine.
        $proprietes = get_object_vars($job);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $proprietes['notificationId']);
        foreach ($proprietes as $valeur) {
            $this->assertNotInstanceOf(MemberNotification::class, $valeur);
            $this->assertNotInstanceOf(User::class, $valeur);
        }
    }

    // =====================================================================
    // 4. La prise de travail
    // =====================================================================

    /**
     * **Deux passages ne produisent qu'UN envoi.**
     *
     * La prise `pending -> sending` se fait en une seule requete, avec l'etat
     * attendu dans le `WHERE`. Le second passage trouve un etat terminal et
     * s'arrete.
     */
    public function test_two_runs_send_exactly_one_email(): void
    {
        $this->transportMesurable();
        $this->autoriserEmail();
        $this->traductionsPresentes();

        $livraison = $this->livraisonEmail();
        $deliverer = app(NotificationEmailDeliverer::class);

        $deliverer->deliver((string) $livraison->notification_id);
        $deliverer->deliver((string) $livraison->notification_id);

        $this->assertCount(1, $this->messagesEnvoyes(), 'Un seul message a ete remis au transport.');
        $this->assertSame(1, EmailLog::query()->whereNotNull('notification_id')->count());
        $this->assertSame(NotificationDeliveryStatus::SENT, $livraison->fresh()->status);
        $this->assertSame(1, $livraison->fresh()->attempts, 'La seconde passe ne prend pas le travail.');
    }

    /** Une livraison deja prise par un autre worker n'est pas reprise. */
    public function test_a_delivery_already_claimed_is_left_alone(): void
    {
        $this->transportMesurable();
        $this->autoriserEmail();
        $this->traductionsPresentes();

        $livraison = $this->livraisonEmail();

        // Un autre worker l'a prise il y a un instant.
        DB::table('member_notification_deliveries')
            ->where('id', $livraison->id)
            ->update(['status' => NotificationDeliveryStatus::SENDING, 'claimed_at' => now(), 'attempts' => 1]);

        app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);

        $this->assertSame([], $this->messagesEnvoyes(), 'Aucun message ne doit atteindre le transport.');
        $this->assertSame(NotificationDeliveryStatus::SENDING, $livraison->fresh()->status);
        $this->assertSame(1, $livraison->fresh()->attempts, 'Le compteur de prises n\'a pas bouge.');
    }

    // =====================================================================
    // 5. Les transitions sont appliquees par le MODELE
    // =====================================================================

    /** `sent` est irreversible. */
    public function test_sent_is_irreversible(): void
    {
        $livraison = $this->livraisonEmail();
        $livraison->status = NotificationDeliveryStatus::SENDING;
        $livraison->save();
        $livraison->status = NotificationDeliveryStatus::SENT;
        $livraison->save();

        foreach ([NotificationDeliveryStatus::PENDING, NotificationDeliveryStatus::SENDING, NotificationDeliveryStatus::FAILED] as $vers) {
            $rejoue = $livraison->fresh();
            $rejoue->status = $vers;

            $this->refusDeMutation(fn () => $rejoue->save(), $vers);
        }
    }

    /** L'identite d'une livraison ne change jamais. */
    public function test_the_delivery_identity_is_frozen(): void
    {
        // Une notification REELLEMENT differente. `notificationAvecInvitation()`
        // est idempotent : reinviter la meme personne rend la MEME notification,
        // et la mutation ne serait alors pas « dirty » — le test passerait sans
        // rien mesurer.
        $livraison = $this->livraisonEmail();
        $autre = $this->notificationPourUnAutreMembre();

        $this->assertNotSame(
            (string) $autre->id,
            (string) $livraison->notification_id,
            'Le test exige deux notifications distinctes pour etre discriminant.'
        );

        foreach (['notification_id' => (string) $autre->id, 'channel' => NotificationCatalogue::CHANNEL_IN_APP] as $colonne => $valeur) {
            $cible = $livraison->fresh();
            $cible->{$colonne} = $valeur;

            $this->refusDeMutation(fn () => $cible->save(), $colonne);
        }
    }

    /** `status` n'est pas affectable en masse. */
    public function test_status_is_not_mass_assignable(): void
    {
        $notification = $this->notificationAvecInvitation();

        $livraison = MemberNotificationDelivery::create([
            'notification_id' => $notification->id,
            'channel' => NotificationCatalogue::CHANNEL_EMAIL,
            'status' => NotificationDeliveryStatus::SENT,
            'sent_at' => now(),
        ]);

        $this->assertSame(
            NotificationDeliveryStatus::PENDING,
            $livraison->fresh()->status,
            'Un status fourni en masse doit etre ignore : sinon on marquerait « envoye » sans email.'
        );
        $this->assertNull($livraison->fresh()->sent_at);
    }

    // =====================================================================
    // 6. Tout est RELU au moment du travail
    // =====================================================================

    /** Une preference coupee arrete l'envoi, et le dit. */
    public function test_a_disabled_preference_skips_without_sending(): void
    {
        $this->transportMesurable();
        $this->autoriserEmail(false);
        $this->traductionsPresentes();

        $livraison = $this->livraisonEmail();

        app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);

        $this->assertSame([], $this->messagesEnvoyes(), 'Aucun message ne doit atteindre le transport.');
        $this->assertSame(NotificationDeliveryStatus::SKIPPED_PREFERENCE, $livraison->fresh()->status);
        $this->assertSame('channel_disabled_by_member', $livraison->fresh()->diagnostic);
        $this->assertSame(0, EmailLog::query()->whereNotNull('notification_id')->count());
    }

    /**
     * **Un destinataire qui a quitte l'Organization n'est plus joignable.**
     *
     * L'appartenance est relue AU MOMENT DU TRAVAIL, pas a l'emission : c'est
     * tout l'interet d'un pipeline asynchrone de la verifier tard.
     */
    public function test_a_recipient_who_left_the_organization_is_skipped(): void
    {
        $this->transportMesurable();
        $this->autoriserEmail();
        $this->traductionsPresentes();

        $livraison = $this->livraisonEmail();

        // La personne change de tenant entre l'emission et l'envoi.
        DB::table('users')->where('id', $this->membre->id)->update(['organization_id' => $this->orgB->id]);

        app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);

        $this->assertSame([], $this->messagesEnvoyes(), 'Aucun message ne doit atteindre le transport.');
        $this->assertSame(NotificationDeliveryStatus::SKIPPED_UNREACHABLE, $livraison->fresh()->status);
        $this->assertSame('recipient_left_organization', $livraison->fresh()->diagnostic);
    }

    /** Une cible devenue inatteignable arrete l'envoi. */
    public function test_an_unreachable_target_is_skipped(): void
    {
        $this->transportMesurable();
        $this->autoriserEmail();
        $this->traductionsPresentes();

        $livraison = $this->livraisonEmail();

        // L'invitation n'est plus en attente : le resolveur ne rend plus de lien.
        DB::table('loop_invitations')
            ->where('id', $livraison->notification->object_id)
            ->update(['status' => 'accepted']);

        app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);

        $this->assertSame([], $this->messagesEnvoyes(), 'Aucun message ne doit atteindre le transport.');
        $this->assertSame(NotificationDeliveryStatus::SKIPPED_UNREACHABLE, $livraison->fresh()->status);
        $this->assertSame('target_no_longer_reachable', $livraison->fresh()->diagnostic);
    }

    /**
     * **Une traduction manquante ECHOUE plutot que d'envoyer une cle brute.**
     *
     * `__()` rend la cle quand elle manque. Sans cette garde, le destinataire
     * recevrait un message contenant `notifications.email.…` — pire que pas de
     * message du tout.
     */
    public function test_a_missing_translation_fails_rather_than_sending_a_raw_key(): void
    {
        $this->transportMesurable();
        $this->autoriserEmail();
        // Aucune traduction posee : c'est le sujet du test.

        $livraison = $this->livraisonEmail();

        app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);

        $this->assertSame([], $this->messagesEnvoyes(), 'Aucun message ne doit atteindre le transport.');
        $this->assertSame(NotificationDeliveryStatus::FAILED, $livraison->fresh()->status);
        $this->assertSame('missing_email_translation', $livraison->fresh()->diagnostic);
    }

    // =====================================================================
    // 7. L'envoi et sa preuve
    // =====================================================================

    /** Un envoi reussi ecrit LUI-MEME sa preuve : aucun listener ne le fera. */
    public function test_a_successful_delivery_writes_its_own_email_log(): void
    {
        $this->transportMesurable();
        $this->autoriserEmail();
        $this->traductionsPresentes();

        $livraison = $this->livraisonEmail();

        app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);

        // On mesure ce qui a ete remis au TRANSPORT, pas notre propre
        // comptabilite. `Mail::assertSentCount()` ne compte que les Mailables :
        // un envoi brut par `Mail::html()` lui est invisible, et l'assertion
        // aurait donc rougi pour une raison sans rapport avec le sujet.
        $messages = $this->messagesEnvoyes();
        $this->assertCount(1, $messages);

        $envoye = $messages[0]->getOriginalMessage();
        $this->assertSame('Invitation a rejoindre une boucle', $envoye->getSubject());
        $this->assertSame((string) $this->membre->email, $envoye->getTo()[0]->getAddress());

        $fraiche = $livraison->fresh();
        $this->assertSame(NotificationDeliveryStatus::SENT, $fraiche->status);
        $this->assertNotNull($fraiche->sent_at);
        $this->assertNotNull($fraiche->claimed_at);

        $journal = EmailLog::query()->whereNotNull('notification_id')->firstOrFail();
        $this->assertSame((string) $livraison->notification_id, (string) $journal->notification_id);
        $this->assertSame('sent', $journal->status);
        $this->assertSame((string) $this->membre->email, $journal->to_email);
        $this->assertSame('fr', $journal->locale, 'La langue de l\'ENVOI est figee.');
        $this->assertNotNull($journal->body_html);
        $this->assertSame(hash('sha256', $journal->body_html), $journal->body_hash);
        $this->assertSame((string) $this->orgA->id, (string) $journal->organization_id);
    }

    /**
     * **Un transport qui leve donne `ambiguous`, jamais `failed`.**
     *
     * Personne ne sait si le message est parti avant la coupure. `failed`
     * inviterait a rejouer, donc a envoyer deux fois ; `sent` affirmerait une
     * livraison peut-etre inexistante.
     */
    public function test_a_transport_failure_is_ambiguous_not_failed(): void
    {
        $this->autoriserEmail();
        $this->traductionsPresentes();

        // Une seule remise au transport doit avoir lieu, meme apres l'incident.
        Mail::shouldReceive('html')->once()->andThrow(new RuntimeException('transport coupe'));

        $livraison = $this->livraisonEmail();
        $deliverer = app(NotificationEmailDeliverer::class);

        $deliverer->deliver((string) $livraison->notification_id);

        $fraiche = $livraison->fresh();
        $this->assertSame(NotificationDeliveryStatus::AMBIGUOUS, $fraiche->status);
        $this->assertSame('transport_outcome_unknown', $fraiche->diagnostic);
        $this->assertNull($fraiche->sent_at, 'On n\'affirme pas une date d\'envoi qu\'on ignore.');
        $this->assertSame(1, $fraiche->attempts);

        // La preuve est ecrite MEME quand l'issue est incertaine, et elle porte
        // `ambiguous` — PAS `failed`. Le schema PostgreSQL n'acceptait que
        // `sent` et `failed` : cette assertion ne vaut que parce que la
        // migration T1377 a elargi la contrainte, et elle rougirait sinon sur
        // PostgreSQL uniquement.
        $journal = EmailLog::query()->whereNotNull('notification_id')->firstOrFail();
        $this->assertSame(EmailLog::STATUS_AMBIGUOUS, $journal->status);
        $this->assertNotSame(EmailLog::STATUS_FAILED, $journal->status, 'ambiguous ne doit JAMAIS etre converti en failed.');
        $this->assertNotNull($journal->error_message, 'Le diagnostic du transport est conserve.');

        // M1 (review Fable) : un CODE STABLE, jamais le message d'exception
        // brut. Un message SMTP peut porter un host, un port, un fragment de
        // DSN — et cette table est CONSULTABLE.
        $this->assertSame('transport_outcome_unknown', $journal->error_message);
        $this->assertStringNotContainsString('transport coupe', (string) $journal->error_message, 'Le message brut de l\'exception ne doit jamais atteindre la trace durable.');

        // AUCUN rejeu : une seconde passe ne retente rien. Le `shouldReceive`
        // ci-dessus est borne a UNE occurrence — un second envoi ferait rougir
        // l'attente elle-meme.
        $deliverer->deliver((string) $livraison->notification_id);

        $this->assertSame(NotificationDeliveryStatus::AMBIGUOUS, $livraison->fresh()->status);
        $this->assertSame(1, $livraison->fresh()->attempts, 'Aucune seconde prise de travail.');
        $this->assertSame(1, EmailLog::query()->whereNotNull('notification_id')->count(), 'Aucune seconde preuve.');
    }

    /**
     * **FRONTIERE H1 (review Fable) — un transport REUSSI, puis un incident de
     * persistance, ne conclut jamais `failed`.**
     *
     * Le defaut trouve : `Mail::html()` reussit, SMTP a peut-etre deja accepte
     * le message, puis `tracer()` (l'ecriture d'`EmailLog`) leve — et le code
     * concluait `failed`. Faux : `failed` inviterait a renvoyer un message
     * peut-etre deja parti.
     *
     * Le transport n'est sollicite qu'UNE fois : c'est ce qui distingue ce test
     * d'un simple test d'echec — il prouve qu'aucune seconde tentative de
     * remise n'est faite pour « corriger » l'incident de persistance.
     */
    public function test_a_failure_after_a_successful_transport_call_is_ambiguous_never_failed(): void
    {
        $this->autoriserEmail();
        $this->traductionsPresentes();

        // Le transport REUSSIT — une seule fois, et une seule. Un second appel
        // ferait rougir l'attente elle-meme : c'est ce qui prouve qu'aucune
        // remise de secours n'est tentee pour « corriger » l'incident qui suit.
        Mail::shouldReceive('html')->once()->andReturn(null);

        $livraison = $this->livraisonEmail();

        // Sabotage de la persistance, PAS du code de production : la table est
        // masquee le temps de l'appel, ce qui fait echouer REELLEMENT
        // `tracer()` — sans toucher au deliverer, et de la meme facon sur
        // SQLite et PostgreSQL. `RefreshDatabase` encapsule le test dans une
        // transaction qui restaure l'etat quoi qu'il arrive ; le `finally`
        // restaure explicitement, au cas ou une assertion echouerait avant.
        Schema::rename('email_logs', 'email_logs_masquee_par_le_test');

        try {
            app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);
        } finally {
            Schema::rename('email_logs_masquee_par_le_test', 'email_logs');
        }

        $fraiche = $livraison->fresh();
        $this->assertSame(NotificationDeliveryStatus::AMBIGUOUS, $fraiche->status, 'Jamais failed apres un transport reussi.');
        $this->assertNotSame(NotificationDeliveryStatus::FAILED, $fraiche->status);
        $this->assertSame('post_transport_persistence_failed', $fraiche->diagnostic);
        $this->assertNull($fraiche->sent_at, 'On n\'affirme pas une date d\'envoi que la persistance n\'a pas confirmee.');
        $this->assertSame(1, $fraiche->attempts, 'Une seule prise de travail.');

        // Aucune preuve n'a pu s'ecrire — l'incident etait dans CETTE ecriture —
        // et c'est attendu : on ne fabrique pas une preuve a partir d'un
        // sabotage de la preuve elle-meme. La table est restauree, la requete
        // porte donc bien sur l'etat reel.
        $this->assertSame(0, EmailLog::query()->count());
    }

    /**
     * **Les etats « ignores » n'ecrivent AUCUNE preuve d'envoi.**
     *
     * Aucune tentative n'a eu lieu : inscrire une ligne dans l'historique des
     * envois laisserait croire le contraire.
     */
    public function test_skipped_states_write_no_email_log(): void
    {
        $this->transportMesurable();
        $this->autoriserEmail(false);
        $this->traductionsPresentes();

        $livraison = $this->livraisonEmail();

        app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);

        $this->assertSame(NotificationDeliveryStatus::SKIPPED_PREFERENCE, $livraison->fresh()->status);
        $this->assertSame(0, EmailLog::query()->count(), 'Aucune tentative : aucune preuve.');
    }

    /**
     * **Le vocabulaire de `status` est refuse identiquement sur les DEUX moteurs.**
     *
     * La migration historique declarait un `enum` : contrainte REELLE sur
     * PostgreSQL, RIEN sur SQLite. Les deux bases ne faisaient donc pas
     * respecter la meme regle, et une suite SQLite ne pouvait pas le voir. Le
     * garde applicatif ferme cet ecart.
     */
    public function test_an_unknown_email_log_status_is_refused_on_both_engines(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EmailLog::create([
            'to_email' => 'x@exemple.test',
            'subject' => 'sujet',
            'status' => 'peut_etre',
        ]);
    }

    /**
     * **Un incident interne n'ecrit jamais un message d'exception BRUT.**
     *
     * Mesure : une violation de contrainte PostgreSQL fait 129 caracteres,
     * contre 120 permis par la colonne. L'ecriture echouerait donc PAR-DESSUS
     * l'incident d'origine et en masquerait la cause. Un message de moteur peut
     * en outre transporter des fragments de requete, donc de donnees.
     */
    public function test_an_internal_failure_records_a_stable_code_not_a_raw_message(): void
    {
        $this->transportMesurable();
        $this->autoriserEmail();
        $this->traductionsPresentes();

        $livraison = $this->livraisonEmail();

        // Le resolveur de cible leve : un incident HORS remise au transport.
        $this->app->bind(NotificationTargetResolver::class, fn () => new class extends NotificationTargetResolver
        {
            public function resolve(MemberNotification $notification): ?string
            {
                throw new RuntimeException(str_repeat('fragment de requete SQL ', 20));
            }
        });

        app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);

        $fraiche = $livraison->fresh();
        $this->assertSame(NotificationDeliveryStatus::FAILED, $fraiche->status);
        $this->assertSame('delivery_internal_error', $fraiche->diagnostic);
        $this->assertLessThanOrEqual(120, mb_strlen((string) $fraiche->diagnostic));
        $this->assertStringNotContainsString('fragment de requete', (string) $fraiche->diagnostic);
    }

    /** Une notification disparue ne fait rien tomber. */
    public function test_a_vanished_notification_is_not_an_error(): void
    {
        $this->transportMesurable();

        app(NotificationEmailDeliverer::class)->deliver('11111111-2222-3333-4444-555555555555');

        $this->assertSame([], $this->messagesEnvoyes(), 'Aucun message ne doit atteindre le transport.');
        $this->assertSame(0, MemberNotificationDelivery::query()->count());
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** Une notification reelle, adossee a une invitation en attente. */
    private function notificationAvecInvitation(): MemberNotification
    {
        Queue::fake();

        app(LoopInvitationService::class)->invite($this->loop, $this->expediteur, $this->membre->email);

        return MemberNotification::query()->latest('created_at')->firstOrFail();
    }

    /**
     * Bascule sur le transport `array` et le vide.
     *
     * `Mail::fake()` ne conviendrait pas : son `assertSentCount()` ne compte que
     * les Mailables, et ce pipeline envoie en BRUT via `Mail::html()`. Le
     * transport `array` collecte les messages reellement remis — c'est le vrai
     * rendu, pas un compteur parallele.
     */
    private function transportMesurable(): void
    {
        config(['mail.default' => 'array']);

        Mail::forgetMailers();
        Mail::mailer()->getSymfonyTransport()->flush();
    }

    /**
     * Les messages REELLEMENT remis au transport.
     *
     * `messages()` rend une Collection ; on la ramene a un tableau pour que les
     * assertions portent sur une structure simple.
     *
     * @return list<SentMessage>
     */
    private function messagesEnvoyes(): array
    {
        return array_values(Mail::mailer()->getSymfonyTransport()->messages()->all());
    }

    /** Une notification adressee a QUELQU'UN D'AUTRE, donc reellement distincte. */
    private function notificationPourUnAutreMembre(): MemberNotification
    {
        Queue::fake();

        $autre = User::factory()->create(['organization_id' => $this->orgA->id]);

        app(LoopInvitationService::class)->invite($this->loop, $this->expediteur, $autre->email);

        return MemberNotification::query()->where('recipient_id', $autre->id)->firstOrFail();
    }

    /** La livraison email d'une notification reelle. */
    private function livraisonEmail(): MemberNotificationDelivery
    {
        $notification = $this->notificationAvecInvitation();

        return MemberNotificationDelivery::create([
            'notification_id' => $notification->id,
            'channel' => NotificationCatalogue::CHANNEL_EMAIL,
        ]);
    }

    /**
     * Substitue le resolveur de preferences.
     *
     * Le catalogue n'autorise EMAIL nulle part en P5 : sans cela, le chemin
     * d'envoi serait inatteignable et ces tests ne mesureraient rien. La
     * frontiere du catalogue est mesuree separement, la ou elle vit.
     */
    private function autoriserEmail(bool $autorise = true): void
    {
        $this->app->bind(NotificationPreferenceResolver::class, fn () => new class($autorise) extends NotificationPreferenceResolver
        {
            public function __construct(private bool $autorise) {}

            public function allows(User $user, string $notificationKey, string $channel): bool
            {
                return $channel === NotificationCatalogue::CHANNEL_EMAIL
                    ? $this->autorise
                    : parent::allows($user, $notificationKey, $channel);
            }
        });
    }

    /** Pose un contenu d'email pour la cle testee, sans le livrer dans le depot. */
    private function traductionsPresentes(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $racine = 'notifications.email.'.str_replace('.', '_', NotificationCatalogue::LOOP_INVITATION);

            Lang::addLines([
                $racine.'.subject' => 'Invitation a rejoindre une boucle',
                $racine.'.body' => 'Bonjour, voici votre invitation : :url',
            ], $locale);
        }
    }

    /**
     * Asserte qu'une mutation est refusee, sans que le test puisse se satisfaire
     * lui-meme.
     *
     * `AssertionFailedError` descend de `RuntimeException` : un `$this->fail()`
     * a l'interieur du `try` serait rattrape par le `catch`, et si son message
     * contenait le mot cherche, l'assertion suivante passerait aussi. Le drapeau
     * est donc leve HORS du bloc.
     */
    private function refusDeMutation(callable $mutation, string $attendu): void
    {
        $refuse = false;
        $message = '';

        try {
            $mutation();
        } catch (RuntimeException $e) {
            $refuse = true;
            $message = $e->getMessage();
        }

        $this->assertTrue($refuse, "[{$attendu}] aurait du etre refuse.");
        $this->assertStringContainsString($attendu, $message);
    }
}
