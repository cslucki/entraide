<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoopMessage;
use App\Models\Message;
use App\Models\Organization;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        // TASK-1666 — une conversation d'echange ne vit que dans les messages
        // d'echange. Un `transaction_id` impose donc l'onglet `exchanges` :
        // sans cela le lien tomberait sur l'onglet ChatLoop par defaut et
        // paraitrait vide alors que la conversation existe. Un faux « il n'y a
        // rien » est pire qu'une erreur : il se croit informatif.
        $transactionId = $request->filled('transaction_id')
            ? (string) $request->input('transaction_id')
            : null;

        if ($transactionId !== null) {
            $filter = 'exchanges';
        }

        $messages = match ($filter) {
            'chatloop' => $this->applyOrganizationFilter(LoopMessage::query(), $selectedOrganizationId)
                ->with(['sender:id,name,email', 'loop:id,name'])
                ->latest()
                ->paginate($perPage)
                ->withQueryString(),

            'exchanges' => $this->applyTransactionFilter(
                $this->applyOrganizationFilter(Message::query(), $selectedOrganizationId),
                $transactionId
            )
                ->with(['sender:id,name,email', 'transaction.buyer:id,name', 'transaction.seller:id,name'])
                ->latest()
                ->paginate($perPage)
                ->withQueryString(),

            default => $this->unifiedFeed($selectedOrganizationId, $perPage),
        };

        return view('admin.messages.index', compact('filter', 'messages', 'organizations', 'selectedOrganizationId', 'transactionId'));
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

    /**
     * TASK-1666 — borne une liste de messages a UNE conversation d'echange.
     *
     * La validation de forme est obligatoire AVANT la comparaison : PostgreSQL
     * leve SQLSTATE 22P02 sur un texte qui n'est pas un UUID, la ou SQLite
     * l'accepte sans broncher. Une forme invalide ne doit donc rien ramener,
     * jamais produire une 500.
     */
    private function applyTransactionFilter($query, ?string $transactionId)
    {
        if ($transactionId === null) {
            return $query;
        }

        if (! Str::isUuid($transactionId)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('transaction_id', $transactionId);
    }

    private function adminOrganizations(): Collection
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

    /**
     * Supprimer PLUSIEURS messages d'un coup.
     *
     * TASK-1668 — les supprimer un par un etait le seul geste possible. Rejouer
     * N fois la route unitaire depuis le navigateur serait N requetes, non
     * atomique, et laisserait un etat partiel si l'une echouait au milieu.
     *
     * Trois gardes, dans cet ordre :
     *
     * 1. **Le perimetre est RECALCULE cote serveur**, jamais deduit des seuls
     *    identifiants postes : on repart de la meme requete que l'ecran
     *    (organisation, et le cas echeant la conversation), et on ne supprime
     *    que l'intersection. Un identifiant hors perimetre est **ignore**, pas
     *    refuse en bloc — sinon une ligne perimee ferait echouer tout le geste.
     * 2. **Une transaction** : tout ou rien.
     * 3. **Le mode `all` est refuse** : le flux unifie melange deux modeles, et
     *    un identifiant ne dit pas auquel il appartient.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|uuid',
            'filter' => 'required|in:chatloop,exchanges',
        ]);

        $selectedOrganizationId = $this->selectedAdminOrganizationId($request);

        $query = $data['filter'] === 'chatloop'
            ? $this->applyOrganizationFilter(LoopMessage::query(), $selectedOrganizationId)
            : $this->applyTransactionFilter(
                $this->applyOrganizationFilter(Message::query(), $selectedOrganizationId),
                $request->filled('transaction_id') ? (string) $request->input('transaction_id') : null
            );

        $cibles = (clone $query)->whereIn('id', $data['ids'])->get();

        if ($cibles->isEmpty()) {
            return back()->with('error', __('admin.messages_bulk_none'));
        }

        DB::transaction(function () use ($cibles) {
            foreach ($cibles as $message) {
                $message->reactions()->delete();
                $message->delete();
            }
        });

        return back()->with('success', __('admin.messages_bulk_done', ['count' => $cibles->count()]));
    }

    public function destroy(Message $message): RedirectResponse
    {
        $message->reactions()->delete();
        $message->delete();

        return back()->with('success', __('admin.message_deleted'));
    }

    public function destroyLoopMessage(LoopMessage $loopMessage): RedirectResponse
    {
        $loopMessage->reactions()->delete();
        $loopMessage->delete();

        return back()->with('success', __('admin.loop_message_deleted'));
    }
}
