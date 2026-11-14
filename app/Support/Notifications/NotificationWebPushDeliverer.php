<?php

namespace App\Support\Notifications;

use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\PushSubscription;
use App\Support\Ops\ExternalEgressGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Encryption;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * TASK-1740 — l'envoi reel d'une notification Web Push.
 *
 * Calque de {@see NotificationEmailDeliverer} : meme prise de travail
 * atomique, memes gardes relues AU MOMENT DE L'ENVOI, memes motifs d'abandon.
 * Ce qui change est le transport.
 *
 * ## Le transport passe par `Http::`, et c'est une decision de SECURITE
 *
 * `minishlink/web-push` sait envoyer tout seul — avec son PROPRE client
 * Guzzle. On ne s'en sert donc QUE pour la cryptographie (en-tetes VAPID,
 * chiffrement de charge utile RFC 8291), et la requete part par le client de
 * Laravel.
 *
 * La raison est mesurable : `ExternalEgressGuard` (T1724) s'accroche par
 * `Http::globalMiddleware()`. Il gouverne donc le client de Laravel, et LUI
 * SEUL. Un envoi par le client interne de la bibliotheque contournerait le
 * garde sans que personne l'ait decide — depuis un jumeau de PRODUCTION
 * compris. Passer par `Http::` remet le garde en autorite : sur
 * `bouclepro_prod_work`, l'envoi est REFUSE, et c'est le comportement voulu.
 *
 * Effet second, utile : la suite de tests peut se contenter de `Http::fake()`
 * sans jamais toucher au reseau.
 *
 * ## Ce que le serveur relit AVANT d'envoyer
 *
 * Un abonnement peut avoir ete pris il y a des semaines. Entre-temps la
 * personne a pu quitter l'Organization, perdre son appartenance a la Boucle,
 * couper le canal, ou basculer sur un compte frere. Les droits sont donc
 * relus ici, jamais supposes — exactement comme le fait le livreur email
 * avant tout courriel.
 *
 * ## La charge utile ne dit RIEN de prive
 *
 * CDC 6.4 : titre generique, aucune donnee privee, aucun nom de personne ni
 * de Boucle confidentielle. Un Push s'affiche sur un ecran VERROUILLE, donc
 * devant n'importe qui. Le detail se lit APRES le clic, sur une page que le
 * serveur re-autorise.
 */
class NotificationWebPushDeliverer
{
    /** La file dediee a ce canal. */
    public const QUEUE = 'notifications-web-push';

    /**
     * Le nombre d'appareils servis pour une notification.
     *
     * En `QUEUE_CONNECTION=sync` (la PROD, confirmee par Cyril), chaque envoi
     * allonge la requete HTTP du producteur. Un quota explicite vaut mieux
     * qu'une latence imprevisible : au-dela, les appareils restants sont
     * ignores et le fait est journalise.
     */
    private const MAX_APPAREILS = 10;

    /**
     * TASK-1779 — le transport a-t-il DEJA ete sollicite pour cette livraison ?
     *
     * C'est la frontiere qui separe `failed` (on sait que rien n'est parti) de
     * `ambiguous` (on ne sait plus). Elle est posee juste avant le premier
     * `deposer()`, et remise a zero a chaque entree dans `deliver()`.
     *
     * Elle ne remplace PAS le `try/catch` interne de `deposer()`, qui absorbe
     * les echecs du transport lui-meme : elle couvre ce qui echoue AUTOUR du
     * transport une fois celui-ci engage — la suppression d'un appareil
     * revoque, l'ecriture du statut final.
     */
    private bool $transportSollicite = false;

    private const TIMEOUT = 8;

    /** Duree de vie du message chez le fournisseur, en secondes. */
    private const TTL = 3600;

