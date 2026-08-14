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
        // TASK-1130 : un enfant ne porte ni owner_id ni loop_id — sa
        // gouvernance se demande a la racine qui le porte. Sur une racine,
        // governingDossier() rend $dossier lui-meme : rien ne change pour
        // l'existant.
        $dossier = $dossier->governingDossier();

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
        // Un enfant n'a pas d'audience propre — il herite de celle de sa
        // racine (TASK-1130) — donc rien a choisir ici pour lui non plus.
        if ($dossier->isLoopDossier() || ! $dossier->isRoot()) {
            return false;
        }

        // « Mes documents » est prive par definition : c'est l'espace ou l'on
        // range ce qu'on ne partage pas encore. On partage un dossier qui s'y
        // trouve, jamais l'espace lui-meme.
        if ($dossier->isPersonalDocumentsRoot()) {
            return false;
        }

        return $this->isOwner($user, $dossier);
    }

    /**
     * Renommer un Dossier — distinct de `update()`, qui gouverne l'ecriture
     * DANS le Dossier (fichiers, Articles, sous-dossiers).
     *
     * La distinction existe pour « Mes documents » : son contenu est
     * parfaitement normal — on y cree, on y depose, on y range — mais son
     * identite appartient au produit. Confondre les deux verrouillerait
     * l'espace entier au lieu de son seul nom.
     */
    public function rename(User $user, Dossier $dossier): bool
    {
        if ($dossier->isPersonalDocumentsRoot()) {
            return false;
        }

        return $this->update($user, $dossier);
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
        // TASK-1130 : gouvernance demandee a la racine, jamais a l'enfant.
        $dossier = $dossier->governingDossier();

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
        // TASK-1130 passe 4 (CAS A) : le Dossier racine d'une Boucle n'est
        // jamais supprimable d'ici — le detruire emporterait tout l'espace
        // documentaire de la Boucle, une decision de cycle de vie de la
        // Boucle elle-meme, hors de portee d'un geste Dossier. Un vrai
        // sous-dossier (parent_id non nul) EST reellement possede par sa
        // Boucle : memes droits que d'y ecrire (update()), pas un refus
        // systematique comme isOwner() seul l'aurait rendu (owner_id est
        // toujours NULL sous gouvernance Boucle).
        if ($dossier->isLoopDossier() && $dossier->isRoot()) {
            return false;
        }

        // Meme raison pour « Mes documents » : supprimer l'espace personnel
        // n'est pas un geste de rangement. Il n'a d'ailleurs pas de
        // remplacant — la primitive le recreerait a la visite suivante, vide,
        // en laissant l'ancien contenu sans destination.
        if ($dossier->isPersonalDocumentsRoot()) {
            return false;
        }

        $governing = $dossier->governingDossier();

        if ($governing->isLoopDossier()) {
            return $governing->loop !== null && $user->can('update', $governing->loop);
        }

        // Dossier personnel, racine ou enfant : seul le proprietaire reel.
        return $this->isOwner($user, $dossier);
    }

    public function manageMembers(User $user, Dossier $dossier): bool
    {
        // Inviter quelqu'un dans « Mes documents » reviendrait a partager tout
        // l'espace personnel d'un coup, y compris ce qui n'y est pas encore.
        if ($dossier->isPersonalDocumentsRoot()) {
            return false;
        }

        return $this->isOwner($user, $dossier);
    }

    public function attachArticle(User $user, Dossier $dossier): bool
    {
        // Un Dossier racine n'a ni owner ni lignes dossier_members : ses
        // editeurs sont ceux de la Boucle, comme pour update(). Sans cette
        // branche, personne — pas meme le proprietaire de la Boucle — ne
        // pouvait y attacher un article. Meme remontee pour un enfant.
        $governing = $dossier->governingDossier();

        if ($governing->isLoopDossier()) {
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
        if ($dossier->governingDossier()->isLoopDossier()) {
            return $this->update($user, $dossier);
        }

        return $this->isOwner($user, $dossier);
    }

    /**
     * Ces quatre verifications delegue toutes a `governingDossier()` — pas
     * seulement pour un root d'article ou de fichier, mais pour n'importe
     * quel Dossier de la chaine (TASK-1130). Un enfant n'a ni owner_id ni
     * dossier_members a lui : les lire directement dessus repondrait
     * toujours non, meme au proprietaire reel.
     */
    public function isOwner(User $user, Dossier $dossier): bool
    {
        $dossier = $dossier->governingDossier();
        $organization = currentOrganization();

        return $organization !== null
            && $dossier->organization_id === $organization->id
            && $user->organization_id === $organization->id
            && $dossier->owner_id === $user->id;
    }

    public function isMember(User $user, Dossier $dossier): bool
    {
        $dossier = $dossier->governingDossier();
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
        $dossier = $dossier->governingDossier();
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
        $dossier = $dossier->governingDossier();
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
