<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\PointLedger;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $transactions = Transaction::with(['service:id,title', 'serviceRequest:id,title', 'buyer:id,name', 'seller:id,name'])
            ->where(fn ($q) => $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id))
            ->latest()
            ->paginate(15);

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

        $this->addSystemMessage($transaction, 'Nouvelle échange envoyée : '.$data['points_proposed'].' points.');

        if (! empty($data['request_id'])) {
            ServiceRequest::where('id', $data['request_id'])->update(['status' => 'in_progress']);
        }

        return response()->json($transaction->load(['buyer:id,name', 'seller:id,name']), 201);
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        $user = $request->user();

        if (! in_array($user->id, [$transaction->buyer_id, $transaction->seller_id])) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        return response()->json($transaction->load(['service:id,title', 'serviceRequest:id,title', 'buyer:id,name', 'seller:id,name']));
    }

    public function approve(Request $request, Transaction $transaction): JsonResponse
    {
        $user = $request->user();

        if ($user->id !== $transaction->seller_id || $transaction->status !== 'pending') {
            return response()->json(['message' => 'Action non autorisée.'], 403);
        }

        $transaction->update([
            'status' => 'accepted',
            'points_agreed' => $transaction->points_proposed,
        ]);

        $this->addSystemMessage($transaction, "Échange acceptée. L'échange est en cours.");

        return response()->json($transaction->fresh());
    }

    public function refuse(Request $request, Transaction $transaction): JsonResponse
    {
        $user = $request->user();

        if ($user->id !== $transaction->seller_id || $transaction->status !== 'pending') {
            return response()->json(['message' => 'Action non autorisée.'], 403);
        }

        $transaction->update(['status' => 'refused']);
        $this->addSystemMessage($transaction, 'Échange refusée.');

        if ($transaction->request_id) {
            ServiceRequest::where('id', $transaction->request_id)->update(['status' => 'open']);
        }

        return response()->json($transaction->fresh());
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
        $this->addSystemMessage($transaction, 'Transaction annulée.');

        if ($transaction->request_id) {
            ServiceRequest::where('id', $transaction->request_id)->update(['status' => 'open']);
        }

        return response()->json($transaction->fresh());
    }

    public function complete(Request $request, Transaction $transaction): JsonResponse
    {
        $user = $request->user();

        if ($user->id !== $transaction->buyer_id || $transaction->status !== 'accepted') {
            return response()->json(['message' => 'Action non autorisée.'], 403);
        }

        $transaction->update([
            'status' => 'buyer_done',
            'buyer_confirmed_at' => now(),
        ]);

        $this->addSystemMessage($transaction, "L'acheteur a déclaré la prestation terminée. En attente de confirmation du vendeur.");

        return response()->json($transaction->fresh());
    }

    public function confirm(Request $request, Transaction $transaction): JsonResponse
    {
        $user = $request->user();

        if ($user->id !== $transaction->seller_id || $transaction->status !== 'buyer_done') {
            return response()->json(['message' => 'Action non autorisée.'], 403);
        }

        DB::transaction(function () use ($transaction) {
            $points = $transaction->points_agreed;

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

            $transaction->update([
                'status' => 'completed',
                'seller_confirmed_at' => now(),
                'completed_at' => now(),
            ]);

            if ($transaction->request_id) {
                ServiceRequest::where('id', $transaction->request_id)->update(['status' => 'closed']);
            }
        });

        $this->addSystemMessage($transaction, 'Échange complété ! Les points ont été transférés.');

        return response()->json($transaction->fresh());
    }

    private function addSystemMessage(Transaction $transaction, string $body): void
    {
        Message::create([
            'transaction_id' => $transaction->id,
            'sender_id' => null,
            'body' => $body,
            'type' => 'system',
            'organization_id' => $transaction->organization_id,
        ]);
    }
}