    public function deliver(string $notificationId): void
    {
        $notification = MemberNotification::query()->find($notificationId);

        if ($notification === null) {
            return;
        }

        $livraison = MemberNotificationDelivery::query()
            ->where('notification_id', $notificationId)
            ->forChannel(NotificationCatalogue::CHANNEL_WEB_PUSH)
            ->first();

        if ($livraison === null || ! $this->prendre($livraison)) {
            // Soit il n'y a rien a faire, soit un autre worker a pris le
            // travail. Dans les deux cas on se retire sans bruit : c'est la
            // prise ATOMIQUE qui tranche, pas un verrou applicatif.
            return;
        }

        // TASK-1779 — le drapeau est remis a zero A CHAQUE ENTREE, jamais lu
        // d'un appel sur l'autre. Ce livreur n'est pas un singleton (il est
        // resolu par job), mais une garde de correction ne doit pas dependre
        // de la duree de vie d'une instance.
        $this->transportSollicite = false;

        try {
            $this->livrer($notification, $livraison);
        } catch (Throwable $incident) {
            // TASK-1779 — sans ce bloc, une erreur INTERNE du chemin push
            // (base injoignable, suppression d'un appareil refusee, cible qui
            // leve) remontait jusqu'a l'appelant. Sous `QUEUE_CONNECTION=sync`,
            // `SyncQueue::handleException()` relance : l'exception atteignait
            // la requete HTTP de l'AUTEUR, qui voyait une 500 alors que son
            // message etait publie. Et la livraison restait bloquee en
            // `sending`, donc jamais reprise — `prendre()` n'accepte que
            // `pending`.
            //
            // Le livreur EMAIL se protegeait deja ainsi
            // (`NotificationEmailDeliverer::deliver()`). Cette TASK rend les
            // deux symetriques ; l'asymetrie n'etait pas un choix, c'etait un
            // oubli.
            report($incident);

            // LA FRONTIERE. Avant toute sollicitation du transport, on SAIT
            // que rien n'est parti : `failed` est exact et autorise une
            // relance manuelle. Des que le premier `deposer()` a ete appele,
            // on ne sait plus : le fournisseur a peut-etre accepte le message
            // avant que la suite echoue. `ambiguous` dit exactement cela, et
            // il interdit la relance.
            //
            // Le diagnostic est un CODE STABLE, jamais le message
            // d'exception : meme raison que dans le livreur email — un
            // message de moteur peut depasser la colonne et faire echouer
            // l'ecriture PAR-DESSUS l'incident d'origine, ou transporter des
            // fragments de donnees.
            $this->abandonnerSansEchouer(
                $livraison,
                $this->transportSollicite
                    ? NotificationDeliveryStatus::AMBIGUOUS
                    : NotificationDeliveryStatus::FAILED,
                $this->transportSollicite ? 'push_outcome_unknown' : 'delivery_internal_error',
            );
        }
    }

