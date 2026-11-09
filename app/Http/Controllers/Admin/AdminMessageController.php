<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoopMessage;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Transaction;
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
    /**
     * TASK-1795 correctif 4 — charger la conversation SUR UN ECRAN SANS TENANT.
     *
     * `Transaction` porte `BelongsToOrganizationScope`, qui est FAIL-CLOSED :
     * sans `current_organization` liee il pose `whereRaw('0 = 1')`. Or
     * `/admin/*` est une surface « plateforme globale »
     * (`ResolveUrlOrganization::$platformGlobalPrefixes`) et n'en lie AUCUNE.
     *
     * Consequence mesuree le 09/11/2026 sur le jumeau : `$message->transaction`
     * valait `null` pour TOUTES les lignes de cet ecran. La colonne
     * « Contexte » — censee montrer « acheteur ↔ vendeur » — rendait donc un
     * tiret partout, en silence, depuis toujours. Avec le scope retire, la
     * meme requete rend bien la transaction et son acheteur.
     *
     * Retirer le scope ICI n'ouvre rien : la restriction tenant de cet ecran
     * porte sur `messages.organization_id`, posee par
     * `applyOrganizationFilter()` et jamais retiree. On ne choisit pas ici
     * quelles lignes l'administrateur voit — on charge la conversation des
     * lignes deja retenues.
     */
    /**
     * TASK-1795 correctif 7 — `banned_at` et `is_admin` sont AU SELECT.
     *
     * Le lien « Se connecter sous » n'est pose que la ou `loginAsUser()`
     * l'honorera : ni soi-meme, ni un compte banni, ni un autre administrateur.
     * Ces deux colonnes portent la garde — omises de la selection, elles
     * vaudraient `null` et la garde laisserait passer. C'est exactement le
     * defaut du correctif 4, ou `first_name` absent du select faisait rendre
     * le seul patronyme : une selection de colonnes ne se devine pas depuis
     * la vue qui la consomme.
     */
    private function chargementDeLaConversation(): array
    {
        return [
            'transaction' => fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class),
            'transaction.buyer:id,first_name,name,email,banned_at,is_admin',
            'transaction.seller:id,first_name,name,email,banned_at,is_admin',
        ];
    }

    public function index(Request $request): View
    {
        // TASK-1795 correctif 3 — la liste s'ouvre sur TOUT, pas sur un coin.
        //
        // Cyril : « Actuellement c'est sur `main`. Donc il faut passer par le
        // menu, et c'est perte de temps. » Les deux defauts se cumulaient : le
        // type `chatloop` ET l'Organization par defaut. Un SuperAdmin qui
        // cherche un message devait donc deviner DEUX filtres avant de le voir,
        // et une liste vide ne disait pas qu'elle etait filtree.
        //
        // `all` n'ouvre aucune donnee nouvelle : `applyOrganizationFilter()` ne
        // fait que NE PAS restreindre, et `/admin/messages` est deja derriere
        // les gardes SuperAdmin. Le detail de chaque ligne garde ses propres
        // permissions — `show()` rend toujours 404 hors de l'Organization de
        // l'administrateur, et la vue n'offre un lien que sur les lignes dont
        // le detail est joignable (`$adminOrganizationId`, TASK-1693 bug R).
        $filter = $request->input('filter', 'all');
        $allowedFilters = ['chatloop', 'exchanges', 'private', 'all'];
        $filter = in_array($filter, $allowedFilters) ? $filter : 'all';
        $organizations = $this->adminOrganizations();
        $selectedOrganizationId = $this->selectedAdminOrganizationId($request, 'all');
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
            // TASK-1795 correctif 4 — l'onglet impose est celui qui CONTIENT
            // la conversation, pas `exchanges` en dur.
            //
            // La garantie de TASK-1666 est « un lien ne doit pas tomber sur un
            // onglet vide ». Avec un quatrieme onglet, `exchanges` ne contient
            // plus les discussions privees : forcer `exchanges` sur une
            // conversation directe aurait recree EXACTEMENT le faux « il n'y a
            // rien » que T1666 avait ferme. On demande donc a la transaction
            // elle-meme de quelle nature elle est.
            $filter = $this->ongletDeLaConversation($transactionId);
        }

        $messages = match ($filter) {
            'chatloop' => $this->applyOrganizationFilter(LoopMessage::query(), $selectedOrganizationId)
                ->with(['sender:id,first_name,name,email,banned_at,is_admin', 'loop:id,name'])
                ->latest()
                ->paginate($perPage)
                ->withQueryString(),

            'exchanges' => $this->applyTransactionFilter(
                $this->natureDeConversation(
                    $this->applyOrganizationFilter(Message::query(), $selectedOrganizationId),
                    'exchanges'
                ),
                $transactionId
            )
                ->with(['sender:id,first_name,name,email,banned_at,is_admin', ...$this->chargementDeLaConversation()])
                ->latest()
                ->paginate($perPage)
                ->withQueryString(),

            'private' => $this->applyTransactionFilter(
                $this->natureDeConversation(
                    $this->applyOrganizationFilter(Message::query(), $selectedOrganizationId),
                    'private'
                ),
                $transactionId
            )
                ->with(['sender:id,first_name,name,email,banned_at,is_admin', ...$this->chargementDeLaConversation()])
                ->latest()
                ->paginate($perPage)
                ->withQueryString(),

            default => $this->unifiedFeed($selectedOrganizationId, $perPage),
        };

        // TASK-1693 bug R — la date devient un lien vers le detail, mais
        // `show()` rend 404 hors de l'Organisation de l'administrateur
        // (garantie ecrite, AdminMessagesTest). Avec le selecteur sur « Toutes
        // les organisations », la liste peut donc contenir des lignes dont le
        // detail serait refuse : la vue a besoin de savoir lesquelles, pour ne
        // PAS offrir un lien mort.
        $adminOrganizationId = $request->user()->organization_id;

        return view('admin.messages.index', compact('filter', 'messages', 'organizations', 'selectedOrganizationId', 'transactionId', 'adminOrganizationId'));
    }

    private function unifiedFeed(string $organizationId, int $perPage): LengthAwarePaginator
    {
        $loopMessages = $this->applyOrganizationFilter(LoopMessage::query(), $organizationId)
            ->with(['sender:id,first_name,name,email,banned_at,is_admin', 'loop:id,name'])
            ->latest()
            ->limit(500)
            ->get()
            ->each->setAttribute('message_type', 'chatloop');

        // TASK-1795 correctif 4 — l'onglet « Tous » nomme les TROIS natures.
        //
        // Il marquait tout message de `messages` comme « Echange ». Depuis que
        // « Messages privés » existe, une discussion privee y portait donc une
        // etiquette que son propre onglet contredit. La nature est lue sur la
        // transaction DEJA chargee — `isDirectConversation()`, le meme
        // predicat que les onglets — et non par une seconde requete.
        $exchangeMessages = $this->applyOrganizationFilter(Message::query(), $organizationId)
            ->with(['sender:id,first_name,name,email,banned_at,is_admin', ...$this->chargementDeLaConversation()])
            ->latest()
            ->limit(500)
            ->get()
            ->each(fn ($message) => $message->setAttribute(
                'message_type',
                $message->transaction?->isDirectConversation() ? 'private' : 'exchange'
            ));

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

    /**
     * TASK-1795 correctif 3 — le defaut est celui de l'APPELANT.
     *
     * Un `organization_id` explicite gouverne toujours, `all` compris : seul le
     * cas « rien dans l'URL » consulte `$defaut`.
     *
     * Ce parametre existe parce que les deux appelants n'ont PAS le meme
     * besoin, et que leur donner le meme defaut serait un defaut :
     *
     *  - `index()` LIT, et demande `'all'` — voir la note qui l'accompagne ;
     *  - `bulkDestroy()` SUPPRIME, et garde l'Organization par defaut. Elargir
     *    son defaut a `'all'` aurait transforme une suppression en masse sans
     *    `organization_id` en suppression a travers TOUTES les Organizations.
     *    Le formulaire poste bien ce champ (vue `admin.messages.index`), donc
     *    le defaut n'y sert jamais en pratique — raison de plus pour ne pas
     *    l'ouvrir : une garde qui ne sert jamais ne coute rien, et celle-la
     *    protegerait le jour ou le champ disparaitrait.
     */
    private function selectedAdminOrganizationId(Request $request, ?string $defaut = null): string
    {
        if ($request->input('organization_id') === 'all') {
            return 'all';
        }

        if ($request->filled('organization_id')) {
            return (string) $request->input('organization_id');
        }

        if ($defaut !== null) {
            return $defaut;
        }

        return (string) (DefaultOrganizationResolver::resolve()?->getKey() ?? 'all');
    }

    /**
     * TASK-1795 correctif 4 — separer les DISCUSSIONS PRIVEES des ECHANGES.
     *
     * ## Pourquoi un quatrieme onglet
     *
     * Les deux vivent dans `messages`, adossees a une ligne `transactions` :
     * l'ecran les melangeait sous « Echanges », ou une discussion privee
     * n'affichait ni service, ni points, ni statut. Cyril : « on va creer une
     * nouvelle vue "Messages privés" ».
     *
     * ## La condition n'est PAS reecrite ici
     *
     * `Transaction::scopeDirectConversation()` la dit deja, et c'est le meme
     * predicat que `isDirectConversation()` — les deux vivent cote a cote dans
     * le modele precisement pour ne pas diverger. L'onglet « Echanges » est son
     * COMPLEMENT, exprime comme la NEGATION du scope et non comme une seconde
     * ecriture de la regle : le jour ou la definition d'une discussion directe
     * bouge, les deux onglets bougent avec elle.
     *
     * `whereNot()` est sur ici : `transactions.points_proposed` est NOT NULL et
     * les deux `IS NULL` rendent toujours un booleen, donc la conjonction n'est
     * jamais NULL et sa negation partitionne reellement les lignes en deux.
     * `messages.transaction_id` est NOT NULL : aucun message n'echappe aux deux
     * onglets.
     */
    private function natureDeConversation($query, string $nature)
    {
        return $query->whereHas('transaction', function ($q) use ($nature) {
            // Le scope tenant est RETIRE de cette sous-requete, et seulement
            // d'elle.
            //
            // `/admin/*` est une surface « plateforme globale »
            // (`ResolveUrlOrganization::$platformGlobalPrefixes`) : aucune
            // Organization courante n'y est liee. Or
            // `BelongsToOrganizationScope` est FAIL-CLOSED — sans contexte
            // tenant il pose `whereRaw('0 = 1')`. Mesure du 09/11/2026 sur le
            // jumeau : `whereHas('transaction', …)` rendait **0** ligne sur 35
            // messages reels, les deux onglets donc vides.
            //
            // Cela n'ouvre rien : la restriction tenant de cet ecran porte sur
            // `messages.organization_id`, posee par `applyOrganizationFilter()`
            // un cran plus haut et jamais retiree. Cette sous-requete ne sert
            // qu'a CLASSER la conversation deja retenue — elle ne choisit pas
            // quelles lignes l'administrateur peut voir.
            $q->withoutGlobalScope(BelongsToOrganizationScope::class);

            if ($nature === 'private') {
                $q->directConversation();

                return;
            }

            $q->whereNot(fn ($complement) => $complement->directConversation());
        });
    }

    /**
     * L'onglet qui CONTIENT reellement cette conversation.
     *
     * Lit la transaction hors du scope tenant : l'ecran SuperAdmin peut etre
     * pose sur « Toutes les organisations », et c'est une lecture d'UNE ligne
     * nommee par l'URL, pas un listing. Introuvable -> `exchanges`, le
     * comportement d'avant : on ne change pas de reponse quand on ne sait pas.
     */
    private function ongletDeLaConversation(string $transactionId): string
    {
        if (! Str::isUuid($transactionId)) {
            return 'exchanges';
        }

        $transaction = Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->whereKey($transactionId)
            ->first();

        if ($transaction === null) {
            return 'exchanges';
        }

        return $transaction->isDirectConversation() ? 'private' : 'exchanges';
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

        return view('admin.messages.show', compact('message', 'before', 'after') + ['isLoop' => false]);
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
            // TASK-1795 correctif 4 — `private` est un onglet comme un autre,
            // et sa barre de suppression en masse s'affiche : sans cette
            // valeur, le formulaire aurait ete refuse par la validation.
            'filter' => 'required|in:chatloop,exchanges,private',
        ]);

        $selectedOrganizationId = $this->selectedAdminOrganizationId($request);

        $query = $data['filter'] === 'chatloop'
            ? $this->applyOrganizationFilter(LoopMessage::query(), $selectedOrganizationId)
            : $this->applyTransactionFilter(
                // La suppression porte sur l'onglet AFFICHE : cocher « tout
                // selectionner » sur « Messages privés » ne doit pas emporter
                // un message d'echange reste hors de la page.
                $this->natureDeConversation(
                    $this->applyOrganizationFilter(Message::query(), $selectedOrganizationId),
                    $data['filter']
                ),
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

    /**
     * TASK-1693 bug R — le detail d'un message de ChatLoop.
     *
     * `show()` juste au-dessus ne connait que les conversations d'ECHANGE. Or
     * l'onglet ChatLoop est celui par DEFAUT de l'ecran : sans ce chemin, le
     * lien demande n'aurait existe que pour une minorite de lignes.
     *
     * Memes bornes que son jumeau, a l'identique — y compris le 404 hors de
     * l'Organisation de l'administrateur, qui est une garantie ecrite et non
     * un detail d'implementation. Le voisinage se lit dans la BOUCLE, la ou
     * celui d'un message d'echange se lit dans la transaction.
     */
    public function showLoopMessage(LoopMessage $loopMessage): View
    {
        $loopMessage->load(['sender', 'loop', 'editedBy']);

        $orgId = auth()->user()->organization_id;

        if (! $orgId || $loopMessage->organization_id !== $orgId) {
            abort(404);
        }

        $before = LoopMessage::where('loop_id', $loopMessage->loop_id)
            ->where('organization_id', $orgId)
            ->where('created_at', '<', $loopMessage->created_at)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->reverse()
            ->values();

        $after = LoopMessage::where('loop_id', $loopMessage->loop_id)
            ->where('organization_id', $orgId)
            ->where('created_at', '>', $loopMessage->created_at)
            ->orderBy('created_at')
            ->limit(5)
            ->get();

        return view('admin.messages.show', [
            'message' => $loopMessage,
            'before' => $before,
            'after' => $after,
            'isLoop' => true,
        ]);
    }

    /**
     * TASK-1693 bug Q — corriger le CORPS d'un message depuis l'administration.
     *
     * ## Ce qui manquait
     *
     * L'ecran de moderation ne savait que SUPPRIMER. Pour une faute de frappe,
     * une donnee personnelle lachee dans un fil ou une phrase a retirer, le
     * seul geste disponible detruisait le message entier — et avec lui la suite
     * de la conversation qui lui repondait.
     *
     * ## Ce qui est modifiable, et ce qui ne l'est pas
     *
     * **Le corps, et lui seul.** Ni l'expediteur, ni la date, ni la Boucle ou
     * l'echange : changer l'auteur d'un propos ne serait pas une moderation,
     * ce serait un faux. Ces champs sont donc affiches en lecture seule sur
     * l'ecran d'edition, pour que l'administrateur voie ce qu'il modifie.
     *
     * ## La trace est la CONDITION de la capacite
     *
     * Chaque edition inscrit `edited_at` et `edited_by`. Le ChatLoop affiche
     * deja « modifie » a partir d'`edited_at` (TASK-1298), et la bulle partagee
     * des conversations d'echange sait le faire aussi : une correction par
     * l'administration est donc VISIBLE par les membres, exactement comme une
     * correction par l'auteur. Un administrateur ne reecrit pas les mots de
     * quelqu'un d'autre en silence.
     *
     * ## Pourquoi pas `LoopMessageService::updateUserMessage()`
     *
     * Ce service exige l'appartenance a la Boucle ET la qualite d'auteur
     * (`isEditableBy()`). L'administrateur n'est ni l'un ni l'autre, et lui
     * ouvrir une derogation dans ce chemin donnerait a un SuperAdmin l'identite
     * d'un auteur. Le geste de moderation est donc un chemin SEPARE, qui porte
     * son propre decideur — c'est precisement ce que `edited_by` enregistre.
     *
     * ## Portee
     *
     * Les memes bornes que la suppression voisine, sur le meme ecran : le
     * groupe `/admin` (middleware `admin`, drapeau PLATEFORME). Modifier est
     * strictement moins destructeur que supprimer, qui est deja offert ici
     * sans borne d'organisation.
     */
    public function edit(Message $message): View
    {
        $message->load(['sender', 'transaction.buyer', 'transaction.seller', 'editedBy']);

        return view('admin.messages.edit', [
            'message' => $message,
            'isLoop' => false,
            'updateRoute' => route('admin.messages.update', $message),
            'showRoute' => $this->lienDetail($message, false),
        ]);
    }

    public function editLoopMessage(LoopMessage $loopMessage): View
    {
        $loopMessage->load(['sender', 'loop', 'editedBy']);

        return view('admin.messages.edit', [
            'message' => $loopMessage,
            'isLoop' => true,
            'updateRoute' => route('admin.loop-messages.update', $loopMessage),
            'showRoute' => $this->lienDetail($loopMessage, true),
        ]);
    }

    public function update(Request $request, Message $message): RedirectResponse
    {
        return $this->appliquerEdition($request, $message, route('admin.messages.edit', $message));
    }

    public function updateLoopMessage(Request $request, LoopMessage $loopMessage): RedirectResponse
    {
        return $this->appliquerEdition($request, $loopMessage, route('admin.loop-messages.edit', $loopMessage));
    }

    /**
     * L'autorite UNIQUE de l'edition, pour les deux familles de messages.
     *
     * Les deux tables n'ont ni les memes colonnes ni les memes relations, mais
     * la REGLE est la meme — et la dupliquer, c'est se condamner a ce qu'une
     * moitie oublie un jour d'inscrire le decideur.
     *
     * `forceFill()` plutot qu'`update()` : `Message` n'a pas d'`updated_at`
     * (`$timestamps = false`), et les deux colonnes de trace ne doivent jamais
     * dependre de ce qu'un `$fillable` laisse passer.
     */
    private function appliquerEdition(Request $request, Message|LoopMessage $message, string $retour): RedirectResponse
    {
        $data = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        // `required` SUFFIT a refuser un corps vide, y compris fait de seuls
        // blancs : le middleware global `TrimStrings` les a deja ramenes a la
        // chaine vide avant la validation. Une garde `if ($body === '')` ici
        // serait inatteignable — contre-epreuve faite, son retrait laissait la
        // suite entierement verte. Ne pas la re-ajouter en croyant combler un
        // manque : pour RETIRER un message, c'est la suppression voisine.
        $body = trim($data['body']);

        // Rien a enregistrer : ne pas inscrire une trace d'edition pour un
        // texte identique. « Modifie » doit vouloir dire quelque chose.
        if ($body === $message->body) {
            return redirect($retour)->with('info', __('admin.message_edit_unchanged'));
        }

        $message->forceFill([
            'body' => $body,
            'edited_at' => now(),
            'edited_by' => $request->user()->id,
        ])->save();

        return redirect($retour)->with('success', __('admin.message_edit_done'));
    }

    /**
     * TASK-1693 bug R — le lien vers le detail, ou `null` s'il serait refuse.
     *
     * Une capacite offerte puis refusee est un cul-de-sac : c'est exactement le
     * defaut corrige au bug K, ou un bouton « Rejoindre » menait a un 403. Le
     * predicat vit donc a UN endroit, et il est le MEME que celui des deux
     * actions `show` — les faire divergere, c'est reinventer le lien mort.
     */
    private function lienDetail(Message|LoopMessage $message, bool $isLoop): ?string
    {
        $orgId = auth()->user()?->organization_id;

        if (! $orgId || $message->organization_id !== $orgId) {
            return null;
        }

        return $isLoop
            ? route('admin.loop-messages.show', $message)
            : route('admin.messages.show', $message);
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
