<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoopMessage;
use App\Models\Message;
use App\Models\Organization;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\View\View;

class AdminMessageController extends Controller
{
    public function index(Request $request): View
    {
        $filter = $request->input('filter', 'chatloop');
        $allowedFilters = ['chatloop', 'exchanges', 'all'];
        $filter = in_array($filter, $allowedFilters) ? $filter : 'chatloop';
        $organizations = $this->adminOrganizations();
        $selectedOrganizationId = $this->selectedAdminOrganizationId($request);
        $perPage = 25;

        $messages = match ($filter) {
            'chatloop' => $this->applyOrganizationFilter(LoopMessage::query(), $selectedOrganizationId)
                ->with(['sender:id,name,email', 'loop:id,name'])
                ->latest()
                ->paginate($perPage)
                ->withQueryString(),

            'exchanges' => $this->applyOrganizationFilter(Message::query(), $selectedOrganizationId)
                ->with(['sender:id,name,email', 'transaction.buyer:id,name', 'transaction.seller:id,name'])
                ->latest()
                ->paginate($perPage)
                ->withQueryString(),

            default => $this->unifiedFeed($selectedOrganizationId, $perPage),
        };

        return view('admin.messages.index', compact('filter', 'messages', 'organizations', 'selectedOrganizationId'));
    }

    private function unifiedFeed(string $organizationId, int $perPage): LengthAwarePaginator
    {
        $loopMessages = $this->applyOrganizationFilter(LoopMessage::query(), $organizationId)
            ->with(['sender:id,name,email', 'loop:id,name'])
            ->latest()
            ->limit(500)
            ->get()
            ->each->setAttribute('message_type', 'chatloop');

        $exchangeMessages = $this->applyOrganizationFilter(Message::query(), $organizationId)
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

    private function adminOrganizations(): \Illuminate\Support\Collection
    {
        return Organization::orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'is_default']);
    }

    private function selectedAdminOrganizationId(Request $request): string
    {
        if ($request->input('organization_id') === 'all') {
            return 'all';
        }

        if ($request->filled('organization_id')) {
            return (string) $request->input('organization_id');
        }

        return (string) (DefaultOrganizationResolver::resolve()?->getKey() ?? 'all');
    }

    private function applyOrganizationFilter($query, string $organizationId)
    {
        if ($organizationId !== 'all') {
            $query->where('organization_id', $organizationId);
        }

        return $query;
    }

    public function show(Message $message): View
    {
        $message->load(['sender', 'transaction.buyer', 'transaction.seller']);

        $orgId = auth()->user()->organization_id;

        if (! $orgId || $message->organization_id !== $orgId) {
            abort(404);
        }

        $before = Message::where('transaction_id', $message->transaction_id)
            ->where('organization_id', $orgId)
            ->where('created_at', '<', $message->created_at)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->reverse()
            ->values();

        $after = Message::where('transaction_id', $message->transaction_id)
            ->where('organization_id', $orgId)
            ->where('created_at', '>', $message->created_at)
            ->orderBy('created_at')
            ->limit(5)
            ->get();

        return view('admin.messages.show', compact('message', 'before', 'after'));
    }

    // destroy removed: T074.9 is strictly read-only (OpenAI review fix)
}