    private function livrer(MemberNotification $notification, MemberNotificationDelivery $livraison): void
    {
        $destinataireId = (string) $notification->recipient_id;

        // 1 — le tenant, relu HORS Eloquent. Sous worker il n'y a pas de
        // `current_organization`, et `BelongsToOrganizationScope` appliquerait
        // `whereRaw('0 = 1')` : la borne est donc ecrite a la main, comme dans
        // `NotificationTargetResolver` depuis T1720.
        $organizationDuDestinataire = DB::table('users')->where('id', $destinataireId)->value('organization_id');

        if ($organizationDuDestinataire === null
            || (string) $organizationDuDestinataire !== (string) $notification->organization_id) {
            $this->abandonner($livraison, NotificationDeliveryStatus::SKIPPED_UNREACHABLE, 'recipient_left_organization');

            return;
        }

        // 2 — la preference COURANTE. Elle a pu changer depuis la creation.
        $destinataire = $notification->recipient;

        if ($destinataire === null) {
            $this->abandonner($livraison, NotificationDeliveryStatus::SKIPPED_UNREACHABLE, 'recipient_missing');

            return;
        }

        $preferences = app(NotificationPreferenceResolver::class);

        // TASK-1775 (CDC C2) — meme discipline qu'en EMAIL : la sourdine par
        // Boucle a pu changer entre la planification et ce rejeu, et seule
        // `LOOP_HELP_REQUEST_CREATED` doit jamais s'y soumettre (`object_id`
        // designe la Boucle AUSSI pour `LOOP_MEMBER_ADDED`, un fait personnel).
        //
        // TASK-1778 — la regle est au catalogue, en un seul exemplaire.
        $loopId = NotificationCatalogue::loopScopeFor(
            (string) $notification->notification_key,
            $notification->object_id === null ? null : (string) $notification->object_id,
        );

        if (! $preferences->allows($destinataire, (string) $notification->notification_key, NotificationCatalogue::CHANNEL_WEB_PUSH, $loopId)) {
            $this->abandonner($livraison, NotificationDeliveryStatus::SKIPPED_PREFERENCE, 'channel_disabled_by_member');

            return;
        }

        // 3 — la cible doit rester atteignable : c'est elle qui revalide
        // l'appartenance a la Boucle et l'etat de l'objet.
        $cible = app(NotificationTargetResolver::class)->resolve($notification);

        if ($cible === null) {
            $this->abandonner($livraison, NotificationDeliveryStatus::SKIPPED_UNREACHABLE, 'target_no_longer_reachable');

            return;
        }

        // 4 — les appareils de CETTE personne. L'unicite posee en T1739
        // garantit qu'un appareil revendique par un compte frere ne figure
        // plus ici : la bascule de compte est donc fermee par la DONNEE, pas
        // par une garde supplementaire.
        $appareils = PushSubscription::query()
            ->where('user_id', $destinataireId)
            // TASK-1740 — ecarter les abonnements crees avec une clef VAPID
            // qui n'a plus cours. Un abonnement porte A VIE la clef avec
            // laquelle le navigateur l'a cree : si celle du serveur a change,
            // le fournisseur accepte toujours l'envoi (`201`) et le navigateur
            // jette le message EN SILENCE. Les servir reviendrait a compter
            // des livraisons qui n'ont pas lieu.
            //
            // Un `vapid_key_hash` a `null` reste servi : il signifie « cree
            // avant que cette colonne existe », pas « suspect ». La
            // reconciliation, qui tourne a chaque affichage de l'ecran des
            // reglages, le stampera d'elle-meme.
            //
            // TASK-1821 — la condition vit desormais dans le MODELE
            // (`scopeCleVapidUtilisable`), inchangee, pour que la console
            // d'administration l'applique sans la recopier : un ecran qui
            // annoncerait « eligible » ce que cet envoi ecarte serait un
            // mensonge par divergence.
            ->cleVapidUtilisable()
            ->orderByDesc('last_seen_at')
            ->limit(self::MAX_APPAREILS + 1)
            ->get();

        if ($appareils->isEmpty()) {
            $this->abandonner($livraison, NotificationDeliveryStatus::SKIPPED_UNREACHABLE, 'no_device_subscribed');

            return;
        }

        if ($appareils->count() > self::MAX_APPAREILS) {
            Log::warning('TASK-1740 quota d appareils atteint, appareils ignores.', [
                'notification_id' => (string) $notification->id,
                'quota' => self::MAX_APPAREILS,
            ]);
            $appareils = $appareils->take(self::MAX_APPAREILS);
        }

        $charge = $this->chargeUtile($notification, $cible);
        $reussites = 0;
        $revoques = 0;
        // TASK-1822 — remise a zero A CHAQUE livraison, jamais lue d'un
        // appel sur l'autre : meme discipline que `$transportSollicite`.
        $this->erreursFournisseurExplicites = 0;

        // TASK-1779 — FRANCHISSEMENT DE LA FRONTIERE. Tout ce qui echoue a
        // partir d'ici doit conclure `ambiguous`, jamais `failed` : le premier
        // appel a `deposer()` est imminent, et un fournisseur peut avoir
        // accepte le message avant qu'une etape suivante echoue.
        $this->transportSollicite = true;

        foreach ($appareils as $appareil) {
            $resultat = $this->deposer($appareil, $charge);

            if ($resultat === 'ok') {
                $reussites++;

                continue;
            }

            if ($resultat === 'revoque') {
                // 404/410 : le fournisseur dit que cet endpoint n'existe plus.
                // On le retire, c'est le seul nettoyage sur lequel on a une
                // AUTORITE — le fournisseur fait foi sur ses propres endpoints.
                $appareil->delete();
                $revoques++;
            }
        }

        if ($reussites > 0) {
            $livraison->forceFill([
                'status' => NotificationDeliveryStatus::SENT,
                'sent_at' => now(),
                'diagnostic' => "devices:{$reussites}".($revoques > 0 ? " revoked:{$revoques}" : ''),
            ])->save();

            return;
        }

        // Aucun appareil n'a recu. Si tous etaient revoques, ce n'est pas un
        // echec de notre cote : la personne n'a simplement plus d'appareil.
        //
        // TASK-1822 — troisieme motif. `push_service_unreachable` disait
        // « le fournisseur n'a pas repondu » ; il couvrait donc a tort le
        // cas ou le fournisseur A repondu, sous 2xx, pour dire non. Les
        // deux appellent des gestes differents — attendre, ou regarder ce
        // que le fournisseur reproche — et un diagnostic qui les confond
        // envoie chercher au mauvais endroit.
        $motif = match (true) {
            $revoques > 0 => 'all_devices_revoked',
            $this->erreursFournisseurExplicites > 0 => 'push_provider_error',
            default => 'push_service_unreachable',
        };

        $this->abandonner(
            $livraison,
            $revoques > 0 ? NotificationDeliveryStatus::SKIPPED_UNREACHABLE : NotificationDeliveryStatus::FAILED,
            $motif,
        );
    }

