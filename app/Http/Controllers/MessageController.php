<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MessageController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        $transactions = Transaction::where('buyer_id', $user->id)
            ->orWhere('seller_id', $user->id)
            ->with(['buyer', 'seller', 'service', 'serviceRequest', 'messages' => fn($q) => $q->latest('created_at')->limit(1)])
            ->orderByDesc('updated_at')
            ->get();

        $activeTransaction = null;

        return view('messages.index', compact('transactions', 'activeTransaction'));
    }

    public function show(Transaction $transaction): View|RedirectResponse
    {
        $this->authorize('view', $transaction);

        $user = auth()->user();

        $transactions = Transaction::where('buyer_id', $user->id)
            ->orWhere('seller_id', $user->id)
            ->with(['buyer', 'seller', 'service', 'serviceRequest', 'messages' => fn($q) => $q->latest('created_at')->limit(1)])
            ->orderByDesc('updated_at')
            ->get();

        return view('messages.index', compact('transactions', 'transaction'));
    }
}
