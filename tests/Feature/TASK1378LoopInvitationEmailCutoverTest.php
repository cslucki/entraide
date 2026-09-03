<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationEmail;
use App\Models\EmailLog;
use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\MemberNotificationPreference;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopInvitationService;
use App\Services\Loops\LoopInvitationMailer;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationDeliveryStatus;
use App\Support\Notifications\NotificationEmailDeliverer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

/**
 * TASK-1378 — le cutover email de l'invitation a une Boucle.
 *
 * ## Ce qui bascule
 *
 * Un membre de l'Organization recoit desormais son invitation par le pipeline
 * Notifications : notification IN_APP, livraison EMAIL, file dediee, preuve
 * dans `email_logs`. Un INCONNU — sans compte, ou dans un autre tenant —
 * continue de la recevoir par le mailer legacy.
 *
 * ## La propriete qui compte : EXACTEMENT UN email
 *
 * Deux chemins pouvant envoyer le meme message, la seule question qui vaille est
 * combien de messages atteignent le transport. Ces tests comptent donc les
 * messages REELLEMENT remis, jamais des intentions.
 *
 * ## Le jeton part, mais ne s'archive JAMAIS
 *
 * La cible est une URL porteuse d'un jeton d'acces vivant. Elle doit atteindre
 * le destinataire, et ne doit pas dormir dans `email_logs`, table consultable
 * et sans expiration propre. Le gabarit est rendu deux fois : le vrai lien part
 * et est hache, un marqueur expurge est archive.
 */
