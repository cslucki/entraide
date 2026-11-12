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

    /**
     * TASK-1802 (demande Cyril, 10/11, mesure en recette sur une AUTRE
     * cle de ce meme producteur) — une adhesion ACTIVE peut survivre au
     * depart de son titulaire vers une AUTRE Organization (ligne
     * `loop_members` non nettoyee). Sans garde, `NotificationEmitter::
     * emit()` refuse ce destinataire (fail-closed sur la frontiere de
     * tenant) et son exception, non rattrapee, annulait la transaction
     * ENTIERE : ni message ChatLoop, ni notification pour PERSONNE, a cause
     * d'UNE seule ligne perimee.
     */
    public function test_un_membre_dont_l_organization_a_change_ne_casse_pas_toute_lannonce(): void
    {
        $parti = User::factory()->create(['organization_id' => Organization::factory()->create(['is_active' => true])->id]);
        LoopMember::create([
            'loop_id' => $this->boucle->id,
            'user_id' => $parti->id,
            'organization_id' => $this->organization->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->poserUneDemande();

        $notifications = $this->notificationsDeDemande();
        $this->assertCount(1, $notifications, 'le membre toujours dans la bonne Organization doit être prévenu');
        $this->assertSame($this->membre->id, $notifications->first()->recipient_id);
        $this->assertNotContains(
            $parti->id,
            $notifications->pluck('recipient_id')->all(),
            'le membre parti vers une autre Organization ne doit pas recevoir de notification',
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

    /**
     * TASK-1775 (arbitrage Cyril, 07/11) — ce test s'appelait
     * `test_le_canal_WEB_PUSH_est_declare_mais_ETEINT` et verrouillait la
     * decision MASTER du 03/11 (canal declare, mais eteint par defaut tant
     * qu'aucune sourdine par Boucle n'existait). Cyril est revenu sur ce
     * point apres avoir vu l'ecran "Mes Boucles" en vrai : la sourdine
     * EXISTE desormais (T1775 B), et il a choisi l'opt-OUT plutot que
     * l'opt-IN. La promesse — « le canal est declare et son defaut est une
     * decision VOLONTAIRE, pas un oubli » — ne change pas ; seule la valeur
     * choisie s'inverse.
     */
    public function test_le_canal_WEB_PUSH_est_declare_et_ACTIF_par_defaut(): void
    {
        $cle = NotificationCatalogue::LOOP_HELP_REQUEST_CREATED;

        $this->assertTrue(
            NotificationCatalogue::allowsChannel($cle, NotificationCatalogue::CHANNEL_WEB_PUSH),
            'le canal doit être déclaré',
        );

        $this->assertTrue(
            NotificationCatalogue::channelDefault($cle, NotificationCatalogue::CHANNEL_WEB_PUSH),
            'arbitrage du 07/11 : opt-OUT plutôt qu\'opt-IN, la sourdine par Boucle rendant ce défaut tenable',
        );

        $this->assertTrue(
            NotificationCatalogue::channelIsConfigurable($cle, NotificationCatalogue::CHANNEL_WEB_PUSH),
            'la personne doit pouvoir le couper elle-même, Boucle par Boucle',
        );
    }

    /**
     * TASK-1775 (arbitrage Cyril, 07/11) — ce test s'appelait
     * `test_l_in_app_est_CONFIGURABLE_contrairement_aux_cles_personnelles`
     * et affirmait l'inverse exact de ce qu'il affirme maintenant. Le §7B
     * avait rendu l'in-app configurable pour permettre de « se taire sans
     * quitter la Boucle » ; Cyril est revenu sur ce point une fois livre
     * l'interrupteur general que chaque membre possede sur chacune de ses
     * Boucles (`LoopNotificationPreference::collective_enabled`) — c'est LUI
     * le vrai levier de calme desormais, pas un reglage global sur le seul
     * canal in-app. L'in-app redevient donc OBLIGATOIRE, exactement comme les
     * six cles personnelles.
     *
     * TASK-1778 — cet interrupteur est INDIVIDUEL : la ligne appartient a un
     * membre (`forOwner`), personne ne coupe une Boucle pour les autres.
     */
    public function test_l_in_app_est_desormais_OBLIGATOIRE_comme_les_cles_personnelles(): void
    {
        $cle = NotificationCatalogue::LOOP_HELP_REQUEST_CREATED;

        $this->assertTrue(NotificationCatalogue::channelDefault($cle, NotificationCatalogue::CHANNEL_IN_APP));
        $this->assertFalse(
            NotificationCatalogue::channelIsConfigurable($cle, NotificationCatalogue::CHANNEL_IN_APP),
            'arbitrage du 07/11 : l interrupteur general du membre sur cette Boucle est desormais le seul levier de calme.',
        );

        // Et l'invitation partage desormais le meme regime — la comparaison
        // qui faisait le point du test avant son renversement n'a plus lieu
        // d'etre, mais la garde reste utile : les deux cles personnelles et
        // collective sont OBLIGATOIRES en in-app.
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

    /**
     * TASK-1775 (arbitrage Cyril, 07/11) — ce test s'appelait
     * `test_une_preference_ETEINTE_coupe_la_notification_in_app` et
     * verrouillait le contraire : que l'in-app, alors configurable, se
     * laissait couper par un ecart stocke. L'in-app etant redevenu
     * OBLIGATOIRE, la garde a verrouiller est desormais l'INVERSE — meme
     * doctrine que T1375 pour les cles personnelles
     * (`test_a_row_written_straight_into_the_database_cannot_disable_a_mandatory_channel`) :
     * un ecart stocke sur un canal obligatoire n'a AUCUN effet, meme ecrit
     * directement en base.
     */
    public function test_une_preference_in_app_stockee_n_a_plus_aucun_effet_canal_obligatoire(): void
    {
        // Le modele refuse desormais d'ECRIRE un tel ecart (garde
        // structurelle de `NotificationPreferenceInvariants`) : simuler une
        // ligne LAISSEE par une epoque ou ce canal etait configurable exige
        // de contourner Eloquent, comme le feraient un import ou une base
        // heritee.
        \Illuminate\Support\Facades\DB::table('member_notification_preferences')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->membre->id,
            'notification_key' => NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            'channel' => NotificationCatalogue::CHANNEL_IN_APP,
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resolveur = app(\App\Support\Notifications\NotificationPreferenceResolver::class);

        $this->assertTrue($resolveur->allows(
            $this->membre->fresh(),
            NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            NotificationCatalogue::CHANNEL_IN_APP,
        ), 'Un canal obligatoire ignore tout ecart stocke — meme ecrit directement en base.');
    }

    /**
     * TASK-1775 — meme renversement, mesure BOUT EN BOUT : une demande d'aide
     * REELLEMENT publiee continue de creer la notification du membre, malgre
     * l'ecart stocke, puisque ce canal ne se laisse plus couper globalement.
     * Seul l'interrupteur general du membre sur cette Boucle (teste dans
     * TASK1775LoopNotificationPreferencesTest) peut desormais l'empecher.
     */
    public function test_une_preference_in_app_stockee_n_empeche_plus_la_creation_de_la_notification(): void
    {
        // Meme contournement que ci-dessus : ce chemin d'ecriture n'existe
        // plus par l'application, seulement comme residu possible.
        \Illuminate\Support\Facades\DB::table('member_notification_preferences')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->membre->id,
            'notification_key' => NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            'channel' => NotificationCatalogue::CHANNEL_IN_APP,
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->poserUneDemande();

        $this->assertSame(
            1,
            MemberNotification::query()
                ->where('notification_key', NotificationCatalogue::LOOP_HELP_REQUEST_CREATED)
                ->where('recipient_id', $this->membre->id)
                ->count(),
            'Un canal obligatoire ne se laisse plus couper par un ecart global : la notification doit exister.'
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
