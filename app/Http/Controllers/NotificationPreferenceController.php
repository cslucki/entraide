<?php

namespace App\Http\Controllers;

use App\Models\Loop;
use App\Models\LoopNotificationPreference;
use App\Models\MemberNotificationPreference;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationPreferenceResolver;
use Illuminate\Database\Eloquent\Collection;
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

        $etat = $resolver->overview($user);

        return view('notifications.preferences', [
            'etat' => $etat,

            // TASK-1775 (CDC B1) — « Mes Boucles », AVANT le reste de l'ecran.
            'mesBoucles' => $this->mesBoucles($user, $resolver),
            'routeLoopsUpdate' => $slug ? 'organization.notifications.preferences.loops.update' : 'notifications.preferences.loops.update',
            'routeLoopsIndex' => $slug ? 'organization.loops.index' : 'loops.index',

            // TASK-1746 (CDC §7A) — le meme etat, REGROUPE par intention.
            //
            // `etat` est conserve tel quel : il reste la forme plate, et
            // `update()` continue de raisonner sur le catalogue entier. On
            // n'ajoute qu'une LECTURE, aucune seconde verite.
            //
            // Les sections sont derivees du catalogue, jamais ecrites en dur :
            // une cle ajoutee demain declare son intention et prend sa place
            // seule. Une intention sans cle eligible est ECARTEE ici plutot
            // que dans la vue — un titre de section sans rien dessous serait
            // une promesse vide, et c'est le cas de la sollicitation
            // pertinente tant que le §9.2 n'existe pas.
            'sections' => collect(NotificationCatalogue::keysByIntention())
                ->map(fn (array $cles): array => array_intersect_key($etat, array_flip($cles)))
                ->reject(fn (array $cles): bool => $cles === [])
                ->all(),
            'routeUpdate' => $slug ? 'organization.notifications.preferences.update' : 'notifications.preferences.update',
            'routeParams' => $slug ? ['organization' => $slug] : [],
            'routeCentre' => $slug ? 'organization.notifications.index' : 'notifications.index',
            // TASK-1739 — la route des abonnements d'appareil, dans la forme
            // qui correspond a la page courante. Le bloc « cet appareil » y
            // envoie ses trois verbes (GET/POST/DELETE sur la meme URL).
            'routePush' => $slug ? 'organization.notifications.push.store' : 'notifications.push.store',
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

    /**
     * TASK-1775 (CDC B3) — enregistrer les reglages COLLECTIFS, Boucle par
     * Boucle.
     *
     * ## L'autorite n'est JAMAIS la requete
     *
     * La liste des Boucles traitees vient d'une requete DB sur l'acteur
     * authentifie — jamais des identifiants postes. Un identifiant de Boucle
     * qui ne figure pas dans CETTE liste est ignore sans bruit : il peut
     * designer une Boucle d'un autre tenant, une Boucle quittee entre le
     * chargement de l'ecran et la soumission, ou une valeur forgee — les
     * trois sont le meme cas pour ce controleur, « pas une Boucle que cette
     * personne peut regler maintenant ».
     *
     * ## Meme philosophie d'ecart que `update()`
     *
     * Les trois leviers par defaut (collecte active, email ACTIF, push ACTIF
     * depuis l'arbitrage du 07/11) et toute ligne qui y revient exactement est
     * supprimee plutot que conservee — le membre retrouve alors le comportement produit si celui-ci
     * change plus tard, et une base neuve n'a jamais besoin d'etre peuplee.
     */
    public function updateLoopPreferences(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user !== null, 404);

        $organization = $user->organization ?? currentOrganization();

        abort_unless($organization !== null, 404);

        $bouclesActives = $this->bouclesActives($user, $organization);

        $demande = $request->input('boucles', []);
        $demande = is_array($demande) ? $demande : [];

        foreach ($bouclesActives as $loop) {
            $loopId = (string) $loop->id;
            $champ = $demande[$loopId] ?? [];
            $champ = is_array($champ) ? $champ : [];

            // TASK-1775 (arbitrage Cyril, 07/11) — le maitre n'est plus un
            // levier EXPOSE : l'ecran ne poste plus `collective`, et meme
            // s'il le faisait, cette valeur serait ignoree. L'in-app est
            // desormais OBLIGATOIRE (catalogue), le maitre de Boucle reste un
            // garde-fou interne, jamais pilote par la requete.
            $collectif = true;
            $email = (bool) ($champ['email'] ?? false);
            $push = (bool) ($champ['web_push'] ?? false);

            $existant = LoopNotificationPreference::query()
                ->forOwner((string) $user->id)
                ->where('loop_id', $loopId)
                ->first();

            // Le defaut d'une Boucle fraichement rejointe (arbitrage Cyril,
            // 07/11) : collecte active, email ET push actifs — opt-OUT, pas
            // opt-IN.
            $estLeDefaut = $collectif === true && $email === true && $push === true;

            if ($estLeDefaut) {
                $existant?->delete();

                continue;
            }

            if ($existant !== null) {
                $existant->update([
                    'collective_enabled' => $collectif,
                    'email_enabled' => $email,
                    'web_push_enabled' => $push,
                ]);

                continue;
            }

            $reglage = new LoopNotificationPreference([
                'collective_enabled' => $collectif,
                'email_enabled' => $email,
                'web_push_enabled' => $push,
            ]);

            // L'identite est posee ICI, jamais recue.
            $reglage->organization_id = (string) $organization->id;
            $reglage->loop_id = $loopId;
            $reglage->user_id = (string) $user->id;
            $reglage->save();
        }

        return back()->with('notification_preferences_saved', __('notifications.preferences_saved'));
    }

    /**
     * L'etat « Mes Boucles » affiche par `edit()` : une entree par Boucle dont
     * la personne est membre ACTIF, dans SON Organization — jamais au-dela.
     *
     * ## Les cases AFFICHEES viennent du RESOLVEUR, jamais de la ligne brute
     *
     * Une Boucle jamais reglee individuellement n'a PAS forcement comme etat
     * effectif « push eteint » : un reglage GLOBAL historique (pose avant
     * cette TASK, depuis l'ecran « Une demande d'aide a ete publiee ») peut
     * deja l'avoir allume pour toutes les Boucles. Lire `$reglage?->champ ??
     * false` l'aurait ignore et affiche une case decochee qui MENT sur ce que
     * la personne recoit reellement — puis, au premier enregistrement sans
     * rien toucher, aurait ecrase ce choix en silence. `allows()` applique
     * deja la bonne priorite (Boucle, puis global, puis catalogue) : s'en
     * servir ICI AUSSI garantit qu'une case cochee au chargement reste cochee
     * apres un enregistrement ou rien n'a change.
     *
     * @return list<array{id: string, nom: string, collective_enabled: bool, email_enabled: bool, web_push_enabled: bool}>
     */
    private function mesBoucles(User $user, NotificationPreferenceResolver $resolver): array
    {
        $organization = $user->organization ?? currentOrganization();

        if ($organization === null) {
            return [];
        }

        $boucles = $this->bouclesActives($user, $organization);

        if ($boucles->isEmpty()) {
            return [];
        }

        $reglages = LoopNotificationPreference::query()
            ->forOwner((string) $user->id)
            ->whereIn('loop_id', $boucles->pluck('id'))
            ->get()
            ->keyBy('loop_id');

        return $boucles->map(function (Loop $loop) use ($reglages, $resolver, $user): array {
            $reglage = $reglages->get((string) $loop->id);

            return [
                'id' => (string) $loop->id,
                'nom' => (string) $loop->name,
                'collective_enabled' => $reglage?->collective_enabled ?? true,
                'email_enabled' => $resolver->allows(
                    $user,
                    NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
                    NotificationCatalogue::CHANNEL_EMAIL,
                    (string) $loop->id,
                ),
                'web_push_enabled' => $resolver->allows(
                    $user,
                    NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
                    NotificationCatalogue::CHANNEL_WEB_PUSH,
                    (string) $loop->id,
                ),
            ];
        })->all();
    }

    /**
     * Les Boucles ACTIVES de CETTE Organization dont l'utilisateur est membre
     * ACTIF — la seule liste qui fasse autorite, a la lecture comme a
     * l'ecriture.
     *
     * @return Collection<int, Loop>
     */
    private function bouclesActives(User $user, Organization $organization): Collection
    {
        return Loop::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->whereHas('members', fn ($q) => $q->where('user_id', $user->id)->where('status', 'active'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
