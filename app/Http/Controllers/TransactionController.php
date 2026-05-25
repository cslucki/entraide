<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\PointLedger;
use App\Models\Scopes\BelongsToTenantScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Notifications\TransactionStatusChanged;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $organization = currentOrganization();

        if (! $organization) {
            abort(404);
        }

        $data = $request->validate([
            'service_id' => 'nullable|uuid|exists:services,id',
            'request_id' => 'nullable|uuid|exists:service_requests,id',
            'points_proposed' => 'required|integer|min:1',
        ]);

        $buyer = auth()->user();

        // Determine seller and organization_id
        $organizationId = null;
        if (! empty($data['service_id'])) {
            $service = Service::withoutGlobalScope(BelongsToTenantScope::class)->findOrFail($data['service_id']);

            if ($service->organization_id === null || $service->organization_id !== $organization->id) {
                abort(404);
            }

            $seller = $service->user;
            $sellerId = $seller->id;
            $organizationId = $service->organization_id;
        } else {
            $serviceReq = ServiceRequest::withoutGlobalScope(BelongsToTenantScope::class)->findOrFail($data['request_id']);

            if ($serviceReq->organization_id === null || $serviceReq->organization_id !== $organization->id) {
                abort(404);
            }

            $seller = $buyer;
            $sellerId = $buyer->id;
            $buyer = $serviceReq->user;
            $organizationId = $serviceReq->organization_id;
        }

        // Prevent self-transaction
        if ($buyer->id === $sellerId) {
            return back()->with('error', 'Vous ne pouvez pas créer une transaction avec vous-même.');
        }

        // Check buyer balance
        if ($buyer->points_balance < $data['points_proposed']) {
            return back()->with('error', 'Solde insuffisant pour cette échange.');
        }

        // Check for existing pending/accepted transaction
        $existingQuery = Transaction::where('buyer_id', $buyer->id)
            ->whereIn('status', ['pending', 'accepted']);

        if (! empty($data['service_id'])) {
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
            'organization_id' => $organizationId,
            'points_proposed' => $data['points_proposed'],
            'status' => 'pending',
        ]);

        $this->addSystemMessage($transaction, 'Nouvelle échange envoyée : '.$data['points_proposed'].' points.');

        // Update service_request status if applicable
        if (! empty($data['request_id'])) {
            ServiceRequest::where('id', $data['request_id'])->update(['status' => 'in_progress']);
        }

        return redirect()->route('messages.show', $transaction)->with('success', 'Échange envoyée !');
    }

    public function approve(Transaction $transaction): RedirectResponse
    {
        $this->authorize('approve', $transaction);

        $transaction->update([
            'status' => 'accepted',
            'points_agreed' => $transaction->points_proposed,
        ]);

        $this->addSystemMessage($transaction, 'Échange acceptée. L\'échange est en cours.');

        $transaction->buyer->notify(new TransactionStatusChanged($transaction->fresh()));

        return redirect()->route('messages.show', $transaction)->with('success', 'Échange acceptée.');
    }

    public function refuse(Transaction $transaction): RedirectResponse
    {
        $this->authorize('refuse', $transaction);

        $transaction->update(['status' => 'refused']);

        $this->addSystemMessage($transaction, 'Échange refusée.');

        $transaction->buyer->notify(new TransactionStatusChanged($transaction->fresh()));

        if ($transaction->request_id) {
            ServiceRequest::where('id', $transaction->request_id)->update(['status' => 'open']);
        }

        return redirect()->route('messages.show', $transaction)->with('success', 'Échange refusée.');
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

        $this->addSystemMessage($transaction, 'Points ajustés à '.$data['points_proposed'].' points.');

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
            $tx = Transaction::where('id', $transaction->id)->lockForUpdate()->first();

            if ($tx->status !== 'buyer_done') {
                return;
            }

            $points = $tx->points_agreed;

            PointLedger::create([
                'user_id' => $tx->buyer_id,
                'transaction_id' => $tx->id,
                'delta' => -$points,
                'reason' => 'exchange_spent',
            ]);

            PointLedger::create([
                'user_id' => $tx->seller_id,
                'transaction_id' => $tx->id,
                'delta' => $points,
                'reason' => 'exchange_earned',
            ]);

            $tx->buyer()->update(['points_balance' => DB::raw('points_balance - '.$points)]);
            $tx->seller()->update(['points_balance' => DB::raw('points_balance + '.$points)]);

            $tx->update([
                'status' => 'completed',
                'seller_confirmed_at' => now(),
                'completed_at' => now(),
            ]);

            if ($tx->request_id) {
                ServiceRequest::where('id', $tx->request_id)->update(['status' => 'closed']);
            }
        });

        $fresh = $transaction->fresh();

        if ($fresh->status !== 'completed') {
            return redirect()->route('messages.show', $transaction)
                ->with('error', 'Cette transaction a déjà été traitée.');
        }

        $this->addSystemMessage($transaction, 'Échange complété ! Les points ont été transférés.');

        $fresh->buyer->notify(new TransactionStatusChanged($fresh));
        $fresh->seller->notify(new TransactionStatusChanged($fresh));

        return redirect()->route('messages.show', $transaction)->with('success', 'Échange complété avec succès !');
    }

    public function contest(Transaction $transaction): RedirectResponse
    {
        $this->authorize('contest', $transaction);

        DB::transaction(function () use ($transaction) {
            $tx = Transaction::where('id', $transaction->id)->lockForUpdate()->first();

            if ($tx->status !== 'buyer_done') {
                return;
            }

            $tx->update(['status' => 'accepted']);
        });

        $fresh = $transaction->fresh();

        if ($fresh->status !== 'accepted') {
            return redirect()->route('messages.show', $transaction)
                ->with('error', 'Impossible de contester cette transaction.');
        }

        $this->addSystemMessage($transaction, 'La prestation est contestée. L\'échange est remis en cours.');

        return redirect()->route('messages.show', $transaction)->with('success', 'Prestation contestée, échange relancé.');
    }

    public function exportCsv(): StreamedResponse
    {
        $user = auth()->user();

        $transactions = Transaction::where('buyer_id', $user->id)
            ->orWhere('seller_id', $user->id)
            ->with(['service', 'serviceRequest', 'buyer', 'seller'])
            ->orderByDesc('created_at')
            ->get();

        $filename = 'entraide-transactions-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($transactions, $user) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
            fputcsv($handle, ['Date', 'Type', 'Service', 'Contrepartie', 'Points', 'Statut'], ';');

            foreach ($transactions as $transaction) {
                $isBuyer = $transaction->buyer_id === $user->id;
                $contrepartie = $isBuyer ? $transaction->seller->name : $transaction->buyer->name;
                $points = $transaction->points_agreed ?? $transaction->points_proposed;

                fputcsv($handle, [
                    $transaction->created_at->format('d/m/Y'),
                    $isBuyer ? 'Achat' : 'Vente',
                    $transaction->subject,
                    $contrepartie,
                    $points,
                    $transaction->status_label,
                ], ';');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
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
