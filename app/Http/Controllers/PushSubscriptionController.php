<?php

namespace App\Http\Controllers;

use App\Services\Push\PushSubscriptionStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TASK-1739 — s'abonner, se desabonner, et dire la verite sur l'etat.
 *
 * ## Trois routes, et pourquoi elles rendent du JSON
 *
 * Le navigateur est le seul a connaitre l'abonnement : lui seul a demande la
 * permission, lui seul detient l'`endpoint` que le fournisseur Push lui a
 * remis. Le serveur ne peut donc rien faire d'utile avant un geste cote
 * client, et c'est ce geste qui appelle ces routes — d'ou du JSON plutot que
 * des redirections.
 *
 * ## Ce que le serveur refuse de croire
 *
 * Tout ce qui arrive est une INTENTION, jamais un etat a recopier. En
 * particulier :
 *
 * - l'`endpoint` doit etre une URL `https` — un `http://` ou un
 *   `javascript:` n'est pas un fournisseur Push, et ne doit pas entrer en
 *   base, ne serait-ce que comme texte ;
 * - le proprietaire vient de la SESSION, jamais de la requete
 *   (`user_id` est hors `$fillable` du modele, et rien ici ne le contourne) ;
 * - `device_label` est borne et facultatif : c'est un repere pour la
 *   personne, pas une signature d'appareil (CDC 5.4 — ni IP, ni UA complet,
 *   ni localisation).
 *
 * ## Et ce qu'il ne promet pas
 *
 * S'abonner ne fait RIEN arriver : le canal `web_push` n'existe pas encore au
 * catalogue. L'ecran le dit explicitement plutot que de laisser croire a une
 * reception imminente (CDC 3.2 : « ne pas promettre la reception de Push si
 * elle n'est pas encore livree »).
 */
class PushSubscriptionController extends Controller
{
    public function __construct(private readonly PushSubscriptionStore $magasin) {}

    /**
     * Revendiquer cet appareil — ou reconfirmer un abonnement existant.
     *
     * La MEME route sert les deux : `pushsubscriptionchange` n'etant pas pris
     * en charge partout, le client reconfirme a la connexion et a chaque
     * reouverture de l'application (CDC 5.4). L'operation est idempotente.
     */
    public function store(Request $request): JsonResponse
    {
        if (! (bool) config('webpush.enabled')) {
            // Interrupteur d'exploitation : le Push peut etre retire de
            // l'interface sans toucher aux clefs.
            return response()->json(['message' => __('push.disabled')], 404);
        }

        $valide = $request->validate([
            // `active_url` est volontairement ABSENT : il resoudrait le DNS du
            // fournisseur a chaque abonnement, ce qui serait une sortie
            // reseau declenchee par une donnee d'entree.
            'endpoint' => ['required', 'string', 'max:1000', 'url', 'starts_with:https://'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'device_label' => ['nullable', 'string', 'max:60'],
        ]);

        $abonnement = $this->magasin->revendiquer(
            $request->user(),
            $valide['endpoint'],
            $valide['keys']['p256dh'],
            $valide['keys']['auth'],
            $valide['device_label'] ?? null,
        );

        $reponse = [
            'subscribed' => true,
            'device_count' => $this->magasin->appareilsDe($request->user())->count(),
            'last_seen_at' => $abonnement->last_seen_at?->toIso8601String(),
        ];

        // TASK-1821 — en LOCAL uniquement : le libelle et l'empreinte de
        // CET abonnement (celui qu'on vient de revendiquer, jamais une
        // ligne choisie arbitrairement), pour que la personne en recette
        // puisse verifier qu'elle regarde bien SON appareil. On reutilise
        // des colonnes deja ecrites par `revendiquer()`, sans requete ni
        // collecte supplementaire.
        if (app()->environment('local')) {
            $reponse['device_label'] = $abonnement->device_label;
            $reponse['fingerprint'] = mb_substr($abonnement->endpoint_hash, 0, 8);
        }

        return response()->json($reponse);
    }

    /**
     * Renoncer a cet appareil.
     *
     * Le magasin filtre sur le proprietaire : un endpoint n'est pas un secret,
     * il voyage dans les requetes du navigateur, et sans ce filtre on pourrait
     * couper les notifications de quelqu'un d'autre.
     *
     * Zero ligne retiree n'est PAS une erreur : le navigateur peut avoir deja
     * oublie son abonnement de son cote.
     */
    public function destroy(Request $request): JsonResponse
    {
        $valide = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000'],
        ]);

        $retirees = $this->magasin->renoncer($request->user(), $valide['endpoint']);

        return response()->json([
            'subscribed' => false,
            'removed' => $retirees,
            'device_count' => $this->magasin->appareilsDe($request->user())->count(),
        ]);
    }

    /**
     * L'etat REEL, tel que le serveur le connait.
     *
     * Le serveur ne sait pas si le navigateur a accorde la permission — cela
     * ne se lit que cote client. Il sait seulement si CET endpoint lui est
     * connu pour CETTE personne. Les deux moities composent l'etat affiche,
     * et c'est pour cela qu'aucune des deux ne suffit.
     */
    public function show(Request $request): JsonResponse
    {
        $endpoint = (string) $request->query('endpoint', '');
        $abonne = $endpoint !== '' && $this->magasin->estAbonne($request->user(), $endpoint);

        $reponse = [
            'enabled' => (bool) config('webpush.enabled'),
            'subscribed' => $abonne,
            'device_count' => $this->magasin->appareilsDe($request->user())->count(),
        ];

        // TASK-1821 — meme regle qu'en `store()` : LOCAL uniquement, et
        // seulement pour l'abonnement qui correspond a CET endpoint, jamais
        // une ligne choisie au hasard parmi les appareils du compte.
        if ($abonne && app()->environment('local')) {
            $ligne = $this->magasin->trouverParEndpoint($request->user(), $endpoint);

            if ($ligne !== null) {
                $reponse['device_label'] = $ligne->device_label;
                $reponse['fingerprint'] = mb_substr($ligne->endpoint_hash, 0, 8);
            }
        }

        return response()->json($reponse);
    }
}
