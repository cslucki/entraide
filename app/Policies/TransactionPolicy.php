<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function view(User $user, Transaction $transaction): bool
    {
        return $this->resourceBelongsToCurrentOrganization($transaction)
            && ($user->id === $transaction->buyer_id || $user->id === $transaction->seller_id);
    }

    /**
     * TASK-1693 bug S — decide celui qui n'a PAS propose.
     *
     * `seller_id` en dur designait le proposant lui-meme des que l'echange
     * naissait d'une demande d'aide : voir `Transaction::decisionMakerId()`.
     * Pour une offre de service, cette methode rend `seller_id` — le
     * comportement historique, a l'identique.
     */
    public function approve(User $user, Transaction $transaction): bool
    {
        return $this->resourceBelongsToCurrentOrganization($transaction)
            && $user->id === $transaction->decisionMakerId() && $transaction->status === 'pending';
    }

    /** Meme regle qu'`approve()` : refuser est l'autre face de la decision. */
    public function refuse(User $user, Transaction $transaction): bool
    {
        return $this->resourceBelongsToCurrentOrganization($transaction)
            && $user->id === $transaction->decisionMakerId() && $transaction->status === 'pending';
    }

    public function adjust(User $user, Transaction $transaction): bool
    {
        return $this->resourceBelongsToCurrentOrganization($transaction)
            && ($user->id === $transaction->buyer_id || $user->id === $transaction->seller_id)
            && $transaction->status === 'pending';
    }

    public function cancel(User $user, Transaction $transaction): bool
    {
        return $this->resourceBelongsToCurrentOrganization($transaction)
            && ($user->id === $transaction->buyer_id || $user->id === $transaction->seller_id)
            && in_array($transaction->status, ['pending', 'accepted']);
    }

    /**
     * TASK-1806 (arbitrage du 11/11) — LE geste final, et il appartient a
     * l'aide.
     *
     * Le parcours visible compte desormais TROIS etapes : demande, accord,
     * confirmation. Ce dernier geste termine l'entraide, credite les points a
     * l'aidant et enregistre l'evaluation eventuelle — il n'y a plus de
     * confirmation distincte cote aidant.
     *
     * `buyer_done` est accepte en PLUS d'`accepted` par pure compatibilite :
     * le nouveau parcours ne produit plus jamais ce statut (mesure du 11/11 :
     * zero ligne sur le jumeau), mais une ligne heritee ne doit pas rester
     * sans issue si l'ancien parcours en avait laisse une.
     */
    public function complete(User $user, Transaction $transaction): bool
    {
        return $this->resourceBelongsToCurrentOrganization($transaction)
            && $user->id === $transaction->buyer_id
            && in_array($transaction->status, ['accepted', 'buyer_done'], true);
    }

    /**
     * CHEMIN HERITE — la confirmation de l'aidant n'existe plus dans le
     * parcours visible (TASK-1806). Conservee uniquement pour denouer une
     * eventuelle ligne restee en `buyer_done`, statut que plus aucun code ne
     * produit. Aucune surface ne propose ce geste.
     */
    public function confirm(User $user, Transaction $transaction): bool
    {
        return $this->resourceBelongsToCurrentOrganization($transaction)
            && $user->id === $transaction->seller_id && $transaction->status === 'buyer_done';
    }

    /** CHEMIN HERITE, meme raison que `confirm()` ci-dessus (TASK-1806). */
    public function contest(User $user, Transaction $transaction): bool
    {
        return $this->resourceBelongsToCurrentOrganization($transaction)
            && $user->id === $transaction->seller_id && $transaction->status === 'buyer_done';
    }

    private function resourceBelongsToCurrentOrganization($resource): bool
    {
        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        return $resource->organization_id === $org->id;
    }
}
