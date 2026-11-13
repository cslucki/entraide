<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\Push\PushSubscriptionStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * TASK-1820 — « Mes appareils Push » : inspecter et retirer INDIVIDUELLEMENT
 * les abonnements du SuperAdmin connecte, et lui seul.
 *
 * ## Le probleme que cet outil resout, et celui qu'il ne resout PAS
 *
 * Cyril a deux abonnements Android enregistres pour un seul telephone
 * principalement utilise — consequence attendue des essais PWA repetes
 * (T1739/T1740/T1745) : l'unicite par `endpoint_hash` fonctionne, mais un
 * meme appareil peut produire plusieurs endpoints SUCCESSIFS (le navigateur
 * en cree un nouveau quand il juge le precedent perime), et chacun reste en
 * base jusqu'a ce qu'on le retire. Supprimer une ligne ne desabonne PAS le
 * navigateur, qui peut la recreer — cet outil ne pretend donc jamais
 * « reinitialiser » un appareil, seulement nettoyer la LISTE serveur, avec
 * l'avertissement explicite a chaque suppression.
 *
 * Hors perimetre, assume et documente plutot que corrige ici : le defaut
 * potentiel de reconciliation VAPID et le fait qu'un `2xx` fournisseur ne
 * prouve jamais une reception Android (tous deux releves par l'audit
 * Sonnet qui a precede cette TASK).
 *
 * ## Pourquoi ce n'est PAS une gestion generale des appareils
 *
 * Reserve au compte CONNECTE : chaque methode part de `$request->user()`,
 * jamais d'un identifiant de compte recu. Pas de recherche d'utilisateur,
 * pas d'acces aux appareils d'un autre SuperAdmin, aucune surface pour
 * OrgAdmin ou membre (porte par le groupe de routes `['auth','admin']`,
 * meme garde que tout le reste de `/admin`).
 *
 * ## Ce que cet ecran ne montre jamais
 *
 * Ni l'endpoint complet, ni aucune clef. `PushTestPolicy::reperageDe()`
 * (T1740) fournit deja ce reperage sans rien exposer — reutilise ici tel
 * quel. La seule donnee supplementaire envoyee a la page est l'EMPREINTE
 * COMPLETE (`endpoint_hash`, 64 caracteres) : un hachage a sens unique, pas
 * l'endpoint, necessaire pour que le NAVIGATEUR puisse reconnaitre localement
 * sa propre ligne (il recalcule le SHA-256 de SON endpoint et compare) sans
 * que le serveur ait besoin de recevoir quoi que ce soit de plus.
 */
class AdminMyPushDevicesController extends Controller
{
    public function __construct(private readonly PushSubscriptionStore $magasin) {}

    public function index(Request $request): View
    {
        $utilisateur = $request->user();

        $appareils = $this->magasin->appareilsDe($utilisateur)
            ->map(fn (PushSubscription $abonnement) => (object) [
                'id' => $abonnement->id,
                'libelle' => $abonnement->device_label,
                'fournisseur' => (string) parse_url($abonnement->endpoint, PHP_URL_HOST),
                // Les 8 premiers caracteres : meme convention que T1740
                // (`PushTestPolicy::reperageDe()`), suffisants pour distinguer
                // deux appareils a l'oeil sans rien reveler de reversible.
                'empreinte' => mb_substr($abonnement->endpoint_hash, 0, 8),
                // L'empreinte COMPLETE : jamais affichee comme texte, portee
                // uniquement en attribut `data-*` pour la comparaison cote
                // navigateur. Ce n'est pas l'endpoint — un hachage a sens
                // unique ne permet de retrouver ni l'endpoint ni les clefs.
                'empreinteComplete' => $abonnement->endpoint_hash,
                'creeLe' => $abonnement->created_at,
                'vuLe' => $abonnement->last_seen_at,
                'environnement' => $abonnement->created_env,
                'etatVapid' => $abonnement->etatCleVapid(),
                // La cle de traduction DIRECTEMENT, pour ne pas faire porter
                // au gabarit une correspondance etat -> libelle qui lui
                // appartient autant qu'au controleur.
                'vapidLangKey' => 'admin.push_devices_vapid_'.match ($abonnement->etatCleVapid()) {
                    PushSubscription::VAPID_CONCORDANTE => 'concordant',
                    PushSubscription::VAPID_DIVERGENTE => 'divergent',
                    default => 'unknown',
                },
            ]);

        return view('admin.outils.my-push-devices', [
            'appareils' => $appareils,
            'total' => $appareils->count(),
        ]);
    }

    /**
     * Retirer UN abonnement — exclusivement celui du compte connecte.
     *
     * Le controleur ne lit JAMAIS `user_id` depuis la requete : la requete
     * part de `$request->user()` et de lui seul. `renoncerParId()` REVERIFIE
     * cette appartenance cote serveur par un `WHERE user_id = …` — un
     * identifiant de ligne connu (jamais secret, visite dans l'URL) ne suffit
     * donc pas a retirer l'appareil de quelqu'un d'autre, meme en le
     * devinant.
     *
     * `false` (rien retire) n'est pas une erreur, c'est un cas ordinaire : la
     * ligne pouvait avoir deja ete retiree (double clic, onglet concurrent),
     * ou — si l'identifiant visait un autre compte — `renoncerParId()` rend
     * exactement la MEME reponse que pour une ligne deja absente, afin de ne
     * jamais reveler l'existence d'une ligne appartenant a quelqu'un d'autre.
     */
    public function destroy(Request $request, string $subscription): RedirectResponse
    {
        validator(['subscription' => $subscription], [
            'subscription' => ['required', 'string', 'uuid'],
        ])->validate();

        $utilisateur = $request->user();
        $retire = $this->magasin->renoncerParId($utilisateur, $subscription);

        // Trace de l'action — SANS endpoint ni clef, uniquement des
        // identifiants techniques. MASTER : « journaliser sans stocker
        // endpoint ou clefs ».
        Log::info('TASK-1820 abonnement Push retire depuis « Mes appareils Push ».', [
            'par' => (string) $utilisateur->id,
            'abonnement' => $subscription,
            'retire' => $retire,
        ]);

        return back()->with(
            'push_devices_resultat',
            $retire
                ? __('admin.push_devices_removed')
                : __('admin.push_devices_already_removed'),
        );
    }
}
