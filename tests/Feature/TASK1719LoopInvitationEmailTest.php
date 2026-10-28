<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\LoopInvitation;
use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use App\Services\LoopInvitationService;
use App\Services\Loops\LoopInvitationMailer;
use App\Services\LoopService;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * TASK-1719 — un utilisateur DEJA INSCRIT invite dans une Boucle.
 *
 * ## Ce que l'audit a mesure, et qui reduit la TASK a un seul defaut
 *
 * L'enonce disait « il ne recoit pas d'email ». Mesure de bout en bout sur un
 * membre de l'Organization :
 *
 *     type invitation            = existing_member
 *     emailHandledByNotifications = true
 *     notifications in-app        = 1
 *     livraisons planifiees       = 1  (canal=email, statut=sent)
 *
 * La notification in-app ET l'email EXISTAIENT donc deja, depuis TASK-1378.
 * Les deux premiers points de la demande etaient satisfaits.
 *
 * **Le defaut reel est le troisieme** : le pipeline composait son corps depuis
 * `lang/<locale>/notifications.php`, un texte generique de cinq lignes, et
 * IGNORAIT le gabarit `loop_invitation` que le SuperAdmin administre. Deux
 * textes pour le meme fait, selon que l'invite avait un compte ou non — et
 * celui qu'on peut modifier ne partait jamais aux membres.
 *
 * ## Une mesure fausse, corrigee en route
 *
 * J'ai d'abord conclu que les clefs de langue manquaient et qu'aucun email ne
 * partait. C'etait FAUX : j'avais interroge `notifications.email.loop.invitation`
 * (avec des points) au lieu de `notifications.email.loop_invitation`, la forme
 * que le code construit. Les clefs existent. La correction porte donc sur le
 * gabarit, pas sur une absence d'envoi.
 */
