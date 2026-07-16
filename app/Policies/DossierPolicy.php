<?php

namespace App\Policies;

use App\Models\Dossier;
use App\Models\User;

class DossierPolicy
{
    public function viewAny(User $user): bool
    {
        $organization = currentOrganization();

        return $organization !== null
            && $user->organization_id === $organization->id
            && ! $user->banned_at;
    }

    public function view(User $user, Dossier $dossier): bool
    {
        return $this->ownsCurrentOrganizationDossier($user, $dossier);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Dossier $dossier): bool
    {
        return $this->ownsCurrentOrganizationDossier($user, $dossier);
    }

    public function delete(User $user, Dossier $dossier): bool
    {
        return $this->ownsCurrentOrganizationDossier($user, $dossier);
    }

    private function ownsCurrentOrganizationDossier(User $user, Dossier $dossier): bool
    {
        $organization = currentOrganization();

        return $organization !== null
            && $dossier->organization_id === $organization->id
            && $user->organization_id === $organization->id
            && $dossier->owner_id === $user->id;
    }
}
