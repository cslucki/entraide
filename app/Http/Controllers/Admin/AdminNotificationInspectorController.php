<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationDeliveryStatus;
use App\Support\Notifications\NotificationWebPushDeliverer;
use App\Support\Push\PushTestPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * TASK-1744 (demande Cyril, 02/11) — LIRE les notifications emises, et en
 * REJOUER une.
 *
 * ## Pourquoi un ecran de plus, alors que le cockpit existe
 *
 * `AdminNotificationCockpitController` (T1380) repond a UNE question : « le
 * systeme fonctionne-t-il, et sinon ou est-ce coince ? ». Son docblock est
 * explicite : « Il ne sert pas a lire les notifications de quelqu'un, ni a en
 * renvoyer une. Il COMPTE. » Lui ajouter des destinataires et un bouton de
 * rejeu contredirait sa doctrine au lieu de l'etendre.
 *
 * Ce que demande la recette est exactement l'inverse : voir les lignes UNE
 * PAR UNE, avec leur destinataire et le diagnostic de chaque canal. Deux
 * besoins opposes, deux ecrans — et le cockpit reste ce qu'il dit etre.
 *
 * ## Ce que cet ecran a coute de ne pas avoir
 *
 * Trois recettes Push ont ete diagnostiquees a coups de `php artisan tinker`
 * dans un terminal, parce que rien dans l'application ne disait pourquoi un
 * Push n'etait pas parti. La reponse tenait pourtant en un mot —
 * `skipped_preference` — ecrit en base depuis le debut.
 *
 * ## Le rejeu est BORNE aux comptes de recette
 *
 * Et par la MEME politique que l'outil de test : {@see PushTestPolicy}. Le
 * jumeau `bouclepro_prod_work` porte de VRAIES adresses — rejouer une
 * notification de 2024 vers le telephone d'une vraie personne serait une
 * sollicitation qu'elle n'a pas demandee, depuis un environnement de travail.
 *
 * Reutiliser la politique plutot que reecrire un `str_ends_with('.test')`
 * ici : deux copies d'une regle de securite finissent toujours par diverger,
 * et c'est la plus laxiste qui gagne.
 *
 * ## Le rejeu ne FABRIQUE aucun evenement metier
 *
 * Il remet une LIVRAISON a `pending` et redemande au livreur de la traiter.
 * Aucune `MemberNotification` n'est creee, aucun `event_id` n'est invente :
 * le fait metier reste celui qui a eu lieu. C'est la difference avec l'outil
 * de test, qui lui n'ecrit rien du tout.
 */
class AdminNotificationInspectorController extends Controller
{
    private const PAR_PAGE = 40;

    public function __construct(private readonly PushTestPolicy $politique) {}

    public function index(Request $request): View
    {
        $recherche = trim((string) $request->query('q', ''));
        $cle = (string) $request->query('cle', '');
        $canal = (string) $request->query('canal', '');

        // Pas de portee globale sur `MemberNotification` : elle retomberait
        // sur `0 = 1` sous worker. La garde est le groupe de routes
        // ['auth','admin'] — meme raisonnement que le cockpit T1380.
        $requete = MemberNotification::query()->orderByDesc('created_at');

        if ($cle !== '' && NotificationCatalogue::has($cle)) {
            $requete->where('notification_key', $cle);
        }

        if ($recherche !== '') {
            // Sur l'ADRESSE du destinataire, insensible a la casse : c'est
            // ainsi qu'on cherche quelqu'un, pas par UUID.
            $requete->whereIn(
                'recipient_id',
                User::query()->whereRaw('LOWER(email) LIKE ?', ['%'.mb_strtolower($recherche).'%'])->select('id')
            );
        }

        if ($canal !== '') {
            $requete->whereIn(
                'id',
                MemberNotificationDelivery::query()->where('channel', $canal)->select('notification_id')
            );
        }

        $notifications = $requete->paginate(self::PAR_PAGE)->withQueryString();

        // Les livraisons et les destinataires en DEUX requetes, pas en N+1 :
        // quarante lignes a trois canaux feraient cent vingt aller-retours.
        $ids = collect($notifications->items())->pluck('id')->all();

        $livraisons = MemberNotificationDelivery::query()
            ->whereIn('notification_id', $ids)
            ->get()
            ->groupBy('notification_id');

        $destinataires = User::query()
            ->whereIn('id', collect($notifications->items())->pluck('recipient_id')->filter()->all())
            ->get(['id', 'email', 'name'])
            ->keyBy('id');

        return view('admin.outils.notifications', [
            'notifications' => $notifications,
            'livraisons' => $livraisons,
            'destinataires' => $destinataires,
            'cles' => NotificationCatalogue::keys(),
            'filtres' => ['q' => $recherche, 'cle' => $cle, 'canal' => $canal],
            'rejouables' => $this->politique->destinatairesAdmis()->pluck('id')->flip(),

            // TASK-1744 — combien d'appareils chaque destinataire a-t-il
            // REELLEMENT ? Un rejeu sans appareil ne peut que conclure
            // `all_devices_revoked` : offrir le bouton quand meme, c'est
            // promettre une action impossible. Mesure du 02/11.
            'appareils' => $this->politique->comptesDAppareilsEnvoyables(
                $destinataires->keys()->map(fn ($id) => (string) $id)->all()
            ),
        ]);
    }

