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
     * Les cles arbitrees qui poussent, d'apres la matrice du CDC 6.2 — puis
     * TASK-1784.
     *
     * Ecrite A LA MAIN, et non derivee du catalogue : un test qui lirait le
     * catalogue pour verifier le catalogue passerait quoi qu'on y mette. Cette
     * discipline est maintenue, et c'est elle qui a fait rougir TASK-1784 au
     * bon endroit — la liste a du etre modifiee a la main, donc la decision a
     * du etre prise en conscience.
     *
     * TASK-1784 : `blog.comment.created` passe d'ECARTEE a POUSSEE. Le CDC 6.2
     * disait « plutot non par defaut » au motif qu'un commentaire ne vous est
     * pas adresse et qu'un auteur prolifique en recevrait des dizaines. Cyril a
     * renverse : « il faut notifier l'auteur du billet par email et PWA ».
     * L'objection reste traitee — le canal est `configurable`, donc un auteur
     * prolifique peut se taire.
     *
     * @var list<string>
     */
    private const CLES_POUSSEES = [
        NotificationCatalogue::LOOP_INVITATION,
        NotificationCatalogue::LOOP_MEMBER_ADDED,
        NotificationCatalogue::LOOP_JOIN_REQUEST_ACCEPTED,
        NotificationCatalogue::BLOG_CO_AUTHOR_ADDED,
        NotificationCatalogue::BLOG_COMMENT_CREATED,
    ];

    /**
     * Les cles ECARTEES, et c'est un arbitrage, pas un oubli.
     *
     * Il n'en reste qu'UNE depuis TASK-1784. Elle garde tout son sens : un
     * refus d'adhesion n'ouvre aucune porte, donc rien a pousser.
     *
     * @var list<string>
     */
    private const CLES_ECARTEES = [
        NotificationCatalogue::LOOP_JOIN_REQUEST_REJECTED,
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
            // Une adresse en `.test` : c'est le discriminant de
            // `PushTestPolicy`, et le rejeu de l'inspecteur s'y tient. Une
            // adresse de fabrique ordinaire ferait echouer le rejeu pour une
            // raison qui n'a rien a voir avec ce qu'on mesure.
            'email' => 'membre.t1744@bouclepro.test',
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

    public function test_les_evenements_arbitres_autorisent_le_push(): void
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
        // CDC 6.2 : « plutot non au lancement » pour le refus d'adhesion. Un
        // refus n'ouvre aucune porte, donc il n'y a rien a pousser.
        //
        // TASK-1784 — le commentaire de blog n'est PLUS ici : Cyril a renverse
        // « plutot non par defaut » et demande email + PWA. La cle a rejoint
        // `CLES_POUSSEES`, ou son entree dit pourquoi.
        foreach (self::CLES_ECARTEES as $cle) {
            $this->assertFalse(
                NotificationCatalogue::allowsChannel($cle, NotificationCatalogue::CHANNEL_WEB_PUSH),
                "[{$cle}] ne doit PAS pousser.",
            );
        }
    }

    public function test_le_push_reste_toujours_REGLABLE(): void
    {
        // Un Push sort de l'application et atterrit sur un ecran VERROUILLE.
        // Quelle que soit la valeur par defaut, la personne doit pouvoir
        // l'eteindre : aucune cle ne peut le declarer obligatoire.
        //
        // Ecrit sur TOUTE cle du catalogue, et pas seulement sur celles qui poussent :
        // c'est la regle du canal, elle doit tenir pour celles qu'on ouvrira
        // plus tard sans que personne ne pense a revenir ici.
        foreach (NotificationCatalogue::keys() as $cle) {
            if (! NotificationCatalogue::allowsChannel($cle, NotificationCatalogue::CHANNEL_WEB_PUSH)) {
                continue;
            }

            $this->assertTrue(
                NotificationCatalogue::channelIsConfigurable($cle, NotificationCatalogue::CHANNEL_WEB_PUSH),
                "[{$cle}] le Push ne doit JAMAIS etre obligatoire.",
            );
        }
    }

    public function test_la_VRAIE_barriere_est_l_appareil_pas_le_defaut(): void
    {
        // Arbitrage Cyril du 03/11 : « les categories importantes sont ON par
        // defaut cote BouclePro, mais la permission systeme est demandee
        // uniquement apres un clic explicite ».
        //
        // Ce test mesure pourquoi ce defaut est sans danger : il ne peut
        // reveiller personne a lui seul. Sans appareil abonne — l'etat de la
        // quasi-totalite des comptes — le livreur conclut `no_device`, quel
        // que soit le catalogue.
        //
        // C'est l'argument qui a remplace `default => false` : la barriere
        // n'etait pas la ou je l'avais mise.
        Http::fake(['*' => Http::response('', 201)]);

        $this->assertTrue(
            NotificationCatalogue::channelDefault(
                NotificationCatalogue::LOOP_INVITATION,
                NotificationCatalogue::CHANNEL_WEB_PUSH
            ),
            'le defaut produit est desormais ON',
        );

        // Aucun appareil, aucune preference stockee : le defaut est ON.
        $livraison = $this->livraisonPush($this->inviter());
        app(NotificationWebPushDeliverer::class)->deliver((string) $livraison->notification_id);

        Http::assertNothingSent();
        $this->assertSame(NotificationDeliveryStatus::SKIPPED_UNREACHABLE, $livraison->fresh()->status);
        $this->assertSame('no_device_subscribed', $livraison->fresh()->diagnostic);
    }

    public function test_le_catalogue_n_a_gagne_AUCUNE_cle(): void
    {
        // Cette TASK ouvre un canal, elle ne cree aucun evenement. Un
        // inventaire figé le dit mieux qu'une intention : si une cle apparait
        // — un message de ChatLoop, une demande d'aide, un rappel — ce test
        // rougit, et la decision se prend dans la TASK qui l'introduit.
        //
        // TASK-1747 : la garde a fait EXACTEMENT ce pour quoi elle a ete
        // ecrite. `loop.help_request.created` est la demande d'aide qu'elle
        // nommait par avance, et l'inventaire s'ouvre ici parce que la
        // decision a ete prise la-bas — in-app ON et configurable, Web Push
        // declare mais ETEINT (arbitrage MASTER du 03/11), aucun canal email.
        // Cette ligne n'affaiblit pas la garde : elle l'acquitte.
        //
        // TASK-1751 : SECONDE fois, et la garde a de nouveau fait son travail.
        // `transaction.proposal_created` est l'evenement « quelqu'un repond a
        // votre annonce », decide dans sa propre TASK a partir du CDC
        // `261104-Notification-entraide.md` : in-app ON et NON configurable
        // (on ne se desabonne pas d'une decision a prendre), email ON
        // configurable, Web Push ON configurable — aligne sur
        // `LOOP_INVITATION` parce que l'evenement est PERSONNEL, et non sur
        // `LOOP_HELP_REQUEST_CREATED` qui est collectif et reste eteint.
        //
        //
        // TASK-1761 : TROISIEME fois. `loop.join_request.created` previent le
        // PROPRIETAIRE qu'on demande a rejoindre sa Boucle — decide dans sa
        // propre TASK, sur specification de Cyril du 05/11 : in-app ON et NON
        // configurable (c'est une decision a prendre, on ne s'en desabonne
        // pas), email ON configurable, et **aucun Web Push**. L'absence de
        // Push est un choix et non un oubli : son jumeau « acceptee » en a un
        // parce qu'il repond a une attente de la personne elle-meme, tandis
        // qu'une demande recue arrive sans qu'on l'attende. CDC 6.2,
        // anti-bruit, meme raisonnement que pour « refusee ».
        //
        // TASK-1772 : QUATRIEME fois. `loop.mention.created` est le fait
        // « on m'a nommement appele dans une ChatLoop », decide dans sa propre
        // TASK a partir du CDC `261105-Mentions.md` et des arbitrages de Cyril
        // du 06/11 : in-app ON et NON configurable (on ne se desabonne pas
        // d'apprendre qu'on vous parle), email ON configurable (le fait reste
        // lisible dans le Centre, couper l'email ne fait rien perdre), Web
        // Push ON configurable — aligne sur `LOOP_INVITATION`, evenement
        // PERSONNEL, et non sur `LOOP_HELP_REQUEST_CREATED` qui est collectif.
        //
        // Le NOM de la cle est lui-meme une decision, prise sous la contrainte
        // de la garde anti-bruit ci-dessous : voir son acquittement.
        //
        // TASK-1786 : CINQUIEME fois. `entraide.comment.created` est le fait
        // « on a commente l'annonce d'entraide que j'ai publiee » — une
        // proposition de service ou une demande d'aide, indifferemment. Decide
        // dans sa propre TASK, sur CDC de Cyril du 08/11 : « les trois canaux
        // sont actives par defaut et configurables », mot pour mot.
        //
        // Donc in-app ON **configurable**, email ON configurable, Web Push ON
        // configurable. Les trois `configurable => true`, et c'est aligne sur
        // `BLOG_COMMENT_CREATED` plutot que sur une invitation, pour la meme
        // raison : un commentaire ne vous est pas ADRESSE et n'appelle aucune
        // reponse de vous. Un membre dont les annonces sont tres commentees doit
        // pouvoir se taire canal par canal — « tout coche par defaut » decide du
        // DEFAUT, pas de l'irrevocabilite.
        //
        // UNE seule cle pour les deux types d'annonce, et c'est la doctrine deja
        // posee juste au-dessus par `TRANSACTION_PROPOSAL_CREATED` : « deux cles
        // auraient duplique l'entree, la migration, le seeder et les quatre
        // libelles pour une nuance de texte ». La nuance (« proposition
        // d'entraide » / « demande d'aide ») est resolue a l'AFFICHAGE, par le
        // Centre.
        //
        // Et le Push reste GENERIQUE malgre le CDC §9, qui proposait « Marie a
        // commente votre proposition d'entraide » : la doctrine du depot (CDC
        // notifications §6.4/6.5, redite dans `NotificationCenterController`)
        // veut qu'un Push ne nomme personne, parce qu'il s'affiche sur un ECRAN
        // VERROUILLE. Le fait se raconte en entier dans `keys_named`, qui n'est
        // lisible qu'authentifie. Ecart signale a MASTER.
        //
        // La garde reste donc ce qu'elle est : un inventaire qui force la
        // decision a se prendre AILLEURS, dans la TASK qui introduit la cle.
        //
        // TASK-1810 : SIXIEME et SEPTIEME fois. Deux cles, decidees dans leur
        // propre TASK sur demande explicite de Cyril (12/11) : « quand
        // quelqu'un commente un evenement du flux, email + app + pwa » et
        // « si la personne fait juste une reaction, la notification est
        // juste dans l'application ».
        //
        // `FEED_POST_COMMENT_CREATED` : les TROIS canaux ON et configurables
        // — meme regime que `ENTRAIDE_COMMENT_CREATED` juste au-dessus et
        // pour la meme raison : un commentaire ne vous est pas ADRESSE et
        // n'appelle aucune reponse de vous.
        //
        // `FEED_POST_REACTION_CREATED` : PREMIERE cle du catalogue dont
        // `channels` ne declare QUE `in_app` — demande explicite de ne
        // JAMAIS envoyer d'email pour une simple reaction.
        $attendues = [
            NotificationCatalogue::LOOP_MENTION_CREATED,
            NotificationCatalogue::LOOP_INVITATION,
            NotificationCatalogue::LOOP_MEMBER_ADDED,
            NotificationCatalogue::LOOP_JOIN_REQUEST_CREATED,
            NotificationCatalogue::LOOP_JOIN_REQUEST_ACCEPTED,
            NotificationCatalogue::LOOP_JOIN_REQUEST_REJECTED,
            NotificationCatalogue::BLOG_COMMENT_CREATED,
            NotificationCatalogue::BLOG_CO_AUTHOR_ADDED,
            NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            NotificationCatalogue::TRANSACTION_PROPOSAL_CREATED,
            NotificationCatalogue::ENTRAIDE_COMMENT_CREATED,
            // TASK-1802 (demande Cyril) — un nouvel Evenement propose dans une
            // Boucle. Fait collectif de plus, meme regime que
            // LOOP_HELP_REQUEST_CREATED juste au-dessus.
            NotificationCatalogue::LOOP_EVENT_CREATED,
            NotificationCatalogue::FEED_POST_COMMENT_CREATED,
            NotificationCatalogue::FEED_POST_REACTION_CREATED,
        ];

        sort($attendues);
        $observees = NotificationCatalogue::keys();
        sort($observees);

        $this->assertSame($attendues, $observees);
    }

    public function test_aucun_message_ordinaire_de_chatloop_ne_pousse(): void
    {
        // TASK-1772 — ACQUITTEMENT.
        //
        // `loop.mention.created` pousse, et elle franchit cette garde telle
        // quelle. Ce n'est pas un contournement : la §6.2 du CDC notifications
        // que cette garde porte vise la diffusion de TOUT message a TOUS les
        // membres — le bruit de masse. Une mention est l'inverse exact : elle
        // est adressee a UNE personne nommement designee, qui l'a vue ecrire
        // son nom.
        //
        // Mais la garde teste une SOUS-CHAINE, pas une intention. C'est
        // pourquoi la cle nomme le FAIT (`mention`) et non le support
        // (`message`). `loop.message.mentioned` ou `chatloop.mention` auraient
        // fait rougir la CI pour une raison sans rapport avec la decision
        // produit, et quelqu'un aurait passe une heure a comprendre pourquoi.
        //
        // La garde n'est donc PAS affaiblie, et elle continue de proteger ce
        // qu'elle protegeait : le jour ou quelqu'un voudra pousser un message
        // ordinaire de ChatLoop, elle rougira encore.
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

    public function test_le_membre_qui_a_COUPE_le_reglage_ne_recoit_rien(): void
    {
        // Le defaut est ON depuis le 03/11 : ce qui doit etre mesure n'est
        // plus l'absence de reglage, mais sa COUPURE explicite. C'est la
        // garde qui compte desormais — « comprendre comment arreter » est la
        // premiere exigence du CDC section 7.
        //
        // Mesure avec un appareil REELLEMENT abonne : sinon le test serait
        // vert faute d'appareil, pas faute de reglage.
        Http::fake(['*' => Http::response('', 201)]);
        $this->appareil();
        $this->eteindreLePush(NotificationCatalogue::LOOP_INVITATION);

        $notificationId = $this->inviter();

        // TASK-1778 — la garde a GAGNE en force, et l'assertion suit.
        //
        // Avant : une livraison PUSH etait creee, un job etait mis en file,
        // et c'est le livreur qui decouvrait la coupure et concluait en
        // `skipped_preference`. Le planificateur consulte desormais les
        // preferences : un canal deja coupe au moment de planifier ne produit
        // plus NI ligne NI job. « Ne recoit rien » se prouve donc mieux
        // qu'avant — par l'absence de travail, pas par son refus.
        //
        // La relecture du livreur, elle, reste mesuree par
        // `test_le_reglage_coupe_APRES_coup_arrete_le_push`
        // juste en dessous : c'est le cas ou quelque chose a reellement change
        // entre les deux moments, et il est le seul a pouvoir le mesurer.
        $this->assertSame(
            0,
            MemberNotificationDelivery::query()
                ->where('notification_id', $notificationId)
                ->forChannel(NotificationCatalogue::CHANNEL_WEB_PUSH)
                ->count(),
            'Un canal coupe ne doit produire aucune livraison a planifier.'
        );

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

    public function test_l_ecran_expose_le_nouveau_canal_sur_les_cles_poussees(): void
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

    public function test_le_membre_peut_COUPER_le_push_depuis_l_ecran(): void
    {
        // Le defaut etant ON, c'est la coupure qui produit un ECART stocke.
        // Un formulaire ou la case est decochee n'envoie que le champ cache
        // a `0` — c'est precisement ce que ce champ existe pour garantir.
        $this->actingAs($this->membre)
            ->post(route('notifications.preferences.update'), [
                'canaux' => [
                    NotificationCatalogue::LOOP_INVITATION => [
                        NotificationCatalogue::CHANNEL_WEB_PUSH => '0',
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('member_notification_preferences', [
            'user_id' => $this->membre->id,
            'notification_key' => NotificationCatalogue::LOOP_INVITATION,
            'channel' => NotificationCatalogue::CHANNEL_WEB_PUSH,
            'enabled' => false,
        ]);
    }

    public function test_rallumer_EFFACE_l_ecart_au_lieu_d_en_stocker_un_second(): void
    {
        // La doctrine de T1375 : la base ne porte QUE les ecarts au
        // catalogue. Revenir au defaut doit donc retirer la ligne, pas en
        // ecrire une qui dirait la meme chose que le defaut.
        $this->eteindreLePush(NotificationCatalogue::LOOP_INVITATION);

        $this->actingAs($this->membre)
            ->post(route('notifications.preferences.update'), [
                'canaux' => [
                    NotificationCatalogue::LOOP_INVITATION => [
                        NotificationCatalogue::CHANNEL_WEB_PUSH => '1',
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('member_notification_preferences', [
            'user_id' => $this->membre->id,
            'notification_key' => NotificationCatalogue::LOOP_INVITATION,
            'channel' => NotificationCatalogue::CHANNEL_WEB_PUSH,
        ]);
    }

    // =====================================================================
    // 4. La charge utile — recette Android du 02/11
    // =====================================================================

    public function test_la_cible_du_clic_est_un_CHEMIN_jamais_une_URL_absolue(): void
    {
        // LE défaut de la recette n°3 : le clic ouvrait la page d'accueil au
        // lieu de la Boucle.
        //
        // La charge est fabriquée par le WORKER, donc hors requête, donc sur
        // `APP_URL`. Le Service Worker REFUSE une cible qui n'est pas de son
        // origine — à raison — et retombe sur `/`. Servie par un tunnel, la
        // PWA avait une autre origine que `APP_URL` : toutes les cibles
        // étaient rejetées, en silence.
        //
        // Un chemin relatif rend la question sans objet : le Service Worker
        // le résout contre SA propre origine, donc il est same-origin par
        // construction — y compris en production avec un `APP_URL` erroné.
        $url = $this->chargeUtilePour($this->inviter())['url'];

        $this->assertStringStartsWith('/', $url, 'la cible doit être un chemin');
        $this->assertStringNotContainsString('://', $url, 'aucune origine ne doit voyager');
        $this->assertNotSame('/', $url, 'et elle doit mener quelque part de précis');
    }

    public function test_le_corps_nomme_une_boucle_PUBLIQUE(): void
    {
        // CDC 6.2 écrit « pas de nom de Boucle PRIVÉE », et 6.4 « aucun nom
        // de Boucle CONFIDENTIELLE ». La distinction est dans le texte : une
        // Boucle publique est déjà lisible sans être connecté, son nom
        // n'apprend rien à qui regarde l'écran verrouillé.
        //
        // Et c'est l'information que la personne cherche : « Vous avez
        // rejoint une Boucle / Touchez pour voir » ne disait pas laquelle.
        $this->loop->forceFill(['visibility' => 'public', 'name' => 'Entraide publique T1744'])->save();

        $this->assertSame('Entraide publique T1744', $this->chargeUtilePour($this->inviter())['body']);
    }

    public function test_le_corps_ne_nomme_JAMAIS_une_boucle_privee(): void
    {
        // La garde qui compte. Un nom de Boucle privée sur un écran
        // verrouillé est exactement ce que le CDC interdit.
        $this->loop->forceFill(['visibility' => 'private', 'name' => 'Boucle Très Confidentielle'])->save();

        $charge = $this->chargeUtilePour($this->inviter());

        $this->assertStringNotContainsString('Confidentielle', json_encode($charge, JSON_UNESCAPED_UNICODE));

        // Et le repli n'est pas le générique vide : chaque clé a sa phrase.
        $this->assertSame(__('notifications.push_body.loop_invitation'), $charge['body']);
        $this->assertNotSame(__('notifications.push_body_generic'), $charge['body']);
    }

    public function test_deux_faits_DISTINCTS_ne_se_remplacent_pas_a_l_ecran(): void
    {
        // Le symptôme rapporté le 02/11 : « la notification est dans l'app
        // mais pas sur le smartphone ». Deux ajouts de Boucle à treize
        // minutes d'intervalle, tous deux `sent devices:1`, et UNE seule
        // notification visible.
        //
        // Les deux partageaient le tag `loop.member_added` : le second a
        // REMPLACÉ le premier. Avec un titre et un corps identiques, un
        // remplacement est indiscernable de « rien n'est arrivé ».
        //
        // Le tag porte donc l'OBJET. Deux Boucles = deux faits = deux
        // notifications qui s'empilent.
        $autreLoop = Loop::factory()->create([
            'organization_id' => $this->org->id,
            'created_by' => $this->expediteur->id,
            'status' => 'active',
        ]);

        $premier = $this->chargeUtilePour($this->inviter())['tag'];

        $this->loop = $autreLoop;
        $autreMembre = User::factory()->create([
            'organization_id' => $this->org->id,
            'email' => 'second.t1744@bouclepro.test',
        ]);
        $second = $this->chargeUtilePour($this->inviter($autreMembre->email))['tag'];

        $this->assertNotSame($premier, $second, 'deux objets distincts doivent porter des tags distincts');
        $this->assertStringStartsWith(NotificationCatalogue::LOOP_INVITATION.':', $premier);
    }

    public function test_le_MEME_fait_repousse_garde_le_meme_tag(): void
    {
        // L'autre moitié de la règle, et c'est elle qui tient l'anti-bruit du
        // CDC 9.3 : rejouer le même fait ne doit pas empiler deux lignes sur
        // l'écran verrouillé.
        $notificationId = $this->inviter();

        $this->assertSame(
            $this->chargeUtilePour($notificationId)['tag'],
            $this->chargeUtilePour($notificationId)['tag'],
        );
    }

    public function test_chaque_cle_poussee_porte_un_corps_propre_dans_les_deux_locales(): void
    {
        foreach (self::CLES_POUSSEES as $cle) {
            $chemin = 'notifications.push_body.'.str_replace('.', '_', $cle);

            foreach (['fr', 'en'] as $locale) {
                $this->assertTrue(Lang::has($chemin, $locale), "[{$locale}] corps manquant pour [{$cle}].");
                $this->assertNotSame(
                    __('notifications.push_body_generic', [], $locale),
                    __($chemin, [], $locale),
                    "[{$locale}] [{$cle}] doit avoir son propre corps.",
                );
            }
        }
    }

    // =====================================================================
    // 5. L'inspecteur des notifications — demande Cyril 02/11
    // =====================================================================

    public function test_l_inspecteur_montre_le_destinataire_ET_le_diagnostic(): void
    {
        // C'est tout l'objet de l'ecran, et c'est ce que le cockpit T1380
        // refuse de montrer par doctrine : il COMPTE sans nommer personne.
        // Trois recettes Push ont ete diagnostiquees au `tinker` faute de
        // cette page, alors que `skipped_preference` etait en base.
        $this->inviter();

        $html = $this->actingAs($this->superAdmin())
            ->get(route('admin.outils.notifications'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($this->membre->email, $html);
        $this->assertStringContainsString('loop.invitation', $html);
        $this->assertStringContainsString('web_push', $html);
    }

    public function test_l_inspecteur_n_offre_PAS_un_rejeu_sans_appareil(): void
    {
        // Mesure du 02/11 : le rejeu a conclu `all_devices_revoked` parce
        // que le dernier endpoint venait d'etre revoque par le fournisseur
        // — vider le cache d'une PWA detruit son abonnement Push.
        //
        // Le bouton etait pourtant propose. Un ecran de diagnostic qui
        // promet une action impossible fabrique le doute qu'il est cense
        // lever : on ne sait plus si c'est le Push qui est casse ou le
        // telephone.
        $this->inviter();

        // Aucun appareil : c'est l'etat exact apres une revocation.
        $this->assertSame(0, PushSubscription::query()->count());

        $html = $this->actingAs($this->superAdmin())
            ->get(route('admin.outils.notifications'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(__('admin.notif_inspector_no_device'), $html);
        $this->assertStringNotContainsString('data-rejouer', $html, 'aucun bouton de rejeu sans appareil');
    }

    public function test_l_inspecteur_offre_le_rejeu_des_qu_un_appareil_existe(): void
    {
        // L'autre moitie : la garde ne doit pas tout fermer. Un appareil
        // abonne, et le bouton revient.
        $this->appareil();
        $this->inviter();

        $html = $this->actingAs($this->superAdmin())
            ->get(route('admin.outils.notifications'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-rejouer', $html);
        $this->assertStringContainsString(trans_choice('admin.notif_inspector_devices', 1, ['count' => 1]), $html);
    }

    public function test_le_compte_d_appareils_suit_la_MEME_regle_que_l_envoi(): void
    {
        // Un compte affiche « 1 appareil » que l'envoi refuserait serait
        // exactement le mensonge que cet ecran existe pour dissiper. Les
        // deux passent donc par `conditionsEnvoyables()`, et ce test le
        // mesure sur le cas qui separe : une clef VAPID perimee.
        $appareil = $this->appareil();
        $appareil->forceFill(['vapid_key_hash' => 'une-clef-qui-n-a-plus-cours'])->save();

        $politique = app(\App\Support\Push\PushTestPolicy::class);

        $this->assertSame(
            $politique->abonnementsEnvoyables($this->membre)->count(),
            $politique->comptesDAppareilsEnvoyables([(string) $this->membre->id])[(string) $this->membre->id] ?? 0,
        );
        $this->assertSame(0, $politique->abonnementsEnvoyables($this->membre)->count());
    }

    public function test_l_inspecteur_signale_une_file_que_personne_ne_consomme(): void
    {
        // LA panne du 03/11, et la plus coûteuse de la série parce qu'elle ne
        // ressemble à rien : le rejeu fonctionne, la préférence est cochée,
        // l'appareil est abonné, la notification s'affiche dans
        // l'application… et aucun Push n'arrive.
        //
        // La différence est que le rejeu est SYNCHRONE, alors qu'un évènement
        // réel passe par la file. Sans worker, le job dort dans `jobs`, la
        // livraison reste `pending`, et rien ne le disait nulle part.
        config()->set('queue.default', 'database');

        \Illuminate\Support\Facades\DB::table('jobs')->insert([
            'queue' => \App\Support\Notifications\NotificationWebPushDeliverer::QUEUE,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.outils.notifications'))
            ->assertOk()
            ->assertSee('data-file-en-attente="1"', false);
    }

    public function test_l_inspecteur_ne_crie_pas_quand_la_file_est_vide(): void
    {
        // Une alerte permanente ne se lit plus. Elle ne doit paraître que
        // lorsqu'il y a réellement quelque chose qui attend.
        config()->set('queue.default', 'database');

        $this->actingAs($this->superAdmin())
            ->get(route('admin.outils.notifications'))
            ->assertOk()
            ->assertDontSee('data-file-en-attente', false);
    }

    public function test_l_inspecteur_est_reserve_a_l_administration(): void
    {
        $this->actingAs($this->membre)
            ->get(route('admin.outils.notifications'))
            ->assertForbidden();
    }

    public function test_le_rejeu_renvoie_vraiment_le_push(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $appareil = $this->appareil();
        $this->allumerLePush(NotificationCatalogue::LOOP_INVITATION);

        $notificationId = $this->inviter();
        $livraison = $this->livraisonPush($notificationId);

        // On la met dans un etat TERMINAL, comme apres un echec reel : c'est
        // precisement l'etat que le rejeu doit savoir rouvrir.
        $livraison->forceFill([
            'status' => NotificationDeliveryStatus::SKIPPED_PREFERENCE,
            'diagnostic' => 'channel_disabled_by_member',
        ])->save();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.outils.notifications.replay', $notificationId))
            ->assertRedirect();

        Http::assertSent(fn ($request) => $request->url() === $appareil->endpoint);
        $this->assertSame(NotificationDeliveryStatus::SENT, $livraison->fresh()->status);
    }

    public function test_la_machine_d_etats_reste_SANS_RETOUR_hors_de_cette_porte(): void
    {
        // La garde la plus importante de l'inspecteur. T1377 a rendu les
        // etats terminaux irreversibles pour qu'un rejeu ACCIDENTEL ne
        // reprenne jamais une livraison deja tranchee. Ouvrir
        // `terminal -> pending` dans la table des transitions aurait ouvert
        // cette porte a tout le monde, worker compris.
        //
        // Elle est donc NOMMEE : seule `rouvrirPourRejeu()` passe, et un
        // `forceFill` ordinaire continue de lever.
        $livraison = $this->livraisonPush($this->inviter());

        // On l'amene a `sent` par le chemin LEGITIME — `pending` ne mene
        // qu'a `sending`. Y sauter directement leverait pour une autre
        // raison que celle qu'on veut mesurer.
        $livraison->forceFill(['status' => NotificationDeliveryStatus::SENDING])->save();
        $livraison->forceFill(['status' => NotificationDeliveryStatus::SENT, 'sent_at' => now()])->save();

        try {
            $livraison->forceFill(['status' => NotificationDeliveryStatus::PENDING])->save();
            $this->fail('un forceFill ordinaire ne doit PAS pouvoir rouvrir un etat terminal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Forbidden notification delivery transition', $e->getMessage());
        }

        // La porte nommee, elle, passe — et elle efface le verdict precedent.
        $livraison->fresh()->rouvrirPourRejeu();

        $apres = $livraison->fresh();
        $this->assertSame(NotificationDeliveryStatus::PENDING, $apres->status);
        $this->assertNull($apres->sent_at);
        $this->assertNull($apres->diagnostic);
    }

    public function test_le_rejeu_ne_FABRIQUE_aucun_evenement_metier(): void
    {
        // La difference avec l'outil de test : celui-ci n'ecrit rien, le
        // rejeu rouvre une LIVRAISON. Ni l'un ni l'autre ne doit inventer un
        // fait metier qui n'a pas eu lieu.
        Http::fake(['*' => Http::response('', 201)]);

        $this->appareil();
        $this->allumerLePush(NotificationCatalogue::LOOP_INVITATION);
        $notificationId = $this->inviter();

        $avant = MemberNotification::query()->count();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.outils.notifications.replay', $notificationId));

        $this->assertSame($avant, MemberNotification::query()->count());
        $this->assertSame(
            1,
            MemberNotificationDelivery::query()
                ->where('notification_id', $notificationId)
                ->forChannel(NotificationCatalogue::CHANNEL_WEB_PUSH)
                ->count(),
            'une seule livraison Push, jamais une seconde',
        );
    }

    public function test_un_compte_REEL_ne_peut_pas_etre_rejoue(): void
    {
        // LA garde du lot. Le jumeau `bouclepro_prod_work` porte de VRAIES
        // adresses : rejouer une notification de l'an dernier reveillerait le
        // telephone de quelqu'un qui n'a rien demande, depuis un
        // environnement de travail.
        Http::fake(['*' => Http::response('', 201)]);

        $reel = User::factory()->create([
            'organization_id' => $this->org->id,
            'email' => 'personne.reelle@gmail.com',
        ]);

        $notificationId = $this->inviter($reel->email);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.outils.notifications.replay', $notificationId))
            ->assertRedirect();

        Http::assertNothingSent();
    }

    public function test_une_cle_sans_push_au_catalogue_ne_se_rejoue_pas(): void
    {
        // Le catalogue est relu AU MOMENT du rejeu, pas au moment de
        // l'emission : retirer le canal d'une cle doit suffire a fermer la
        // porte, ici comme ailleurs.
        Http::fake(['*' => Http::response('', 201)]);
        $this->appareil();

        $notification = MemberNotification::create([
            'organization_id' => $this->org->id,
            'recipient_id' => $this->membre->id,
            'notification_key' => NotificationCatalogue::LOOP_JOIN_REQUEST_REJECTED,
            'event_id' => (string) \Illuminate\Support\Str::uuid(),
            'object_type' => NotificationCatalogue::OBJECT_LOOP_JOIN_REQUEST,
        ]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.outils.notifications.replay', $notification->id))
            ->assertRedirect();

        Http::assertNothingSent();
        $this->assertSame(
            0,
            MemberNotificationDelivery::query()->where('notification_id', $notification->id)->count(),
            'aucune livraison ne doit etre fabriquee pour une cle non autorisee',
        );
    }

    public function test_le_rail_porte_l_inspecteur(): void
    {
        $html = $this->actingAs($this->superAdmin())
            ->get(route('admin.outils.notifications'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('admin.outils.notifications'), $html);
        $this->assertStringContainsString(__('admin.notif_inspector_title'), $html);
    }

    // =====================================================================
    // 5. L'outil de diagnostic entre au rail gauche — demande Cyril 02/11
    // =====================================================================

    public function test_le_rail_du_superadmin_porte_l_outil_de_test_push(): void
    {
        // T1740 avait livre l'outil sans aucune entree de navigation : il
        // n'etait atteignable que par son URL. C'est la forme la plus
        // discrete de code mort — present, fonctionnel, introuvable.
        $superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_admin' => true,
        ]);

        $html = $this->actingAs($superAdmin)
            ->get(route('admin.outils.integrite'))
            ->assertOk()
            ->getContent();

        // L'URL doit figurer dans le rail, et le libelle avec elle : une
        // entree sans texte lisible ne se clique pas.
        $this->assertStringContainsString(route('admin.outils.test-notification'), $html);
        $this->assertStringContainsString(__('admin.push_test_title_page'), $html);
    }

    public function test_l_outil_reste_refuse_a_qui_n_est_pas_administrateur(): void
    {
        // Rendre un outil visible ne doit pas le rendre accessible. La garde
        // est celle de la route, et l'entree de rail n'y change rien — mais
        // on le mesure, parce que c'est le genre de chose qu'on croit sur
        // parole.
        $this->actingAs($this->membre)
            ->get(route('admin.outils.test-notification'))
            ->assertForbidden();
    }

    // =====================================================================
    // Harnais
    // =====================================================================

    private function inviter(?string $email = null): string
    {
        $email ??= $this->membre->email;

        app(LoopInvitationService::class)->invite($this->loop, $this->expediteur, $email);

        $destinataireId = (string) \Illuminate\Support\Facades\DB::table('users')->where('email', $email)->value('id');

        return (string) MemberNotification::query()
            ->where('recipient_id', $destinataireId)
            ->value('id');
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'organization_id' => $this->org->id,
            'is_admin' => true,
        ]);
    }

    /**
     * La charge utile REELLEMENT composee pour cette notification.
     *
     * Lue par reflexion, et c'est le bon niveau : le corps part CHIFFRE, donc
     * illisible depuis la requete sortante — c'est precisement ce que T1740
     * mesure deja (`test_la_charge_utile_ne_dit_rien_de_prive`). Ce qui est
     * neuf ici est la COMPOSITION, pas le transport.
     *
     * La cible vient du vrai resolveur, pas d'une chaine inventee : le defaut
     * du 02/11 portait justement sur la forme de cette cible.
     *
     * @return array<string, mixed>
     */
    private function chargeUtilePour(string $notificationId): array
    {
        $notification = MemberNotification::query()->findOrFail($notificationId);
        $cible = app(\App\Support\Notifications\NotificationTargetResolver::class)->resolve($notification);

        $this->assertNotNull($cible, 'la cible doit etre resolvable, sinon le test ne mesure rien');

        $livreur = app(NotificationWebPushDeliverer::class);
        $methode = new \ReflectionMethod($livreur, 'chargeUtile');
        $methode->setAccessible(true);

        return json_decode($methode->invoke($livreur, $notification, $cible), true);
    }

    private function livraisonPush(string $notificationId): MemberNotificationDelivery
    {
        return MemberNotificationDelivery::query()
            ->where('notification_id', $notificationId)
            ->forChannel(NotificationCatalogue::CHANNEL_WEB_PUSH)
            ->firstOrFail();
    }

    /** L'ecart INVERSE : couper un canal actif par defaut. */
    private function eteindreLePush(string $cle): void
    {
        $preference = new MemberNotificationPreference([
            'notification_key' => $cle,
            'channel' => NotificationCatalogue::CHANNEL_WEB_PUSH,
            'enabled' => false,
        ]);
        $preference->user_id = (string) $this->membre->id;
        $preference->save();
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
