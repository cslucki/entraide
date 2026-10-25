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
 *
 * ## TASK-1695 — UNE regle propre, et une seule
 *
 * `viewFiles` gagne une SECONDE source d'autorisation : un partage pose sur le
 * fichier lui-meme (`dossier_file_shares`). C'est la seule chose que cette
 * policy sait dire de son cote ; tout le reste continue de deleguer au Dossier.
 *
 * L'avertissement ci-dessus reste donc valable et a ete suivi a la lettre : la
 * branche AJOUTE un cas, elle ne REDIT aucune regle du Dossier. Si la
 * gouvernance d'un Dossier change demain, rien ici n'a besoin de bouger.
 *
 * ## Ce que le partage N'OUVRE PAS
 *
 * Ni `manageFiles`, ni `deleteFile` : arbitrage de Cyril (chemin « C »),
 * LECTURE SEULE. Un destinataire ne renomme pas, ne deplace pas, ne supprime
 * pas, et ne repartage pas. Ces deux abilities restent une pure delegation au
 * Dossier — c'est ce qui rend l'absence de droit demontrable, plutot que
 * seulement voulue.
 *
 * ## Un fichier SUPPRIME n'est plus lisible, meme partage
 *
 * `DossierFile` emploie `SoftDeletes` : la cascade SQL de
 * `dossier_file_shares` ne se declenche qu'a la suppression DURE. Une ligne de
 * partage survit donc a une suppression douce, et sans la verification
 * explicite ci-dessous, le destinataire garderait l'acces a un fichier que son
 * proprietaire croit supprime.
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
        if (! $this->tenantOk($user, $file)) {
            return false;
        }

        // Le Dossier d'abord : le cas de loin le plus frequent, et celui qui
        // n'interroge pas la base une fois de plus.
        if ($file->dossier !== null && $user->can('viewFiles', $file->dossier)) {
            return true;
        }

        return $this->sharedWith($user, $file);
    }

    /**
     * TASK-1695 — un partage pose sur CE fichier, pour CETTE personne.
     *
     * Le tenant a deja ete borne par `tenantOk()` ; cette methode ne le redit
     * pas, elle repond a la seule question qui reste.
     *
     * `trashed()` est verifie ICI et pas ailleurs : un fichier supprime en
     * douceur garde ses lignes de partage (la cascade SQL ne joue qu'a la
     * suppression dure), et le destinataire lirait un fichier que son
     * proprietaire croit disparu.
     */
    private function sharedWith(User $user, DossierFile $file): bool
    {
        if ($file->trashed()) {
            return false;
        }

        if ($file->shares()
            ->where('user_id', $user->id)
            ->where('organization_id', $file->organization_id)
            ->exists()) {
            return true;
        }

        // TASK-1695 (5a) — partage avec une BOUCLE : le destinataire est la
        // Boucle, l'acces de chacun se resout ICI, a la lecture. Membre ACTIF
        // seulement — un arrivant voit le fichier des son entree, un partant
        // le perd en sortant, sans qu'aucune ligne ne soit ecrite ni effacee.
        //
        // La Boucle doit appartenir a l'Organization DU FICHIER : sans cette
        // borne, une ligne forgee vers une Boucle d'un autre tenant ouvrirait
        // la lecture a travers la cloison (meme garde que les partages
        // nominatifs, prouvee par forcage au geste A).
        return $file->loopShares()
            ->where('organization_id', $file->organization_id)
            ->whereHas('loop', fn ($q) => $q
                ->where('organization_id', $file->organization_id)
                ->whereHas('activeMembers', fn ($m) => $m->where('user_id', $user->id)))
            ->exists();
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
