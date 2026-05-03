<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\PointLedger;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Notifications\TransactionStatusChanged;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'service_id' => 'nullable|uuid|exists:services,id',
            'request_id' => 'nullable|uuid|exists:service_requests,id',
            'points_proposed' => 'required|integer|min:1',
        ]);

        $buyer = auth()->user();

        // Determine seller
        if (!empty($data['service_id'])) {
            $service = Service::findOrFail($data['service_id']);
            $seller = $service->user;
            $sellerId = $seller->id;
        } else {
            $serviceReq = ServiceRequest::findOrFail($data['request_id']);
            $seller = $buyer;
            $sellerId = $buyer->id;
            $buyer = $serviceReq->user;
        }

        // Prevent self-transaction
        if ($buyer->id === $sellerId) {
            return back()->with('error', 'Vous ne pouvez pas créer une transaction avec vous-même.');
        }

        // Check buyer balance
        if ($buyer->points_balance < $data['points_proposed']) {
            return back()->with('error', 'Solde insuffisant pour cette proposition.');
        }

        // Check for existing pending/accepted transaction
        $existingQuery = Transaction::where('buyer_id', $buyer->id)
            ->whereIn('status', ['pending', 'accepted']);

        if (!empty($data['service_id'])) {
            $existingQuery->where('service_id', $data['service_id']);
        } else {
            $existingQuery->where('request_id', $data['request_id']);
        }

        if ($existingQuery->exists()) {
            return back()->with('error', 'Vous avez déjà une transaction en cours pour cette annonce.');
        }

        $transaction = Transaction::create([
            'service_id' => $data['service_id'] ?? null,
            'request_id' => $data['request_id'] ?? null,
            'buyer_id' => $buyer->id,
            'seller_id' => $sellerId,
            'points_proposed' => $data['points_proposed'],
            'status' => 'pending',
        ]);

        $this->addSystemMessage($transaction, 'Nouvelle proposition envoyée : ' . $data['points_proposed'] . ' points.');

        // Update service_request status if applicable
        if (!empty($data['request_id'])) {
            ServiceRequest::where('id', $data['request_id'])->update(['status' => 'in_progress']);
        }

        return redirect()->route('messages.show', $transaction)->with('success', 'Proposition envoyée !');
    }

    public function approve(Transaction $transaction): RedirectResponse
    {
        $this->authorize('approve', $transaction);

        $transaction->update([
            'status' => 'accepted',
            'points_agreed' => $transaction->points_proposed,
        ]);

        $this->addSystemMessage($transaction, 'Proposition acceptée. L\'échange est en cours.');

        $transaction->buyer->notify(new TransactionStatusChanged($transaction->fresh()));

        return redirect()->route('messages.show', $transaction)->with('success', 'Proposition acceptée.');
    }

    public function refuse(Transaction $transaction): RedirectResponse
    {
        $this->authorize('refuse', $transaction);

        $transaction->update(['status' => 'refused']);

        $this->addSystemMessage($transaction, 'Proposition refusée.');

        $transaction->buyer->notify(new TransactionStatusChanged($transaction->fresh()));

        if ($transaction->request_id) {
            ServiceRequest::where('id', $transaction->request_id)->update(['status' => 'open']);
        }

        return redirect()->route('messages.show', $transaction)->with('success', 'Proposition refusée.');
    }

    public function adjust(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('adjust', $transaction);

        $data = $request->validate([
            'points_proposed' => 'required|integer|min:1',
        ]);

        if ($transaction->status !== 'pending') {
            return back()->with('error', 'Ajustement impossible hors statut "en attente".');
        }

        $transaction->update(['points_proposed' => $data['points_proposed']]);

        $this->addSystemMessage($transaction, 'Points ajustés à ' . $data['points_proposed'] . ' points.');

        return redirect()->route('messages.show', $transaction)->with('success', 'Points ajustés.');
    }

    public function cancel(Transaction $transaction): RedirectResponse
    {
        $this->authorize('cancel', $transaction);

        $transaction->update(['status' => 'cancelled']);

        $this->addSystemMessage($transaction, 'Transaction annulée.');

        if ($transaction->request_id) {
            ServiceRequest::where('id', $transaction->request_id)->update(['status' => 'open']);
        }

        return redirect()->route('messages.show', $transaction)->with('success', 'Transaction annulée.');
    }

    public function complete(Transaction $transaction): RedirectResponse
    {
        $this->authorize('complete', $transaction);

        $transaction->update([
            'status' => 'buyer_done',
            'buyer_confirmed_at' => now(),
        ]);

        $this->addSystemMessage($transaction, 'L\'acheteur a déclaré la prestation terminée. En attente de confirmation du vendeur.');

        $transaction->seller->notify(new TransactionStatusChanged($transaction->fresh()));

        return redirect()->route('messages.show', $transaction)->with('success', 'Prestation déclarée terminée.');
    }

    public function confirm(Transaction $transaction): RedirectResponse
    {
        $this->authorize('confirm', $transaction);

        DB::transaction(function () use ($transaction) {
            $points = $transaction->points_agreed;

            PointLedger::create([
                'user_id' => $transaction->buyer_id,
                'transaction_id' => $transaction->id,
                'delta' => -$points,
                'reason' => 'exchange_spent',
            ]);

            PointLedger::create([
                'user_id' => $transaction->seller_id,
                'transaction_id' => $transaction->id,
                'delta' => $points,
                'reason' => 'exchange_earned',
            ]);

            $transaction->buyer()->update(['points_balance' => DB::raw('points_balance - ' . $points)]);
            $transaction->seller()->update(['points_balance' => DB::raw('points_balance + ' . $points)]);

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

        $fresh = $transaction->fresh();
        $fresh->buyer->notify(new TransactionStatusChanged($fresh));
        $fresh->seller->notify(new TransactionStatusChanged($fresh));

        return redirect()->route('messages.show', $transaction)->with('success', 'Échange complété avec succès !');
    }

    public function contest(Transaction $transaction): RedirectResponse
    {
        $this->authorize('contest', $transaction);

        $transaction->update(['status' => 'accepted']);

        $this->addSystemMessage($transaction, 'La prestation est contestée. L\'échange est remis en cours.');

        return redirect()->route('messages.show', $transaction)->with('success', 'Prestation contestée, échange relancé.');
    }

    private function addSystemMessage(Transaction $transaction, string $body): void
    {
        Message::create([
            'transaction_id' => $transaction->id,
            'sender_id' => null,
            'body' => $body,
            'type' => 'system',
        ]);
    }
}
