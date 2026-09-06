<?php

namespace App\Http\Controllers;

use App\Models\MemberNotificationPreference;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationPreferenceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1375 — l'ecran de reglages des notifications.
 *
 * ## Le proprietaire ne vient JAMAIS de la requete
 *
 * `user_id` est hors de `$fillable`, et il est pose ici depuis l'utilisateur
 * authentifie. Un `create($request->validated())` un peu rapide laisserait
 * sinon n'importe qui ecrire les reglages de n'importe qui — et une page de
 * preferences est precisement l'endroit ou l'on manipule des donnees venues du
 * client.
 *
 * ## Un controleur, pas un composant Livewire
 *
 * Meme raison qu'au Centre de notifications : les actions Livewire passent par
 * `/livewire-{hash}/update`, et un instantane est une capacite durable, sans
 * nonce ni expiration. Un POST suivi d'une redirection n'a aucun de ces
 * problemes.
 *
 * ## Cet ecran n'invente aucun reglage
 *
 * Il affiche ce que le catalogue declare, et rien d'autre. Un canal obligatoire
 * s'y montre **sans bouton** plutot que de disparaitre : le membre a le droit de
 * savoir ce qui lui sera envoye, meme quand il ne peut pas le changer.
 */
class NotificationPreferenceController extends Controller
{
    public function edit(Request $request, NotificationPreferenceResolver $resolver): View
    {
        $user = $request->user();

        abort_unless($user !== null, 404);

        $slug = $request->route('organization');

        return view('notifications.preferences', [
            'etat' => $resolver->overview($user),
            'routeUpdate' => $slug ? 'organization.notifications.preferences.update' : 'notifications.preferences.update',
            'routeParams' => $slug ? ['organization' => $slug] : [],
            'routeCentre' => $slug ? 'organization.notifications.index' : 'notifications.index',
        ]);
    }

    /**
     * Enregistrer les ecarts.
     *
     * Ce qui est envoye par le client est traite comme une INTENTION, jamais
     * comme un etat a recopier : chaque couple (cle, canal) est confronte au
     * catalogue, et tout ce qui n'y figure pas — ou qui vise un canal
     * obligatoire — est ignore sans bruit. Une valeur inconnue n'est pas une
     * erreur a signaler, c'est une valeur a ne pas suivre.
     *
     * Et l'ecart n'est stocke QUE s'il differe du defaut. Revenir au defaut
     * supprime la ligne : le membre retrouve alors le comportement produit, y
     * compris si celui-ci change plus tard.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user !== null, 404);

        $demande = $request->input('canaux', []);
        $demande = is_array($demande) ? $demande : [];

        foreach (NotificationCatalogue::keys() as $cle) {
            foreach (NotificationCatalogue::channelsFor($cle) as $canal) {
                if (! NotificationCatalogue::channelIsConfigurable($cle, $canal)) {
                    continue;
                }

                $voulu = (bool) ($demande[$cle][$canal] ?? false);
                $defaut = (bool) NotificationCatalogue::channelDefault($cle, $canal);

                $existant = MemberNotificationPreference::query()
                    ->forOwner((string) $user->id)
                    ->where('notification_key', $cle)
                    ->where('channel', $canal)
                    ->first();

                if ($voulu === $defaut) {
                    $existant?->delete();

                    continue;
                }

                if ($existant !== null) {
                    $existant->update(['enabled' => $voulu]);

                    continue;
                }

                $preference = new MemberNotificationPreference([
                    'notification_key' => $cle,
                    'channel' => $canal,
                    'enabled' => $voulu,
                ]);

                // Le proprietaire est pose ICI, jamais recu.
                $preference->user_id = (string) $user->id;
                $preference->save();
            }
        }

        return back()->with('notification_preferences_saved', __('notifications.preferences_saved'));
    }
}