    /**
     * Envoyer une notification de TEST a une liste d'appareils donnee.
     *
     * Employee par `/admin/outils/test-notification`, et par lui seul. Elle ne
     * CHOISIT pas les destinataires : l'appelant les fournit, et c'est le
     * controleur d'administration qui impose de n'envoyer qu'a soi-meme.
     * Mettre ce choix ici aurait dilue la garde dans deux endroits.
     *
     * Elle ne cree aucune livraison et ne touche a aucune notification metier :
     * un test n'est pas un evenement du produit, et il ne doit laisser aucune
     * trace qui ressemblerait a l'un.
     *
     * Rend le detail par appareil, parce que « ca n'a pas marche » n'aide
     * personne : on veut savoir si c'est refuse, revoque, ou en panne.
     *
     * @param  \Illuminate\Support\Collection<int, PushSubscription>  $appareils
     * @param  array<string, mixed>  $charge
     * @return array{ok: int, revoque: int, echec: int, motifs: list<string>, http: list<string>}
     */
    public function envoyerUnTest($appareils, array $charge): array
    {
        $compte = ['ok' => 0, 'revoque' => 0, 'echec' => 0];
        $motifs = [];
        // Le code HTTP BRUT du fournisseur, par appareil. MASTER : « ne pas
        // confondre acceptation par FCM et affichage effectif sur Android ».
        // Un `201` dit que Google a pris le message en charge, rien de plus —
        // le rendre tel quel laisse cette distinction visible.
        $http = [];

        foreach ($appareils as $appareil) {
            $resultat = $this->deposer($appareil, (string) json_encode($charge, JSON_UNESCAPED_UNICODE));
            $compte[$resultat]++;

            if ($resultat === 'revoque') {
                $appareil->delete();
            }

            $http[] = ($appareil->device_label ?? 'appareil').' : HTTP '.($this->dernierCodeHttp ?? '—');

            if ($resultat !== 'ok') {
                $motifs[] = ($appareil->device_label ?? 'appareil').' : '.$this->dernierMotif;
            }
        }

        return $compte + ['motifs' => $motifs, 'http' => $http];
    }

    /** Le motif du dernier depot en echec, pour que le diagnostic puisse le dire. */
    private string $dernierMotif = '';

    /** Le code HTTP rendu par le fournisseur au dernier depot, ou `null`. */
    private ?int $dernierCodeHttp = null;

    /**
     * TASK-1822 — combien de depots ont lu une erreur EXPLICITE du
     * fournisseur dans un corps 2xx, pour cette livraison.
     *
     * Elle existe pour que le diagnostic final distingue deux echecs que
     * `push_service_unreachable` confondait : « le fournisseur n'a pas
     * repondu » et « le fournisseur a repondu, et il a dit non ». Remise a
     * zero a chaque entree dans `livrer()`, comme `$transportSollicite`.
     */
    private int $erreursFournisseurExplicites = 0;

    /**
     * Base64URL vers binaire brut.
     *
     * Le bourrage `=` est REAJOUTE avant de decoder : `base64_decode()` tolere
     * une chaine non bourree en mode permissif, mais rend une valeur tronquee
     * sur certaines longueurs — et une clef tronquee d'un octet produit une
     * signature invalide que le fournisseur refusera sans dire pourquoi.
     */
    private static function depuisBase64Url(string $valeur): string
    {
        $bourrage = (4 - strlen($valeur) % 4) % 4;

        return (string) base64_decode(strtr($valeur, '-_', '+/').str_repeat('=', $bourrage), true);
    }

