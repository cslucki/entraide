<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\PointLedger;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Services\ServiceRequestLifecycle;
use App\Models\Transaction;
use App\Support\Entraide\MessageSysteme;
use App\Support\Entraide\TempsPartage;
use App\Notifications\TransactionStatusChanged;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmitter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $organization = currentOrganization();
        $actor = auth()->user();

        // TASK-1289 : la garde existante verifiait l'Organization resolue puis,
        // plus bas, que la CIBLE (service ou demande) lui appartient (T075.15).
        // Elle ne verifiait jamais que l'ACTEUR lui appartient : un membre
        // d'une autre Organization devenait buyer (service) ou seller (demande)
        // d'une Transaction creee dans l'Organization par defaut. Completee
        // ici, meme forme que ProfileController / ReportController.
        if (! $organization || $actor->organization_id !== $organization->id) {
            abort(404);
        }

        $data = $request->validate([
            'service_id' => 'nullable|uuid|exists:services,id',
            'request_id' => 'nullable|uuid|exists:service_requests,id',
            // TASK-1754 §9.3 — `min:0`, et c'est LA difference philosophique avec
            // une place de marche : le don y est impossible, ici il doit etre un
            // bouton. Arbitrage Q7 de Cyril : partout, pas seulement en reponse a
            // une demande — une proposition d'aide peut aussi s'offrir.
            'points_proposed' => 'required|integer|min:0',
        ]);

        $buyer = $actor;

        // Determine seller and organization_id
        $organizationId = null;
        if (! empty($data['service_id'])) {
            $service = Service::withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($data['service_id']);

            if ($service->organization_id === null || $service->organization_id !== $organization->id) {
                abort(404);
            }

            $seller = $service->user;
            $sellerId = $seller->id;
            $organizationId = $service->organization_id;

            // TASK-1751 — le destinataire est pose ICI, dans la branche.
            //
            // Il doit etre celui qui n'a PAS clique : l'auteur de l'annonce.
            // Le deduire apres coup de `$buyer` ou `$seller` serait fragile —
            // la branche voisine REASSIGNE `$buyer` quelques lignes plus bas,
            // et une relecture distraite notifierait l'acteur lui-meme.
            $destinataire = $service->user;
        } else {
            $serviceReq = ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($data['request_id']);

            if ($serviceReq->organization_id === null || $serviceReq->organization_id !== $organization->id) {
                abort(404);
            }

            $seller = $buyer;
            $sellerId = $buyer->id;
            $buyer = $serviceReq->user;
            $organizationId = $serviceReq->organization_id;

            // TASK-1751 — ici aussi l'auteur de l'annonce, et c'est la ligne
            // qui rend le piege visible : `$buyer` VIENT d'etre reassigne.
            $destinataire = $serviceReq->user;
        }

        // Prevent self-transaction
        if ($buyer->id === $sellerId) {
            return back()->with('error', 'Vous ne pouvez pas créer une transaction avec vous-même.');
        }

        // Check buyer balance
        if ($buyer->points_balance < $data['points_proposed']) {
            return back()->with('error', 'Solde insuffisant pour cet échange.');
        }

        // TASK-1693 bug S — « VOUS avez deja une transaction en cours » doit
        // parler de CELUI QUI PROPOSE, pas de l'acheteur.
        //
        // Sur une demande d'aide, `$buyer` a ete echange juste au-dessus : il
        // designe l'AUTEUR DE LA DEMANDE, pas la personne qui clique. La garde
        // disait donc « cette demande a deja une proposition » tout en
        // l'annoncant au second aidant comme SA transaction — un message faux
        // pour lui, qui n'avait rien. C'est le troisieme symptome de la meme
        // racine que le bouton « Accepter » : le proposant n'etait pas celui
        // qu'on croyait.
        //
        // Le proposant est TOUJOURS l'acteur authentifie. On vise donc la
        // colonne qui le porte, cote service comme cote demande.
        // TASK-1754 — la MEME methode que les deux pages d'annonce interrogent.
        // Recopiee ici, elle aurait diverge, et la divergence serait muette :
        // une vue qui croit qu'il n'y a pas d'echange affiche le formulaire, le
        // POST part, et la garde le refuse — le cul-de-sac revient.

        // TASK-1754 (§4.2b) — un doublon n'est plus une ERREUR, c'est une
        // REDIRECTION vers la conversation qui existe deja.
        //
        // ## Le defaut repare
        //
        // La garde rendait `back()->with('error', …)` : un toast rouge sur la
        // page de l'annonce, et AUCUN chemin vers la conversation. Un
        // cul-de-sac, reproduit en recette le 04/11. L'utilisateur voulait
        // parler a quelqu'un ; on lui disait qu'il ne pouvait pas, sans lui dire
        // ou aller.
        //
        // ## Pourquoi la garde RESTE malgre le correctif de la vue
        //
        // Le lot 3 fait disparaitre le formulaire quand un echange est actif.
        // Ca ne suffit pas : un second onglet, un bouton de la PWA garde en
        // cache, un double-submit — le POST en double reste possible. La vue
        // supprime l'occasion, cette garde supprime la consequence.
        //
        // `info` et non `error` : il ne s'est rien passe de mal.
        $existant = Transaction::echangeActif(
            $actor->id,
            $data['service_id'] ?? null,
            $data['request_id'] ?? null,
        );

        if ($existant !== null) {
            return $this->redirectToMessage($existant)
                ->with('info', __('entraide.deja_en_discussion'));
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

        MessageSysteme::ecrire($transaction, 'proposition_envoyee', ['points' => (int) $data['points_proposed']]);

        // TASK-1751 — prevenir l'auteur de l'annonce.
        //
        // `store()` n'emettait RIEN jusqu'ici : ni in-app, ni email, ni push.
        // Quelqu'un pouvait repondre a votre annonce sans que vous l'appreniez
        // autrement qu'en revenant voir par vous-meme.
        //
        // `rescue()` comme `approve()` et `refuse()` le font deja : l'echange
        // EST cree a ce stade, et la transaction metier ne doit jamais echouer
        // parce qu'une notification a leve. Le destinataire verrait une erreur
        // pour un echange pourtant enregistre.
        //
        // `eventId` = l'id de la Transaction. Ici l'EVENEMENT (« cet echange
        // vient d'etre cree ») et l'OBJET coincident, parce qu'une Transaction
        // n'est creee qu'une fois — contrairement a `loop.help_request.created`
        // qui devait eviter l'id de la Boucle, reutilisable par plusieurs
        // demandes. L'idempotence sur `(event_id, recipient_id)` protege donc
        // d'une double soumission.
        rescue(fn () => app(NotificationEmitter::class)->emit(
            notificationKey: NotificationCatalogue::TRANSACTION_PROPOSAL_CREATED,
            organization: (string) $organizationId,
            recipient: $destinataire,
            eventId: (string) $transaction->id,
            objectType: NotificationCatalogue::OBJECT_TRANSACTION,
            objectId: (string) $transaction->id,
            actor: $actor,
        ), report: true);

        // TASK-1693 bug W — la demande ne quitte PLUS l'explorateur ici.
        //
        // Elle y restait jusqu'a la premiere proposition seulement, et ne
        // pouvait donc recevoir qu'UNE offre, de qui que ce soit. Elle le
        // quitte desormais a l'ACCEPTATION (`approve()`), ce qui laisse son
        // auteur comparer plusieurs propositions.
        //
        // Rien d'autre ne bouge : la demande reste `open`, donc l'explorateur
        // (`scopeOpen`) et le formulaire « Proposer mon aide »
        // (`requests/show.blade.php:111`) la montrent sans qu'on ait a les
        // toucher — les deux verrous tombent d'eux-memes.

        return $this->redirectToMessage($transaction)->with('success', 'Échange envoyé !');
    }

    public function orgStore(Request $request, string $org): RedirectResponse
    {
        return $this->store($request);
    }

    public function approve(Transaction $transaction): RedirectResponse
    {
        $this->authorize('approve', $transaction);

        // TASK-1693 bug W — le solde est verifie AU MOMENT DE L'ENGAGEMENT.
        //
        // Il ne l'etait qu'a la proposition, et rien n'est reserve entre les
        // deux (les points ne bougent qu'a la fin de la prestation). Tant
        // qu'une seule proposition pouvait exister, cela ne pouvait pas nuire.
        // Avec plusieurs, trois offres de 20 points a quelqu'un qui en a 25
        // passeraient toutes, et accepter la deuxieme mettrait son compte a
        // decouvert. L'acceptation est le moment ou l'engagement devient reel.
        if ($transaction->buyer->points_balance < $transaction->points_proposed) {
            return back()->with('error', __('requests.lifecycle.insufficient_balance'));
        }

        $transaction->update([
            'status' => 'accepted',
            'points_agreed' => $transaction->points_proposed,
        ]);

        MessageSysteme::ecrire($transaction, 'echange_accepte');

        // La demande est pourvue, et les propositions concurrentes closes.
        app(ServiceRequestLifecycle::class)->markHandled($transaction);

        rescue(
            fn () => $transaction->buyer->notify(new TransactionStatusChanged($transaction->fresh())),
            fn (\Throwable $e) => Log::error('Email notification failed after successful business action', [
                'event' => 'transaction_approved',
                'transaction_id' => $transaction->id,
                'organization_id' => $transaction->organization_id,
                'notifiable_id' => $transaction->buyer->id,
                'exception_class' => $e::class,
            ]),
            false,
        );

        return $this->redirectToMessage($transaction)->with('success', 'Échange accepté.');
    }

    public function orgApprove(string $org, Transaction $transaction): RedirectResponse
    {
        return $this->approve($transaction);
    }

    public function refuse(Transaction $transaction): RedirectResponse
    {
        $this->authorize('refuse', $transaction);

        $transaction->update(['status' => 'refused']);

        MessageSysteme::ecrire($transaction, 'echange_decline');

        rescue(
            fn () => $transaction->buyer->notify(new TransactionStatusChanged($transaction->fresh())),
            fn (\Throwable $e) => Log::error('Email notification failed after successful business action', [
                'event' => 'transaction_refused',
                'transaction_id' => $transaction->id,
                'organization_id' => $transaction->organization_id,
                'notifiable_id' => $transaction->buyer->id,
                'exception_class' => $e::class,
            ]),
            false,
        );

        // TASK-1693 bug W — ne rouvrir que si AUCUNE autre proposition ne
        // tient la demande. Sans cette condition, relacher une concurrente
        // rouvrirait une demande deja pourvue — sans danger tant qu'une seule
        // proposition pouvait exister, plus du tout aujourd'hui.
        app(ServiceRequestLifecycle::class)->releaseIfNothingHolds($transaction);

        return $this->redirectToMessage($transaction)->with('success', 'Échange refusé.');
    }

    public function orgRefuse(string $org, Transaction $transaction): RedirectResponse
    {
        return $this->refuse($transaction);
    }

    public function adjust(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('adjust', $transaction);

        $data = $request->validate([
            // TASK-1754 §9.3 — on doit pouvoir OFFRIR en cours de route, pas
            // seulement a l'ouverture.
            'points_proposed' => 'required|integer|min:0',
        ]);

        if ($transaction->status !== 'pending') {
            return back()->with('error', 'Ajustement impossible hors statut "en attente".');
        }

        $transaction->update(['points_proposed' => $data['points_proposed']]);

        MessageSysteme::ecrire($transaction, 'temps_ajuste', ['points' => (int) $data['points_proposed']]);

        return $this->redirectToMessage($transaction)->with('success', 'Points ajustés.');
    }

    public function orgAdjust(Request $request, string $org, Transaction $transaction): RedirectResponse
    {
        return $this->adjust($request, $transaction);
    }

    public function cancel(Transaction $transaction): RedirectResponse
    {
        $this->authorize('cancel', $transaction);

        $transaction->update(['status' => 'cancelled']);

        MessageSysteme::ecrire($transaction, 'echange_annule');

        // TASK-1693 bug W — ne rouvrir que si AUCUNE autre proposition ne
        // tient la demande. Sans cette condition, relacher une concurrente
        // rouvrirait une demande deja pourvue — sans danger tant qu'une seule
        // proposition pouvait exister, plus du tout aujourd'hui.
        app(ServiceRequestLifecycle::class)->releaseIfNothingHolds($transaction);

        return $this->redirectToMessage($transaction)->with('success', 'Transaction annulée.');
    }

    public function orgCancel(string $org, Transaction $transaction): RedirectResponse
    {
        return $this->cancel($transaction);
    }

    public function complete(Transaction $transaction): RedirectResponse
    {
        $this->authorize('complete', $transaction);

        $transaction->update([
            'status' => 'buyer_done',
            'buyer_confirmed_at' => now(),
        ]);

        MessageSysteme::ecrire($transaction, 'aide_recue', ['prenom' => $transaction->buyer?->first_name ?: $transaction->buyer?->name]);

        rescue(
            fn () => $transaction->seller->notify(new TransactionStatusChanged($transaction->fresh())),
            fn (\Throwable $e) => Log::error('Email notification failed after successful business action', [
                'event' => 'transaction_completed_by_buyer',
                'transaction_id' => $transaction->id,
                'organization_id' => $transaction->organization_id,
                'notifiable_id' => $transaction->seller->id,
                'exception_class' => $e::class,
            ]),
            false,
        );

        return $this->redirectToMessage($transaction)->with('success', 'Prestation déclarée terminée.');
    }

    public function orgComplete(string $org, Transaction $transaction): RedirectResponse
    {
        return $this->complete($transaction);
    }

    public function confirm(Transaction $transaction): RedirectResponse
    {
        $this->authorize('confirm', $transaction);

        DB::transaction(function () use ($transaction) {
            $tx = Transaction::where('id', $transaction->id)->lockForUpdate()->first();

            if ($tx->status !== 'buyer_done') {
                return;
            }

            $points = (int) $tx->points_agreed;

            // TASK-1754 §9.3 — un coup de main OFFERT ne touche NI le ledger NI
            // les soldes.
            //
            // Deux lignes a zero seraient du bruit comptable : elles
            // alourdiraient l'historique de points de chaque membre sans rien
            // dire, et le ledger est la piece justificative des soldes — y
            // ecrire des mouvements nuls le rend plus difficile a auditer, pas
            // plus complet.
            //
            // Le reste de la choregraphie est INCHANGE : l'echange passe bien a
            // `completed`, la demande se clot, le remerciement est propose. Un
            // don traverse donc tout le cycle, il ne transfere simplement rien.
            if ($points > 0) {
                PointLedger::create([
                    'user_id' => $tx->buyer_id,
                    'transaction_id' => $tx->id,
                    'delta' => -$points,
                    'organization_id' => $tx->organization_id,
                    'reason' => 'exchange_spent',
                ]);

                PointLedger::create([
                    'user_id' => $tx->seller_id,
                    'transaction_id' => $tx->id,
                    'delta' => $points,
                    'organization_id' => $tx->organization_id,
                    'reason' => 'exchange_earned',
                ]);

                $tx->buyer()->update(['points_balance' => DB::raw('points_balance - '.$points)]);
                $tx->seller()->update(['points_balance' => DB::raw('points_balance + '.$points)]);
            }

            $tx->update([
                'status' => 'completed',
                'seller_confirmed_at' => now(),
                'completed_at' => now(),
            ]);

            app(ServiceRequestLifecycle::class)->close($tx);
        });

        $fresh = $transaction->fresh();

        if ($fresh->status !== 'completed') {
            return $this->redirectToMessage($transaction)
                ->with('error', 'Cette transaction a déjà été traitée.');
        }

        MessageSysteme::ecrire($transaction, 'echange_termine', ['points' => (int) ($transaction->points_agreed ?? $transaction->points_proposed)]);

        rescue(
            fn () => $fresh->buyer->notify(new TransactionStatusChanged($fresh)),
            fn (\Throwable $e) => Log::error('Email notification failed after successful business action', [
                'event' => 'transaction_confirmed_buyer_notification',
                'transaction_id' => $fresh->id,
                'organization_id' => $fresh->organization_id,
                'notifiable_id' => $fresh->buyer->id,
                'exception_class' => $e::class,
            ]),
            false,
        );
        rescue(
            fn () => $fresh->seller->notify(new TransactionStatusChanged($fresh)),
            fn (\Throwable $e) => Log::error('Email notification failed after successful business action', [
                'event' => 'transaction_confirmed_seller_notification',
                'transaction_id' => $fresh->id,
                'organization_id' => $fresh->organization_id,
                'notifiable_id' => $fresh->seller->id,
                'exception_class' => $e::class,
            ]),
            false,
        );

        return $this->redirectToMessage($transaction)->with('success', 'Échange complété avec succès !');
    }

    public function orgConfirm(string $org, Transaction $transaction): RedirectResponse
    {
        return $this->confirm($transaction);
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
            return $this->redirectToMessage($transaction)
                ->with('error', 'Impossible de contester cette transaction.');
        }

        MessageSysteme::ecrire($transaction, 'pas_encore_termine');

        return $this->redirectToMessage($transaction)->with('success', 'Prestation contestée, échange relancé.');
    }

    public function orgContest(string $org, Transaction $transaction): RedirectResponse
    {
        return $this->contest($transaction);
    }

    public function exportCsv(): StreamedResponse
    {
        $user = auth()->user();

        $transactions = Transaction::where('buyer_id', $user->id)
            ->orWhere('seller_id', $user->id)
            ->with(['service', 'serviceRequest', 'buyer', 'seller'])
            ->orderByDesc('created_at')
            ->get()
            // TASK-1754 (§5.4 du CDC, critere d'acceptation n°7) — les
            // DISCUSSIONS directes sortent de l'export.
            //
            // « Ecrire a quelqu'un » cree une Transaction a 0 point
            // (`MessageController::showWithUser()`). Ces pseudo-echanges
            // sortaient dans le CSV en « Achat, 0 pts, En attente » —
            // eternellement, puisque rien ne les termine jamais. Un membre
            // lisait donc son historique d'entraide pollue par ses simples
            // conversations.
            //
            // Le filtre est pose ICI et non dans la requete : `isDirectConversation()`
            // est la definition CANONIQUE (ni service, ni demande, ET 0 point),
            // et la re-deriver en SQL garantirait la divergence le jour ou elle
            // change.
            ->reject(fn (Transaction $t) => $t->isDirectConversation());

        $filename = __('entraide.csv.fichier').'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($transactions, $user) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
            fputcsv($handle, (array) __('entraide.csv.entetes'), ';');

            foreach ($transactions as $transaction) {
                // `buyer` = celui qui RECOIT l'aide, `seller` = celui qui
                // l'APPORTE. Les colonnes gardent leurs noms de marketplace en
                // base ; l'export, lui, parle d'entraide.
                $recoitLAide = $transaction->buyer_id === $user->id;
                $avec = $recoitLAide ? $transaction->seller->name : $transaction->buyer->name;
                $points = $transaction->points_agreed ?? $transaction->points_proposed;

                fputcsv($handle, [
                    $transaction->created_at->format('d/m/Y'),
                    $recoitLAide ? __('entraide.csv.aide_recue') : __('entraide.csv.aide_apportee'),
                    $transaction->subject,
                    $avec,
                    TempsPartage::duree($points),
                    $points,
                    $transaction->status_label,
                ], ';');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function redirectToMessage(Transaction $transaction): RedirectResponse
    {
        $organization = request()->route('organization');

        if ($organization) {
            return redirect()->route('organization.messages.show', ['organization' => $organization, 'transaction' => $transaction]);
        }

        return redirect()->route('messages.show', $transaction);
    }
}
