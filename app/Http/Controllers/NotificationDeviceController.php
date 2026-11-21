<?php

namespace App\Http\Controllers;

use App\Services\Push\PushSubscriptionStore;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationPreferenceResolver;
use App\Support\Notifications\NotificationWebPushDeliverer;
use App\Support\Push\PushTestPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * TASK-1877 — « Notifications sur cet appareil », sur sa propre page.
 *
 * Le bloc vivait sur l'ecran des preferences depuis T1739. Il y est retire
 * tel quel : meme partiel, meme script, memes routes d'abonnement. Cette
 * page n'ajoute qu'UNE capacite — un test Web Push reel.
 *
 * ## Le test ne vise QUE l'appareil courant de la personne connectee
 *
 * Le serveur ne sait pas quel appareil est « celui-ci » : seul le navigateur
 * connait son endpoint. Il l'envoie donc, et le serveur le traite comme une
 * DESIGNATION, jamais comme une autorisation :
 *
 * 1. `trouverParEndpoint()` filtre sur l'empreinte ET sur le proprietaire
 *    authentifie — l'endpoint d'un autre compte ne designe rien ;
 * 2. `PushTestPolicy::abonnementsEnvoyables()` reapplique les filtres de
 *    l'envoi : abonnement cree sur CET environnement, clef VAPID en cours.
 *    Un abonnement importe d'un dump, ou porte par une clef retiree, n'est
 *    pas envoyable — le test ne promet pas ce que l'envoi reel refuserait.
 *
 * La charge est FIXE, ecrite ici. Rien du client n'y entre.
 *
 * ## Un 2xx n'est pas une reception
 *
 * Le fournisseur qui accepte n'a pas affiche la notification. La reponse
 * rend donc un VERDICT (`envoye`, `revoque`, `echec`), et l'ecran demande a
 * la personne de verifier elle-meme l'arrivee. Le code HTTP brut et le motif
 * technique ne sortent qu'en local (arbitrage MASTER du 21/11).
 */
class NotificationDeviceController extends Controller
{
    public function edit(Request $request, NotificationPreferenceResolver $resolver): View
    {
        $user = $request->user();

        abort_unless($user !== null, 404);

        $slug = $request->route('organization');
        $routeParams = $slug ? ['organization' => $slug] : [];

        // TASK-1744 — combien d'evenements cette personne a-t-elle ouverts au
        // Push ? Calcul deplace a l'identique depuis `preferences.blade.php` :
        // un appareil enregistre sans aucune case cochee ne recoit RIEN, et
        // le panneau doit le dire.
        $pushActifs = collect($resolver->overview($user))
            ->filter(fn (array $canaux) => ($canaux[NotificationCatalogue::CHANNEL_WEB_PUSH]['enabled'] ?? false) === true)
            ->count();

        return view('notifications.device', [
            'pushActifs' => $pushActifs,
            'routeParams' => $routeParams,
            'routePush' => $slug ? 'organization.notifications.push.store' : 'notifications.push.store',
            'routeTest' => $slug ? 'organization.notifications.device.test' : 'notifications.device.test',
            'routePreferences' => $slug ? 'organization.notifications.preferences.edit' : 'notifications.preferences.edit',
        ]);
    }

    public function test(
        Request $request,
        PushSubscriptionStore $magasin,
        PushTestPolicy $politique,
        NotificationWebPushDeliverer $livreur,
    ): JsonResponse {
        $user = $request->user();

        abort_unless($user !== null, 404);

        // Meme interrupteur d'exploitation que l'abonnement.
        if (! (bool) config('webpush.enabled')) {
            return response()->json(['resultat' => 'indisponible', 'message' => __('push.disabled')], 404);
        }

        $valide = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000'],
        ]);

        $ligne = $magasin->trouverParEndpoint($user, $valide['endpoint']);

        $appareil = $ligne === null
            ? null
            : $politique->abonnementsEnvoyables($user)->whereKey($ligne->getKey())->first();

        if ($appareil === null) {
            return response()->json(['resultat' => 'inconnu', 'message' => __('push.test_unknown')], 404);
        }

        $resultats = $livreur->envoyerUnTest(collect([$appareil]), [
            'title' => __('push.test_payload_title'),
            'body' => __('push.test_payload_body'),
            'url' => route(
                $request->route('organization') ? 'organization.notifications.device.edit' : 'notifications.device.edit',
                $request->route('organization') ? ['organization' => $request->route('organization')] : [],
            ),
            'tag' => 'bouclepro-test-appareil',
        ]);

        $resultat = match (true) {
            $resultats['ok'] > 0 => 'envoye',
            $resultats['revoque'] > 0 => 'revoque',
            default => 'echec',
        };

        // Ni endpoint, ni clef : le reperage court suffit a retrouver l'essai.
        Log::info('TASK-1877 test Web Push sur son propre appareil.', [
            'user_id' => (string) $user->id,
            'appareil' => $politique->reperageDe($appareil),
            'resultat' => $resultat,
        ]);

        $reponse = [
            'resultat' => $resultat,
            'message' => match ($resultat) {
                'envoye' => __('push.test_sent'),
                'revoque' => __('push.test_revoked'),
                default => __('push.test_failed'),
            },
        ];

        // Les details bruts (code HTTP, motif du fournisseur) restent un outil
        // de recette : local seulement, comme `data-push-debug`.
        if (app()->environment('local')) {
            $reponse['detail'] = array_merge($resultats['http'], $resultats['motifs']);
        }

        return response()->json($reponse);
    }
}
