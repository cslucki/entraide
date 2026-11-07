<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1747 (CDC §8.1) — une demande d'aide publiée prévient la Boucle.
 *
 * PREMIER producteur du §8, et le premier événement COLLECTIF du catalogue.
 * Les six clés précédentes s'adressaient à une personne désignée ; celle-ci
 * touche tous les membres d'une Boucle.
 *
 * ## Le test qui compte le plus est négatif
 *
 * `test_un_message_ORDINAIRE_ne_notifie_personne`. Le CDC interdit le Push
 * sur chaque message de ChatLoop, et le piège est connu : brancher
 * l'émission sur l'évènement de message plutôt que sur le TYPE finirait, un
 * jour, par notifier tout le fil. L'émission vit donc là où le type est
 * décidé, et ce test le vérifie plutôt que de le supposer.
 */
class TASK1747HelpRequestNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Loop $boucle;

    private User $auteur;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        $this->auteur = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->membre = User::factory()->create(['organization_id' => $this->organization->id]);

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

    private function service(): LoopMessageService
    {
        return app(LoopMessageService::class);
    }

    private function poserUneDemande(): void
    {
        $this->service()->sendHelpRequestMessage(
            $this->boucle,
            $this->auteur,
            'J’ai besoin d’un coup de main.',
            'Relecture d’un dossier',
            'Une relecture critique',
            'Dossier à rendre cette semaine',
            'relecture',
        );
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, MemberNotification> */
    private function notificationsDeDemande()
    {
        return MemberNotification::query()
            ->where('notification_key', NotificationCatalogue::LOOP_HELP_REQUEST_CREATED)
            ->get();
    }

    public function test_une_demande_d_aide_previent_les_membres_SAUF_son_auteur(): void
    {
        $this->poserUneDemande();

        $notifications = $this->notificationsDeDemande();

        $this->assertCount(1, $notifications, 'un seul membre doit être prévenu');
        $this->assertSame($this->membre->id, $notifications->first()->recipient_id);

        // Se faire prévenir de sa propre demande n'apprend rien.
        $this->assertSame(
            0,
            $this->notificationsDeDemande()->where('recipient_id', $this->auteur->id)->count(),
            "l'auteur ne doit pas être prévenu de sa propre demande",
        );
    }

    public function test_un_message_ORDINAIRE_ne_notifie_personne(): void
    {
        // LA garde de cette TASK. Le CDC interdit de notifier chaque message
        // de ChatLoop ; si l'émission était branchée sur l'évènement de
        // message plutôt que sur le type, ce test rougirait.
        //
        // On couvre les TROIS autres producteurs de messages, pas seulement
        // le message libre : un sondage et un évènement passent par le même
        // `LoopMessageCreated`, et c'est précisément ce qui rendrait un
        // listener dangereux.
        $avant = MemberNotification::query()->count();

        $this->service()->sendUserMessage($this->boucle, $this->auteur, 'Bonjour tout le monde.');

        $sondage = \App\Models\LoopPoll::create([
            'loop_id' => $this->boucle->id,
            'organization_id' => $this->organization->id,
            'created_by' => $this->auteur->id,
            'question' => 'Quel créneau vous convient ?',
            'options' => ['Lundi', 'Mardi'],
            'status' => 'open',
        ]);
        $this->service()->sendPollEventMessage($this->boucle, $this->auteur, $sondage, 'created');

        $this->assertSame(
            $avant,
            MemberNotification::query()->count(),
            'aucun message ordinaire — libre ou de sondage — ne doit produire de notification',
        );
    }

    public function test_la_notification_reste_dans_l_Organization_de_la_Boucle(): void
    {
        // Une Boucle n'est pas un Tenant : la borne se pose à la main, et on
        // la mesure. Un membre d'une AUTRE Organization — cas impossible par
        // le produit, mais c'est précisément ce qu'une garde doit couvrir —
        // ne doit pas être atteint.
        $autre = Organization::factory()->create(['is_active' => true]);
        $etranger = User::factory()->create(['organization_id' => $autre->id]);

        LoopMember::create([
            'loop_id' => $this->boucle->id,
            'user_id' => $etranger->id,
            'organization_id' => $autre->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->poserUneDemande();

        $destinataires = $this->notificationsDeDemande()->pluck('recipient_id')->all();

        $this->assertNotContains(
            $etranger->id,
            $destinataires,
            "une appartenance d'une autre Organization ne doit pas être atteinte",
        );

        foreach ($this->notificationsDeDemande() as $notification) {
            $this->assertSame((string) $this->organization->id, (string) $notification->organization_id);
        }
    }

    public function test_un_membre_INACTIF_n_est_pas_prevenu(): void
    {
        LoopMember::query()
            ->where('loop_id', $this->boucle->id)
            ->where('user_id', $this->membre->id)
            ->update(['status' => 'pending']);

        $this->poserUneDemande();

        $this->assertCount(
            0,
            $this->notificationsDeDemande(),
            'une appartenance non active ne doit pas recevoir',
        );
    }

    public function test_la_PROJECTION_d_une_Demande_canonique_previent_aussi(): void
    {
        // Du point de vue des membres, c'est le même fait : un besoin vient
        // d'apparaître dans leur Boucle. Que la Demande ait été créée
        // ailleurs ne change rien — ils ne l'avaient pas vue.
        $demande = \App\Models\ServiceRequest::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->auteur->id,
            'status' => 'open',
        ]);

        $this->service()->sendServiceRequestProjection($this->boucle, $this->auteur, $demande);

        $this->assertCount(1, $this->notificationsDeDemande());
    }

    public function test_le_canal_WEB_PUSH_est_declare_mais_ETEINT(): void
    {
        // Décision MASTER du 03/11. Le canal doit EXISTER — pour que la
        // personne puisse l'allumer, et pour que l'ouvrir un jour ne demande
        // aucun autre changement — mais il ne doit réveiller personne tant
        // que la sourdine par Boucle (§7B) n'existe pas.
        $cle = NotificationCatalogue::LOOP_HELP_REQUEST_CREATED;

        $this->assertTrue(
            NotificationCatalogue::allowsChannel($cle, NotificationCatalogue::CHANNEL_WEB_PUSH),
            'le canal doit être déclaré',
        );

        $this->assertFalse(
            NotificationCatalogue::channelDefault($cle, NotificationCatalogue::CHANNEL_WEB_PUSH),
            'un premier producteur COLLECTIF ne doit pas pousser par défaut',
        );

        $this->assertTrue(
            NotificationCatalogue::channelIsConfigurable($cle, NotificationCatalogue::CHANNEL_WEB_PUSH),
            'la personne doit pouvoir l’allumer elle-même',
        );
    }

    public function test_l_in_app_est_CONFIGURABLE_contrairement_aux_cles_personnelles(): void
    {
        // Une invitation n'arrive qu'une fois et appelle une réponse : on ne
        // s'en désabonne pas. Une demande d'aide est RÉCURRENTE — c'est le
        // premier événement du catalogue dont on peut vouloir se taire sans
        // quitter la Boucle.
        $cle = NotificationCatalogue::LOOP_HELP_REQUEST_CREATED;

        $this->assertTrue(NotificationCatalogue::channelDefault($cle, NotificationCatalogue::CHANNEL_IN_APP));
        $this->assertTrue(NotificationCatalogue::channelIsConfigurable($cle, NotificationCatalogue::CHANNEL_IN_APP));

        // Et l'invitation, elle, reste obligatoire : la comparaison est le
        // point du test.
        $this->assertFalse(NotificationCatalogue::channelIsConfigurable(
            NotificationCatalogue::LOOP_INVITATION,
            NotificationCatalogue::CHANNEL_IN_APP,
        ));
    }

    public function test_la_cle_rend_la_TROISIEME_section_du_7A_reelle(): void
    {
        // Le §7A avait provisionné « sollicitations pertinentes » sans aucune
        // clé, et l'écran la masquait. Cette TASK la remplit.
        $parIntention = NotificationCatalogue::keysByIntention();

        $this->assertContains(
            NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            $parIntention[NotificationCatalogue::INTENTION_SOLLICITATION_PERTINENTE],
        );

        $this->actingAs($this->membre)
            ->get('/notifications/preferences')
            ->assertOk()
            ->assertSee('data-preference-intention="'.NotificationCatalogue::INTENTION_SOLLICITATION_PERTINENTE.'"', false);
    }

    public function test_une_preference_ETEINTE_coupe_la_notification_in_app(): void
    {
        // L'in-app étant configurable, l'éteindre doit réellement couper.
        // Sans ce test, « configurable » serait une case décorative.
        // `user_id` n'est volontairement PAS mass-assignable sur ce modèle :
        // l'identité du propriétaire d'une préférence ne doit jamais venir
        // d'un tableau de requête. On le pose donc explicitement, comme le
        // contrôleur le fait côté serveur.
        $preference = new \App\Models\MemberNotificationPreference([
            'notification_key' => NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            'channel' => NotificationCatalogue::CHANNEL_IN_APP,
            'enabled' => false,
        ]);
        $preference->user_id = $this->membre->id;
        $preference->save();

        $resolveur = app(\App\Support\Notifications\NotificationPreferenceResolver::class);

        $this->assertFalse($resolveur->allows(
            $this->membre->fresh(),
            NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            NotificationCatalogue::CHANNEL_IN_APP,
        ));
    }

    /**
     * TASK-1775 — meme preference qu'au test precedent, mais mesuree BOUT EN
     * BOUT.
     *
     * Le test precedent ne verifiait que le resolveur, ISOLEMENT : rien ne
     * prouvait qu'une demande d'aide REELLEMENT publiee respecte ce reglage.
     * C'est exactement le defaut que l'audit prealable a la TASK-1775 a
     * signale — l'in-app n'etait filtre par AUCUNE preference, ni a
     * l'emission ni a l'affichage. Desormais corrige dans
     * `LoopMessageService::notifierLaDemandeDAide()` : AUCUNE
     * `MemberNotification` ne doit meme etre creee pour ce membre.
     */
    public function test_une_preference_globale_ETEINTE_empeche_la_creation_de_la_notification(): void
    {
        $preference = new \App\Models\MemberNotificationPreference([
            'notification_key' => NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            'channel' => NotificationCatalogue::CHANNEL_IN_APP,
            'enabled' => false,
        ]);
        $preference->user_id = $this->membre->id;
        $preference->save();

        $this->poserUneDemande();

        $this->assertSame(
            0,
            MemberNotification::query()
                ->where('notification_key', NotificationCatalogue::LOOP_HELP_REQUEST_CREATED)
                ->where('recipient_id', $this->membre->id)
                ->count(),
            'Le reglage global in_app coupe doit empecher la creation meme de la ligne.'
        );
    }

    /**
     * TASK-1775 (CDC C4) — le libelle nomme la Boucle, exactement comme ses
     * voisines (T1725).
     *
     * Avant cette TASK, `keys_named.loop_help_request_created` n'existait pas :
     * le resolveur de sujet (`sujetsDesBoucles()`) couvrait deja cette cle —
     * `object_type` vaut `OBJECT_LOOP` — mais faute de forme nommee, le
     * Centre retombait silencieusement sur le libelle generique.
     */
    public function test_le_centre_nomme_la_boucle_dans_le_libelle(): void
    {
        $this->boucle->forceFill(['name' => 'Boucle Entraide'])->save();

        $this->poserUneDemande();

        $this->actingAs($this->membre)->get(route('notifications.index', ['filtre' => 'toutes']))
            ->assertOk()
            ->assertSee('Une demande d’aide a été publiée dans la Boucle « Boucle Entraide »');
    }
}
