<?php

namespace App\Policies;

use App\Models\Dossier;
use App\Models\DossierMember;
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
        if ($this->isOwner($user, $dossier)) {
            return true;
        }

        return $this->isMember($user, $dossier);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Dossier $dossier): bool
    {
        if ($this->isOwner($user, $dossier)) {
            return true;
        }

        return $this->isEditor($user, $dossier);
    }

    public function delete(User $user, Dossier $dossier): bool
    {
        return $this->isOwner($user, $dossier);
    }

    public function manageMembers(User $user, Dossier $dossier): bool
    {
        return $this->isOwner($user, $dossier);
    }

    public function attachArticle(User $user, Dossier $dossier): bool
    {
        if ($this->isOwner($user, $dossier)) {
            return true;
        }

        return $this->isEditor($user, $dossier);
    }

    public function detachArticle(User $user, Dossier $dossier): bool
    {
        return $this->attachArticle($user, $dossier);
    }

    public function reorderArticles(User $user, Dossier $dossier): bool
    {
        return $this->update($user, $dossier);
    }

    public function manageSeries(User $user, Dossier $dossier): bool
    {
        return $this->update($user, $dossier);
    }

    public function viewSeries(User $user, Dossier $dossier): bool
    {
        return $this->view($user, $dossier);
    }

    public function isOwner(User $user, Dossier $dossier): bool
    {
        $organization = currentOrganization();

        return $organization !== null
            && $dossier->organization_id === $organization->id
            && $user->organization_id === $organization->id
            && $dossier->owner_id === $user->id;
    }

    public function isMember(User $user, Dossier $dossier): bool
    {
        $organization = currentOrganization();

        if ($organization === null || $dossier->organization_id !== $organization->id || $user->organization_id !== $organization->id) {
            return false;
        }

        return $dossier->dossierMembers()
            ->where('user_id', $user->id)
            ->exists();
    }

    public function isEditor(User $user, Dossier $dossier): bool
    {
        $organization = currentOrganization();

        if ($organization === null || $dossier->organization_id !== $organization->id || $user->organization_id !== $organization->id) {
            return false;
        }

        return $dossier->dossierMembers()
            ->where('user_id', $user->id)
            ->where('role', DossierMember::ROLE_EDITOR)
            ->exists();
    }

    public function isReader(User $user, Dossier $dossier): bool
    {
        $organization = currentOrganization();

        if ($organization === null || $dossier->organization_id !== $organization->id || $user->organization_id !== $organization->id) {
            return false;
        }

        return $dossier->dossierMembers()
            ->where('user_id', $user->id)
            ->where('role', DossierMember::ROLE_READER)
            ->exists();
    }
}
