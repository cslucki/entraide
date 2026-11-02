<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\MemberNotificationPreference;
use App\Models\Organization;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\LoopInvitationService;
use App\Services\Push\PushSubscriptionStore;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationDeliveryStatus;
use App\Support\Notifications\NotificationWebPushDeliverer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TASK-1744 — l'ACTIVATION du canal `web_push` sur des evenements qui
 * existaient deja.
 *
 * ## Ce qui est mesure ici, et ce qui ne l'est pas
 *
 * T1740 a livre et mesure le TRANSPORT : chiffrement, en-tetes VAPID,
 * rembourrage RFC 8188, urgence, 404/410, quota d'appareils. Rien de cela
 * n'est re-mesure ici.
 *
 * Ce qui est neuf, et seul objet de cette suite, c'est la DECISION PRODUIT :
 * quelles cles ont le droit de pousser, lesquelles ne l'ont pas, et ce qu'un
 * evenement metier reel produit desormais de bout en bout. Jusqu'a cette
 * TASK, `NotificationDeliveryPlanner::canauxALivrer()` rendait un tableau
 * VIDE pour toute notification : la machinerie tournait a vide.
 *
 * ## La propriete qui compte : UN evenement, TROIS modes de livraison
 *
 * CDC 6.1. Un Push n'est pas une seconde notification : c'est un troisieme
 * mode de livraison de la MEME `MemberNotification`. Les tests comptent donc
 * les notifications autant que les livraisons — un doublon d'evenement metier
 * serait exactement le defaut que le CDC interdit.
 */
