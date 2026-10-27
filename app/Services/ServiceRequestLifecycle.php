<?php

namespace App\Services;

use App\Models\Message;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1693 bug W — le cycle de vie d'une demande d'aide, a UN seul endroit.
 *
 * ## Ce que ce service change
 *
 * Une demande quittait l'explorateur des la PREMIERE proposition
 * (`TransactionController:120`, `Api\TransactionController:91` — la regle etait
 * ecrite deux fois). Elle ne pouvait donc recevoir qu'UNE offre, de qui que ce
 * soit, alors que :
 *
 *   - aucune contrainte d'unicite n'existe sur `transactions.request_id` ;
 *   - la garde anti-doublon est conçue PAR PERSONNE (« VOUS avez deja une
 *     transaction en cours pour cette annonce »), ce qui n'a de sens que si
 *     l'on attend plusieurs personnes differentes.
 *
 * Le code avait donc ete pense pour plusieurs propositions, et une seule ligne
 * imposait le contraire. La demande quitte desormais l'explorateur a
 * l'ACCEPTATION : tant que rien n'est accepte, elle reste visible et peut
 * recevoir plusieurs offres.
 *
 * ## Pourquoi un service, et pas deux lignes deplacees
 *
 * Six endroits dans DEUX controleurs manipulaient `service_requests.status` a
 * la main. Le bug S vient de montrer ce que coute cette duplication : la copie
 * de la regle dans l'API avait survecu a la correction de la policy. La regle
 * vit ici, les controleurs l'appellent.
 */
class ServiceRequestLifecycle
{
    /**
     * Une proposition vient d'etre ACCEPTEE : la demande est pourvue.
     *
     * Les propositions CONCURRENTES encore en attente sont refusees, chacune
     * avec un message systeme dans son fil. Les laisser vivre sur une demande
     * deja pourvue recreerait exactement le mensonge d'interface corrige au
     * bug L — des boutons « Accepter / Refuser » sans objet, pour des gens a
     * qui personne n'a rien dit.
     */
    public function markHandled(Transaction $accepted): void
    {
        if ($accepted->request_id === null) {
            return;
        }

        DB::transaction(function () use ($accepted) {
            ServiceRequest::where('id', $accepted->request_id)->update(['status' => 'in_progress']);

            $concurrentes = Transaction::where('request_id', $accepted->request_id)
                ->where('id', '!=', $accepted->id)
                ->where('status', 'pending')
                ->get();

            foreach ($concurrentes as $concurrente) {
                $concurrente->update(['status' => 'refused']);

                Message::create([
                    'transaction_id' => $concurrente->id,
                    'sender_id' => null,
                    'body' => __('requests.lifecycle.another_offer_accepted'),
                    'type' => 'system',
                    'organization_id' => $concurrente->organization_id,
                ]);
            }
        });
    }

    /**
     * Une proposition est relachee (refusee, annulee) : la demande redevient
     * ouverte — SAUF si une autre la tient.
     *
     * Sans cette condition, refuser une proposition concurrente rouvrirait une
     * demande deja pourvue. C'etait sans danger tant qu'une seule proposition
     * pouvait exister ; ca ne l'est plus.
     */
    public function releaseIfNothingHolds(Transaction $released): void
    {
        if ($released->request_id === null) {
            return;
        }

        $tenue = Transaction::where('request_id', $released->request_id)
            ->where('id', '!=', $released->id)
            ->whereIn('status', ['accepted', 'buyer_done'])
            ->exists();

        if ($tenue) {
            return;
        }

        ServiceRequest::where('id', $released->request_id)->update(['status' => 'open']);
    }

    /** La prestation est terminee : la demande est close. */
    public function close(Transaction $completed): void
    {
        if ($completed->request_id === null) {
            return;
        }

        ServiceRequest::where('id', $completed->request_id)->update(['status' => 'closed']);
    }
}
