<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

class MessageController extends Controller
{
    private function loadConversations(): array
    {
        $user = auth()->user();
        $organization = currentOrganization();

        $transactions = Transaction::where(function ($query) use ($user) {
            $query->where('buyer_id', $user->id)
                ->orWhere('seller_id', $user->id);
        })
            ->when($organization, fn ($query) => $query->where('organization_id', $organization->id))
            ->with(['buyer', 'seller', 'service', 'serviceRequest', 'messages' => fn ($q) => $q->latest('created_at')->limit(1)])
            ->orderByDesc('updated_at')
            ->get();

        // TASK-1693 bug V — meme regle que le badge du rail.
        //
        // Ce compteur-ci traitait DEJA correctement le `sender_id` NULL des
        // messages systeme, mais les ecartait par leur `type`. Les deux
        // compteurs auraient donc diverge : le rail annoncant 3, les fils
        // affichant 0. Un badge qu'aucune ligne ne justifie est pire que pas
        // de badge.
        $unreadCounts = Message::whereIn('transaction_id', $transactions->pluck('id'))
            ->where(fn ($q) => $q->where('sender_id', '!=', $user->id)->orWhereNull('sender_id'))
            ->whereNull('read_at')
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

        $organization = currentOrganization();
        if ($organization && $transaction->organization_id !== $organization->id) {
            abort(404);
        }

        [$transactions, $unreadCounts] = $this->loadConversations();

        return view('messages.index', compact('transactions', 'transaction', 'unreadCounts'));
    }

    public function orgShow(string $org, Transaction $transaction): View|RedirectResponse
    {
        return $this->show($transaction);
    }

    /**
     * TASK-1693 bug AA — le jumeau prefixe d'`showWithUser()`.
     *
     * Sur une route `/org/{organization}/…`, le parametre `{organization}`
     * arrive en PREMIER argument du controleur : sans ce relais, la methode
     * recevait le slug de l'Organization a la place de la personne, et levait
     * un `TypeError` — un 500. C'est l'idiome deja tenu par `orgShow()` juste
     * au-dessus, et par `orgStore()` / `orgCancel()` ailleurs.
     */
    /**
     * TASK-1693 bug AA — rester sur la surface de l'Organization.
     *
     * Rediriger vers `messages.show` sans prefixe ramenait sur la surface
     * courte, ou `ResolveUrlOrganization` lie l'Organization PAR DEFAUT —
     * exactement le piege que ce correctif ferme un cran plus haut. Ouvrir une
     * conversation depuis sa Boucle doit y laisser.
     */
    private function versLaConversation(Transaction $transaction): RedirectResponse
    {
        $organization = currentOrganization();

        if ($organization !== null && Route::has('organization.messages.show')) {
            return redirect()->route('organization.messages.show', [
                'organization' => $organization->slug,
                'transaction' => $transaction,
            ]);
        }

        return redirect()->route('messages.show', $transaction);
    }

    public function orgShowWithUser(string $org, User $user): View|RedirectResponse
    {
        return $this->showWithUser($user);
    }

    public function showWithUser(User $user): View|RedirectResponse
    {
        $currentUser = auth()->user();

        if ($currentUser->is($user)) {
            return redirect()->route('messages.index');
        }

        // TASK-1693 bug AB — on rouvre une DISCUSSION, jamais un echange.
        //
        // La recherche portait sur n'importe quelle transaction entre les deux
        // personnes : « Ecrire a user2 » ramenait donc dans « Proposition 1
        // d'aide de user », un echange termine, avec ses points et ses boutons
        // « Confirmer / Contester ». Ecrire a quelqu'un n'a rien a voir avec
        // une transaction en cours.
        //
        // L'alternative des deux sens passe par un SCOPE, et c'est ce qui la
        // met a l'abri : Laravel groupe les conditions d'un scope. Ecrite en
        // ligne — comme elle l'etait — un `where(...)->orWhere(...)` auquel on
        // ajoute une condition rendrait « (conditions ET A) OU B », et la
        // seconde branche echapperait au filtre.
        $conversation = Transaction::query()
            ->directConversation()
            ->between($currentUser->id, $user->id)
            ->latest()
            ->first();

        if ($conversation) {
            return $this->versLaConversation($conversation);
        }

        $organization = currentOrganization() ?? $currentUser->organization ?? $user->organization;

        if ($organization === null || $currentUser->organization_id !== $user->organization_id) {
            return redirect()->route('messages.index');
        }

        // TASK-1289 : garde d'appartenance a l'Organization RESOLUE, avant
        // toute ecriture. La verification ci-dessus compare les deux
        // utilisateurs entre eux (on ne demarre pas une conversation avec
        // quelqu'un d'une autre Organization) ; celle-ci compare l'acteur a
        // l'Organization dans laquelle la Transaction et le Message vont etre
        // crees. Sans elle, deux membres d'une meme AUTRE Organization
        // ecrivaient dans l'Organization par defaut de la surface courte.
        if ($currentUser->organization_id !== $organization->id) {
            return redirect()->route('messages.index')->with('error', __('messages.cross_org'));
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

        return $this->versLaConversation($transaction);
    }
}
