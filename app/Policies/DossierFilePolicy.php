<?php

namespace App\Policies;

use App\Models\DossierFile;
use App\Models\DossierMember;
use App\Models\User;

class DossierFilePolicy
{
    public function manageFiles(User $user, DossierFile $file): bool
    {
        $organization = currentOrganization();

        if ($organization === null || $file->organization_id !== $organization->id || $user->organization_id !== $organization->id) {
            return false;
        }

        $dossier = $file->dossier;

        if ($dossier === null) {
            return false;
        }

        if ($dossier->owner_id === $user->id) {
            return true;
        }

        return $dossier->dossierMembers()
            ->where('user_id', $user->id)
            ->whereIn('role', [DossierMember::ROLE_OWNER, DossierMember::ROLE_EDITOR])
            ->exists();
    }

    public function viewFiles(User $user, DossierFile $file): bool
    {
        $organization = currentOrganization();

        if ($organization === null || $file->organization_id !== $organization->id || $user->organization_id !== $organization->id) {
            return false;
        }

        $dossier = $file->dossier;

        if ($dossier === null) {
            return false;
        }

        if ($dossier->owner_id === $user->id) {
            return true;
        }

        return $dossier->dossierMembers()
            ->where('user_id', $user->id)
            ->exists();
    }

    public function deleteFile(User $user, DossierFile $file): bool
    {
        $organization = currentOrganization();

        if ($organization === null || $file->organization_id !== $organization->id || $user->organization_id !== $organization->id) {
            return false;
        }

        $dossier = $file->dossier;

        if ($dossier === null) {
            return false;
        }

        return $dossier->owner_id === $user->id;
    }
}
