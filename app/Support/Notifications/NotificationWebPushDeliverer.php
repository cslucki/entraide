<?php

namespace App\Support\Notifications;

use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Encryption;
use Minishlink\WebPush\VAPID;

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

        $this->livrer($notification, $livraison);
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

        if (! $preferences->allows($destinataire, (string) $notification->notification_key, NotificationCatalogue::CHANNEL_WEB_PUSH)) {
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
        $this->abandonner(
            $livraison,
            $revoques > 0 ? NotificationDeliveryStatus::SKIPPED_UNREACHABLE : NotificationDeliveryStatus::FAILED,
            $revoques > 0 ? 'all_devices_revoked' : 'push_service_unreachable',
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
     * @return array{ok: int, revoque: int, echec: int, motifs: list<string>}
     */
    public function envoyerUnTest($appareils, array $charge): array
    {
        $compte = ['ok' => 0, 'revoque' => 0, 'echec' => 0];
        $motifs = [];

        foreach ($appareils as $appareil) {
            $resultat = $this->deposer($appareil, (string) json_encode($charge, JSON_UNESCAPED_UNICODE));
            $compte[$resultat]++;

            if ($resultat === 'revoque') {
                $appareil->delete();
            }

            if ($resultat !== 'ok') {
                $motifs[] = ($appareil->device_label ?? 'appareil').' : '.$this->dernierMotif;
            }
        }

        return $compte + ['motifs' => $motifs];
    }

    /** Le motif du dernier depot en echec, pour que le diagnostic puisse le dire. */
    private string $dernierMotif = '';

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
            $chiffre = Encryption::encrypt($charge, $appareil->p256dh, $appareil->auth, ContentEncoding::aes128gcm);

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
                'Urgency' => 'normal',
            ])->withBody($corps, 'application/octet-stream')
                ->timeout(self::TIMEOUT)
                ->post($appareil->endpoint);

            if (in_array($reponse->status(), [404, 410], true)) {
                $this->dernierMotif = 'endpoint revoque par le fournisseur (HTTP '.$reponse->status().')';

                return 'revoque';
            }

            if ($reponse->successful()) {
                $this->dernierMotif = '';

                return 'ok';
            }

            $this->dernierMotif = 'refus du fournisseur (HTTP '.$reponse->status().')';

            return 'echec';
        } catch (\Throwable $e) {
            // Le garde d'egress leve ici quand la base visee est un jumeau de
            // PRODUCTION. C'est un refus VOULU, pas une panne : on le
            // journalise sans le maquiller en succes.
            $this->dernierMotif = mb_substr($e->getMessage(), 0, 160);

            Log::warning('TASK-1740 depot Push refuse ou en echec.', [
                'subscription_id' => (string) $appareil->id,
                'raison' => $this->dernierMotif,
            ]);

            return 'echec';
        }
    }

    /**
     * La charge utile, volontairement PAUVRE.
     *
     * CDC 6.4 : un Push s'affiche sur un ecran verrouille, devant n'importe
     * qui. Le titre vient du catalogue, l'URL est une route same-origin que le
     * serveur re-autorisera au clic, et rien d'autre ne voyage. Aucun nom de
     * personne, aucun nom de Boucle, aucun extrait de message.
     */
    private function chargeUtile(MemberNotification $notification, string $cible): string
    {
        $cle = (string) $notification->notification_key;
        $titre = 'notifications.push_title.'.str_replace('.', '_', $cle);

        return (string) json_encode([
            'title' => \Illuminate\Support\Facades\Lang::has($titre) ? __($titre) : __('notifications.push_title_fallback'),
            'body' => __('notifications.push_body_generic'),
            'url' => $cible,
            // `tag` : un second Push de la MEME cle REMPLACE le precedent au
            // lieu de s'empiler. C'est l'anti-bruit du CDC 9.3, et il ne
            // pretend pas etre un resume periodique.
            'tag' => $cle,
        ], JSON_UNESCAPED_UNICODE);
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
}