    /**
     * Rejouer la livraison Web Push d'une notification EXISTANTE.
     *
     * Trois gardes, dans cet ordre, et chacune peut refuser seule :
     * le destinataire doit etre un compte de recette, la cle doit autoriser
     * le canal AUJOURD'HUI, et la livraison doit exister ou pouvoir etre
     * creee.
     */
    public function replay(Request $request, string $notification): RedirectResponse
    {
        $modele = MemberNotification::query()->find($notification);

        if ($modele === null) {
            return back()->with('inspecteur_resultat', __('admin.notif_inspector_not_found'));
        }

        // 1 — le destinataire. La politique fait autorite, pas le formulaire :
        // un identifiant se falsifie, une politique relue ne se falsifie pas.
        $destinataire = User::query()->find($modele->recipient_id);

        if (! $this->politique->destinataireEstAdmis($destinataire)) {
            return back()->with('inspecteur_resultat', __('admin.notif_inspector_recipient_refused'));
        }

        // 2 — le catalogue, relu MAINTENANT. Une notification emise avant
        // l'ouverture du canal n'avait pas de livraison Push ; elle en merite
        // une si la cle l'autorise desormais. L'inverse vaut aussi : une cle
        // dont on retirerait le canal ne doit plus rien pouvoir rejouer.
        $cle = (string) $modele->notification_key;

        if (! NotificationCatalogue::allowsChannel($cle, NotificationCatalogue::CHANNEL_WEB_PUSH)) {
            return back()->with('inspecteur_resultat', __('admin.notif_inspector_channel_refused'));
        }

        // 3 — la livraison. On la REMET a `pending` plutot que d'en creer une
        // seconde : l'unicite (notification, canal) l'interdit, et surtout
        // deux lignes pour un fait rendraient le diagnostic illisible.
        $livraison = MemberNotificationDelivery::query()
            ->where('notification_id', $modele->id)
            ->forChannel(NotificationCatalogue::CHANNEL_WEB_PUSH)
            ->first();

        if ($livraison === null) {
            $livraison = MemberNotificationDelivery::create([
                'notification_id' => (string) $modele->id,
                'channel' => NotificationCatalogue::CHANNEL_WEB_PUSH,
            ]);
        } else {
            // La porte NOMMEE du modele, et la seule qui existe. Un
            // `forceFill(['status' => 'pending'])` est refuse par le hook
            // `updating` : la machine d'etats de T1377 est sans retour, et
            // elle le reste pour tout ce qui n'est pas ce geste humain.
            $livraison->rouvrirPourRejeu();
        }

        Log::info('TASK-1744 rejeu d une livraison Web Push depuis l administration.', [
            'notification_id' => (string) $modele->id,
            'notification_key' => $cle,
            'par' => (string) $request->user()?->id,
        ]);

        // SYNCHRONE : l'administrateur doit voir le resultat, pas un accuse
        // de mise en file. C'est tout l'interet de l'ecran.
        app(NotificationWebPushDeliverer::class)->deliver((string) $modele->id);

        $apres = $livraison->fresh();

        return back()->with('inspecteur_resultat', __('admin.notif_inspector_replayed', [
            'statut' => (string) $apres?->status,
            'diagnostic' => (string) ($apres?->diagnostic ?: '—'),
        ]));
    }
}
