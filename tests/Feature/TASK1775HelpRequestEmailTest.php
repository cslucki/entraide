<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopNotificationPreference;
use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationDeliveryStatus;
use App\Support\Notifications\NotificationEmailDeliverer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TASK-1775 (CDC C3) — le canal EMAIL de `loop.help_request.created`.
 *
 * ## La garde la plus importante de ce fichier est negative
 *
 * `test_sans_rien_regler_aucun_email_ne_part`. Ouvrir un canal au catalogue
 * ne doit RIEN declencher pour qui n'a rien demande — c'est la promesse
 * explicite du §7 contre le bruit de masse, et c'est elle qui justifie que le
 * defaut soit `false` ET que `LoopNotificationPreference` doive etre reglee
 * individuellement, Boucle par Boucle, avant qu'un seul email ne parte.
 *
 * ## `Mail::fake()` ne convient pas ici
 *
 * Meme defaut que T1377 : ce pipeline envoie en BRUT par `Mail::html()`, que
 * `Mail::fake()`/`assertSentCount()` ne voit jamais (ils ne comptent que les
 * Mailables). La preuve qui fait autorite est `EmailLog` — meme mesure que
 * T1377/T1744 sur ce fichier.
 *
 * ## `Queue::fake()` est necessaire, pas cosmetique
 *
 * `QUEUE_CONNECTION=sync` en test : sans `Queue::fake()`, poser une demande
 * planifie ET livre l'email dans le MEME appel, rendant impossible de
 * regler une preference ENTRE la planification et la livraison — exactement
 * ce que `test_le_reglage_email_coupe_apres_coup_arrete_l_envoi` doit
 * mesurer.
 */
