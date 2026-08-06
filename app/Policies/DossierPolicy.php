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
        if ($dossier->isLoopDossier()) {
            return $dossier->loop !== null
                && $user->can('update', $dossier->loop)
                && $this->loopCardIsActive($dossier->loop);
        }

        if ($this->isOwner($user, $dossier)) {
            return true;
        }

        return $this->isEditor($user, $dossier);
    }

    /**
     * La Card Dossiers est-elle allumee sur cette Boucle (TASK-1091) ?
     *
     * L'ecran d'un Dossier s'atteint par sa propre adresse, independamment du
     * workspace : sans ce test, eteindre la Card l'aurait retiree de la Boucle
     * sans rien refuser a qui connait l'URL. C'est la seule chose que ce test
     * ajoute — il ne dit pas *qui* peut ecrire, seulement *si* la Boucle expose
     * encore son Dossier. La lecture n'est deliberement pas concernee : eteindre
     * une Card ne doit jamais rendre des donnees illisibles.
     */
    private function loopCardIsActive(\App\Models\Loop $loop): bool
    {
        return in_array(
            'core.dossiers',
            app(\App\Support\Loops\LoopTypeRegistry::class)->activeCardsFor($loop),
            true,
        );
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

    public function manageFiles(User $user, Dossier $dossier): bool
    {
        // Passe par update(), donc herite du test de Card pour un Dossier de
        // Boucle : deposer un fichier est une ecriture comme une autre.
        return $this->update($user, $dossier);
    }

    public function viewFiles(User $user, Dossier $dossier): bool
    {
        return $this->view($user, $dossier);
    }

    public function deleteFile(User $user, Dossier $dossier): bool
    {
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
