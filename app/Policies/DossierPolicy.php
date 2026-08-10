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

    /**
     * Reading a Dossier, across the three modalities.
     *
     * Evaluated on every read, never cached and never duplicated into a column:
     * a Loop that becomes private takes its Dossier with it on the very next
     * request, with no synchronisation to go wrong.
     */
    public function view(User $user, Dossier $dossier): bool
    {
        if ($this->isOwner($user, $dossier)) {
            return true;
        }

        // Explicitly invited people, whatever the modality.
        if ($this->isMember($user, $dossier)) {
            return true;
        }

        // Tenant first: nothing below may cross an Organization boundary.
        $organization = currentOrganization();

        if ($organization === null
            || $dossier->organization_id !== $organization->id
            || $user->organization_id !== $organization->id
            || $user->banned_at) {
            return false;
        }

        // A root Dossier has no audience of its own: it is its Loop's.
        if ($dossier->isLoopDossier()) {
            return $dossier->loop !== null && $user->can('viewWorkspace', $dossier->loop);
        }

        if ($dossier->visibility === Dossier::VISIBILITY_ORGANIZATION) {
            return true;
        }

        if ($dossier->visibility === Dossier::VISIBILITY_LOOP && $dossier->sharedWithLoop) {
            return $user->can('viewWorkspace', $dossier->sharedWithLoop);
        }

        return false;
    }

    /**
     * Choosing the audience — refused outright on a root Dossier.
     *
     * Its visibility is its Loop's, so offering the control at all would be a
     * lie. The refusal is on the server, not merely a hidden field.
     */
    public function updateVisibility(User $user, Dossier $dossier): bool
    {
        if ($dossier->isLoopDossier()) {
            return false;
        }

        return $this->isOwner($user, $dossier);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Dossier $dossier): bool
    {
        // A root Dossier has no personal owner, so isOwner() would lock
        // everyone out — including the people who run the Loop. Its editors are
        // those who may edit the Loop's identity.
        //
        // Cette porte ne consulte deliberement AUCUNE Card. Le Dossier racine
        // est une infrastructure commune : la Card Dossiers en est une vue, et
        // d'autres viendront — Article, Support de cours, Travaux a rendre, le
        // document racine, les fichiers de la Boucle. Eteindre une interface ne
        // doit pas eteindre le systeme documentaire qu'elle regarde.
        //
        // TASK-1091 avait ajoute ici un test sur `core.dossiers`. C'etait trop
        // large : cela faisait dependre l'ecriture du Dossier de la presence
        // d'une seule de ses vues.
        if ($dossier->isLoopDossier()) {
            return $dossier->loop !== null && $user->can('update', $dossier->loop);
        }

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
        // Un Dossier racine n'a ni owner ni lignes dossier_members : ses
        // editeurs sont ceux de la Boucle, comme pour update(). Sans cette
        // branche, personne — pas meme le proprietaire de la Boucle — ne
        // pouvait y attacher un article.
        if ($dossier->isLoopDossier()) {
            return $this->update($user, $dossier);
        }

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

    public function manageFiles(User $user, Dossier $dossier): bool
    {
        return $this->update($user, $dossier);
    }

    public function viewFiles(User $user, Dossier $dossier): bool
    {
        return $this->view($user, $dossier);
    }

    public function deleteFile(User $user, Dossier $dossier): bool
    {
        // Meme delegue que l'ecriture : qui administre la Boucle administre
        // les fichiers de son Dossier racine. isOwner() seul aurait interdit
        // toute suppression, owner_id etant null par doctrine.
        if ($dossier->isLoopDossier()) {
            return $this->update($user, $dossier);
        }

        return $this->isOwner($user, $dossier);
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
