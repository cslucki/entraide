<?php

namespace App\Http\Controllers;

use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\NotificationTargetResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * TASK-1373 — le Centre de notifications d'un membre.
 *
 * ## Un controleur, pas un composant Livewire
 *
 * Le choix n'est pas stylistique. Une action Livewire passe par
 * `/livewire-{hash}/update`, et ce chemin resout l'Organization **par defaut de
 * la plateforme** (`ResolveUrlOrganization::isFeatureRoute()` renvoie vrai pour
 * tout segment commencant par `livewire-`) : le tenant devrait alors etre fige
 * depuis l'acteur et re-verifie a chaque hydratation, puisqu'un instantane
 * Livewire est une capacite durable, sans nonce ni expiration.
 *
 * Un POST classique suivi d'une redirection n'a aucun de ces problemes, et le
 * badge du rail — rendu serveur — se recalcule de lui-meme a la page suivante.
 * Le probleme le plus simple a resoudre est celui qu'on n'a pas.
 *
 * ## L'Organization vient du MEMBRE, pas du contexte
 *
 * `$user->organization ?? currentOrganization()`, et dans cet ordre. Sur la
 * route non prefixee, l'« Organization courante » est celle par defaut de la
 * plateforme : un membre y verrait le compteur d'un tenant qui n'est pas le
 * sien. C'est la regle deja ecrite dans `UserAiUsageController::index()`, et le
 * rail utilise la MEME expression — sinon le badge et la page se
 * contrediraient.
 *
 * ## Cette tranche ne resout pas encore les objets
 *
 * Une notification ne porte que des references (`object_type` + `object_id`).
 * Les resoudre en lien profond, re-verifier la permission au clic et afficher
 * un etat honnete quand la cible a disparu appartient a la tranche qui branchera
 * le premier producteur reel. Ici le Centre montre ce qui existe, sans pretendre
 * y donner acces.
 */
class NotificationCenterController extends Controller
{
    public const FILTRE_TOUTES = 'toutes';

    public const FILTRE_NON_LUES = 'non-lues';

    private const PAR_PAGE = 25;

    public function index(Request $request): View
    {
        [$user, $organization] = $this->acteur($request);

        $filtre = $this->filtre($request);

        $query = MemberNotification::query()
            ->forRecipient((string) $organization->id, (string) $user->id)
            // Depart sur `created_at` a la seconde : trois precedents dans ce
            // depot montrent que deux lignes creees dans le meme test partagent
            // la seconde, et qu'un SQLite vert ne prouve alors rien.
            ->latest('created_at')
            ->latest('id');

        if ($filtre === self::FILTRE_NON_LUES) {
            $query->unread();
        }

        // Les noms de route sont resolus ICI. Les laisser a la vue l'obligerait
        // a re-deviner le contexte org-scope a chaque bouton, et une vue qui
        // devine est une vue qui se trompera.
        $slug = $request->route('organization');

        return view('notifications.index', [
            'notifications' => $query->paginate(self::PAR_PAGE)->withQueryString(),
            'filtre' => $filtre,
            'nonLues' => $user->unreadNotificationsCount((string) $organization->id),
            'routeParams' => $slug ? ['organization' => $slug] : [],
            'routeRead' => $slug ? 'organization.notifications.read' : 'notifications.read',
            'routeOpen' => $slug ? 'organization.notifications.open' : 'notifications.open',
            'routeReadAll' => $slug ? 'organization.notifications.read-all' : 'notifications.read-all',
        ]);
    }

    public function read(Request $request): RedirectResponse
    {
        [$user, $organization] = $this->acteur($request);

        $this->resoudre($this->identifiant($request), (string) $organization->id, (string) $user->id)
            ->markAsReadFor((string) $organization->id, (string) $user->id);

        return back();
    }

