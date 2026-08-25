<?php

namespace App\Livewire;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Reaction;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\LoopMessageService;
use App\Services\Loops\LoopLifecycleService;
use App\Services\UrlPreviewService;
use App\Support\Loops\LoopPermissionResolver;
use App\Support\Loops\SlashIa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Laravel\Facades\Image;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class LoopChat extends Component
{
    use WithFileUploads;

    public Loop $loop;

    public string $body = '';

    public bool $isMember = false;

    public ?string $replyToMessageId = null;

    public ?array $replyingTo = null;

    public $photo = null;

    public int $messagePageSize = 30;

    public array $loadedMessageIds = [];

    public bool $hasOlderMessages = false;

    public ?string $editingMessageId = null;

    public string $editingBody = '';

    public function mount(Loop $loop): void
    {
        $this->loop = $loop;
        $this->refreshMembership();
        $this->loadInitialMessages();
    }

    /**
     * Recalculer l'adhesion **a chaque requete**, y compris les mises a jour
     * Livewire.
     *
     * `$isMember` est une propriete publique : elle voyage dans le snapshot, et
     * Livewire la restitue telle qu'elle etait au chargement de la page. Une
     * personne retiree de la Boucle gardait donc `true` et continuait a lire —
     * y compris des messages postes **apres** son depart — en rejouant son
     * dernier snapshot. Le snapshot est une capacite durable : il n'a ni nonce,
     * ni expiration.
     *
     * `booted()` s'execute a l'hydratation comme au montage : l'adhesion est
     * desormais une conclusion tiree a chaque fois, pas un fait recopie.
     */
    public function booted(): void
    {
        $this->refreshMembership();
    }

    private function refreshMembership(): void
    {
        $user = auth()->user();

        $this->isMember = $user
            && ! $user->isDeactivated()
            && LoopMember::where('loop_id', $this->loop->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();
    }

    public function aiRoute(): string
    {
        // L'Organization vient de la **Boucle**, pas de `request()`. Sur une
        // mise a jour Livewire, la requete est le `POST /livewire/update` et ne
        // porte aucun parametre `organization` : la route retombait alors sur
        // sa forme sans prefixe, et changeait donc entre le chargement de la
        // page et le premier clic.
        $organization = request()->route('organization') ?? $this->loop->organization?->slug;

        // `routeIs('organization.*')` ne tient pas non plus sur un POST
        // Livewire — la route courante y est celle de Livewire. C'est
        // l'existence d'une Organization pour cette Boucle qui decide, et elle
        // ne change pas d'une requete a l'autre.
        if ($organization && Route::has('organization.loops.ai')) {
            return route('organization.loops.ai', [
                'organization' => $organization,
                'loop' => $this->loop,
            ]);
        }

        return route('loops.ai', $this->loop);
    }

    public function replyTo(string $messageId): void
    {
        $message = LoopMessage::where('id', $messageId)
            ->where('loop_id', $this->loop->id)
            ->with('sender')
            ->first();

        if (! $message) {
            return;
        }

        $this->replyToMessageId = $message->id;
        $this->replyingTo = [
            'body' => $message->isDeleted() ? __('messages.deleted_message_placeholder') : mb_substr($message->body, 0, 120),
            'sender_name' => $message->sender?->publicDisplayName() ?? 'BouclePro',
        ];
    }

    public function cancelReply(): void
    {
        $this->replyToMessageId = null;
        $this->replyingTo = null;
    }

    public function updatedPhoto(): void
    {
        if (! $this->photo) {
            return;
        }
        $validator = validator(['photo' => $this->photo], ['photo' => 'image|max:10240']);
        if ($validator->fails()) {
            $this->photo = null;
            $this->addError('photo', __('messages.invalid_file'));
        }
    }

    /**
     * Whether this person may add to the conversation right now.
     *
     * Was six copies of the same three conditions. The fourth — an archived Loop
     * is read-only — would have had to be added to all six, and the next
     * contributor would have had five chances to forget it.
     *
     * Reading is unaffected: `$isMember` still gates what the panel shows, so an
     * archived Loop keeps its history visible to the people who could see it.
     */
    private function canContribute(?User $user): bool
    {
        return $user !== null
            && ! $user->isDeactivated()
            && $this->isMember
            && app(LoopLifecycleService::class)->isWritable($this->loop);
    }

    public function sendMessage(LoopMessageService $service): void
    {
        $this->validate([
            'body' => 'required_without:photo|string|max:5000',
            'photo' => 'nullable|image|max:10240',
        ], [
            'body.required_without' => __('messages.body_or_image_required'),
        ]);

        $user = auth()->user();
        if (! $this->canContribute($user)) {
            return;
        }

        // TASK-1299 : `/ia` en tete du corps invoque l'IA documentaire de
        // CETTE Boucle. Une Boucle agent est exclue : son agent (T-2) repond
        // deja a chaque message — une seconde IA serait une seconde depense.
        $slashIaQuestion = $this->loop->isAiAgent() ? null : SlashIa::question($this->body);

        if ($slashIaQuestion === '') {
            // `/ia` vide : aide locale deterministe pour l'auteur seul. Rien
            // n'est persiste, la saisie reste dans le composeur, aucun
            // provider n'est appele, aucune ligne de ledger n'est ecrite.
            $this->addError('body', __('loops.slash_ia_help'));

            return;
        }

        try {
            $imagePath = null;

            if ($this->photo) {
                $imagePath = $this->storeImage($this->photo, 'loop-messages');
                $this->photo = null;
            }

            $url = UrlPreviewService::extractFirstUrl($this->body);
            $preview = $url ? app(UrlPreviewService::class)->fetchPreview($url) : null;

            $metadata = $preview !== null ? ['url_preview' => $preview] : null;

            if ($slashIaQuestion !== null) {
                // Le corps est persiste TEL QUE TAPE, prefixe compris — la
                // provenance de l'invocation vit ici, en metadata.
                $metadata = ($metadata ?? []) + ['slash_ia' => true];
            }

            // TASK-1300 : `/ia` explicite d'abord — un corps `/ia ...` en
            // reponse a un message IA reste une invocation /ia (une seule),
            // le contexte de fil etant construit par le service depuis le
            // lien de reponse (arbitrage Cyril 24/08, test dedie).
            $continuationParent = $slashIaQuestion === null ? $this->continuationParent() : null;

            if ($continuationParent !== null) {
                $metadata = ($metadata ?? []) + ['ai_continuation' => true];
            }

            $message = $service->sendUserMessage($this->loop, $user, $this->body, $metadata, $this->replyToMessageId, $imagePath);
            $this->body = '';
            $this->cancelReply();
        } catch (\RuntimeException) {
            $this->addError('body', 'Impossible d\'envoyer le message.');

            return;
        }

        if ($slashIaQuestion !== null) {
            $this->answerSlashIa($message, $slashIaQuestion, $user);
        } elseif ($continuationParent !== null && $message->reply_to_id === $continuationParent->id) {
            // TASK-1300 : continuation — le membre a REPONDU au message IA,
            // son corps est la question. Meme chaine knowledge, meme
            // declencheur deja persiste (le piege de la double persistance
            // reste ferme par T-3), meme conservation du message humain en
            // cas de refus ou d'echec.
            $this->answerSlashIa($message, trim($message->body), $user);
        }

        $this->syncNewerMessages();
        $this->dispatch('message-sent');
    }

    /**
     * TASK-1300 : le parent d'une CONTINUATION — le message IA (type `ai`
     * strictement : jamais `member_agent`), non supprime, de CETTE Boucle,
     * que le membre vise avec « Repondre ». Tout autre cas est un reply
     * ordinaire : Boucle agent (l'agent T-2 repond deja a tout message —
     * deux IA seraient deux depenses), reply a un humain, parent efface
     * (conservateur : son contenu a quitte le fil), corps vide (photo
     * seule : pas de question a poser). Un reply_to_id d'une AUTRE Boucle
     * ne declenche rien : cette requete est bornee a la Boucle courante,
     * `sendUserMessage()` annule le lien de son cote, et la branche
     * appelante re-verifie le lien PERSISTE avant d'invoquer.
     */
    private function continuationParent(): ?LoopMessage
    {
        if ($this->loop->isAiAgent() || $this->replyToMessageId === null || trim($this->body) === '') {
            return null;
        }

        return LoopMessage::query()
            ->where('id', $this->replyToMessageId)
            ->where('loop_id', $this->loop->id)
            ->where('type', 'ai')
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Repondre au message `/ia` DEJA persiste — jamais l'inverse.
     *
     * La chaine est celle de « Consulter les Dossiers » (T-1, TASK-1297) :
     * RAG Loop-scoped (TASK-1294), garde economique existante, sources
     * publiques filtrees par `KnowledgeAnswer::publicSource()`. Le message
     * humain etant deja dans le fil, tout echec ici — refus economique,
     * panne provider, aucune source — le CONSERVE et ne publie aucune
     * fausse reponse : l'auteur seul est prevenu, dans le composeur.
     */
    private function answerSlashIa(LoopMessage $message, string $question, User $user): void
    {
        try {
            $answer = app(LoopKnowledgeAnswerService::class)
                ->answer($this->loop, $user, $question, inThreadTrigger: $message);

            if ($answer->interactionId === null) {
                // Zero source pertinente : rien n'a coute, rien n'est publie
                // (principe T-1) — mais l'auteur doit savoir pourquoi le fil
                // reste muet.
                $this->addError('body', __('loops.knowledge_no_sources'));
            }
        } catch (\RuntimeException $exception) {
            // AiRefusedException comprise : son message est le message
            // produit (credit epuise, budget atteint, IA non configuree).
            $this->addError('body', $exception->getMessage());
        }
    }

    public function removePhoto(): void
    {
        $this->photo = null;
    }

    public function pinnedMessage(): ?LoopMessage
    {
        return $this->loop->messages()
            ->pinned()
            ->notDeleted()
            ->with('sender')
            ->first();
    }

    public function pinMessage(string $messageId): void
    {
        $user = auth()->user();
        if (! $this->canContribute($user)) {
            return;
        }

        $message = LoopMessage::where('id', $messageId)
            ->where('loop_id', $this->loop->id)
            ->first();

        if (! $message) {
            return;
        }

        if ($message->isDeleted()) {
            return;
        }

        LoopMessage::where('loop_id', $this->loop->id)
            ->whereNotNull('pinned_at')
            ->update(['pinned_at' => null, 'pinned_by_id' => null]);

        $message->pin($user);
    }

    public function loadOlderMessages(): void
    {
        if ($this->loadedMessageIds === []) {
            $this->loadInitialMessages();
            $this->dispatch('older-messages-loaded');

            return;
        }

        $oldest = $this->oldestLoadedMessage();

        if (! $oldest) {
            $this->hasOlderMessages = false;
            $this->dispatch('older-messages-loaded');

            return;
        }

        $olderIds = $this->olderThan($oldest)
            ->limit($this->messagePageSize)
            ->pluck('id')
            ->reverse()
            ->values()
            ->all();

        $this->loadedMessageIds = array_values(array_unique(array_merge($olderIds, $this->loadedMessageIds)));
        $this->sortLoadedMessageIds();
        $this->updateHasOlderMessages();
        $this->dispatch('older-messages-loaded');
    }

    public function showMessageInThread(string $messageId): void
    {
        $message = LoopMessage::where('id', $messageId)
            ->where('loop_id', $this->loop->id)
            ->where('organization_id', $this->loop->organization_id)
            ->first();

        if (! $message) {
            return;
        }

        if (! in_array($message->id, $this->loadedMessageIds, true)) {
            $nearbyIds = $this->messagesAround($message)
                ->pluck('id')
                ->all();

            $this->loadedMessageIds = array_values(array_unique(array_merge($this->loadedMessageIds, $nearbyIds, [$message->id])));
            $this->sortLoadedMessageIds();
            $this->updateHasOlderMessages();
        }

        $this->dispatch('scroll-to-message', messageId: $message->id);
    }

    public function editMessage(string $messageId): void
    {
        $user = auth()->user();
        if (! $this->canContribute($user)) {
            return;
        }

        $message = LoopMessage::where('id', $messageId)
            ->where('loop_id', $this->loop->id)
            ->where('organization_id', $this->loop->organization_id)
            ->first();

        if (! $message || ! $message->isEditableBy($user)) {
            return;
        }

        $this->editingMessageId = $message->id;
        $this->editingBody = $message->body;
    }

    public function cancelEdit(): void
    {
        $this->editingMessageId = null;
        $this->editingBody = '';
    }

    public function saveEdit(): void
    {
        $this->validate([
            'editingBody' => 'required|string|max:5000',
        ]);

        $user = auth()->user();
        if (! $this->canContribute($user) || $this->editingMessageId === null) {
            return;
        }

        $message = LoopMessage::where('id', $this->editingMessageId)
            ->where('loop_id', $this->loop->id)
            ->where('organization_id', $this->loop->organization_id)
            ->first();

        if (! $message) {
            $this->cancelEdit();

            return;
        }

        try {
            $url = UrlPreviewService::extractFirstUrl($this->editingBody);
            $preview = $url ? app(UrlPreviewService::class)->fetchPreview($url) : null;
            $metadata = $preview !== null ? ['url_preview' => $preview] : null;

            app(LoopMessageService::class)->updateUserMessage($this->loop, $message, $user, $this->editingBody, $metadata);
            $this->cancelEdit();
        } catch (\RuntimeException) {
            $this->addError('editingBody', __('messages.edit_failed'));
        }
    }

    public function deleteMessage(string $messageId): void
    {
        $user = auth()->user();
        if (! $user || $user->isDeactivated()) {
            return;
        }

        $message = LoopMessage::where('id', $messageId)
            ->where('loop_id', $this->loop->id)
            ->where('organization_id', $this->loop->organization_id)
            ->first();

        if (! $message) {
            return;
        }

        try {
            app(LoopMessageService::class)->deleteMessage($this->loop, $message, $user);

            if ($this->editingMessageId === $message->id) {
                $this->cancelEdit();
            }
        } catch (\RuntimeException) {
            return;
        }
    }

    public function unpinMessage(): void
    {
        $user = auth()->user();
        if (! $this->canContribute($user)) {
            return;
        }

        LoopMessage::where('loop_id', $this->loop->id)
            ->whereNotNull('pinned_at')
            ->update(['pinned_at' => null, 'pinned_by_id' => null]);
    }

    public function toggleReaction(string $messageId, string $reactionType): void
    {
        $user = auth()->user();
        if (! $this->canContribute($user)) {
            return;
        }

        if (! in_array($reactionType, Reaction::REACTION_TYPES, true)) {
            return;
        }

        $message = LoopMessage::where('id', $messageId)
            ->where('loop_id', $this->loop->id)
            ->first();

        if (! $message) {
            return;
        }

        if ($message->isDeleted()) {
            return;
        }

        $existing = Reaction::where('user_id', $user->id)
            ->where('reactionable_id', $message->id)
            ->where('reactionable_type', LoopMessage::class)
            ->first();

        if ($existing) {
            if ($existing->reaction_type === $reactionType) {
                $existing->delete();
            } else {
                $existing->update(['reaction_type' => $reactionType]);
            }
        } else {
            Reaction::create([
                'organization_id' => $message->organization_id,
                'user_id' => $user->id,
                'reactionable_id' => $message->id,
                'reactionable_type' => LoopMessage::class,
                'reaction_type' => $reactionType,
            ]);
        }
    }

    private function storeImage($file, string $subdirectory): string
    {
        $img = Image::decode($file);
        $img->scaleDown(1200, 800);

        $filename = Str::uuid()->toString().'.webp';
        $relativePath = 'message-images/'.$this->loop->organization_id.'/'.$subdirectory.'/'.$filename;

        Storage::disk('public')->put($relativePath, (string) $img->encode(new WebpEncoder(quality: 80)));

        return $relativePath;
    }

    /**
     * Une Card voisine vient de publier une activite dans ce fil.
     *
     * La methode ne fait **rien** : le seul fait d'avoir ete appelee provoque un
     * nouveau rendu, et `render()` appelle deja `syncNewerMessages()`, qui
     * ramene ce qui est plus recent et dedoublonne par identifiant. Y ajouter
     * une requete reviendrait a ecrire deux fois la meme regle.
     *
     * ChatLoop rattrapait deja ces messages au battement suivant de son
     * `wire:poll.3s`. Ce que l'evenement supprime, c'est l'attente — pas une
     * absence. Le sondage periodique reste : il sert les messages des autres
     * personnes, qu'aucune Card locale ne peut annoncer.
     *
     * L'identifiant de Boucle est verifie : plusieurs Boucles peuvent vivre
     * dans un meme onglet, et un fil ne se rafraichit que pour la sienne.
     */
    #[On('loop-activity-published')]
    public function onLoopActivityPublished(?string $loopId = null): void
    {
        if ($loopId !== null && $loopId !== $this->loop->id) {
            $this->skipRender();
        }
    }

    public function render()
    {
        // **Sans adhesion, rien ne se lit.** `$loadedMessageIds` voyage dans le
        // snapshot : une personne retiree de la Boucle gardait sa liste, et
        // `syncNewerMessages()` y ajoutait consciencieusement les messages
        // postes apres son depart. Recalculer l'adhesion ne suffisait pas — il
        // fallait que la lecture en depende.
        if (! $this->isMember) {
            $this->loadedMessageIds = [];
        }

        $this->syncNewerMessages();

        $messages = $this->loop->messages()
            ->with('sender')
            ->with('replyTo.sender')
            ->with('reactions')
            ->whereIn('id', $this->loadedMessageIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $pinnedMessage = $this->pinnedMessage();

        $reactionData = [];
        $myReactions = [];
        $userId = auth()->id();

        foreach ($messages as $msg) {
            $counts = [];
            $myReaction = null;

            foreach ($msg->reactions as $reaction) {
                $type = $reaction->reaction_type;
                $counts[$type] = ($counts[$type] ?? 0) + 1;
                if ($reaction->user_id === $userId) {
                    $myReaction = $type;
                }
            }

            $reactionData[$msg->id] = $counts;
            $myReactions[$msg->id] = $myReaction;
        }

        $requestedByNames = $this->requestedByNames($messages);
        $projectedRequests = $this->projectedRequests($messages);
        $projectedRequestUrls = $this->projectedRequestUrls($projectedRequests);
        $aiRoute = $this->aiRoute();
        $canDeleteMessages = $this->canDeleteDisplayedMessages();
        // La vue retire le compositeur plutot que d'accepter un message que
        // sendMessage() refusera : un refus silencieux passe pour une panne.
        $canContribute = $this->canContribute(auth()->user());

        return view('livewire.loop-chat', compact(
            'messages',
            'pinnedMessage',
            'reactionData',
            'myReactions',
            'requestedByNames',
            'projectedRequests',
            'projectedRequestUrls',
            'aiRoute',
            'canDeleteMessages',
            'canContribute',
        ));
    }

    /**
     * Charge toutes les demandes projetees en une requete, explicitement dans
     * le tenant de la Boucle. Une metadata malformee n'atteint jamais la
     * colonne UUID PostgreSQL et aucune requete ne part depuis Blade.
     *
     * @return Collection<string, ServiceRequest>
     */
    private function projectedRequests(Collection $messages): Collection
    {
        $ids = $messages
            ->filter(fn (LoopMessage $message) => $message->isServiceRequestProjection())
            ->map(fn (LoopMessage $message) => $message->metadata['service_request_id'])
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->where('organization_id', $this->loop->organization_id)
            ->whereIn('id', $ids)
            ->with('user')
            ->get()
            ->reject(fn (ServiceRequest $request) => $request->user?->isDeactivated() ?? true)
            ->keyBy('id');
    }

    /** @param Collection<string, ServiceRequest> $requests */
    private function projectedRequestUrls(Collection $requests): array
    {
        $slug = $this->loop->organization?->slug;

        return $requests->mapWithKeys(function (ServiceRequest $request) use ($slug): array {
            $url = $slug && Route::has('organization.requests.show')
                ? route('organization.requests.show', ['organization' => $slug, 'request' => $request])
                : route('requests.show', $request);

            return [$request->id => $url];
        })->all();
    }

    private function loadInitialMessages(): void
    {
        if (! $this->isMember) {
            $this->loadedMessageIds = [];

            return;
        }

        $this->loadedMessageIds = $this->loop->messages()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->messagePageSize)
            ->pluck('id')
            ->reverse()
            ->values()
            ->all();

        $this->updateHasOlderMessages();
    }

    private function syncNewerMessages(): void
    {
        if (! $this->isMember) {
            return;
        }

        if ($this->loadedMessageIds === []) {
            $this->loadInitialMessages();

            return;
        }

        $newest = $this->newestLoadedMessage();

        if (! $newest) {
            $this->loadInitialMessages();

            return;
        }

        $newerIds = $this->newerThan($newest)
            ->pluck('id')
            ->all();

        if ($newerIds !== []) {
            $this->loadedMessageIds = array_values(array_unique(array_merge($this->loadedMessageIds, $newerIds)));
            $this->sortLoadedMessageIds();
        }
    }

    private function oldestLoadedMessage(): ?LoopMessage
    {
        return $this->loadedCursorMessage($this->loadedMessageIds[0] ?? null);
    }

    private function newestLoadedMessage(): ?LoopMessage
    {
        return $this->loadedCursorMessage($this->loadedMessageIds[array_key_last($this->loadedMessageIds)] ?? null);
    }

    private function loadedCursorMessage(?string $messageId): ?LoopMessage
    {
        if ($messageId === null) {
            return null;
        }

        return LoopMessage::where('id', $messageId)
            ->where('loop_id', $this->loop->id)
            ->where('organization_id', $this->loop->organization_id)
            ->first();
    }

    private function olderThan(LoopMessage $message)
    {
        return $this->loop->messages()
            ->where(function ($query) use ($message) {
                $query->where('created_at', '<', $message->created_at)
                    ->orWhere(function ($query) use ($message) {
                        $query->where('created_at', $message->created_at)
                            ->where('id', '<', $message->id);
                    });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private function newerThan(LoopMessage $message)
    {
        return $this->loop->messages()
            ->where(function ($query) use ($message) {
                $query->where('created_at', '>', $message->created_at)
                    ->orWhere(function ($query) use ($message) {
                        $query->where('created_at', $message->created_at)
                            ->where('id', '>', $message->id);
                    });
            })
            ->orderBy('created_at')
            ->orderBy('id');
    }

    private function messagesAround(LoopMessage $message): Collection
    {
        $older = $this->olderThan($message)
            ->limit(15)
            ->get();

        $newer = $this->newerThan($message)
            ->limit(15)
            ->get();

        return $older
            ->push($message)
            ->merge($newer)
            ->sortBy([['created_at', 'asc'], ['id', 'asc']])
            ->values();
    }

    private function updateHasOlderMessages(): void
    {
        $oldest = $this->oldestLoadedMessage();
        $this->hasOlderMessages = $oldest !== null && $this->olderThan($oldest)->exists();
    }

    private function sortLoadedMessageIds(): void
    {
        $this->loadedMessageIds = LoopMessage::whereIn('id', $this->loadedMessageIds)
            ->where('loop_id', $this->loop->id)
            ->where('organization_id', $this->loop->organization_id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    private function canDeleteDisplayedMessages(): bool
    {
        $user = auth()->user();

        if (! $user || $user->isDeactivated()) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        if ($this->loop->organization_id !== $user->organization_id) {
            return false;
        }

        $role = LoopMember::where('loop_id', $this->loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('role');

        // Resolved centrally (CP5ter).
        $user = auth()->user();

        return $user !== null
            && app(LoopPermissionResolver::class)->can($user, $this->loop, 'chatloop.manage');
    }

    private function requestedByNames(Collection $messages): array
    {
        $requesterIds = $messages
            ->where('type', 'ai')
            ->map(fn (LoopMessage $message) => $message->metadata['requested_by'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($requesterIds->isEmpty()) {
            return [];
        }

        $names = User::query()
            ->whereIn('id', $requesterIds)
            ->get()
            ->keyBy('id')
            ->map(fn (User $user) => $user->publicDisplayName());

        $map = [];

        foreach ($messages as $message) {
            if ($message->type !== 'ai') {
                continue;
            }

            $requesterId = $message->metadata['requested_by'] ?? null;

            if ($requesterId !== null && $names->has($requesterId)) {
                $map[$message->id] = (string) $names->get($requesterId);
            }
        }

        return $map;
    }
}