class TASK1378LoopInvitationEmailCutoverTest extends TestCase
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

        $this->orgA = Organization::factory()->create(['name' => 'T1378 Organization A', 'is_active' => true]);
        $this->orgB = Organization::factory()->create(['name' => 'T1378 Organization B', 'is_active' => true]);

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

        $this->transportMesurable();

        // La configuration de test met la queue en `sync` : sans cela, le job
        // partirait DES `invite()`, avant toute mutation que le test veut
        // mesurer — et un test « vert » le serait alors pour la mauvaise
        // raison. Chaque test declenche donc la livraison EXPLICITEMENT.
        Queue::fake();
    }

    // =====================================================================
    // 1. Le catalogue est le POINT DE BASCULE
    // =====================================================================

    /** EMAIL est active sur l'invitation, et il est CONFIGURABLE. */
    public function test_the_catalogue_now_activates_email_on_the_invitation(): void
    {
        $cle = NotificationCatalogue::LOOP_INVITATION;

        $this->assertTrue(NotificationCatalogue::allowsChannel($cle, NotificationCatalogue::CHANNEL_EMAIL));
        $this->assertTrue(NotificationCatalogue::channelDefault($cle, NotificationCatalogue::CHANNEL_EMAIL), 'EMAIL est actif par defaut.');
        $this->assertTrue(NotificationCatalogue::channelIsConfigurable($cle, NotificationCatalogue::CHANNEL_EMAIL), 'EMAIL est reglable.');

        // IN_APP reste OBLIGATOIRE : une invitation appelle une reponse, on ne
        // s'en desabonne pas.
        $this->assertTrue(NotificationCatalogue::allowsChannel($cle, NotificationCatalogue::CHANNEL_IN_APP));
        $this->assertFalse(NotificationCatalogue::channelIsConfigurable($cle, NotificationCatalogue::CHANNEL_IN_APP));
    }

    /** Les deux locales portent le contenu de l'email. */
    public function test_both_locales_carry_the_email_content(): void
    {
        foreach (['fr', 'en'] as $locale) {
            foreach (['subject', 'body'] as $champ) {
                $this->assertTrue(
                    Lang::has("notifications.email.loop_invitation.{$champ}", $locale),
                    "[{$locale}.{$champ}] doit exister."
                );
            }
        }
    }

    // =====================================================================
    // 2. Membre de l'Organization — le nouveau pipeline, et lui seul
    // =====================================================================

    /**
     * **Un membre : une notification, une livraison EMAIL en attente, un job
     * sur la file DEDIEE — et aucun email legacy.**
     */
    public function test_an_existing_member_goes_through_the_notifications_pipeline(): void
    {
        $invitation = $this->inviter($this->membre->email);

        $this->assertSame(LoopInvitation::TYPE_EXISTING_MEMBER, $invitation->invitation_type);
        $this->assertSame(1, MemberNotification::query()->count());

        $livraison = MemberNotificationDelivery::query()->firstOrFail();
        $this->assertSame(NotificationCatalogue::CHANNEL_EMAIL, $livraison->channel);
        $this->assertSame(NotificationDeliveryStatus::PENDING, $livraison->status);

        Queue::assertPushed(SendNotificationEmail::class, 1);
        Queue::assertPushed(SendNotificationEmail::class, fn ($job) => $job->queue === 'notifications-email');

        // AUCUN email legacy : le mailer refuse de servir un membre.
        app(LoopInvitationMailer::class)->send($invitation);

        $this->assertSame([], $this->messagesEnvoyes(), 'Le mailer legacy ne doit pas servir un membre.');
        $this->assertSame(0, EmailLog::query()->count());
    }

    /** Et le worker envoie alors EXACTEMENT un email. */
    public function test_the_worker_sends_exactly_one_email_to_a_member(): void
    {
        $invitation = $this->inviter($this->membre->email);
        $livraison = MemberNotificationDelivery::query()->firstOrFail();

        // Le mailer legacy est sollicite comme en production — il doit s'effacer.
        app(LoopInvitationMailer::class)->send($invitation);

        app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);

        $messages = $this->messagesEnvoyes();
        $this->assertCount(1, $messages, 'EXACTEMENT un email.');

        $this->assertSame(NotificationDeliveryStatus::SENT, $livraison->fresh()->status);
        $this->assertSame(1, EmailLog::query()->count());
    }

    // =====================================================================
    // 3. Le jeton part, mais ne s'archive pas
    // =====================================================================

    /**
     * **Le vrai lien atteint le destinataire ; le jeton n'entre PAS dans
     * `email_logs`.**
     *
     * C'est le HARD GATE arbitre par MASTER. Les deux moities comptent : un
     * email sans son lien ne sert a rien, et un jeton archive dans une table
     * consultable reste utilisable.
     */
    public function test_the_real_link_is_sent_but_the_token_is_never_archived(): void
    {
        $invitation = $this->inviter($this->membre->email);
        $livraison = MemberNotificationDelivery::query()->firstOrFail();

        app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);

        $token = (string) $invitation->fresh()->token;
        $this->assertNotEmpty($token, 'Le test exige un jeton reel pour etre discriminant.');

        // 1. Le VRAI lien est parti.
        $envoye = $this->messagesEnvoyes()[0]->getOriginalMessage();
        $corpsEnvoye = $envoye->getHtmlBody() ?? $envoye->getBody()->bodyToString();
        $this->assertStringContainsString($token, $corpsEnvoye, 'Le vrai jeton doit atteindre le destinataire.');
        $this->assertStringContainsString(route('loop-invitations.show', $token), $corpsEnvoye);

        // 2. Le jeton n'est PAS archive.
        $journal = EmailLog::query()->firstOrFail();
        $this->assertStringNotContainsString($token, (string) $journal->body_html, 'Aucun jeton vivant dans la trace durable.');
        $this->assertStringContainsString('[action-link-redacted]', (string) $journal->body_html, 'Le marqueur expurge doit etre visible.');

        // 3. Le hachage porte sur ce qui est REELLEMENT parti.
        $this->assertSame(hash('sha256', $corpsEnvoye), $journal->body_hash);
        $this->assertNotSame(
            hash('sha256', (string) $journal->body_html),
            $journal->body_hash,
            'Le hachage ne doit PAS correspondre au corps expurge : sinon il ne prouverait rien sur l\'envoi.'
        );

        // 4. Nulle part ailleurs : ni diagnostic, ni error_message, ni charge utile.
        $this->assertStringNotContainsString($token, (string) $livraison->fresh()->diagnostic);
        $this->assertStringNotContainsString($token, (string) $journal->error_message);
        $this->assertStringNotContainsString($token, json_encode($journal->data ?? []));
        $this->assertStringNotContainsString($token, json_encode(get_object_vars(new SendNotificationEmail((string) $livraison->notification_id))));
    }

    // =====================================================================
    // 4. Inconnu — le legacy, et lui seul
    // =====================================================================

    /** Une adresse sans compte : aucune notification, un email legacy. */
    public function test_an_outsider_gets_exactly_one_legacy_email(): void
    {
        $invitation = $this->inviter('inconnu-t1378@exemple-externe.test');

        $this->assertSame(LoopInvitation::TYPE_EXTERNAL, $invitation->invitation_type);
        $this->assertSame(0, MemberNotification::query()->count(), 'Aucune notification pour un inconnu.');
        $this->assertSame(0, MemberNotificationDelivery::query()->count());
        Queue::assertNotPushed(SendNotificationEmail::class);

        app(LoopInvitationMailer::class)->send($invitation);

        $this->assertCount(1, $this->messagesEnvoyes(), 'EXACTEMENT un email legacy.');
        $this->assertSame(1, EmailLog::query()->count());
    }

    /**
     * **Un membre d'un AUTRE tenant est un inconnu ici.**
     *
     * Connaitre une adresse n'a jamais donne le droit de franchir une frontiere
     * d'Organization.
     */
    public function test_a_member_of_another_tenant_is_an_outsider(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->orgB->id]);

        $invitation = $this->inviter($etranger->email);

        $this->assertSame(LoopInvitation::TYPE_EXTERNAL, $invitation->invitation_type);
        $this->assertSame(0, MemberNotification::query()->count(), 'Aucune notification cross-tenant.');
        Queue::assertNotPushed(SendNotificationEmail::class);

        app(LoopInvitationMailer::class)->send($invitation);

        $this->assertCount(1, $this->messagesEnvoyes());
    }

    // =====================================================================
    // 5. Aucun second email, jamais
    // =====================================================================

    /** Rejouer la MEME invitation en attente n'envoie rien de plus. */
    public function test_replaying_the_same_pending_invitation_sends_nothing_more(): void
    {
        $invitation = $this->inviter($this->membre->email);
        $livraison = MemberNotificationDelivery::query()->firstOrFail();

        app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);
        $this->assertCount(1, $this->messagesEnvoyes());

        // Second appel a `invite()` : l'invitation en attente est REUTILISEE.
        $this->inviter($this->membre->email);

        $this->assertSame(1, MemberNotification::query()->count());
        $this->assertSame(1, MemberNotificationDelivery::query()->count(), 'Aucune seconde livraison.');

        app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);

        $this->assertCount(1, $this->messagesEnvoyes(), 'Toujours EXACTEMENT un email.');
        $this->assertSame(1, EmailLog::query()->count());
    }

    /**
     * **La colonne `invitation_type` est FIGEE a la creation — s'y fier
     * enverrait DEUX emails.**
     *
     * Le scenario est ordinaire : on invite une adresse sans compte, la personne
     * cree son compte dans l'Organization, puis quelqu'un relance l'invitation.
     * L'invitation en attente est REUTILISEE, donc la colonne dit encore
     * `external` — alors que la notification, elle, est bien creee, parce que la
     * clause qui la decide est evaluee EN VIF.
     *
     * Si le mailer legacy se fiait a la colonne, il enverrait de son cote : deux
     * emails pour un seul fait. Le garde recalcule donc la meme clause, au meme
     * instant. Mesure verifiee par sabotage.
     */
    public function test_a_stale_invitation_type_would_send_twice_so_the_guard_recomputes(): void
    {
        // 1. Une adresse sans compte : invitation externe, aucune notification.
        $adresse = 'futur-membre-t1378@exemple-externe.test';
        $invitation = $this->inviter($adresse);

        $this->assertSame(LoopInvitation::TYPE_EXTERNAL, $invitation->invitation_type);
        $this->assertSame(0, MemberNotification::query()->count());

        // 2. La personne rejoint l'Organization.
        User::factory()->create([
            'organization_id' => $this->orgA->id,
            'email' => $adresse,
            'preferred_locale' => 'fr',
        ]);

        // 3. On relance : l'invitation EN ATTENTE est reutilisee, donc la colonne
        //    reste `external` — mais la notification est bien creee.
        $relance = $this->inviter($adresse);

        $this->assertSame(
            LoopInvitation::TYPE_EXTERNAL,
            $relance->invitation_type,
            'La colonne est figee : c\'est precisement ce qui rend ce test discriminant.'
        );
        $this->assertSame(1, MemberNotification::query()->count(), 'La clause VIVE, elle, a bien vu le nouveau membre.');
        $this->assertSame(1, MemberNotificationDelivery::query()->count());

        // 4. Le mailer legacy est sollicite comme en production. Il doit
        //    s'effacer — sinon deux emails partent pour un seul fait.
        app(LoopInvitationMailer::class)->send($relance);

        $this->assertSame([], $this->messagesEnvoyes(), 'Le legacy doit s\'effacer malgre la colonne perimee.');

        // 5. Et le pipeline en envoie EXACTEMENT un.
        $livraison = MemberNotificationDelivery::query()->firstOrFail();
        app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);

        $this->assertCount(1, $this->messagesEnvoyes(), 'EXACTEMENT un email, pas deux.');
    }

    /** Un SECOND expediteur n'envoie pas un second email. */
    public function test_a_second_sender_sends_no_second_email(): void
    {
        $invitation = $this->inviter($this->membre->email);
        $livraison = MemberNotificationDelivery::query()->firstOrFail();

        app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);

        $autreExpediteur = User::factory()->create(['organization_id' => $this->orgA->id]);
        app(LoopInvitationService::class)->invite($this->loop, $autreExpediteur, $this->membre->email);

        $this->assertSame(1, MemberNotificationDelivery::query()->count());

        app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);

        $this->assertCount(1, $this->messagesEnvoyes(), 'Un second expediteur ne redeclenche rien.');
    }

    // =====================================================================
    // 6. Les relectures COURANTES, avant tout envoi
    // =====================================================================

    /** Preference EMAIL coupee : aucun email, et le legacy ne prend PAS le relais. */
    public function test_a_member_who_disabled_email_receives_nothing(): void
    {
        $invitation = $this->inviter($this->membre->email);
        $livraison = MemberNotificationDelivery::query()->firstOrFail();

        // L'ecart est pose APRES l'emission : c'est tout l'interet de relire.
        $preference = new MemberNotificationPreference([
            'notification_key' => NotificationCatalogue::LOOP_INVITATION,
            'channel' => NotificationCatalogue::CHANNEL_EMAIL,
            'enabled' => false,
        ]);
        $preference->user_id = (string) $this->membre->id;
        $preference->save();

        app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);

        $this->assertSame([], $this->messagesEnvoyes(), 'Zero email.');
        $this->assertSame(NotificationDeliveryStatus::SKIPPED_PREFERENCE, $livraison->fresh()->status);
        $this->assertSame(0, EmailLog::query()->count());

        // Et le legacy ne compense pas : le membre a choisi de ne pas recevoir.
        app(LoopInvitationMailer::class)->send($invitation);
        $this->assertSame([], $this->messagesEnvoyes(), 'Le legacy ne contourne pas la preference.');
    }

    /** Invitation acceptee, revoquee ou expiree avant le worker : aucun email. */
    public function test_a_stale_invitation_sends_nothing(): void
    {
        foreach ([LoopInvitation::STATUS_ACCEPTED, LoopInvitation::STATUS_REVOKED, LoopInvitation::STATUS_EXPIRED] as $statut) {
            // Un destinataire NEUF a chaque tour : reutiliser le meme reutiliserait
            // l'invitation en attente, et les tours suivants ne mesureraient plus
            // rien. Pas de `setUp()` rappele a la main — un test qui reconstruit
            // son harnais en cours de route ne mesure plus ce qu'il croit.
            $destinataire = User::factory()->create([
                'organization_id' => $this->orgA->id,
                'preferred_locale' => 'fr',
            ]);

            $this->inviter($destinataire->email);

            $livraison = MemberNotificationDelivery::query()
                ->whereHas('notification', fn ($q) => $q->where('recipient_id', $destinataire->id))
                ->firstOrFail();

            DB::table('loop_invitations')
                ->where('id', $livraison->notification->object_id)
                ->update(['status' => $statut]);

            $avant = count($this->messagesEnvoyes());

            app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);

            $this->assertCount($avant, $this->messagesEnvoyes(), "[{$statut}] ne doit produire aucun email.");
            $this->assertSame(
                NotificationDeliveryStatus::SKIPPED_UNREACHABLE,
                $livraison->fresh()->status,
                "[{$statut}] doit conclure skipped_unreachable."
            );
            $this->assertSame('target_no_longer_reachable', $livraison->fresh()->diagnostic);
        }
    }

    /** Le destinataire change d'Organization avant le worker : aucun email. */
    public function test_a_recipient_moved_to_another_tenant_sends_nothing(): void
    {
        $this->inviter($this->membre->email);
        $livraison = MemberNotificationDelivery::query()->firstOrFail();

        DB::table('users')->where('id', $this->membre->id)->update(['organization_id' => $this->orgB->id]);

        app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);

        $this->assertSame([], $this->messagesEnvoyes());
        $this->assertSame(NotificationDeliveryStatus::SKIPPED_UNREACHABLE, $livraison->fresh()->status);
        $this->assertSame('recipient_left_organization', $livraison->fresh()->diagnostic);
    }

    // =====================================================================
    // 7. Aucune autorite de session dans le worker
    // =====================================================================

    /**
     * **Le worker n'emprunte ni session, ni utilisateur connecte, ni
     * Organization courante.**
     *
     * Un worker n'est l'utilisateur de personne. On envoie donc en etant
     * authentifie comme QUELQU'UN D'AUTRE, dans un AUTRE tenant : si le livreur
     * lisait un contexte ambiant, il se tromperait de destinataire ou ne
     * resoudrait rien.
     */
    public function test_the_worker_borrows_no_session_authority(): void
    {
        $this->inviter($this->membre->email);
        $livraison = MemberNotificationDelivery::query()->firstOrFail();

        $intrus = User::factory()->create(['organization_id' => $this->orgB->id]);
        $this->actingAs($intrus);
        app()->instance('current_organization', $this->orgB);

        app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);

        $this->assertCount(1, $this->messagesEnvoyes());

        // Le message part bien au VRAI destinataire, pas a l'utilisateur connecte.
        $envoye = $this->messagesEnvoyes()[0]->getOriginalMessage();
        $this->assertSame((string) $this->membre->email, $envoye->getTo()[0]->getAddress());
        $this->assertSame(NotificationDeliveryStatus::SENT, $livraison->fresh()->status);

        $journal = EmailLog::query()->firstOrFail();
        $this->assertSame((string) $this->orgA->id, (string) $journal->organization_id, 'Le tenant vient de la notification, pas du contexte.');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function inviter(string $email): LoopInvitation
    {
        return app(LoopInvitationService::class)->invite($this->loop, $this->expediteur, $email);
    }

    /**
     * Bascule sur le transport `array` et le vide.
     *
     * `Mail::fake()` ne conviendrait pas : son `assertSentCount()` ne compte que
     * les Mailables, et les deux chemins envoient en BRUT via `Mail::html()`.
     * Le transport `array` collecte les messages reellement remis — c'est ce
     * qu'il faut compter pour prouver « exactement un email ».
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
     * @return list<SentMessage>
     */
    private function messagesEnvoyes(): array
    {
        return array_values(Mail::mailer()->getSymfonyTransport()->messages()->all());
    }
}
