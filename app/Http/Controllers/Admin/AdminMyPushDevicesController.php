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
 * TASK-1820 puis TASK-1821 — « Appareils Push » : inspecter et retirer
 * INDIVIDUELLEMENT les abonnements Push de la plateforme.
 *
 * ## Ce que T1821 a changé, et pourquoi
 *
 * T1820 bornait cet écran au compte CONNECTÉ (`$request->user()` partout,
 * aucune recherche d'utilisateur). La recette l'a invalidé : un abonnement
 * créé depuis un téléphone via un tunnel Cloudflare appartenait au compte de
 * démonstration authentifié sur CE navigateur-là, pas au compte ouvert sur
 * l'écran d'administration — donc invisible, sans que rien ne soit en panne.
 * La liste vide était exacte et inutile.
 *
 * Arbitrage MASTER (14/11) : cet écran montre désormais TOUS les abonnements,
 * tous comptes confondus, et peut en retirer n'importe lequel. L'URL et le
 * nom de route ne changent pas — décision produit : un seul écran, pas deux.
 *
 * ## La contrepartie : une garde plus stricte
 *
 * Parce que cet écran lit — et peut détruire — les données d'autrui,
 * `['auth','admin']` ne suffit plus : `is_admin` ouvre déjà tout `/admin`, y
 * compris (mesuré le 14/11) à des comptes de DÉMONSTRATION. Les deux routes
 * portent donc `can:superadmin-push-all-devices`
 * (`SuperAdminPushAccessPolicy`), qui exclut HORS LOCAL tout compte `.test`.
 * La garde est déclarée sur la route, pas ici : elle doit être lisible à
 * l'endroit où l'URL est définie, et s'appliquer avant le contrôleur.
 *
 * ## Ce que cet écran ne montre jamais
 *
 * Ni l'endpoint complet, ni aucune clef. La seule donnée sensible en
 * apparence est l'EMPREINTE COMPLÈTE (`endpoint_hash`, SHA-256, 64
 * caractères) : un hachage à sens unique, porté en attribut `data-*` pour que
 * le NAVIGATEUR reconnaisse localement sa propre ligne — il recalcule le
 * SHA-256 de SON endpoint et compare, sans que le serveur reçoive rien de
 * plus. C'est aussi pourquoi « Cet appareil » reste un calcul CLIENT, lié au
 * navigateur et à l'origine courants, et jamais une déduction serveur.
 *
 * ## Ce que retirer une ligne ne fait PAS
 *
 * Supprimer ne désabonne PAS le navigateur de la personne, qui peut recréer
 * l'abonnement au prochain lancement. Cet outil nettoie la LISTE serveur,
 * avec l'avertissement explicite à chaque suppression — il ne « réinitialise »
 * aucun appareil.
 */
class AdminMyPushDevicesController extends Controller
{
    public function __construct(private readonly PushSubscriptionStore $magasin) {}

