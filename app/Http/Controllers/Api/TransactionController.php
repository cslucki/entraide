<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PointLedger;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ServiceRequestLifecycle;
use App\Support\Entraide\EvaluationEntraide;
use App\Support\Entraide\MessageSysteme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $transactions = Transaction::with([
            'service:id,title',
            'serviceRequest:id,title',
            'buyer:id,organization_id,first_name,name,banned_at',
            'seller:id,organization_id,first_name,name,banned_at',
        ])
            ->where(fn ($q) => $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id))
            ->latest()
            ->paginate(15)
            ->through(fn (Transaction $transaction) => $this->transactionPayload($transaction));

        return response()->json($transactions);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_id' => 'nullable|uuid|exists:services,id',
            'request_id' => 'nullable|uuid|exists:service_requests,id',
            'points_proposed' => 'required|integer|min:1',
        ]);

        if (empty($data['service_id']) && empty($data['request_id'])) {
            return response()->json(['message' => 'service_id ou request_id requis.'], 422);
        }

        $buyer = $request->user();

        if (! empty($data['service_id'])) {
            $service = Service::findOrFail($data['service_id']);
            $seller = $service->user;
        } else {
            $serviceReq = ServiceRequest::findOrFail($data['request_id']);
            $seller = $buyer;
            $buyer = $serviceReq->user;
        }

        if ($buyer->id === $seller->id) {
            return response()->json(['message' => 'Impossible de créer une transaction avec vous-même.'], 422);
        }

        if ($buyer->points_balance < $data['points_proposed']) {
            return response()->json(['message' => 'Solde insuffisant.'], 422);
        }

        $existingQuery = Transaction::where('buyer_id', $buyer->id)->whereIn('status', ['pending', 'accepted']);
        if (! empty($data['service_id'])) {
            $existingQuery->where('service_id', $data['service_id']);
        } else {
            $existingQuery->where('request_id', $data['request_id']);
        }

        if ($existingQuery->exists()) {
            return response()->json(['message' => 'Vous avez déjà une transaction en cours pour cette annonce.'], 422);
        }

        $transaction = Transaction::create([
            'service_id' => $data['service_id'] ?? null,
            'request_id' => $data['request_id'] ?? null,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'points_proposed' => $data['points_proposed'],
            'organization_id' => app()->bound('current_organization') ? app('current_organization')->getKey() : null,
            'status' => 'pending',
        ]);

        MessageSysteme::ecrire($transaction, 'proposition_envoyee', ['points' => (int) $data['points_proposed']]);

        // TASK-1693 bug W — la demande ne quitte plus l'explorateur a la
        // proposition, mais a l'ACCEPTATION. Meme regle que le web : voir
        // `ServiceRequestLifecycle`.

        return response()->json($this->transactionPayload($transaction->load([
            'buyer:id,organization_id,first_name,name,banned_at',
            'seller:id,organization_id,first_name,name,banned_at',
        ])), 201);
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        $user = $request->user();

        if (! in_array($user->id, [$transaction->buyer_id, $transaction->seller_id])) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        return response()->json($this->transactionPayload($transaction->load([
            'service:id,title',
            'serviceRequest:id,title',
            'buyer:id,organization_id,first_name,name,banned_at',
            'seller:id,organization_id,first_name,name,banned_at',
        ])));
    }

    public function approve(Request $request, Transaction $transaction): JsonResponse
    {
        $user = $request->user();

        // TASK-1693 bug S — decide celui qui n'a PAS propose (voir
        // `Transaction::decisionMakerId()`). Cette API dupliquait la regle en
        // dur au lieu de passer par la policy : sans ce correctif, elle aurait
        // garde le comportement inverse.
        if ($user->id !== $transaction->decisionMakerId() || $transaction->status !== 'pending') {
            return response()->json(['message' => 'Action non autorisée.'], 403);
        }

        // TASK-1693 bug W — le solde est verifie AU MOMENT DE L'ENGAGEMENT.
        if ($transaction->buyer->points_balance < $transaction->points_proposed) {
            return response()->json(['message' => __('requests.lifecycle.insufficient_balance')], 422);
        }

        $transaction->update([
            'status' => 'accepted',
            'points_agreed' => $transaction->points_proposed,
        ]);

        MessageSysteme::ecrire($transaction, 'echange_accepte');

        app(ServiceRequestLifecycle::class)->markHandled($transaction);

        return response()->json($this->freshTransactionPayload($transaction));
    }

    public function refuse(Request $request, Transaction $transaction): JsonResponse
    {
        $user = $request->user();

        // TASK-1693 bug S — decide celui qui n'a PAS propose (voir
        // `Transaction::decisionMakerId()`). Cette API dupliquait la regle en
        // dur au lieu de passer par la policy : sans ce correctif, elle aurait
        // garde le comportement inverse.
        if ($user->id !== $transaction->decisionMakerId() || $transaction->status !== 'pending') {
            return response()->json(['message' => 'Action non autorisée.'], 403);
        }

        $transaction->update(['status' => 'refused']);
        MessageSysteme::ecrire($transaction, 'echange_decline');

        app(ServiceRequestLifecycle::class)->releaseIfNothingHolds($transaction);

        return response()->json($this->freshTransactionPayload($transaction));
    }

    public function cancel(Request $request, Transaction $transaction): JsonResponse
    {
        $user = $request->user();

        if (! in_array($user->id, [$transaction->buyer_id, $transaction->seller_id])) {
            return response()->json(['message' => 'Action non autorisée.'], 403);
        }

        if (! in_array($transaction->status, ['pending', 'accepted'])) {
            return response()->json(['message' => 'Annulation impossible dans ce statut.'], 422);
        }

        $transaction->update(['status' => 'cancelled']);
        MessageSysteme::ecrire($transaction, 'echange_annule');

        app(ServiceRequestLifecycle::class)->releaseIfNothingHolds($transaction);

        return response()->json($this->freshTransactionPayload($transaction));
    }

    /**
     * TASK-1806 — MEME parcours a trois etapes que la surface web.
     *
     * Cette classe reimplemente la machine a etats de son cote (dette connue,
     * relevee a l'audit T1806) : la bascule devait donc y etre refaite, sinon
     * l'API serait restee en silence sur l'ancien parcours a deux
     * confirmations. Coherence Web/API exigee par MASTER le 11/11.
     *
     * Le verrou et la garde de statut sont alignes sur
     * `TransactionController::complete()` : la cloture et le transfert de
     * points tiennent dans UNE transaction, un second appel ressort sans rien
     * retransferer.
     */
    public function complete(Request $request, Transaction $transaction): JsonResponse
    {
        $user = $request->user();

        if ($user->id !== $transaction->buyer_id || ! in_array($transaction->status, ['accepted', 'buyer_done'], true)) {
            return response()->json(['message' => 'Action non autorisée.'], 403);
        }

        $donnees = $request->validate([
            'rating' => 'nullable|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($transaction) {
            $tx = Transaction::where('id', $transaction->id)->lockForUpdate()->first();

            if (! in_array($tx->status, ['accepted', 'buyer_done'], true)) {
                return;
            }

            $points = (int) $tx->points_agreed;

            // Comme sur le web : un coup de main offert ne touche ni le ledger
            // ni les soldes (TASK-1754 §9.3).
            if ($points > 0) {
                PointLedger::create([
                    'user_id' => $tx->buyer_id,
                    'transaction_id' => $tx->id,
                    'delta' => -$points,
                    'organization_id' => $tx->organization_id,
                    'reason' => 'exchange_spent',
                ]);

                PointLedger::create([
                    'user_id' => $tx->seller_id,
                    'transaction_id' => $tx->id,
                    'delta' => $points,
                    'organization_id' => $tx->organization_id,
                    'reason' => 'exchange_earned',
                ]);

                $tx->buyer()->update(['points_balance' => DB::raw('points_balance - '.$points)]);
                $tx->seller()->update(['points_balance' => DB::raw('points_balance + '.$points)]);
            }

            $tx->update([
                'status' => 'completed',
                'buyer_confirmed_at' => $tx->buyer_confirmed_at ?? now(),
                'completed_at' => now(),
            ]);

            app(ServiceRequestLifecycle::class)->close($tx);
        });

        $fresh = $transaction->fresh();

        if ($fresh->status !== 'completed') {
            return response()->json(['message' => __('entraide.flash.deja_traite')], 409);
        }

        MessageSysteme::ecrire($transaction, 'echange_termine', ['points' => (int) ($transaction->points_agreed ?? $transaction->points_proposed)]);

        // Meme contrat que le web (verification MASTER du 11/11) : si l'avis
        // ne s'ecrit pas, l'appelant ne doit pas croire qu'il est parti.
        // L'entraide reste close, les points restent transferes, et l'avis
        // reste redeposable — `evaluationEnAttente()` est encore vrai.
        $avisDemande = ($donnees['rating'] ?? null) !== null;

        // TASK-1808 — meme regle que cote web : une cloture sans note n'ecrit
        // ni avis ni renoncement. Voir le commentaire du controleur web.
        $avisEnregistre = ! $avisDemande || rescue(
            function () use ($fresh, $donnees) {
                // Point de sauvegarde dedie — meme raison que dans le
                // controleur web : sous PostgreSQL une requete en echec avorte
                // la transaction englobante et la connexion refuse tout ordre
                // suivant. Voir le commentaire detaille cote web.
                DB::transaction(function () use ($fresh, $donnees) {
                    EvaluationEntraide::enregistrer($fresh, $donnees['rating'] ?? null, $donnees['comment'] ?? null);
                });

                return true;
            },
            function (\Throwable $e) use ($fresh) {
                Log::error('Evaluation could not be recorded after a successful closure', [
                    'event' => 'entraide_evaluation_not_recorded',
                    'transaction_id' => $fresh->id,
                    'organization_id' => $fresh->organization_id,
                    'reviewer_id' => $fresh->buyer_id,
                    'exception_class' => $e::class,
                ]);

                return false;
            },
            false,
        );

        $payload = $this->freshTransactionPayload($transaction);

        if ($avisDemande && ! $avisEnregistre) {
            $payload['evaluation_error'] = __('entraide.flash.echange_termine_avis_non_enregistre');
        }

        return response()->json($payload);
    }

    /**
     * CHEMIN HERITE (TASK-1806), symetrique de
     * `TransactionController::confirm()` : ne sert qu'a denouer une ligne
     * restee en `buyer_done`, statut que plus aucun code ne produit.
     */
    public function confirm(Request $request, Transaction $transaction): JsonResponse
    {
        $user = $request->user();

        if ($user->id !== $transaction->seller_id || $transaction->status !== 'buyer_done') {
            return response()->json(['message' => 'Action non autorisée.'], 403);
        }

        DB::transaction(function () use ($transaction) {
            $points = (int) $transaction->points_agreed;

            if ($points > 0) {
                PointLedger::create([
                    'user_id' => $transaction->buyer_id,
                    'transaction_id' => $transaction->id,
                    'delta' => -$points,
                    'organization_id' => $transaction->organization_id,
                    'reason' => 'exchange_spent',
                ]);

                PointLedger::create([
                    'user_id' => $transaction->seller_id,
                    'transaction_id' => $transaction->id,
                    'delta' => $points,
                    'organization_id' => $transaction->organization_id,
                    'reason' => 'exchange_earned',
                ]);

                $transaction->buyer()->update(['points_balance' => DB::raw('points_balance - '.$points)]);
                $transaction->seller()->update(['points_balance' => DB::raw('points_balance + '.$points)]);
            }

            $transaction->update([
                'status' => 'completed',
                'seller_confirmed_at' => now(),
                'completed_at' => now(),
            ]);

            app(ServiceRequestLifecycle::class)->close($transaction);
        });

        MessageSysteme::ecrire($transaction, 'echange_termine', ['points' => (int) ($transaction->points_agreed ?? $transaction->points_proposed)]);

        return response()->json($this->freshTransactionPayload($transaction));
    }

    private function transactionPayload(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'service_id' => $transaction->service_id,
            'request_id' => $transaction->request_id,
            'status' => $transaction->status,
            'points_proposed' => $transaction->points_proposed,
            'points_agreed' => $transaction->points_agreed,
            'created_at' => $transaction->created_at,
            'updated_at' => $transaction->updated_at,
            'service' => $transaction->service,
            'service_request' => $transaction->serviceRequest,
            'buyer' => $this->publicUserPayload($transaction->buyer, $transaction->organization_id),
            'seller' => $this->publicUserPayload($transaction->seller, $transaction->organization_id),
        ];
    }

    private function freshTransactionPayload(Transaction $transaction): array
    {
        return $this->transactionPayload($transaction->fresh()->load([
            'service:id,title',
            'serviceRequest:id,title',
            'buyer:id,organization_id,first_name,name,banned_at',
            'seller:id,organization_id,first_name,name,banned_at',
        ]));
    }

    private function publicUserPayload(?User $user, ?string $organizationId): ?array
    {
        if (! $user) {
            return null;
        }

        if (! $user->isDisplayableIn($organizationId)) {
            return [
                'id' => null,
                'name' => __('profile.deactivated_user'),
            ];
        }

        return [
            'id' => $user->id,
            'name' => $user->fullName,
        ];
    }
}
