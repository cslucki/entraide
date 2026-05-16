<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoopMessage;
use App\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\View\View;

class AdminMessageController extends Controller
{
    public function index(Request $request): View
    {
        $user = auth()->user();
        $orgId = $user->organization_id ?? $user->community_id;
        $filter = $request->input('filter', 'chatloop');
        $allowedFilters = ['chatloop', 'exchanges', 'all'];
        $filter = in_array($filter, $allowedFilters) ? $filter : 'chatloop';
        $perPage = 25;

        if (! $orgId) {
            $messages = new LengthAwarePaginator([], 0, $perPage);
        } else {
            $messages = match ($filter) {
                'chatloop' => LoopMessage::whereHas('loop', fn($q) => $q->where('community_id', $orgId))
                    ->with(['sender:id,name,email', 'loop:id,name'])
                    ->latest()
                    ->paginate($perPage)
                    ->withQueryString(),

                'exchanges' => Message::whereHas('transaction', fn($q) => $q->where('organization_id', $orgId))
                    ->with(['sender:id,name,email', 'transaction.buyer:id,name', 'transaction.seller:id,name'])
                    ->latest()
                    ->paginate($perPage)
                    ->withQueryString(),

                default => $this->unifiedFeed($orgId, $perPage),
            };
        }

        return view('admin.messages.index', compact('filter', 'messages'));
    }

    private function unifiedFeed(string $orgId, int $perPage): LengthAwarePaginator
    {
        $loopMessages = LoopMessage::whereHas('loop', fn($q) => $q->where('community_id', $orgId))
            ->with(['sender:id,name,email', 'loop:id,name'])
            ->latest()
            ->limit(500)
            ->get()
            ->each->setAttribute('message_type', 'chatloop');

        $exchangeMessages = Message::whereHas('transaction', fn($q) => $q->where('organization_id', $orgId))
            ->with(['sender:id,name,email', 'transaction.buyer:id,name', 'transaction.seller:id,name'])
            ->latest()
            ->limit(500)
            ->get()
            ->each->setAttribute('message_type', 'exchange');

        $merged = $loopMessages->concat($exchangeMessages)
            ->sortByDesc('created_at')
            ->values();

        $page = Paginator::resolveCurrentPage();
        $total = $merged->count();

        return new LengthAwarePaginator(
            $merged->forPage($page, $perPage)->values(),
            $total,
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()]
        )->withQueryString();
    }

    public function show(Message $message): View
    {
        $message->load(['sender', 'transaction.buyer', 'transaction.seller']);

        $orgId = auth()->user()->organization_id ?? auth()->user()->community_id;

        if (! $orgId || ! $message->transaction || $message->transaction->organization_id !== $orgId) {
            abort(404);
        }

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

    // destroy removed: T074.9 is strictly read-only (OpenAI review fix)
}
