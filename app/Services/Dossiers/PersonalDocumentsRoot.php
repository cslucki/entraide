<?php

namespace App\Services\Dossiers;

use App\Models\Dossier;
use Illuminate\Database\QueryException;

/**
 * La primitive unique de resolution de « Mes documents » (TASK-1130).
 *
 * Tout le produit passe par ici pour obtenir la racine personnelle d'un
 * utilisateur dans une Organization. Un seul endroit sait comment elle est
 * reconnue (`system_role`), comment elle nait (paresseusement, a la premiere
 * visite) et comment elle est nommee (une traduction, jamais un identifiant).
 *
 * ## Pourquoi une creation paresseuse
 *
 * Aucun backfill : les comptes existants — et ils sont nombreux a porter deja
 * plusieurs racines personnelles — n'ont rien a migrer. La racine apparait
 * quand quelqu'un ouvre le module, et pas avant. Un compte qui n'y va jamais
 * ne cree aucune ligne.
 *
 * ## Pourquoi elle est sure sous concurrence
 *
 * Deux requetes simultanees (deux onglets, un double-clic) tenteraient la meme
 * insertion. C'est l'index partiel `dossiers_personal_documents_unique` qui
 * tranche : la seconde insertion echoue, et on relit la ligne gagnante au lieu
 * de propager l'erreur. La discipline des appelants ne garantit rien ; l'index,
 * si — meme raisonnement que `unique('loop_id')` pour la racine d'une Boucle.
 */
class PersonalDocumentsRoot
{
    /**
     * The user's « Mes documents » root in this Organization, created if absent.
     */
    public function resolve(string $organizationId, string $userId): Dossier
    {
        $existing = $this->find($organizationId, $userId);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return Dossier::create([
                'organization_id' => $organizationId,
                'owner_id' => $userId,
                'loop_id' => null,
                'parent_id' => null,
                'system_role' => Dossier::SYSTEM_ROLE_PERSONAL_DOCUMENTS,
                // Une valeur de repli, jamais lue pour identifier la racine :
                // l'affichage passe par `Dossier::displayName()`, donc par la
                // traduction de la langue courante.
                'name' => __('dossiers.my_documents'),
                'visibility' => Dossier::VISIBILITY_PRIVATE,
            ]);
        } catch (QueryException $e) {
            // Course perdue contre une requete concurrente : la ligne existe
            // desormais, elle est la bonne.
            $winner = $this->find($organizationId, $userId);

            if ($winner === null) {
                throw $e;
            }

            return $winner;
        }
    }

    /**
     * The root if it already exists — no creation, for read paths that must
     * stay side-effect free (a policy, a count, a test).
     */
    public function find(string $organizationId, string $userId): ?Dossier
    {
        return Dossier::query()
            ->where('organization_id', $organizationId)
            ->where('owner_id', $userId)
            ->where('system_role', Dossier::SYSTEM_ROLE_PERSONAL_DOCUMENTS)
            ->first();
    }
}
