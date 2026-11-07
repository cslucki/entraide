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
 * ## Arbitrage Cyril du 07/11 — opt-OUT, pas opt-IN
 *
 * Ce fichier defendait a l'origine l'inverse : « ouvrir un canal au catalogue
 * ne doit RIEN declencher pour qui n'a rien demande ». Cyril est revenu sur
 * ce point APRES avoir vu l'ecran "Mes Boucles" en vrai — une fois la
 * sourdine par Boucle livree, il a juge le silence par defaut trop
 * invisible, et choisi l'opt-OUT : chaque membre d'une Boucle recoit
 * desormais un email par demande d'aide, sauf s'il le desactive pour CETTE
 * Boucle precise. La garde qui compte desormais est donc la SYMETRIQUE de
 * l'ancienne : `test_sans_rien_regler_l_email_part_quand_meme` verrouille
 * que le defaut ENVOIE, et `test_le_membre_peut_desactiver_l_email_pour_une_boucle`
 * verrouille que l'opt-OUT fonctionne reellement.
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

    /**
     * TASK-1775 (arbitrage Cyril, 07/11) — ce test s'appelait
     * `test_le_canal_email_est_declare_mais_eteint_par_defaut`. Cyril a
     * choisi l'opt-OUT apres avoir vu l'ecran "Mes Boucles" en vrai.
     */
    public function test_le_canal_email_est_declare_et_ACTIF_par_defaut(): void
    {
        $this->assertTrue(NotificationCatalogue::allowsChannel(
            NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            NotificationCatalogue::CHANNEL_EMAIL,
        ));
        $this->assertTrue(NotificationCatalogue::channelDefault(
            NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            NotificationCatalogue::CHANNEL_EMAIL,
        ));
        $this->assertTrue(NotificationCatalogue::channelIsConfigurable(
            NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            NotificationCatalogue::CHANNEL_EMAIL,
        ));
    }

    /**
     * **La garde qui remplace l'ancienne garde contre le bruit de masse.**
     *
     * TASK-1775 (arbitrage Cyril, 07/11) — ce test s'appelait
     * `test_sans_rien_regler_aucun_email_ne_part` et verrouillait l'inverse
     * exact : que RIEN ne parte sans reglage explicite. Cyril est revenu sur
     * ce point en voyant l'ecran reel : sans aucune ligne
     * `LoopNotificationPreference`, une demande d'aide doit desormais
     * produire un envoi REEL — c'est le defaut produit, pas une anomalie.
     */
    public function test_sans_rien_regler_l_email_part_quand_meme_par_defaut(): void
    {
        $notification = $this->poserUneDemande();
        $this->assertNotNull($notification);

        app(NotificationEmailDeliverer::class)->deliver((string) $notification->id);

        $journal = EmailLog::query()->whereNotNull('notification_id')->firstOrFail();
        $this->assertSame($this->membre->email, $journal->to_email);

        $livraison = $this->livraisonEmail((string) $notification->id);
        $this->assertSame(NotificationDeliveryStatus::SENT, $livraison->status);
    }

    // =====================================================================
    // 2. L'opt-OUT : desactiver l'email pour UNE Boucle precise
    // =====================================================================

    /** Confirme l'opt-OUT explicite : le resultat est le MEME que le defaut, et c'est le point. */
    public function test_le_membre_qui_a_explicitement_allume_l_email_pour_la_boucle_le_recoit(): void
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

    /**
     * **La garde qui prouve que l'opt-OUT fonctionne reellement.**
     *
     * Le defaut etant desormais ACTIF, il faut verifier separement qu'un
     * membre peut encore se taire — sans cela, "configurable => true" serait
     * une case decorative.
     */
    public function test_le_membre_peut_desactiver_l_email_pour_une_boucle_precise(): void
    {
        $reglage = new LoopNotificationPreference(['collective_enabled' => true, 'email_enabled' => false]);
        $reglage->organization_id = (string) $this->organization->id;
        $reglage->loop_id = (string) $this->boucle->id;
        $reglage->user_id = (string) $this->membre->id;
        $reglage->save();

        $notification = $this->poserUneDemande();

        // L'in-app reste livre : seul l'email est coupe. La notification
        // existe donc, et c'est ce qui rend l'absence de livraison EMAIL
        // significative — sinon le test serait vert faute de notification.
        $this->assertNotNull($notification);

        // TASK-1778 — l'opt-OUT se prouve desormais par l'ABSENCE de travail.
        //
        // Avant : une livraison EMAIL etait creee, un job mis en file, et le
        // livreur concluait en `skipped_preference` apres avoir relu la
        // preference — 12 requetes et deux ecritures pour un email que
        // personne n'allait recevoir. Le planificateur consulte maintenant
        // les preferences : rien n'est planifie du tout.
        //
        // L'assertion est donc PLUS forte qu'avant, pas plus faible : « aucune
        // ligne » implique « aucun envoi », tandis que « une ligne refusee »
        // laissait le travail exister.
        $this->assertSame(
            0,
            MemberNotificationDelivery::query()
                ->where('notification_id', $notification->id)
                ->forChannel(NotificationCatalogue::CHANNEL_EMAIL)
                ->count(),
            'Un email coupe pour cette Boucle ne doit produire aucune livraison a planifier.'
        );

        app(NotificationEmailDeliverer::class)->deliver((string) $notification->id);

        $this->assertSame(0, EmailLog::query()->whereNotNull('notification_id')->count());
    }

    /** Le corps nomme la Boucle ET l'auteur — le repli generique, lui, ne les connait pas. */
    public function test_le_corps_de_l_email_nomme_la_boucle_et_l_auteur(): void
    {
        $this->allumerLEmailPourLaBoucle();

        $notification = $this->poserUneDemande();
        $this->assertNotNull($notification);

        app(NotificationEmailDeliverer::class)->deliver((string) $notification->id);

        $journal = EmailLog::query()->whereNotNull('notification_id')->firstOrFail();

        // `e()` : un nom tire par Faker peut porter une apostrophe (~1,55 %
        // du temps — O'Connell, O'Hara...), que le rendu echappe en HTML.
        // Comparer la forme BRUTE romprait ce test au hasard (TASK-1147).
        $this->assertStringContainsString($this->boucle->name, $journal->subject.$journal->body_html);
        $this->assertStringContainsString(e($this->auteur->publicDisplayName()), $journal->body_html);
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
