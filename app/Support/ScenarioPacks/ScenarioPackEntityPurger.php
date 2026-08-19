<?php

namespace App\Support\ScenarioPacks;

use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Suppression PHYSIQUE d'une entite de scenario pack (TASK-1245), primitive
 * unique partagee par `ScenarioPackRemover` (retrait du pack) et
 * `ScenarioPackResetter` (orphelins d'une version anterieure). La regle
 * vit ici, pas chez les appelants, pour ne pas se perdre au premier
 * remaniement.
 *
 * Contrat :
 *  - n'accepte QUE `ownership = created` : c'est le droit de destruction.
 *    `reused` (jamais supprimee) et NULL (inconnu, jamais devine) sont
 *    refuses par construction — les appelants filtrent en amont, la
 *    primitive verifie quand meme ;
 *  - `forceDelete()` sur le query builder, sans global scopes : suppression
 *    reelle y compris pour les modeles SoftDeletes (Service, BlogPost,
 *    Dossier, DossierFile, LoopRoadmapItem) et y compris si l'entite a ete
 *    soft-supprimee entre-temps par un utilisateur pendant l'usage du pack.
 *    C'est le SEUL endroit de BouclePro ou un `forceDelete` est decide par
 *    le moteur de scenario pack ; la politique SoftDeletes generale de
 *    l'application n'est pas touchee ;
 *  - toujours borne a l'Organization du chargement (`organization_id` dans
 *    le WHERE, en plus de la cle) ;
 *  - `DossierFile` : le fichier physique du disque est supprime aussi
 *    (symetrique de `DossierFileController::destroy()`), mais APRES le
 *    commit de la transaction englobante (`DB::afterCommit`) : la base
 *    d'abord (annulable), le storage ensuite (irreversible). Si la
 *    transaction est annulee, aucun fichier n'a disparu et aucune ligne ne
 *    pointe vers un fichier absent. Un `DossierFile` `created` implique
 *    que le pack a lui-meme ecrit ce fichier (garde
 *    `assertStoragePathAvailable` au chargement) : aucun fichier
 *    preexistant ne peut arriver ici.
 *
 * Avant TASK-1245, `remove()` faisait `->delete()` : soft delete pour les
 * modeles SoftDeletes, dont 4 sur 5 disparaissaient ensuite physiquement
 * par ACCIDENT (cascade `cascadeOnDelete` sur `user_id`/`loop_id` depuis
 * les hard-deletes tardifs de User/Loop dans la meme transaction), et
 * `DossierFile` (FK `nullOnDelete`) survivait. Ce nettoyage est desormais
 * intentionnel et independant de l'ordre ou des autres types.
 */
class ScenarioPackEntityPurger
{
    public function purge(ScenarioPackEntity $entity, Organization $organization): void
    {
        if (! $entity->isOwnedByPack()) {
            throw new LogicException(
                "ScenarioPackEntityPurger : purge refusee pour '{$entity->entity_type}:{$entity->internal_key}' ".
                '(ownership='.($entity->ownership ?? 'NULL').'). Seul ownership=created donne le droit de destruction.'
            );
        }

        if ((string) $entity->organization_id !== (string) $organization->id) {
            throw new LogicException(
                "ScenarioPackEntityPurger : '{$entity->entity_type}:{$entity->internal_key}' appartient a une autre Organization."
            );
        }

        $modelClass = $entity->entity_model;

        if (! class_exists($modelClass) || ! is_a($modelClass, Model::class, true)) {
            return;
        }

        if ($modelClass === DossierFile::class) {
            $this->scheduleStoredFileDeletion($entity, $organization);
        }

        $modelClass::query()
            ->withoutGlobalScopes()
            ->whereKey($entity->entity_id)
            ->where('organization_id', $organization->id)
            ->forceDelete();
    }

    private function scheduleStoredFileDeletion(ScenarioPackEntity $entity, Organization $organization): void
    {
        $file = DossierFile::query()
            ->withoutGlobalScopes()
            ->whereKey($entity->entity_id)
            ->where('organization_id', $organization->id)
            ->first(['id', 'disk', 'path']);

        if ($file === null || $file->disk === null || $file->path === null) {
            return;
        }

        $disk = $file->disk;
        $path = $file->path;

        // Hors transaction : execute immediatement. Dans la transaction du
        // remover/resetter : execute au commit, jamais si elle est annulee.
        DB::afterCommit(fn () => Storage::disk($disk)->delete($path));
    }
}
