<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminMessageController extends Controller
{
    public function index(Request $request): View
    {
        $query = Message::with(['sender', 'transaction.buyer', 'transaction.seller'])
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $query->where('body', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from . ' 00:00:00');
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        if ($request->filled('user')) {
            $term = $request->user;
            $query->where(function ($q) use ($term) {
                $q->whereHas('sender', fn($u) => $u->where('name', 'like', "%$term%")->orWhere('email', 'like', "%$term%"))
                  ->orWhereHas('transaction.buyer', fn($u) => $u->where('name', 'like', "%$term%")->orWhere('email', 'like', "%$term%"))
                  ->orWhereHas('transaction.seller', fn($u) => $u->where('name', 'like', "%$term%")->orWhere('email', 'like', "%$term%"));
            });
        }

        $messages = $query->paginate(50)->withQueryString();

        return view('admin.messages.index', compact('messages'));
    }

    public function show(Message $message): View
    {
        $message->load(['sender', 'transaction.buyer', 'transaction.seller']);

        $before = Message::where('transaction_id', $message->transaction_id)
            ->where('created_at', '<', $message->created_at)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->reverse()
            ->values();

        $after = Message::where('transaction_id', $message->transaction_id)
            ->where('created_at', '>', $message->created_at)
            ->orderBy('created_at')
            ->limit(5)
            ->get();

        return view('admin.messages.show', compact('message', 'before', 'after'));
    }

    public function destroy(Message $message): RedirectResponse
    {
        $message->delete();
        return redirect()->route('admin.messages')->with('success', 'Message supprimé définitivement.');
    }
}
