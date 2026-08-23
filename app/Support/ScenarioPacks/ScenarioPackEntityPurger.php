<?php

namespace App\Support\ScenarioPacks;

use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\ScenarioPackEntity;
use App\Models\User;
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
 *    preexistant ne peut arriver ici ;
 *  - `PointLedger` (TASK-1274) : la ligne est supprimee comme toute autre
 *    entite `created`, puis `users.points_balance` de l'utilisateur
 *    concerne est REALIGNE sur `SUM(delta)` des lignes de ledger restantes
 *    pour cet utilisateur dans cette Organization (0 s'il n'en reste
 *    aucune). Le ledger est la source de verite comptable et la balance en
 *    est la somme (invariant produit : tout chemin qui credite/debite ecrit
 *    une ligne) ; une purge qui retirerait la ligne sans realigner la
 *    balance laisserait un credit sans justificatif — exactement ce que le
 *    pack ne doit jamais produire. La regle vit ICI, pas dans le pack :
 *    remover (retrait) et resetter (orphelins) en heritent tous deux.
 *    Exception BORNEE a la politique `POLICY_BLOCK` de
 *    `UserDataLifecycleRegistry` sur `point_ledger` (inchangee) : seule une
 *    ligne inscrite au registre `ownership = created` dans l'Organization
 *    du chargement peut arriver ici — une ligne `reused` (historique
 *    anterieur du persona) n'est jamais supprimee, jamais realignee.
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

        // Lu AVANT la suppression : apres, la ligne ne dit plus a qui elle
        // appartenait.
        $ledgerUserId = $modelClass === PointLedger::class
            ? $this->pointLedgerUserId($entity, $organization)
            : null;

        $modelClass::query()
            ->withoutGlobalScopes()
            ->whereKey($entity->entity_id)
            ->where('organization_id', $organization->id)
            ->forceDelete();

        if ($ledgerUserId !== null) {
            $this->realignPointsBalance($ledgerUserId, $organization);
        }
    }

    /**
     * TASK-1274 — `users.points_balance` = `SUM(delta)` des lignes
     * `point_ledger` de l'utilisateur dans CETTE Organization (0 sans
     * ligne). Borne au tenant des deux cotes : la somme ne lit que les
     * lignes de l'Organization, l'ecriture ne touche l'utilisateur que s'il
     * en est membre. Primitive unique de realignement du moteur de scenario
     * pack ; rend la balance ecrite.
     */
    public function realignPointsBalance(string $userId, Organization $organization): int
    {
        $balance = (int) PointLedger::query()
            ->where('user_id', $userId)
            ->where('organization_id', $organization->id)
            ->sum('delta');

        User::query()
            ->whereKey($userId)
            ->where('organization_id', $organization->id)
            ->update(['points_balance' => $balance]);

        return $balance;
    }

    private function pointLedgerUserId(ScenarioPackEntity $entity, Organization $organization): ?string
    {
        $line = PointLedger::query()
            ->whereKey($entity->entity_id)
            ->where('organization_id', $organization->id)
            ->first(['id', 'user_id']);

        return $line?->user_id;
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