    public function index(Request $request): View
    {
        $recherche = trim((string) $request->query('q', ''));

        // TASK-1823 — les deux filtres. `etat` est valide contre la liste
        // du magasin : une valeur inconnue est ECARTEE ici plutot que
        // transmise, pour que l'ecran n'affiche jamais un filtre actif qui
        // ne filtrerait rien.
        $etat = (string) $request->query('etat', '');
        $etat = in_array($etat, PushSubscriptionStore::situations(), true) ? $etat : '';

        $organizationId = (string) $request->query('org', '');

        $page = $this->magasin->tous(
            $recherche === '' ? null : $recherche,
            etat: $etat === '' ? null : $etat,
            organizationId: $organizationId === '' ? null : $organizationId,
        );

        // TASK-1821 — les traces de livraison du PROPRIETAIRE, chargees en UNE
        // requete pour toute la page. Elles sont attachees a la personne,
        // jamais a l'appareil : `member_notification_deliveries` ne porte
        // aucun identifiant d'abonnement (voir le docblock de
        // `dernieresLivraisonsWebPush()`), et la vue le dit explicitement.
        $idsProprietaires = $page->getCollection()->pluck('user_id')->filter()->unique()->values()->all();

        $livraisonsParPersonne = $this->magasin->dernieresLivraisonsWebPush($idsProprietaires);

        // TASK-1823 — l'indicateur d'incident, mesure sur la fenetre ENTIERE
        // de 30 jours et non sur les cinq livraisons ci-dessus. MASTER :
        // « les cinq dernieres livraisons affichees ne doivent pas
        // determiner seules le diagnostic ». Une ligne pourrait n'afficher
        // que des succes recents tout en appartenant a un compte qui a
        // echoue trois semaines plus tot.
        $comptesEnIncident = array_flip(
            $this->magasin->idsDesComptesAvecIncidentRecent($idsProprietaires),
        );

        $appareils = $page->getCollection()
            ->map(fn (PushSubscription $abonnement) => (object) [
                'id' => $abonnement->id,
                'libelle' => $abonnement->device_label,
                // TASK-1821 — le PROPRIÉTAIRE, puisque l'écran n'est plus
                // borné au compte connecté : une ligne sans son propriétaire
                // serait illisible, et surtout indécidable avant suppression.
                'proprietaireNom' => $abonnement->user?->full_name ?? $abonnement->user?->name,
                'proprietaireEmail' => $abonnement->user?->email,
                // TASK-1821 — l'Organization, demandee par MASTER : deux
                // comptes homonymes se distinguent par elle, et un abonnement
                // orphelin d'Organization est une anomalie qu'on doit voir.
                'proprietaireOrganization' => $abonnement->user?->organization?->name,
                // Les livraisons de la PERSONNE, jamais de l'appareil.
                'livraisonsDuProprietaire' => $livraisonsParPersonne[(string) $abonnement->user_id] ?? [],
                // TASK-1823 — porte par TOUTES les lignes du compte, parce
                // qu'aucune donnee ne permet de designer l'appareil en
                // cause. La vue doit le formuler comme un fait de COMPTE.
                'compteEnIncident' => isset($comptesEnIncident[(string) $abonnement->user_id]),
                'fournisseur' => (string) parse_url($abonnement->endpoint, PHP_URL_HOST),
                // Les 8 premiers caracteres : meme convention que T1740
                // (`PushTestPolicy::reperageDe()`), suffisants pour distinguer
                // deux appareils a l'oeil sans rien reveler de reversible.
                'empreinte' => mb_substr($abonnement->endpoint_hash, 0, 8),
                // L'empreinte COMPLETE : jamais affichée comme texte, portée
                // uniquement en attribut `data-*` pour la comparaison côté
                // navigateur. Ce n'est pas l'endpoint — un hachage à sens
                // unique ne permet de retrouver ni l'endpoint ni les clefs.
                'empreinteComplete' => $abonnement->endpoint_hash,
                'creeLe' => $abonnement->created_at,
                'vuLe' => $abonnement->last_seen_at,
                'environnement' => $abonnement->created_env,
                // TASK-1821 — l'etat CONTEXTUALISE, et lui seul a l'ecran.
                //
                // `etatCleVapid()` compare a la clef du serveur courant : lu
                // depuis un autre environnement, il affichait « DIVERGENTE »
                // pour cinq abonnements de production parfaitement sains. Le
                // 14/11 cela s'est lu comme une panne. La comparaison n'a de
                // sens qu'a l'interieur de l'environnement qui a stampe la
                // ligne — ailleurs, le seul mot honnete est « non
                // comparable ».
                'etatVapid' => $abonnement->etatCleVapidContextualise(),
                // La cle de traduction DIRECTEMENT, pour ne pas faire porter
                // au gabarit une correspondance etat -> libelle qui lui
                // appartient autant qu'au controleur.
                // TASK-1823 — la SITUATION de l'abonnement, derivee du meme
                // etat contextualise que le libelle VAPID : une pastille et
                // une phrase qui divergeraient seraient pires que pas de
                // pastille du tout.
                'situation' => match ($abonnement->etatCleVapidContextualise()) {
                    PushSubscription::VAPID_CONCORDANTE => PushSubscriptionStore::SITUATION_ELIGIBLE,
                    PushSubscription::VAPID_DIVERGENTE => PushSubscriptionStore::SITUATION_CLE_INCOMPATIBLE,
                    default => PushSubscriptionStore::SITUATION_A_VERIFIER,
                },
                'vapidLangKey' => 'admin.push_devices_vapid_'.match ($abonnement->etatCleVapidContextualise()) {
                    PushSubscription::VAPID_CONCORDANTE => 'concordant',
                    PushSubscription::VAPID_DIVERGENTE => 'divergent',
                    PushSubscription::VAPID_NON_COMPARABLE => 'non_comparable',
                    default => 'unknown',
                },
            ]);

        return view('admin.outils.my-push-devices', [
            'appareils' => $appareils,
            'pagination' => $page,
            'recherche' => $recherche,
            'total' => $page->total(),
            // TASK-1821 — le resume PAR ENVIRONNEMENT. Un total unique
            // melangerait des mesures incomparables : chaque environnement a
            // sa propre paire VAPID.
            'resume' => $this->magasin->resumeParEnvironnement(),
            'environnementCourant' => (string) config('app.env'),
            // TASK-1823 — le bento, les filtres et leur etat courant.
            'compteurs' => $this->magasin->compteursDeSituation(),
            'organizations' => $this->magasin->organizationsAvecAbonnements(),
            'filtres' => ['q' => $recherche, 'etat' => $etat, 'org' => $organizationId],
            'fenetreIncidents' => PushSubscriptionStore::FENETRE_INCIDENTS_JOURS,
            // TASK-1821 — en LOCAL uniquement : l'identité du compte qui
            // REGARDE. L'écran montrant désormais tout le monde, savoir sous
            // quel compte on le consulte reste utile en recette — c'est ce
            // qui a manqué le 14/11, quand une liste vide s'expliquait
            // simplement par le compte connecté.
            'identiteLocale' => app()->environment('local') ? $request->user()->email : null,
        ]);
    }