class TASK1744WebPushBusinessEventsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les quatre cles arbitrees, d'apres la matrice du CDC 6.2.
     *
     * Ecrite A LA MAIN, et non derivee du catalogue : un test qui lirait le
     * catalogue pour verifier le catalogue passerait quoi qu'on y mette.
     *
     * @var list<string>
     */
    private const CLES_POUSSEES = [
        NotificationCatalogue::LOOP_INVITATION,
        NotificationCatalogue::LOOP_MEMBER_ADDED,
        NotificationCatalogue::LOOP_JOIN_REQUEST_ACCEPTED,
        NotificationCatalogue::BLOG_CO_AUTHOR_ADDED,
    ];

    /**
     * Les cles ECARTEES de la V1, et c'est un arbitrage, pas un oubli.
     *
     * @var list<string>
     */
    private const CLES_ECARTEES = [
        NotificationCatalogue::LOOP_JOIN_REQUEST_REJECTED,
        NotificationCatalogue::BLOG_COMMENT_CREATED,
    ];

    private Organization $org;

    private User $expediteur;

    private User $membre;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('webpush.enabled', true);
        config()->set('webpush.vapid.subject', 'mailto:contact@exemple.test');

        $this->org = Organization::factory()->create(['name' => 'T1744 Organization', 'is_active' => true]);
        $this->expediteur = User::factory()->create(['organization_id' => $this->org->id]);
        $this->membre = User::factory()->create([
            'organization_id' => $this->org->id,
            'preferred_locale' => 'fr',
        ]);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->org->id,
            'created_by' => $this->expediteur->id,
            'status' => 'active',
        ]);

        // La configuration de test met la queue en `sync` : sans cela l'envoi
        // partirait DES `invite()`, avant toute mutation que le test veut
        // mesurer. Chaque test declenche donc la livraison EXPLICITEMENT.
        Queue::fake();
    }

    // =====================================================================
    // 1. La matrice d'eligibilite — CDC 6.2
    // =====================================================================

    public function test_les_quatre_evenements_arbitres_autorisent_le_push(): void
    {
        foreach (self::CLES_POUSSEES as $cle) {
            $this->assertTrue(
                NotificationCatalogue::allowsChannel($cle, NotificationCatalogue::CHANNEL_WEB_PUSH),
                "[{$cle}] doit autoriser le Push.",
            );
        }
    }

    public function test_les_evenements_ecartes_restent_SANS_push(): void
    {
        // CDC 6.2 : « plutot non au lancement » pour le refus d'adhesion,
        // « plutot non par defaut » pour le commentaire de blog. Un refus
        // n'ouvre aucune porte ; un commentaire ne vous est pas adresse.
        foreach (self::CLES_ECARTEES as $cle) {
            $this->assertFalse(
                NotificationCatalogue::allowsChannel($cle, NotificationCatalogue::CHANNEL_WEB_PUSH),
                "[{$cle}] ne doit PAS pousser.",
            );
        }
    }

    public function test_aucun_push_n_est_allume_a_la_place_de_quelqu_un(): void
    {
        // La propriete la plus importante du lot. Un Push sort de
        // l'application et atterrit sur un ecran VERROUILLE : il exige trois
        // gestes de la personne — permission systeme, abonnement de
        // l'appareil, puis ce reglage — et aucun ne peut etre fait pour elle.
        //
        // Ecrit sur TOUTE cle du catalogue, et pas seulement sur les quatre :
        // c'est la regle du canal, elle doit tenir pour celles qu'on ouvrira
        // plus tard sans que personne ne pense a revenir ici.
        foreach (NotificationCatalogue::keys() as $cle) {
            if (! NotificationCatalogue::allowsChannel($cle, NotificationCatalogue::CHANNEL_WEB_PUSH)) {
                continue;
            }

            $this->assertFalse(
                NotificationCatalogue::channelDefault($cle, NotificationCatalogue::CHANNEL_WEB_PUSH),
                "[{$cle}] le Push ne doit JAMAIS etre actif par defaut.",
            );
            $this->assertTrue(
                NotificationCatalogue::channelIsConfigurable($cle, NotificationCatalogue::CHANNEL_WEB_PUSH),
                "[{$cle}] le Push ne doit JAMAIS etre obligatoire.",
            );
        }
    }

    public function test_le_catalogue_n_a_gagne_AUCUNE_cle(): void
    {
        // Cette TASK ouvre un canal, elle ne cree aucun evenement. Un
        // inventaire figé le dit mieux qu'une intention : si une cle apparait
        // — un message de ChatLoop, une demande d'aide, un rappel — ce test
        // rougit, et la decision se prend dans la TASK qui l'introduit.
        $attendues = [
            NotificationCatalogue::LOOP_INVITATION,
            NotificationCatalogue::LOOP_MEMBER_ADDED,
            NotificationCatalogue::LOOP_JOIN_REQUEST_ACCEPTED,
            NotificationCatalogue::LOOP_JOIN_REQUEST_REJECTED,
            NotificationCatalogue::BLOG_COMMENT_CREATED,
            NotificationCatalogue::BLOG_CO_AUTHOR_ADDED,
        ];

        sort($attendues);
        $observees = NotificationCatalogue::keys();
        sort($observees);

        $this->assertSame($attendues, $observees);
    }

    public function test_aucun_message_ordinaire_de_chatloop_ne_pousse(): void
    {
        // CDC 6.2 : « Message ordinaire de ChatLoop → Non par defaut »,
        // au titre de l'anti-bruit. Le produit ne doit pas devenir une
        // sollicitation permanente. Aucune cle de ce domaine n'existe, et la
        // garde porte sur le DOMAINE, pas sur un nom exact : elle vaudra
        // encore si quelqu'un nomme la sienne `chatloop.post.created`.
        foreach (NotificationCatalogue::keys() as $cle) {
            if (! NotificationCatalogue::allowsChannel($cle, NotificationCatalogue::CHANNEL_WEB_PUSH)) {
                continue;
            }

            $this->assertStringNotContainsString('chatloop', $cle);
            $this->assertStringNotContainsString('message', $cle);
        }
    }

    public function test_chaque_cle_poussee_porte_un_titre_PROPRE_dans_les_deux_locales(): void
    {
        // Le livreur retombe sur « BouclePro » quand le titre manque. C'est un
        // repli sain, mais il ne dit rien : une personne reveillee doit savoir
        // de quoi on lui parle avant de deverrouiller son telephone.
        foreach (self::CLES_POUSSEES as $cle) {
            $chemin = 'notifications.push_title.'.str_replace('.', '_', $cle);

            foreach (['fr', 'en'] as $locale) {
                $this->assertTrue(
                    Lang::has($chemin, $locale),
                    "[{$locale}] titre de Push manquant pour [{$cle}].",
                );
                $this->assertNotSame(
                    __('notifications.push_title_fallback', [], $locale),
                    __($chemin, [], $locale),
                    "[{$locale}] [{$cle}] doit avoir son propre titre.",
                );
            }
        }
    }

    public function test_aucun_titre_de_push_ne_nomme_une_boucle_ni_une_personne(): void
    {
        // CDC 6.4 : la charge utile reste vraie devant n'importe qui. Les
        // titres sont des chaines FIXES, sans aucun parametre : un
        // `:nom` ou `:boucle` y ouvrirait la porte a une fuite sur un ecran
        // verrouille. Le libelle du Centre, lui, nomme la Boucle depuis
        // T1725 — et c'est precisement la difference.
        foreach (self::CLES_POUSSEES as $cle) {
            $chemin = 'notifications.push_title.'.str_replace('.', '_', $cle);

            foreach (['fr', 'en'] as $locale) {
                $this->assertStringNotContainsString(':', __($chemin, [], $locale));
            }
        }
    }

    // =====================================================================
    // 2. Le chemin REEL d'une invitation — l'evenement de recette
    // =====================================================================

    public function test_une_invitation_planifie_le_push_EN_PLUS_de_l_email(): void
    {
        $this->inviter();

        $livraisons = MemberNotificationDelivery::query()
            ->whereHas('notification', fn ($q) => $q->where('recipient_id', $this->membre->id))
            ->pluck('channel')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            [NotificationCatalogue::CHANNEL_EMAIL, NotificationCatalogue::CHANNEL_WEB_PUSH],
            $livraisons,
            'Les DEUX canaux asynchrones doivent etre planifies.',
        );
    }

    public function test_le_push_ne_DOUBLE_pas_l_evenement_metier(): void
    {
        // CDC 6.1. C'est la propriete structurante : `in_app` reste porte par
        // l'EXISTENCE de la notification, et le Push est un mode de livraison
        // de celle-la. Une seconde ligne serait un systeme parallele.
        $this->inviter();

        $this->assertSame(
            1,
            MemberNotification::query()->where('recipient_id', $this->membre->id)->count(),
            'Un evenement metier reste UNE notification.',
        );

        // Et aucune livraison IN_APP n'est fabriquee : cette table decrit le
        // travail qui reste A FAIRE, et l'in-app est deja livre.
        $this->assertSame(
            0,
            MemberNotificationDelivery::query()
                ->whereHas('notification', fn ($q) => $q->where('recipient_id', $this->membre->id))
                ->forChannel(NotificationCatalogue::CHANNEL_IN_APP)
                ->count(),
        );
    }

    public function test_sans_le_reglage_du_membre_RIEN_ne_part(): void
    {
        // Le defaut est OFF : un membre abonne qui n'a rien regle ne doit rien
        // recevoir. C'est la garde que `default => false` achete, et elle se
        // mesure avec un appareil REELLEMENT abonne — sinon le test serait
        // vert faute d'appareil, pas faute de reglage.
        Http::fake(['*' => Http::response('', 201)]);
        $this->appareil();

        $livraison = $this->livraisonPush($this->inviter());

        app(NotificationWebPushDeliverer::class)->deliver((string) $livraison->notification_id);

        $this->assertSame(NotificationDeliveryStatus::SKIPPED_PREFERENCE, $livraison->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_avec_le_reglage_et_un_appareil_le_push_part_vraiment(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $appareil = $this->appareil();
        $this->allumerLePush(NotificationCatalogue::LOOP_INVITATION);

        $livraison = $this->livraisonPush($this->inviter());

        app(NotificationWebPushDeliverer::class)->deliver((string) $livraison->notification_id);

        Http::assertSent(fn ($request) => $request->url() === $appareil->endpoint);

        $fraiche = $livraison->fresh();
        $this->assertSame(NotificationDeliveryStatus::SENT, $fraiche->status);
        $this->assertSame('devices:1', $fraiche->diagnostic);
        $this->assertNotNull($fraiche->sent_at);
    }

    public function test_le_reglage_coupe_APRES_coup_arrete_le_push(): void
    {
        // Un abonnement peut avoir ete pris il y a des semaines, et la
        // livraison attend dans une file. La preference est donc relue AU
        // MOMENT DE L'ENVOI, jamais supposee depuis la creation.
        Http::fake(['*' => Http::response('', 201)]);

        $this->appareil();
        $this->allumerLePush(NotificationCatalogue::LOOP_INVITATION);

        $livraison = $this->livraisonPush($this->inviter());

        // La personne change d'avis entre la planification et le worker.
        MemberNotificationPreference::query()
            ->where('user_id', $this->membre->id)
            ->where('channel', NotificationCatalogue::CHANNEL_WEB_PUSH)
            ->update(['enabled' => false]);

        app(NotificationWebPushDeliverer::class)->deliver((string) $livraison->notification_id);

        $this->assertSame(NotificationDeliveryStatus::SKIPPED_PREFERENCE, $livraison->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_le_depart_de_l_organization_annule_le_push(): void
    {
        // CDC 6.5 : « depart de l'Organization : zero envoi apres revocation ».
        Http::fake(['*' => Http::response('', 201)]);

        $this->appareil();
        $this->allumerLePush(NotificationCatalogue::LOOP_INVITATION);

        $livraison = $this->livraisonPush($this->inviter());

        // Hors Eloquent : le scope de tenant ne doit pas masquer la mutation
        // qu'on est en train de poser.
        $autre = Organization::factory()->create(['is_active' => true]);
        DB::table('users')->where('id', $this->membre->id)->update(['organization_id' => $autre->id]);

        app(NotificationWebPushDeliverer::class)->deliver((string) $livraison->notification_id);

        $this->assertSame(NotificationDeliveryStatus::SKIPPED_UNREACHABLE, $livraison->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_sans_appareil_abonne_la_livraison_conclut_proprement(): void
    {
        // Le cas le plus courant au lancement : la personne a allume le
        // reglage mais n'a pas encore abonne de telephone. Ce n'est pas un
        // echec de notre cote, et la livraison ne doit pas rester `pending`.
        Http::fake(['*' => Http::response('', 201)]);

        $this->allumerLePush(NotificationCatalogue::LOOP_INVITATION);

        $livraison = $this->livraisonPush($this->inviter());

        app(NotificationWebPushDeliverer::class)->deliver((string) $livraison->notification_id);

        $this->assertSame(NotificationDeliveryStatus::SKIPPED_UNREACHABLE, $livraison->fresh()->status);
        Http::assertNothingSent();
    }

    // =====================================================================
    // 3. L'ecran de reglages — derive du catalogue, sans code ajoute
    // =====================================================================

    public function test_l_ecran_expose_le_nouveau_canal_sur_les_quatre_cles(): void
    {
        $reponse = $this->actingAs($this->membre)->get(route('notifications.preferences.edit'));

        $reponse->assertOk();

        foreach (self::CLES_POUSSEES as $cle) {
            $reponse->assertSee('data-preference-channel="'.$cle.':'.NotificationCatalogue::CHANNEL_WEB_PUSH.'"', false);
        }

        foreach (self::CLES_ECARTEES as $cle) {
            $reponse->assertDontSee('data-preference-channel="'.$cle.':'.NotificationCatalogue::CHANNEL_WEB_PUSH.'"', false);
        }
    }

    public function test_le_membre_peut_allumer_le_push_depuis_l_ecran(): void
    {
        $this->actingAs($this->membre)
            ->post(route('notifications.preferences.update'), [
                'canaux' => [
                    NotificationCatalogue::LOOP_INVITATION => [
                        NotificationCatalogue::CHANNEL_WEB_PUSH => '1',
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('member_notification_preferences', [
            'user_id' => $this->membre->id,
            'notification_key' => NotificationCatalogue::LOOP_INVITATION,
            'channel' => NotificationCatalogue::CHANNEL_WEB_PUSH,
            'enabled' => true,
        ]);
    }

    // =====================================================================
    // Harnais
    // =====================================================================

    private function inviter(): string
    {
        app(LoopInvitationService::class)->invite($this->loop, $this->expediteur, $this->membre->email);

        return (string) MemberNotification::query()
            ->where('recipient_id', $this->membre->id)
            ->value('id');
    }

    private function livraisonPush(string $notificationId): MemberNotificationDelivery
    {
        return MemberNotificationDelivery::query()
            ->where('notification_id', $notificationId)
            ->forChannel(NotificationCatalogue::CHANNEL_WEB_PUSH)
            ->firstOrFail();
    }

    /** L'ECART stocke : le defaut vit dans le catalogue, la base ne porte que les ecarts. */
    private function allumerLePush(string $cle): void
    {
        $preference = new MemberNotificationPreference([
            'notification_key' => $cle,
            'channel' => NotificationCatalogue::CHANNEL_WEB_PUSH,
            'enabled' => true,
        ]);
        // `user_id` n'est pas affectable en masse (T1375) : pose a la main.
        $preference->user_id = (string) $this->membre->id;
        $preference->save();
    }

    /**
     * Un appareil REELLEMENT abonne, cryptographie comprise.
     *
     * Copie du harnais de T1740, et c'est volontaire : la cryptographie refuse
     * une valeur bidon, et un test qui ne chiffre pas ne mesure rien.
     */
    private function appareil(): PushSubscription
    {
        $paire = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $detail = openssl_pkey_get_details($paire);
        $publique = "\x04".str_pad($detail['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($detail['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $b64 = static fn (string $b): string => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');

        // La paire du SERVEUR est posee UNE fois : elle ne tourne pas entre
        // deux enregistrements d'appareils, et la garde de divergence de clef
        // ecarterait sinon des abonnements parfaitement valides.
        if (((string) config('webpush.vapid.public_key')) === '') {
            $serveur = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            $detailServeur = openssl_pkey_get_details($serveur);

            config()->set('webpush.vapid.public_key', $b64("\x04".str_pad($detailServeur['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($detailServeur['ec']['y'], 32, "\0", STR_PAD_LEFT)));
            config()->set('webpush.vapid.private_key', $b64(str_pad($detailServeur['ec']['d'], 32, "\0", STR_PAD_LEFT)));
        }

        return app(PushSubscriptionStore::class)->revendiquer(
            $this->membre,
            'https://fcm.googleapis.com/fcm/send/t1744-'.bin2hex(random_bytes(6)),
            $b64($publique),
            $b64(random_bytes(16)),
        );
    }
}
