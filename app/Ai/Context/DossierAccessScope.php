<?php

namespace App\Ai\Context;

use App\Models\Dossier;
use App\Models\Loop;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * TASK-1307 : perimetre des Dossiers accessibles a UN utilisateur, dans UNE
 * Organization, eventuellement resserre a UNE Boucle.
 *
 * Extrait de `DossierRetrievalSource` (TASK-1213/1294) pour etre partage avec
 * `DossierManifestSource` (TASK-1307) sans dupliquer la logique de
 * permission : une seule regle d'appartenance/visibilite, jamais deux qui
 * pourraient diverger.
 *
 * Ce que cette classe garantit :
 * - Organization = Tenant : les candidats sont toujours bornes a
 *   l'Organization donnee ;
 * - permission-safe : seuls les Dossiers autorises par `DossierPolicy::view`
 *   pour CET utilisateur sortent de `accessibleDossierIds()` ;
 * - loop-scoped (TASK-1294) : une Boucle dans le perimetre ne garde que son
 *   Dossier racine, les Dossiers qui lui sont partages, et leurs enfants
 *   (meme lecture que `DossierPolicy::view`, via `governingDossier()`).
 */
final class DossierAccessScope
{
    private const MAX_CANDIDATE_DOSSIERS = 200;

    /**
     * Une Boucle du contexte doit appartenir a CETTE Organization — meme
     * garde que `LoopMessagesSource` : le contexte porte des identifiants
     * deja autorises par l'appelant, mais une source ne fait jamais confiance
     * sur parole.
     */
    public function loopBelongsToOrganization(string $loopId, string $organizationId): bool
    {
        return Loop::query()
            ->whereKey($loopId)
            ->where('organization_id', $organizationId)
            ->exists();
    }

    /**
     * Les Dossiers de l'Organization que CET utilisateur peut voir — la
     * politique du produit (`DossierPolicy::view`), pas une regle locale.
     *
     * Avec une Boucle (TASK-1294), le perimetre se resserre d'abord sur les
     * Dossiers de CETTE Boucle ; la policy s'applique ensuite, inchangee, sur
     * ce qui reste.
     *
     * @return list<string>
     */
    public function accessibleDossierIds(string $organizationId, User $user, ?string $loopId): array
    {
        $gate = Gate::forUser($user);

        return $this->candidateDossiers($organizationId, $loopId)
            ->filter(fn (Dossier $dossier): bool => ($loopId === null || $this->belongsToLoop($dossier, $loopId))
                && $gate->allows('view', $dossier))
            ->map(fn (Dossier $dossier): string => (string) $dossier->id)
            ->values()
            ->all();
    }

    /**
     * Les candidats sur lesquels le filtre s'exerce.
     *
     * Sans Boucle : les Dossiers de l'Organization sous le cap
     * MAX_CANDIDATE_DOSSIERS — le comportement historique, inchange.
     *
     * Avec une Boucle : la restriction Boucle s'applique AVANT le cap, sinon
     * une Boucle plus recente que les MAX_CANDIDATE_DOSSIERS premiers
     * Dossiers de l'Organization (tri created_at) aurait un perimetre VIDE.
     * Les racines liees a la Boucle se disent en SQL ; leurs enfants ne
     * portent ni `loop_id` ni `shared_with_loop_id` (doctrine T1130) et se
     * recuperent par descente `parent_id`, bornee par `Dossier::MAX_DEPTH`
     * comme `governingDossier()`. La descente ne fait que GENERER des
     * candidats : `belongsToLoop()` reste seul juge de l'appartenance, en
     * aval.
     *
     * @return Collection<int, Dossier>
     */
    private function candidateDossiers(string $organizationId, ?string $loopId): Collection
    {
        $query = Dossier::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->limit(self::MAX_CANDIDATE_DOSSIERS);

        if ($loopId === null) {
            return $query->get();
        }

        $candidates = $query->clone()
            ->where(fn ($roots) => $roots
                ->where('loop_id', $loopId)
                ->orWhere(fn ($shared) => $shared
                    ->where('shared_with_loop_id', $loopId)
                    ->where('visibility', Dossier::VISIBILITY_LOOP)))
            ->get();

        $parentIds = $candidates->pluck('id');
        $depth = 0;

        while ($parentIds->isNotEmpty()
            && $depth < Dossier::MAX_DEPTH
            && $candidates->count() < self::MAX_CANDIDATE_DOSSIERS) {
            $children = Dossier::query()
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->whereIn('parent_id', $parentIds)
                ->orderBy('created_at')
                ->limit(self::MAX_CANDIDATE_DOSSIERS - $candidates->count())
                ->get();

            $candidates = $candidates->concat($children);
            $parentIds = $children->pluck('id');
            $depth++;
        }

        // Un cycle parent_id (donnee corrompue) ne doit ni boucler — la borne
        // ci-dessus — ni produire un doublon dans le perimetre.
        return $candidates->unique('id')->values();
    }

    /**
     * Le Dossier appartient-il a CETTE Boucle ? La meme lecture que
     * `DossierPolicy::view` : un enfant ne porte ni `loop_id` ni
     * `shared_with_loop_id`, la question se pose a sa racine gouvernante
     * (doctrine T1130), qui n'a que deux manieres d'etre liee a une Boucle —
     * etre SON Dossier racine, ou lui etre partagee (et le partage exige la
     * visibilite ET la colonne, comme dans la policy).
     */
    private function belongsToLoop(Dossier $dossier, string $loopId): bool
    {
        $governing = $dossier->governingDossier();

        if ($governing->isLoopDossier()) {
            return (string) $governing->loop_id === $loopId;
        }

        return $governing->visibility === Dossier::VISIBILITY_LOOP
            && (string) $governing->shared_with_loop_id === $loopId;
    }
}