    /**
     * Deposer le message chiffre chez le fournisseur de CET appareil.
     *
     * Rend `ok`, `revoque` (le fournisseur ne connait plus cet endpoint) ou
     * `echec`. Aucune exception ne remonte : un fournisseur injoignable ne
     * doit pas casser l'action metier qui a produit la notification.
     */
    private function deposer(PushSubscription $appareil, string $charge): string
    {
        try {
            // TASK-1740 — REMBOURRER avant de chiffrer, et ce n'est pas une
            // optimisation : c'est la cause de la panne du 01/11.
            //
            // En `aes128gcm` (RFC 8188), le texte clair doit se terminer par
            // l'octet delimiteur de fin d'enregistrement `0x02`. C'est
            // exactement ce que `padPayload()` ajoute — `$charge.chr(2)` —
            // et appeler `encrypt()` sans lui produit un enregistrement
            // MALFORME.
            //
            // Le symptome etait indetectable depuis le serveur : le
            // fournisseur ne regarde jamais le chiffre, il repondait donc 201,
            // et c'est Chrome qui, apres avoir dechiffre correctement,
            // rejetait l'enregistrement et jetait le message EN SILENCE. Un
            // push SANS charge utile arrivait, lui, parfaitement — c'est cette
            // bissection qui a isole le defaut.
            //
            // L'aller-retour de dechiffrement que j'avais ecrit en PHP ne
            // pouvait pas le voir : il verifiait que le JSON revenait intact,
            // jamais que le delimiteur etait la. Une doublure ecrite avec les
            // memes hypotheses que le code teste ne prouve rien.
            //
            // Le rembourrage a une seconde vertu, qui justifie de le garder a
            // longueur FIXE : la taille du chiffre fuirait sinon la longueur
            // du message. Toutes nos notifications font donc le meme poids.
            $rembourree = Encryption::padPayload(
                $charge,
                Encryption::MAX_COMPATIBILITY_PAYLOAD_LENGTH,
                ContentEncoding::aes128gcm,
            );

            $chiffre = Encryption::encrypt($rembourree, $appareil->p256dh, $appareil->auth, ContentEncoding::aes128gcm);

            $corps = Encryption::getContentCodingHeader(
                $chiffre['salt'],
                $chiffre['localPublicKey'],
                ContentEncoding::aes128gcm,
            ).$chiffre['cipherText'];

            $origine = parse_url($appareil->endpoint, PHP_URL_SCHEME).'://'.parse_url($appareil->endpoint, PHP_URL_HOST);

            // Les clefs sont stockees en BASE64URL — c'est le format qu'exige
            // `applicationServerKey` cote navigateur, donc celui du `.env`.
            // Mais `VAPID::getVapidHeaders()` attend du BINAIRE BRUT : il fait
            // `bin2hex()` et verifie que le premier octet vaut `04`. Lui
            // passer la chaine base64url leve « only uncompressed keys are
            // supported », un message qui accuse la forme de la clef alors que
            // c'est l'encodage qui ne va pas. D'ou ce decodage explicite.
            $entetes = VAPID::getVapidHeaders(
                $origine,
                (string) config('webpush.vapid.subject'),
                self::depuisBase64Url((string) config('webpush.vapid.public_key')),
                self::depuisBase64Url((string) config('webpush.vapid.private_key')),
                ContentEncoding::aes128gcm,
            );

            // `Http::` et non le client interne de la bibliotheque : voir le
            // docblock de la classe. C'est ce qui garde `ExternalEgressGuard`
            // en autorite sur cet envoi.
            $reponse = Http::withHeaders($entetes + [
                'Content-Type' => 'application/octet-stream',
                'Content-Encoding' => 'aes128gcm',
                'TTL' => (string) self::TTL,
                // TASK-1740 — `high` et non `normal`, et ce n'est pas un
                // confort.
                //
                // Android DIFFERE les messages d'urgence `normal` quand
                // l'appareil est en veille profonde (Doze) : ils attendent la
                // prochaine fenetre de maintenance, parfois des dizaines de
                // minutes. Le fournisseur a bien repondu 201 — il a accepte et
                // mis en file — et l'appareil n'affiche rien. C'est exactement
                // le symptome que nous avons observe, et il ne se distingue
                // pas d'une panne tant qu'on attend.
                //
                // Une notification destinee a etre VUE par une personne est
                // `high` par definition : `userVisibleOnly` a ete impose a
                // l'abonnement, le navigateur exige donc un affichage.
                'Urgency' => 'high',
            ])
                // TASK-1740 — le MARQUEUR de transport. C'est le seul endroit
                // du depot qui le pose, et c'est la troisieme des quatre
                // conditions de `ExternalEgressGuard::sortieDeRecetteAutorisee()`.
                //
                // Sans lui, une requete HTTP ordinaire vers `fcm.googleapis.com`
                // reste REFUSEE meme quand le drapeau de recette est actif :
                // l'exception porte sur CE transport, pas sur ces domaines.
                ->withOptions([ExternalEgressGuard::OPTION_TRANSPORT_PUSH => true])
                ->withBody($corps, 'application/octet-stream')
                ->timeout(self::TIMEOUT)
                ->post($appareil->endpoint);

            $this->dernierCodeHttp = $reponse->status();

            if (in_array($reponse->status(), [404, 410], true)) {
                $this->dernierMotif = 'endpoint revoque par le fournisseur (HTTP '.$reponse->status().')';

                return 'revoque';
            }

            if ($reponse->successful()) {
                // TASK-1740 — un 2xx n'est PAS une livraison.
                //
                // L'endpoint historique `/fcm/send/` rend ses echecs dans le
                // CORPS, sous un statut 2xx : un `failure` a 1, ou un
                // `results[].error` a `NotRegistered`. Ne lire que le statut
                // laissait donc passer un echec en le nommant « reussite » —
                // c'est precisement la confusion que MASTER a demande de ne
                // pas commettre, et elle a coute plusieurs essais a l'aveugle.
                //
                // TASK-1822 — ce docblock decrivait le defaut depuis T1740,
                // mais le code rendait `ok` quoi qu'il arrive : le corps
                // n'etait range que dans `$dernierMotif`. Mesure en T1821 :
                // 4 abonnements sur 6 emploient cet endpoint historique,
                // dont 3 en production. Le corps est desormais LU.
                $corpsReponse = trim($reponse->body());

                $this->dernierMotif = $corpsReponse === ''
                    ? ''
                    : 'le fournisseur a repondu : '.mb_substr($corpsReponse, 0, 300);

                return $this->verdictSurCorps2xx($corpsReponse);
            }

            $this->dernierMotif = 'refus du fournisseur (HTTP '.$reponse->status().')';

            return 'echec';
        } catch (Throwable $e) {
            // Le garde d'egress leve ici quand la base visee est un jumeau de
            // PRODUCTION. C'est un refus VOULU, pas une panne : on le
            // journalise sans le maquiller en succes.
            $this->dernierCodeHttp = null;
            $this->dernierMotif = mb_substr($e->getMessage(), 0, 160);

            Log::warning('TASK-1740 depot Push refuse ou en echec.', [
                'subscription_id' => (string) $appareil->id,
                'raison' => $this->dernierMotif,
            ]);

            return 'echec';
        }
    }

