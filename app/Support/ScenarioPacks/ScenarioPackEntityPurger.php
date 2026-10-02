<?php

namespace App\Support\ScenarioPacks;

use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\ScenarioPackEntity;
use App\Models\User;
use App\Services\Users\UserDeletionExecutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
 *  - `User` (TASK-1635) : avant de supprimer un persona, l'Organization du
 *    chargement est detachee de lui si elle l'avait pour responsable
 *    (`organizations.admin_id`). Les packs `AiLabPack` et
 *    `ArtSciLabEnglishPack` font `$organization->update(['admin_id' => ...])`
 *    au chargement, en nommant un persona qu'ils CREENT responsable d'une
 *    Organization HOTE, preexistante. Ils doivent donc defaire ce qu'ils ont
 *    fait avant de detruire ce persona.
 *    Jusqu'a TASK-1635, le schema portait `ON DELETE SET NULL` sur cette
 *    colonne et absorbait l'oubli en SILENCE : l'Organization se retrouvait
 *    sans responsable et personne ne le voyait. La colonne est desormais en
 *    `ON DELETE RESTRICT` — le defaut ne peut plus passer inapercu, et le
 *    retrait doit etre explicite. Exception BORNEE, comme celle du ledger
 *    ci-dessus : seule l'Organization du chargement est touchee, jamais une
 *    autre, et uniquement quand elle pointe vers le persona detruit.
 *    Ce detachement n'est PAS un executeur de suppression d'utilisateur
 *    (TASK-1636) : c'est le pack qui annule sa propre ecriture.
 *
 *  - `User` (TASK-1636) : les biens TRANSFERABLES encore attribues a un
 *    persona — article, publication, service, demande — sont detruits avant
 *    lui. Le provisioning du produit en cree sans que le pack les declare :
 *    `LoopRootDocumentService` pose un article racine par Boucle, attribue au
 *    membre qui la cree. Sous `ON DELETE CASCADE` ils disparaissaient avec leur
 *    auteur ; depuis la migration M3 de TASK-1636 ils portent
 *    `ON DELETE RESTRICT` et interdisent le retrait du pack.
 *    Ce n'est pas une destruction arbitraire : un bien dont l'AUTEUR a ete cree
 *    par le pack ne peut pas lui preexister. Et les biens que le pack declare
 *    au registre sont deja partis a ce stade — les entites sont purgees par
 *    `sequence` decroissante, donc apres leur auteur dans l'ordre de creation.
 *    La liste des tables n'est pas redeclaree ici : elle est lue sur
 *    `UserDeletionExecutor::TRANSFERABLE`, qui la tient du registre.
 *    Borne a l'Organization du chargement, comme tout le reste.
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

        if ($modelClass === User::class) {
            $this->releaseOrganizationAdmin($entity->entity_id, $organization);
            $this->releaseTransferableProperties($entity->entity_id, $organization);
        }

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
     * TASK-1636 — les biens encore attribues a ce persona partent avec lui.
     *
     * Voir le bloc `User` en tete de fichier : il s'agit du provisioning que le
     * produit cree sans que le pack le declare. Les tables viennent de
     * `UserDeletionExecutor::TRANSFERABLE` — donc du registre — et jamais d'une
     * liste recopiee ici.
     */
    private function releaseTransferableProperties(string $userId, Organization $organization): void
    {
        foreach (UserDeletionExecutor::TRANSFERABLE as $spec) {
            if (! Schema::hasTable($spec['table'])) {
                continue;
            }

            $query = DB::table($spec['table'])->where($spec['column'], $userId);

            if (Schema::hasColumn($spec['table'], 'organization_id')) {
                $query->where('organization_id', $organization->id);
            }

            $query->delete();
        }
    }

    /**
     * TASK-1635 — l'Organization du chargement cesse d'avoir ce persona pour
     * responsable, avant qu'il ne soit detruit.
     *
     * Bornee a DEUX conditions cumulatives : l'Organization du chargement, et
     * le fait qu'elle designe precisement ce persona. Aucune autre
     * Organization n'est jamais touchee, et une Organization qui a deja un
     * autre responsable est laissee telle quelle.
     */
    private function releaseOrganizationAdmin(string $userId, Organization $organization): void
    {
        Organization::query()
            ->withoutGlobalScopes()
            ->whereKey($organization->id)
            ->where('admin_id', $userId)
            ->update(['admin_id' => null]);
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