class TASK1719LoopInvitationEmailTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organisation;

    private User $hote;

    private $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisation = Organization::factory()->create(['is_default' => true]);
        app()->instance('current_organization', $this->organisation);

        $this->hote = User::factory()->create(['organization_id' => $this->organisation->id]);
        $this->loop = (new LoopService)->createLoop($this->hote, 'Boucle Invitation');
    }

    private function gabarit(string $sujet, string $corps): SystemEmailTemplate
    {
        // `updateOrCreate` et non `create` : une ligne GLOBALE `loop_invitation`
        // est deja posee par migration, et en creer une seconde ne ferait que
        // laisser le resolveur choisir l'ancienne. Mesure : le premier jet de
        // ces gardes echouait ici, et le `fallback_reason` nul l'a dit.
        return SystemEmailTemplate::updateOrCreate(
            ['slug' => 'loop_invitation', 'organization_id' => null],
            ['name' => 'Invitation Boucle', 'subject' => $sujet, 'content_html' => $corps],
        );
    }

    private function inviter(string $email): LoopInvitation
    {
        return app(LoopInvitationService::class)->invite($this->loop, $this->hote, $email, null, null);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 1. Ce qui existait deja, et qui ne doit pas se casser
    // ───────────────────────────────────────────────────────────────────────

    public function test_un_membre_existant_recoit_une_notification_in_app_ET_un_email(): void
    {
        User::factory()->create(['organization_id' => $this->organisation->id, 'email' => 'deja@inscrit.test']);

        Mail::fake();
        $this->inviter('deja@inscrit.test');

        $this->assertSame(1, MemberNotification::where(
            'notification_key', NotificationCatalogue::LOOP_INVITATION
        )->count());

        $livraison = MemberNotificationDelivery::where('channel', 'email')->first();
        $this->assertNotNull($livraison, 'une livraison email doit etre planifiee');
        $this->assertSame('sent', $livraison->status);
    }

    public function test_un_inconnu_recoit_toujours_son_email_par_le_mailer(): void
    {
        // La non-regression du chemin EXTERNE : inviter quelqu'un qui n'a pas
        // de compte reste un cas metier normal, et c'est `LoopInvitationMailer`
        // qui le sert. Sans cette garde, deplacer le rendu pourrait casser la
        // moitie du produit qui marchait.
        $invitation = $this->inviter('inconnu@dehors.test');

        $this->assertSame(LoopInvitation::TYPE_EXTERNAL, $invitation->invitation_type);
        $this->assertFalse(app(LoopInvitationService::class)->emailHandledByNotifications($invitation));
    }

    // ───────────────────────────────────────────────────────────────────────
    // 2. LE defaut corrige : le gabarit administrable sert les DEUX chemins
    // ───────────────────────────────────────────────────────────────────────

    public function test_le_gabarit_loop_invitation_sert_aussi_un_membre_existant(): void
    {
        $this->gabarit('Invitation a {{ loop_name }}', '<p>Bonjour {{ recipient_name }}, voici {{ invitation_url }}</p>');
        User::factory()->create(['organization_id' => $this->organisation->id, 'email' => 'deja@inscrit.test']);

        Mail::fake();
        $this->inviter('deja@inscrit.test');

        // Le SUJET prouve l'origine : le texte generique de
        // `lang/<locale>/notifications.php` dit « Vous êtes invité à rejoindre
        // une boucle » et ne contient JAMAIS le nom de la Boucle.
        //
        // L'assertion porte sur la TRACE archivee par le pipeline, et non sur
        // `Mail::assertSent()` : le transport passe par `Mail::html()`, qui
        // n'envoie aucun Mailable — `assertSent` n'a donc rien a inspecter.
        $journal = EmailLog::query()->latest('id')->first();

        $this->assertNotNull($journal, 'le pipeline doit tracer son envoi');
        $this->assertStringContainsString('Boucle Invitation', (string) $journal->subject);
    }

    public function test_le_meme_gabarit_sert_un_inconnu(): void
    {
        // LA garde qui donne son sens a la precedente : un seul texte pour le
        // meme fait, quelle que soit la porte d'entree.
        $this->gabarit('Invitation a {{ loop_name }}', '<p>Bonjour {{ recipient_name }}</p>');

        $compose = app(LoopInvitationMailer::class)->compose($this->inviter('inconnu@dehors.test'));

        $this->assertStringContainsString('Boucle Invitation', $compose['subject']);
        // `fallback_reason` nul prouve que c'est bien le GABARIT qui a servi,
        // et non le repli Blade qui produirait un sujet voisin.
        $this->assertNull($compose['fallback_reason']);
    }

    public function test_sans_gabarit_le_repli_tient_toujours(): void
    {
        // Le gabarit est RETIRE pour de bon : le repli Blade de
        // `LoopInvitationMailer` doit continuer a produire un courriel, sinon
        // la TASK aurait echange un texte generique contre pas de texte.
        SystemEmailTemplate::where('slug', 'loop_invitation')->delete();

        $compose = app(LoopInvitationMailer::class)->compose($this->inviter('inconnu@dehors.test'));

        $this->assertNotSame('', trim($compose['subject']));
        $this->assertNotSame('', trim($compose['body']));
        $this->assertNotNull($compose['fallback_reason'], 'le repli doit etre JOURNALISE, pas silencieux');
    }

    // ───────────────────────────────────────────────────────────────────────
    // 3. Le jeton ne doit pas finir dans le journal
    // ───────────────────────────────────────────────────────────────────────

    public function test_le_corps_archive_ne_porte_pas_le_jeton(): void
    {
        $this->gabarit('Invitation', '<p><a href="{{ invitation_url }}">Voir</a></p>');
        $invitation = $this->inviter('inconnu@dehors.test');

        $reel = app(LoopInvitationMailer::class)->compose($invitation);
        $expurge = app(LoopInvitationMailer::class)->compose($invitation, '[lien masque]');

        $this->assertStringContainsString($invitation->token, $reel['body']);
        $this->assertStringNotContainsString($invitation->token, $expurge['body']);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 4. Les frontieres que la TASK ne doit pas percer
    // ───────────────────────────────────────────────────────────────────────

    public function test_un_compte_d_un_AUTRE_tenant_n_est_pas_notifie(): void
    {
        // Connaitre une adresse n'a jamais donne le droit de franchir une
        // frontiere d'Organization : l'email part, la notification in-app non.
        $autre = Organization::factory()->create();
        User::factory()->create(['organization_id' => $autre->id, 'email' => 'ailleurs@autre.test']);

        $invitation = $this->inviter('ailleurs@autre.test');

        $this->assertSame(LoopInvitation::TYPE_EXTERNAL, $invitation->invitation_type);
        $this->assertSame(0, MemberNotification::count());
    }

    public function test_deux_invitations_de_suite_ne_doublent_pas_la_notification(): void
    {
        User::factory()->create(['organization_id' => $this->organisation->id, 'email' => 'deja@inscrit.test']);

        Mail::fake();
        $this->inviter('deja@inscrit.test');
        $this->inviter('deja@inscrit.test');

        $this->assertSame(1, MemberNotification::where(
            'notification_key', NotificationCatalogue::LOOP_INVITATION
        )->count());
    }
}