    /**
     * TASK-1822 — lire un corps de reponse 2xx, et n'en conclure que ce
     * qu'il dit vraiment.
     *
     * ## Le cas reel que cette methode traite
     *
     * L'endpoint FCM historique `/fcm/send/` repond `200` et place l'echec
     * dans le corps, sous une forme documentee par Google :
     *
     *     {"multicast_id":…,"success":0,"failure":1,"canonical_ids":0,
     *      "results":[{"error":"NotRegistered"}]}
     *
     * Les autres fournisseurs — Apple (`web.push.apple.com`), FCM moderne
     * (`/wp/`), Mozilla — rendent un corps VIDE sur succes. Pour eux, rien
     * ne change : un corps vide reste `ok`.
     *
     * ## Les trois verdicts, et pourquoi la suppression est la plus prudente
     *
     * `revoque` DETRUIT la ligne cote serveur. On ne le rend donc que sur
     * les deux erreurs qui etablissent qu'un enregistrement n'existe plus —
     * `NotRegistered` et `InvalidRegistration` — exactement les memes que
     * les statuts HTTP 404/410 traites plus haut.
     *
     * `MismatchSenderId` n'en fait PAS partie, et c'est delibere : il dit
     * que l'envoi vient d'une autre identite VAPID, pas que l'appareil a
     * disparu. Supprimer la ligne sur ce motif detruirait un abonnement
     * parfaitement vivant a cause d'un probleme de CLEF — c'est-a-dire le
     * defaut meme que cette TASK corrige, retourne en perte de donnees.
     * Idem pour `Unavailable` ou `InternalServerError`, qui sont
     * temporaires : `echec`, et la ligne reste.
     *
     * ## Un corps illisible ne vaut pas un echec
     *
     * Si le corps n'est pas du JSON, ou ne porte ni `failure` ni `results`,
     * on ne conclut RIEN et on garde `ok` : inventer un echec sur une forme
     * inattendue casserait des fournisseurs qui fonctionnent. On ne traite
     * comme echec que ce qu'on a LU comme tel.
     *
     * @return 'ok'|'revoque'|'echec'
     */
    private function verdictSurCorps2xx(string $corps): string
    {
        if ($corps === '') {
            return 'ok';
        }

        $decode = json_decode($corps, true);

        if (! is_array($decode)) {
            return 'ok';
        }

        $resultats = is_array($decode['results'] ?? null) ? $decode['results'] : [];
        $echecs = (int) ($decode['failure'] ?? 0);

        $erreurs = [];

        foreach ($resultats as $resultat) {
            if (is_array($resultat) && isset($resultat['error']) && is_string($resultat['error'])) {
                $erreurs[] = $resultat['error'];
            }
        }

        if ($echecs < 1 && $erreurs === []) {
            return 'ok';
        }

        // Une erreur EXPLICITE a ete lue : la livraison ne peut plus etre
        // comptee comme acceptee, quel que soit le statut HTTP.
        $this->erreursFournisseurExplicites++;
        $this->dernierMotif = 'erreur fournisseur sous HTTP 2xx : '
            .($erreurs === [] ? 'failure='.$echecs : implode(', ', $erreurs));

        // Les deux seules erreurs qui etablissent qu'un enregistrement
        // n'existe plus. Memes consequences que 404/410.
        $revocations = ['NotRegistered', 'InvalidRegistration'];

        foreach ($erreurs as $erreur) {
            if (in_array($erreur, $revocations, true)) {
                return 'revoque';
            }
        }

        return 'echec';
    }

