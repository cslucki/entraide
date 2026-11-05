<?php

namespace App\Support\Loops;

use App\Models\LoopJoinRequest;
use Illuminate\Support\Collection;

/**
 * TASK-1761 point 2 — les demandes d'adhesion en attente, pour le SuperAdmin.
 *
 * ## Pourquoi une classe et pas une requete dans chaque controleur
 *
 * La meme liste est demandee a DEUX endroits — le tableau de bord et
 * `/admin/loops` — et Cyril l'a dit explicitement : « ce composant pourra etre
 * utilise egalement sur /admin/loops ». Ecrite deux fois, la requete finirait
 * par diverger : l'une chargerait une relation que l'autre oublie, et la carte
 * ferait N+1 sur une seule des deux pages.
 *
 * ## CROSS-TENANT, et c'est assume
 *
 * « ceci toute organisation confondues ». `withoutGlobalScopes()` est donc
 * VOULU ici — mais il n'est jamais seul : cette classe ne sert que des surfaces
 * `/admin`, et la decision passe par `LoopPolicy::manageJoinRequests()`, qui
 * autorise un `is_admin` sur n'importe quelle Organization
 * (`LoopPermissionResolver`, mesure du 05/11).
 *
 * La frontiere n'est donc pas ici : elle est a la DECISION. Une liste qui
 * montre ne decide de rien.
 *
 * ## `$organizationId` borne la liste quand on le demande
 *
 * `/admin/loops` filtre deja par Organization. Lui servir la liste globale
 * ferait apparaitre, sous une page filtree, des demandes d'ailleurs.
 */
final class DemandesDAdhesionEnAttente
{
    /**
     * @return Collection<int, LoopJoinRequest>
     */
    public static function lister(?string $organizationId = null, int $limite = 20): Collection
    {
        return LoopJoinRequest::query()
            ->withoutGlobalScopes()
            ->where('status', LoopJoinRequest::STATUS_PENDING)
            // `with` et non des acces paresseux : la carte affiche le nom du
            // demandeur, celui de la Boucle et celui de l'Organization pour
            // chaque ligne. Sans cela, vingt lignes font soixante requetes.
            ->with(['user', 'loop.organization'])
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            // Une Boucle supprimee laisserait une ligne qu'on ne saurait ni
            // nommer ni decider.
            ->whereHas('loop')
            ->orderBy('created_at')
            ->limit($limite)
            ->get();
    }

    /** Le NOMBRE en attente — pour le compteur du point 4. */
    public static function compter(?string $organizationId = null): int
    {
        return LoopJoinRequest::query()
            ->withoutGlobalScopes()
            ->where('status', LoopJoinRequest::STATUS_PENDING)
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereHas('loop')
            ->count();
    }

    /**
     * Le nombre en attente PAR BOUCLE — pour la liste de `/admin/loops`.
     *
     * Une seule requete groupee, et non un compte par ligne : la page affiche
     * toutes les Boucles d'une Organization.
     *
     * @return Collection<string, int> identifiant de Boucle => nombre
     */
    public static function compterParBoucle(?string $organizationId = null): Collection
    {
        return LoopJoinRequest::query()
            ->withoutGlobalScopes()
            ->where('status', LoopJoinRequest::STATUS_PENDING)
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->selectRaw('loop_id, count(*) as total')
            ->groupBy('loop_id')
            ->pluck('total', 'loop_id');
    }
}
