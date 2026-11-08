<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1788 correctif 11 — la gestion des notifications, pour le SuperAdmin.
 *
 * Ecrit sur le gabarit d'`AdminFeedController` : liste transversale filtree,
 * tri par colonne, fiche, suppression. Aucun second systeme.
 *
 * ## ⚠️ « Modifier » ne peut PAS vouloir dire « corriger les champs »
 *
 * Et ce n'est pas un choix que je prends : le modele l'INTERDIT, et il
 * explique pourquoi.
 *
 *   « Une notification est un fait date : a qui, dans quel tenant, a propos de
 *     quoi. **Cela ne se corrige pas, cela se remplace.** Seul l'etat de
 *     lecture evolue. »  — `MemberNotification::booted()`
 *
 * `NotificationInvariants::IMMUTABLE` fige sept colonnes (`organization_id`,
 * `recipient_id`, `notification_key`, `event_id`, `object_type`, `object_id`,
 * `actor_id`) et un `update()` dessus **leve une exception**. Offrir un
 * formulaire de correction afficherait donc des champs qui exploseraient a
 * l'enregistrement.
 *
 * « Modifier » est donc **l'etat de lecture**, le seul qui evolue — et il
 * passe par `markAsReadFor()` / `markAsUnreadFor()`, qui exigent le couple
 * (organization, recipient) : connaitre l'identifiant d'une ligne n'a jamais
 * ete un droit sur elle, et ce controleur ne fait pas exception.
 *
 * ## ⚠️ Ce modele n'a AUCUN scope global — mesure
 *
 * `getGlobalScopes()` rend un tableau VIDE : ni tenant, ni suppression douce.
 * Cette page lit les deux tenants parce que rien ne l'en empeche, **pas**
 * parce qu'on retire quoi que ce soit. La seule garde est `AdminMiddleware`
 * (`is_admin`, le SuperAdmin plateforme). Un test la tient.
 *
 * Et comme il n'y a pas de suppression douce, « Supprimer » detruit pour de
 * bon : l'ecran le dit avant de le faire.
 */
class AdminNotificationController extends Controller
{
    /** Les tris offerts, et la colonne SQL de chacun. */
    private const TRIS = [
        'date' => 'member_notifications.created_at',
        'type' => 'member_notifications.notification_key',
        'user' => 'destinataire.first_name',
    ];

    public function index(Request $request): View
    {
        $organizationId = $request->string('organization_id')->toString();
        $userId = $request->string('user_id')->toString();

        $tri = $request->string('tri')->toString();
        $tri = array_key_exists($tri, self::TRIS) ? $tri : 'date';
        $sens = $request->string('sens')->toString() === 'asc' ? 'asc' : 'desc';

        $query = MemberNotification::query()
            ->with(['organization', 'recipient', 'actor', 'deliveries'])
            // La jointure sert UNIQUEMENT le tri par personne : ordonner sur
            // `recipient_id` trierait des UUID, ce que personne ne lit. `left`
            // et non `inner` : une notification dont le destinataire a ete
            // supprime doit rester visible, c'est meme la qu'on la cherche.
            ->leftJoin('users as destinataire', 'destinataire.id', '=', 'member_notifications.recipient_id')
            ->select('member_notifications.*');

        if ($organizationId !== '') {
            $query->where('member_notifications.organization_id', $organizationId);
        }

        if ($userId !== '') {
            $query->where('member_notifications.recipient_id', $userId);
        }

        $query->orderBy(self::TRIS[$tri], $sens);

        // Second critere STABLE : deux notifications du meme type ou du meme
        // destinataire sortiraient dans un ordre dependant du moteur, et la
        // page changerait d'ordre d'un rechargement a l'autre.
        if ($tri !== 'date') {
            $query->orderByDesc('member_notifications.created_at');
        }

        $notifications = $query->paginate(50)->withQueryString();

        // Les destinataires proposes sont ceux qui ont REELLEMENT recu quelque
        // chose, dans le tenant choisi s'il y en a un : lister tous les comptes
        // donnerait un menu dont presque aucune entree ne rendrait de ligne.
        $destinatairesQuery = MemberNotification::query()->whereNotNull('recipient_id');

        if ($organizationId !== '') {
            $destinatairesQuery->where('organization_id', $organizationId);
        }

        $destinataires = User::whereIn('id', $destinatairesQuery->select('recipient_id'))
            ->orderBy('first_name')->orderBy('name')
            ->get(['id', 'first_name', 'name']);

        return view('admin.notifications.index', [
            'notifications' => $notifications,
            'organizations' => Organization::orderBy('name')->get(['id', 'name', 'slug']),
            'destinataires' => $destinataires,
            'organizationId' => $organizationId,
            'userId' => $userId,
            'tri' => $tri,
            'sens' => $sens,
        ]);
    }

    /** La fiche : tout ce que la ligne porte, sans rien deviner. */
    public function show(string $notification): View
    {
        return view('admin.notifications.show', [
            'notification' => $this->resoudre($notification),
        ]);
    }

    /**
     * « Modifier » : l'etat de lecture, le seul qui evolue.
     *
     * Le couple (organization, recipient) est repris SUR LA LIGNE et passe aux
     * methodes du modele, qui le re-verifient. C'est redondant par
     * construction — et c'est voulu : la garde du modele reste la seule
     * autorite, ce controleur ne la contourne pas, il la nourrit.
     */
    public function toggleRead(string $notification): RedirectResponse
    {
        $ligne = $this->resoudre($notification);

        if ($ligne->isRead()) {
            $ligne->markAsUnreadFor($ligne->organization_id, $ligne->recipient_id);
        } else {
            $ligne->markAsReadFor($ligne->organization_id, $ligne->recipient_id);
        }

        return back()->with('success', __('admin.notifications_toggled'));
    }

    /** Supprimer : il n'y a pas de suppression douce ici, c'est definitif. */
    public function destroy(string $notification): RedirectResponse
    {
        $this->resoudre($notification)->delete();

        return redirect()
            ->route('admin.notifications')
            ->with('success', __('admin.notifications_deleted'));
    }

    private function resoudre(string $id): MemberNotification
    {
        return MemberNotification::with(['organization', 'recipient', 'actor', 'deliveries'])
            ->whereKey($id)
            ->firstOrFail();
    }
}
