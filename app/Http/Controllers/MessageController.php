<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MessageController extends Controller
{
    private function loadConversations(): array
    {
        $user = auth()->user();

        $transactions = Transaction::where('buyer_id', $user->id)
            ->orWhere('seller_id', $user->id)
            ->with(['buyer', 'seller', 'service', 'serviceRequest', 'messages' => fn($q) => $q->latest('created_at')->limit(1)])
            ->orderByDesc('updated_at')
            ->get();

        $unreadCounts = Message::whereIn('transaction_id', $transactions->pluck('id'))
            ->where(fn($q) => $q->where('sender_id', '!=', $user->id)->orWhereNull('sender_id'))
            ->whereNull('read_at')
            ->where('type', 'user')
            ->selectRaw('transaction_id, count(*) as cnt')
            ->groupBy('transaction_id')
            ->pluck('cnt', 'transaction_id');

        return [$transactions, $unreadCounts];
    }

    public function index(): View
    {
        [$transactions, $unreadCounts] = $this->loadConversations();
        $activeTransaction = null;

        return view('messages.index', compact('transactions', 'activeTransaction', 'unreadCounts'));
    }

    public function show(Transaction $transaction): View|RedirectResponse
    {
        $this->authorize('view', $transaction);

        [$transactions, $unreadCounts] = $this->loadConversations();

        return view('messages.index', compact('transactions', 'transaction', 'unreadCounts'));
    }

    public function showWithUser(User $user): View|RedirectResponse
    {
        $currentUser = auth()->user();

        if ($currentUser->is($user)) {
            return redirect()->route('messages.index');
        }

        $transaction = Transaction::where(function ($q) use ($currentUser, $user) {
            $q->where('buyer_id', $currentUser->id)->where('seller_id', $user->id);
        })->orWhere(function ($q) use ($currentUser, $user) {
            $q->where('buyer_id', $user->id)->where('seller_id', $currentUser->id);
        })->first();

        if ($transaction) {
            return redirect()->route('messages.show', $transaction);
        }

        $organization = currentOrganization() ?? $currentUser->organization ?? $user->organization;

        if ($organization === null || $currentUser->organization_id !== $user->organization_id) {
            return redirect()->route('messages.index');
        }

        $transaction = Transaction::create([
            'organization_id' => $organization->id,
            'buyer_id' => $currentUser->id,
            'seller_id' => $user->id,
            'points_proposed' => 0,
            'status' => 'pending',
        ]);

        Message::create([
            'transaction_id' => $transaction->id,
            'body' => 'Conversation directe démarrée.',
            'type' => 'system',
            'organization_id' => $organization->id,
        ]);

        return redirect()->route('messages.show', $transaction);
    }
}