    /**
     * La charge utile, volontairement PAUVRE.
     *
     * CDC 6.4 : un Push s'affiche sur un ecran verrouille, devant n'importe
     * qui. Le titre vient du catalogue, l'URL est une route same-origin que le
     * serveur re-autorisera au clic, et rien d'autre ne voyage. Aucun nom de
     * personne, aucun extrait de message.
     *
     * TASK-1744 — le corps peut nommer une Boucle PUBLIQUE, et seulement elle.
     * Le CDC ecrit « pas de nom de Boucle PRIVEE » (6.2) et « aucun nom de
     * Boucle CONFIDENTIELLE » (6.4) : la distinction est dans le texte, elle
     * n'est pas inventee ici. Une Boucle publique est deja lisible par
     * n'importe qui sans etre connecte — son nom sur un ecran verrouille
     * n'apprend rien que la personne ne pourrait voir en ouvrant le site.
     */
    private function chargeUtile(MemberNotification $notification, string $cible): string
    {
        $cle = (string) $notification->notification_key;
        $titre = 'notifications.push_title.'.str_replace('.', '_', $cle);
        $corps = 'notifications.push_body.'.str_replace('.', '_', $cle);

        return (string) json_encode([
            'title' => \Illuminate\Support\Facades\Lang::has($titre) ? __($titre) : __('notifications.push_title_fallback'),

            // Le nom d'une Boucle publique vaut mieux que n'importe quelle
            // phrase : c'est l'information que la personne cherche. A defaut,
            // une phrase propre a l'evenement, et « Touchez pour voir » en
            // dernier recours seulement.
            'body' => $this->nomDeBouclePublique($notification)
                ?? (\Illuminate\Support\Facades\Lang::has($corps) ? __($corps) : __('notifications.push_body_generic')),

            // TASK-1744 — un CHEMIN, jamais une URL absolue.
            //
            // Le resolveur rend une URL absolue, batie sur `APP_URL` : c'est
            // ce qu'il faut pour un email. Dans un Push, c'est un defaut.
            // Le Service Worker REFUSE une cible qui n'est pas de son origine
            // — a raison, sinon une charge distante deviendrait une
            // redirection ouverte — et retombe alors sur `/`.
            //
            // Mesure du 02/11 : la charge est fabriquee par le WORKER, donc
            // hors requete, donc `APP_URL` (`https://test.laravel`) ; la PWA
            // tournait sur un tunnel. Origines differentes, cible rejetee,
            // clic ouvrant la PAGE D'ACCUEIL au lieu de la Boucle.
            //
            // Un chemin relatif supprime la question : le Service Worker le
            // resout contre SA propre origine, donc il est same-origin par
            // construction. Cela vaut aussi en production, ou un `APP_URL`
            // errone produirait le meme silence.
            'url' => $this->cheminDe($cible),

            // `tag` : un second Push du MEME FAIT remplace le precedent au
            // lieu de s'empiler (anti-bruit, CDC 9.3).
            //
            // TASK-1744 — le tag porte desormais l'OBJET, pas seulement la
            // cle, et ce n'est pas un detail : la recette du 02/11 a vu deux
            // ajouts de Boucle a treize minutes d'intervalle ne produire
            // qu'UNE notification a l'ecran. Les deux partageaient le tag
            // `loop.member_added`, le second a donc REMPLACE le premier —
            // avec un titre et un corps identiques, le remplacement est
            // indiscernable de « rien n'est arrive ».
            //
            // « Etre ajoute a deux Boucles » sont DEUX faits : ils doivent
            // s'empiler. « Le meme ajout repousse deux fois » est UN fait :
            // il doit se remplacer. L'objet est exactement ce qui les
            // distingue.
            'tag' => $cle.($notification->object_id !== null ? ':'.$notification->object_id : ''),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Le chemin d'une URL, requete et fragment compris.
     *
     * Rend `/` plutot que `null` sur une entree illisible : une cible vide
     * laisse le Service Worker ouvrir l'accueil, ce qui est le comportement
     * deja prevu, et jamais une origine etrangere.
     */
    private function cheminDe(string $url): string
    {
        $morceaux = parse_url($url);

        if ($morceaux === false) {
            return '/';
        }

        $chemin = $morceaux['path'] ?? '/';

        if (($morceaux['query'] ?? '') !== '') {
            $chemin .= '?'.$morceaux['query'];
        }

        if (($morceaux['fragment'] ?? '') !== '') {
            $chemin .= '#'.$morceaux['fragment'];
        }

        return $chemin === '' ? '/' : $chemin;
    }

    /**
     * Le nom de la Boucle concernee, UNIQUEMENT si elle est publique.
     *
     * Rend `null` des que le moindre doute existe — Boucle privee, objet
     * d'un autre type, ligne introuvable, tenant qui ne correspond pas. Le
     * silence est le repli sur : on ne nomme jamais « au cas ou ».
     *
     * Les requetes sont ecrites hors Eloquent et bornees A LA MAIN par
     * `organization_id`, pour la meme raison qu'en T1720 : ce code tourne
     * dans un WORKER, ou `BelongsToOrganizationScope` appliquerait son
     * fail-closed et rendrait la Boucle introuvable EN SILENCE.
     */
    private function nomDeBouclePublique(MemberNotification $notification): ?string
    {
        $objetId = $notification->object_id;

        if ($objetId === null) {
            return null;
        }

        $boucleId = match ($notification->object_type) {
            NotificationCatalogue::OBJECT_LOOP => $objetId,
            NotificationCatalogue::OBJECT_LOOP_INVITATION => DB::table('loop_invitations')->where('id', $objetId)->value('loop_id'),
            NotificationCatalogue::OBJECT_LOOP_JOIN_REQUEST => DB::table('loop_join_requests')->where('id', $objetId)->value('loop_id'),
            // TASK-1772 — sans cette ligne, le Push d'une mention perdrait le
            // nom de la Boucle PUBLIQUE : le corps retomberait sur son texte
            // generique, alors qu'il a le droit de nommer une Boucle publique.
            // `DB::table` et non Eloquent : aucun scope global ne doit
            // intervenir, ce code tourne sous worker.
            NotificationCatalogue::OBJECT_LOOP_MESSAGE => DB::table('loop_messages')->where('id', $objetId)->value('loop_id'),

            // Un article co-ecrit est le plus souvent un BROUILLON : son titre
            // n'a rien a faire sur un ecran verrouille.
            default => null,
        };

        if ($boucleId === null) {
            return null;
        }

        $boucle = DB::table('loops')
            ->where('id', $boucleId)
            ->where('organization_id', $notification->organization_id)
            ->first(['name', 'visibility']);

        if ($boucle === null || $boucle->visibility !== 'public') {
            return null;
        }

        $nom = trim((string) $boucle->name);

        return $nom === '' ? null : $nom;
    }

    /**
     * La prise de travail ATOMIQUE — copie conforme du livreur email.
     *
     * Un seul UPDATE conditionne par `status = pending`. Deux workers qui se
     * disputent la meme livraison : un seul obtient une ligne modifiee.
     */
    private function prendre(MemberNotificationDelivery $livraison): bool
    {
        $lignes = DB::table('member_notification_deliveries')
            ->where('id', $livraison->id)
            ->where('status', NotificationDeliveryStatus::PENDING)
            ->update([
                'status' => NotificationDeliveryStatus::SENDING,
                'claimed_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($lignes !== 1) {
            return false;
        }

        $livraison->refresh();

        return true;
    }

    private function abandonner(MemberNotificationDelivery $livraison, string $statut, string $motif): void
    {
        $livraison->forceFill(['status' => $statut, 'diagnostic' => $motif])->save();
    }

    /**
     * TASK-1779 — conclure sans jamais relancer vers l'appelant.
     *
     * On est deja dans le `catch` d'un incident : si l'ecriture du statut
     * echoue a son tour — c'est le cas le plus probable, puisque l'incident
     * d'origine est souvent la base elle-meme — relancer ferait remonter la
     * SECONDE exception et masquerait completement la premiere. Le seul
     * resultat serait une 500 sur la publication, exactement ce que cette
     * TASK ferme.
     *
     * Le point de sauvegarde vaut pour la meme raison qu'ailleurs dans ce
     * module : sur PostgreSQL, une requete qui echoue abandonne la
     * transaction courante, et l'appelant peut nous avoir appele depuis la
     * sienne. Meme parade qu'en T1372 et dans le livreur email.
     */
    private function abandonnerSansEchouer(MemberNotificationDelivery $livraison, string $statut, string $motif): void
    {
        try {
            DB::transaction(fn () => $this->abandonner($livraison, $statut, $motif));
        } catch (Throwable $incident) {
            report($incident);
        }
    }
}