    /**
     * Ouvrir une notification : la marquer lue, PUIS resoudre sa cible.
     *
     * ## Un POST, pas un lien
     *
     * Ouvrir mute l'etat de lecture. Un GET qui mute est un GET qui se rejoue —
     * par un prefetch, un scanner de liens, un bouton « precedent ». Le clic est
     * donc un formulaire, comme les deux autres actions de cet ecran.
     *
     * ## L'ordre n'est pas negociable
     *
     * 1. verifier que la notification appartient a ce membre, dans ce tenant ;
     * 2. la marquer lue ;
     * 3. resoudre la reference sous les permissions ACTUELLES ;
     * 4. rediriger, ou dire honnetement que la cible n'est plus accessible.
     *
     * **Le marquage vient AVANT la resolution, et il tient meme quand la cible a
     * disparu.** C'est contre-intuitif une seconde, puis evident : le membre a
     * pris connaissance du signal, et l'application lui a repondu. Laisser la
     * notification non lue condamnerait le badge a signaler indefiniment quelque
     * chose de deja traite — et un badge qui ment est pire qu'un badge absent,
     * parce qu'on cesse de le regarder.
     */
    public function open(Request $request, NotificationTargetResolver $resolver): RedirectResponse
    {
        [$user, $organization] = $this->acteur($request);

        $ligne = $this->resoudre($this->identifiant($request), (string) $organization->id, (string) $user->id);

        $ligne->markAsReadFor((string) $organization->id, (string) $user->id);

        $cible = $resolver->resolve($ligne);

        return $cible === null
            ? back()->with('notification_unreachable', __('notifications.target_unreachable'))
            : redirect()->to($cible);
    }

    /**
     * Tout marquer lu, une ligne a la fois et par la porte du modele.
     *
     * Un `update(['read_at' => now()])` de masse serait plus court et faux :
     * `read_at` n'est pas affectable en masse, et surtout le geste doit nommer
     * son proprietaire. `markAsReadFor()` refuse bruyamment sinon, et reste
     * idempotent — la premiere lecture fait foi.
     */
    public function readAll(Request $request): RedirectResponse
    {
        [$user, $organization] = $this->acteur($request);

        MemberNotification::query()
            ->forRecipient((string) $organization->id, (string) $user->id)
            ->unread()
            ->each(fn (MemberNotification $n) => $n->markAsReadFor(
                (string) $organization->id,
                (string) $user->id,
            ));

        return back();
    }

    /**
     * L'identifiant de la notification, lu EXPLICITEMENT sur la route.
     *
     * Le prendre en argument de methode revenait a dependre de la facon dont le
     * framework apparie les parametres — et la variante org-scopee porte un
     * segment `{organization}` avant lui. Le lire par son nom ne laisse aucune
     * place a cette question.
     *
     * Defaut a chaine vide plutot qu'a `null` : la garde de forme qui suit s'en
     * charge, et rend 404 comme pour n'importe quelle valeur invalide.
     */
    private function identifiant(Request $request): string
    {
        return (string) $request->route('notification');
    }

    /**
     * Le couple (membre, Organization), re-resolu a CHAQUE action.
     *
     * @return array{0: User, 1: Organization}
     */
    private function acteur(Request $request): array
    {
        $user = $request->user();
        $organization = $user?->organization ?? currentOrganization();

        abort_unless($user !== null && $organization !== null, 404);

        return [$user, $organization];
    }

    /**
     * Le filtre vient du client : il passe par une liste blanche.
     *
     * Toute autre valeur retombe sur « toutes » plutot que d'atteindre la
     * requete — un filtre inconnu n'est pas une erreur a signaler, c'est une
     * valeur a ignorer.
     */
    private function filtre(Request $request): string
    {
        $demande = (string) $request->query('filtre', self::FILTRE_TOUTES);

        return in_array($demande, [self::FILTRE_TOUTES, self::FILTRE_NON_LUES], true)
            ? $demande
            : self::FILTRE_TOUTES;
    }

    /**
     * Resout une notification DANS le tenant et POUR ce membre.
     *
     * Le controle de forme vient AVANT la requete : sous PostgreSQL la colonne
     * est un `uuid` natif, et une chaine qui n'en est pas un rend `SQLSTATE
     * 22P02` — donc un 500 — la ou SQLite se contente de ne rien trouver.
     *
     * Et c'est `404`, jamais `403` : distinguer l'inexistant de l'interdit
     * ferait de cette route un oracle sur les notifications des autres.
     */
    private function resoudre(string $id, string $organizationId, string $recipientId): MemberNotification
    {
        abort_unless(Str::isUuid($id), 404);

        $notification = MemberNotification::query()
            ->forRecipient($organizationId, $recipientId)
            ->whereKey($id)
            ->first();

        abort_unless($notification !== null, 404);

        return $notification;
    }
}