class TASK1775HelpRequestEmailTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $auteur;

    private User $membre;

    private Loop $boucle;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        $this->auteur = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->membre = User::factory()->create([
            'organization_id' => $this->organization->id,
            'preferred_locale' => 'fr',
        ]);

        $this->boucle = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->auteur->id,
            'status' => 'active',
        ]);

        foreach ([$this->auteur, $this->membre] as $personne) {
            LoopMember::create([
                'loop_id' => $this->boucle->id,
                'user_id' => $personne->id,
                'organization_id' => $this->organization->id,
                'role' => $personne->is($this->auteur) ? 'owner' : 'member',
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }
    }

    private function allumerLEmailPourLaBoucle(): void
    {
        $reglage = new LoopNotificationPreference(['collective_enabled' => true, 'email_enabled' => true]);
        $reglage->organization_id = (string) $this->organization->id;
        $reglage->loop_id = (string) $this->boucle->id;
        $reglage->user_id = (string) $this->membre->id;
        $reglage->save();
    }

    /** Pose une demande d'aide et rend la notification du MEMBRE (jamais celle de l'auteur). */
    private function poserUneDemande(): ?MemberNotification
    {
        app(LoopMessageService::class)->sendHelpRequestMessage(
            $this->boucle,
            $this->auteur,
            'J’ai besoin d’un coup de main.',
            'Relecture d’un dossier',
            'Une relecture critique',
            'Dossier à rendre cette semaine',
            'relecture',
        );

        return MemberNotification::query()
            ->where('notification_key', NotificationCatalogue::LOOP_HELP_REQUEST_CREATED)
            ->where('recipient_id', $this->membre->id)
            ->first();
    }

    private function livraisonEmail(string $notificationId): MemberNotificationDelivery
    {
        return MemberNotificationDelivery::query()
            ->where('notification_id', $notificationId)
            ->forChannel(NotificationCatalogue::CHANNEL_EMAIL)
            ->firstOrFail();
    }

    // =====================================================================
    // 1. Le catalogue : le canal existe, et il n'allume RIEN tout seul
    // =====================================================================

    public function test_le_canal_email_est_declare_mais_eteint_par_defaut(): void
    {
        $this->assertTrue(NotificationCatalogue::allowsChannel(
            NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            NotificationCatalogue::CHANNEL_EMAIL,
        ));
        $this->assertFalse(NotificationCatalogue::channelDefault(
            NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            NotificationCatalogue::CHANNEL_EMAIL,
        ));
        $this->assertTrue(NotificationCatalogue::channelIsConfigurable(
            NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            NotificationCatalogue::CHANNEL_EMAIL,
        ));
    }

    /**
     * **La garde contre le bruit de masse.**
     *
     * Ouvrir le canal au catalogue ne doit reveiller personne : sans aucune
     * ligne `LoopNotificationPreference`, la demande d'aide ne doit produire
     * AUCUN envoi reel — seulement une livraison qui conclut honnetement
     * qu'elle n'a rien a faire.
     */
    public function test_sans_rien_regler_aucun_email_ne_part(): void
    {
        $notification = $this->poserUneDemande();
        $this->assertNotNull($notification);

        app(NotificationEmailDeliverer::class)->deliver((string) $notification->id);

        $this->assertSame(0, EmailLog::query()->whereNotNull('notification_id')->count());

        $livraison = $this->livraisonEmail((string) $notification->id);
        $this->assertSame(NotificationDeliveryStatus::SKIPPED_PREFERENCE, $livraison->status);
    }

    // =====================================================================
    // 2. L'allumer Boucle par Boucle, et SEULEMENT alors, part l'email
    // =====================================================================

    public function test_le_membre_qui_a_allume_l_email_pour_la_boucle_le_recoit(): void
    {
        $this->allumerLEmailPourLaBoucle();

        $notification = $this->poserUneDemande();
        $this->assertNotNull($notification);

        app(NotificationEmailDeliverer::class)->deliver((string) $notification->id);

        $journal = EmailLog::query()->whereNotNull('notification_id')->firstOrFail();
        $this->assertSame($this->membre->email, $journal->to_email);

        $livraison = $this->livraisonEmail((string) $notification->id);
        $this->assertSame(NotificationDeliveryStatus::SENT, $livraison->status);
    }

    /** Le corps nomme la Boucle ET l'auteur — le repli generique, lui, ne les connait pas. */
    public function test_le_corps_de_l_email_nomme_la_boucle_et_l_auteur(): void
    {
        $this->allumerLEmailPourLaBoucle();

        $notification = $this->poserUneDemande();
        $this->assertNotNull($notification);

        app(NotificationEmailDeliverer::class)->deliver((string) $notification->id);

        $journal = EmailLog::query()->whereNotNull('notification_id')->firstOrFail();

        $this->assertStringContainsString($this->boucle->name, $journal->subject.$journal->body_html);
        $this->assertStringContainsString($this->auteur->publicDisplayName(), $journal->body_html);
    }

    /** Le maitre de Boucle coupe tout, EMAIL compris — rien n'est meme emis pour ce membre. */
    public function test_le_maitre_de_boucle_eteint_empeche_aussi_l_email(): void
    {
        $reglage = new LoopNotificationPreference(['collective_enabled' => false, 'email_enabled' => true]);
        $reglage->organization_id = (string) $this->organization->id;
        $reglage->loop_id = (string) $this->boucle->id;
        $reglage->user_id = (string) $this->membre->id;
        $reglage->save();

        $notification = $this->poserUneDemande();

        $this->assertNull($notification, 'Une Boucle coupee ne doit produire aucune notification, email y compris.');
        $this->assertSame(0, EmailLog::query()->whereNotNull('notification_id')->count());
    }

    /** Le reglage coupe ENTRE la planification et la livraison arrete l'envoi. */
    public function test_le_reglage_email_coupe_apres_coup_arrete_l_envoi(): void
    {
        $this->allumerLEmailPourLaBoucle();

        $notification = $this->poserUneDemande();
        $this->assertNotNull($notification);

        // Le job est reste en file (`Queue::fake()`) : la livraison planifiee
        // existe, mais rien n'a encore ete envoye.
        $this->assertSame(0, EmailLog::query()->whereNotNull('notification_id')->count());

        LoopNotificationPreference::query()
            ->forOwner((string) $this->membre->id)
            ->where('loop_id', $this->boucle->id)
            ->update(['email_enabled' => false]);

        app(NotificationEmailDeliverer::class)->deliver((string) $notification->id);

        $this->assertSame(0, EmailLog::query()->whereNotNull('notification_id')->count());

        $livraison = $this->livraisonEmail((string) $notification->id);
        $this->assertSame(NotificationDeliveryStatus::SKIPPED_PREFERENCE, $livraison->status);
    }

    /** Le depart de l'Organization annule l'envoi, meme email allume. */
    public function test_le_depart_de_l_organization_annule_l_email(): void
    {
        $this->allumerLEmailPourLaBoucle();

        $notification = $this->poserUneDemande();
        $this->assertNotNull($notification);

        $autre = Organization::factory()->create(['is_active' => true]);
        DB::table('users')->where('id', $this->membre->id)->update(['organization_id' => $autre->id]);

        app(NotificationEmailDeliverer::class)->deliver((string) $notification->id);

        $this->assertSame(0, EmailLog::query()->whereNotNull('notification_id')->count());

        $livraison = $this->livraisonEmail((string) $notification->id);
        $this->assertSame(NotificationDeliveryStatus::SKIPPED_UNREACHABLE, $livraison->status);
    }

    /** Rejouer la livraison apres un premier envoi reussi n'envoie pas une seconde fois. */
    public function test_le_rejeu_apres_envoi_n_envoie_pas_deux_fois(): void
    {
        $this->allumerLEmailPourLaBoucle();

        $notification = $this->poserUneDemande();
        $this->assertNotNull($notification);

        $deliverer = app(NotificationEmailDeliverer::class);

        $deliverer->deliver((string) $notification->id);
        $deliverer->deliver((string) $notification->id);

        $this->assertSame(1, EmailLog::query()->whereNotNull('notification_id')->count(), 'Aucune seconde preuve.');
    }

    public function test_les_deux_langues_portent_le_repli_de_la_cle(): void
    {
        $fr = require lang_path('fr/notifications.php');
        $en = require lang_path('en/notifications.php');

        foreach (['subject', 'body'] as $champ) {
            $this->assertArrayHasKey($champ, $fr['email']['loop_help_request_created']);
            $this->assertArrayHasKey($champ, $en['email']['loop_help_request_created']);
            $this->assertNotSame('', trim((string) $en['email']['loop_help_request_created'][$champ]));
        }
    }
}
