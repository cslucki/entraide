<?php

namespace App\Policies;

use App\Models\DossierFile;
use App\Models\User;

/**
 * Les abilities d'un fichier sont celles de son Dossier — par delegation.
 *
 * Cette policy redisait `owner_id` et `dossier_members` a sa facon, sans la
 * branche « Dossier racine » que DossierPolicy possede : sur un Dossier de
 * Boucle, elle refusait tout le monde. Personne ne l'appelait encore, mais une
 * policy dormante desalignee est un piege arme — le premier appelant aurait
 * herite d'une regle fausse. Elle ne porte plus aucune regle en propre.
 */
class DossierFilePolicy
{
    public function manageFiles(User $user, DossierFile $file): bool
    {
        return $this->tenantOk($user, $file)
            && $file->dossier !== null
            && $user->can('manageFiles', $file->dossier);
    }

    public function viewFiles(User $user, DossierFile $file): bool
    {
        return $this->tenantOk($user, $file)
            && $file->dossier !== null
            && $user->can('viewFiles', $file->dossier);
    }

    public function deleteFile(User $user, DossierFile $file): bool
    {
        return $this->tenantOk($user, $file)
            && $file->dossier !== null
            && $user->can('deleteFile', $file->dossier);
    }

    /** Tenant d'abord, toujours : un fichier ne traverse pas une Organization. */
    private function tenantOk(User $user, DossierFile $file): bool
    {
        $organization = currentOrganization();

        return $organization !== null
            && $file->organization_id === $organization->id
            && $user->organization_id === $organization->id;
    }
}