    /**
     * Retirer UN abonnement — celui de n'importe quel compte.
     *
     * TASK-1821 : la garde n'est plus « c'est le mien », elle est
     * « j'ai le droit de gérer ceux de tout le monde »
     * (`can:superadmin-push-all-devices`, portée par la route). Le chemin de
     * suppression est donc DISTINCT de celui du propriétaire :
     * `PushSubscriptionStore::renoncerParId()` — qui filtre sur `user_id` —
     * reste intact pour le self-service, et n'est pas appelé ici.
     *
     * `false` (rien retiré) n'est pas une erreur, c'est un cas ordinaire : la
     * ligne pouvait avoir déjà été retirée (double clic, onglet concurrent).
     */
    public function destroy(Request $request, string $subscription): RedirectResponse
    {
        validator(['subscription' => $subscription], [
            'subscription' => ['required', 'string', 'uuid'],
        ])->validate();

        // Lu AVANT suppression, uniquement pour journaliser QUI possédait la
        // ligne retirée : la trace doit dire sur qui l'action a porté, et
        // l'identifiant de ligne seul ne le dit plus une fois la ligne partie.
        $proprietaireId = PushSubscription::query()->where('id', $subscription)->value('user_id');

        $retire = $this->magasin->retirerParIdPourSuperadmin($subscription);

        // Trace de l'action — SANS endpoint ni clef, uniquement des
        // identifiants techniques. MASTER : « journaliser sans stocker
        // endpoint ou clefs ».
        Log::info('TASK-1821 abonnement Push retire depuis « Appareils Push ».', [
            'par' => (string) $request->user()->id,
            'abonnement' => $subscription,
            'proprietaire' => $proprietaireId,
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
