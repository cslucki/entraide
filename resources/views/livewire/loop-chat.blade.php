<div wire:poll.3s class="flex-1 flex flex-col min-h-0" x-on:reply-to-message.window="$wire.replyTo($event.detail.messageId)">
    <x-conversation.pinned-message-banner
        :pinned-message="$pinnedMessage"
        :can-unpin="$isMember"
    />

    <x-conversation.message-list :has-messages="$messages->isNotEmpty()">
        <x-slot:messages>
            @if($hasOlderMessages)
                <div class="flex justify-center pb-3">
                    <button
                        type="button"
                        x-on:click="$dispatch('loading-older')"
                        wire:click="loadOlderMessages"
                        class="inline-flex items-center rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-medium text-gray-600 shadow-sm transition hover:border-violet-200 hover:text-violet-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:text-violet-300"
                    >
                        {{ __('messages.load_previous_messages') }}
                    </button>
                </div>
            @endif

            @if($messages->isNotEmpty())
                <div class="flex items-center gap-3 px-1 pb-3 pt-1 text-[11px] font-semibold text-gray-400 dark:text-gray-500">
                    <span class="h-px flex-1 bg-gray-200 dark:bg-gray-700"></span>
                    {{ __('loops.today') }}
                    <span class="h-px flex-1 bg-gray-200 dark:bg-gray-700"></span>
                </div>
            @endif

            @forelse($messages as $msg)
                @php
                    // TASK-1308 : identite tenant-generique d'une bulle IA —
                    // jamais « Facilitateur IA », jamais un nom code en dur.
                    // `ai_mode` est le discriminant canonique ('llm'|'rag') ;
                    // les messages IA anterieurs a cette TASK sont derives
                    // depuis leur `action` historique (aucune migration).
                    $orgName = $viewLoop->organization?->name ?? config('app.name', 'BouclePro');
                    // TASK-1309 : troisieme valeur `llm_rag` (IA + Dossiers).
                    $aiModeOf = function ($message) {
                        $mode = $message?->metadata['ai_mode'] ?? null;
                        if (in_array($mode, ['llm', 'rag', 'llm_rag'], true)) {
                            return $mode;
                        }
                        $action = $message?->metadata['action'] ?? null;
                        return in_array($action, ['knowledge', 'slash_ia', 'continuation', 'dossiers'], true) ? 'rag' : 'llm';
                    };
                    $aiModeLabel = fn ($mode) => match ($mode) {
                        'rag' => __('loops.dossiers_mode_label'),
                        'llm_rag' => __('loops.hybrid_mode_label'),
                        default => __('loops.ia_mode_label'),
                    };
                    $aiBubbleLabel = fn ($message) => $orgName.' · '.$aiModeLabel($aiModeOf($message));

                    $isOwn = $msg->sender_id === auth()->id();
                    $senderDisplayable = $msg->sender?->isDisplayableIn(currentOrganization()) ?? false;
                    $senderName = $msg->type === 'ai' ? $aiBubbleLabel($msg) : ($msg->sender?->publicDisplayName() ?? __('messages.member'));
                    $replySenderName = $msg->replyTo
                        ? ($msg->replyTo->type === 'ai' ? $aiBubbleLabel($msg->replyTo) : ($msg->replyTo->sender?->publicDisplayName() ?? __('messages.member')))
                        : null;
                    $replyBody = $msg->replyTo?->isDeleted()
                        ? __('messages.deleted_message_placeholder')
                        : mb_substr((string) ($msg->replyTo?->body ?? ''), 0, 120);
                    $isDeleted = $msg->isDeleted();
                    $canEdit = $isMember && auth()->user() && $msg->isEditableBy(auth()->user());

                    $aiBubbleSubtitle = null;
                    if ($msg->type === 'ai') {
                        $aiBubbleSubtitle = match ($aiModeOf($msg)) {
                            'rag' => __('loops.dossiers_bubble_subtitle'),
                            'llm_rag' => __('loops.hybrid_bubble_subtitle'),
                            default => __('loops.ia_bubble_subtitle'),
                        };
                        if (isset($requestedByNames[$msg->id])) {
                            $aiBubbleSubtitle .= ' · '.__('loops.ai_requested_by', ['name' => $requestedByNames[$msg->id]]);
                        }
                    }
                @endphp
                <div id="loop-message-{{ $msg->id }}" wire:key="msg-{{ $msg->id }}" class="transition-all duration-300">
                    @if($isDeleted)
                        <x-conversation.message-bubble
                            :type="$isOwn ? 'sent' : 'received'"
                            :time="$msg->created_at->diffForHumans()"
                            :name="$isOwn ? __('messages.me') : $senderName"
                            :message-id="$msg->id"
                            :reply-to="$msg->replyTo ? ['body' => $replyBody, 'sender_name' => $replySenderName] : null"
                        >
                            {{ __('messages.deleted_message_placeholder') }}
                        </x-conversation.message-bubble>
                    @elseif($editingMessageId === $msg->id)
                        <div class="flex justify-end">
                            <form wire:submit="saveEdit" class="w-full max-w-[90%] sm:max-w-md md:max-w-lg rounded-2xl rounded-br-sm bg-indigo-600 p-3 text-white shadow-sm">
                                <textarea
                                    wire:model="editingBody"
                                    rows="3"
                                    class="w-full rounded-lg border-indigo-400 bg-white/95 text-sm text-gray-900 focus:border-white focus:ring-white"
                                ></textarea>
                                @error('editingBody')
                                    <p class="mt-1 text-xs text-indigo-100">{{ $message }}</p>
                                @enderror
                                <div class="mt-2 flex justify-end gap-2">
                                    <button type="button" wire:click="cancelEdit" class="text-xs font-medium text-indigo-100 hover:text-white">
                                        {{ __('messages.cancel_edit') }}
                                    </button>
                                    <button type="submit" class="rounded-lg bg-white px-3 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-50">
                                        {{ __('messages.save') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    @elseif($msg->type === 'loop_event')
                        {{-- Une rencontre proposee, deplacee ou annulee. Meme
                             forme discrete que le message de Sondage : un
                             reperage dans la conversation, pas une prise de
                             parole. --}}
                        @php
                            $evMeta = $msg->metadata ?? [];
                            $evCancelled = ($evMeta['event'] ?? null) === 'cancelled';
                        @endphp
                        <div class="flex flex-wrap items-center gap-2 rounded-xl border px-3 py-2 {{ $evCancelled
                                ? 'border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/60'
                                : 'border-sky-200 bg-sky-50/70 dark:border-sky-800/50 dark:bg-sky-900/20' }}">
                            <svg class="h-4 w-4 shrink-0 {{ $evCancelled ? 'text-gray-400' : 'text-sky-600 dark:text-sky-300' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0V11.25A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                            <span class="min-w-0 flex-1 text-xs leading-5 {{ $evCancelled ? 'text-gray-600 line-through dark:text-gray-400' : 'text-sky-900 dark:text-sky-100' }}">{{ $msg->body }}</span>
                            <button type="button"
                                    x-on:click="$dispatch('bp-open-loop-card', { card: 'core.events' })"
                                    class="shrink-0 rounded-lg px-2.5 py-1 text-[11px] font-semibold text-white transition {{ $evCancelled ? 'bg-gray-500 hover:bg-gray-600' : 'bg-sky-600 hover:bg-sky-700' }}">
                                {{ __('events.chat_open_card') }}
                            </button>
                            <span class="w-full text-[10px] text-gray-400 dark:text-gray-500">{{ $msg->created_at->diffForHumans() }}</span>
                        </div>
                    @elseif($msg->type === 'poll_event')
                        {{-- Un Sondage pose ou clos. Une ligne discrete, pas une
                             bulle : c'est un reperage dans la conversation, pas
                             une prise de parole. Le bouton ouvre la Card. --}}
                        @php $pollMeta = $msg->metadata ?? []; @endphp
                        <div class="flex flex-wrap items-center gap-2 rounded-xl border border-violet-200 bg-violet-50/70 px-3 py-2 dark:border-violet-800/50 dark:bg-violet-900/20">
                            <svg class="h-4 w-4 shrink-0 text-violet-600 dark:text-violet-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg>
                            <span class="min-w-0 flex-1 text-xs leading-5 text-violet-900 dark:text-violet-100">{{ $msg->body }}</span>
                            <button type="button"
                                    x-on:click="$dispatch('bp-open-loop-card', { card: 'core.polls' })"
                                    class="shrink-0 rounded-lg bg-violet-600 px-2.5 py-1 text-[11px] font-semibold text-white transition hover:bg-violet-700">
                                {{ __('polls.chat_open_card') }}
                            </button>
                            <span class="w-full text-[10px] text-gray-400 dark:text-gray-500">{{ $msg->created_at->diffForHumans() }}</span>
                        </div>
                    @elseif($msg->type === 'help_request')
                        @php
                            $meta = $msg->metadata ?? [];
                            $projectionId = $msg->isServiceRequestProjection()
                                ? ($meta['service_request_id'] ?? null)
                                : null;
                            $canonicalRequest = $projectionId
                                ? $projectedRequests->get($projectionId)
                                : null;
                        @endphp
                        <div x-data="{ deleteOpen: false }" class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50 rounded-xl p-4 space-y-2">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-semibold text-amber-700 dark:text-amber-300 bg-amber-100 dark:bg-amber-900/40 px-2 py-0.5 rounded-full">{{ __('loops.help_request_badge') }}</span>
                                    <span class="text-[10px] text-gray-400 dark:text-gray-500">{{ $msg->created_at->diffForHumans() }}</span>
                                    @if($msg->edited_at)
                                        <span class="text-[10px] text-gray-400 dark:text-gray-500">{{ __('messages.edited') }}</span>
                                    @endif
                                </div>
                                @if($canDeleteMessages)
                                    <button
                                        type="button"
                                        x-on:click="deleteOpen = true"
                                        class="text-[11px] text-gray-400 hover:text-red-600 dark:hover:text-red-400 transition"
                                    >
                                        {{ __('messages.delete') }}
                                    </button>
                                    <template x-teleport="body">
                                        <div x-show="deleteOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center px-4" x-on:keydown.escape.window="deleteOpen = false">
                                            <div class="fixed inset-0 bg-gray-950/50 backdrop-blur-sm" x-on:click="deleteOpen = false"></div>
                                            <div class="relative w-full max-w-sm rounded-2xl border border-white/70 bg-white p-5 shadow-2xl shadow-gray-950/20 dark:border-gray-700 dark:bg-gray-900">
                                                <h3 class="text-sm font-semibold text-gray-950 dark:text-gray-100">{{ __('messages.delete_modal_title') }}</h3>
                                                <p class="mt-1 text-sm leading-5 text-gray-500 dark:text-gray-400">{{ __('messages.delete_modal_body') }}</p>
                                                <div class="mt-5 flex justify-end gap-2">
                                                    <button type="button" x-on:click="deleteOpen = false" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                                                        {{ __('messages.cancel_edit') }}
                                                    </button>
                                                    <button type="button" x-on:click="$wire.deleteMessage('{{ $msg->id }}'); deleteOpen = false" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-red-700">
                                                        {{ __('messages.delete') }}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                @endif
                            </div>
                            @if($projectionId)
                                @if($canonicalRequest)
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="min-w-0 flex-1 text-sm font-bold text-gray-900 dark:text-gray-100">{{ $canonicalRequest->title }}</h3>
                                        @if($canonicalRequest->status === 'closed')
                                            <span class="rounded-full bg-gray-200 px-2 py-0.5 text-[10px] font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ __('requests.status_closed') }}</span>
                                        @endif
                                    </div>
                                    <p class="text-sm leading-relaxed text-gray-700 dark:text-gray-300">{{ Str::limit(strip_tags($canonicalRequest->description), 320) }}</p>
                                    <a href="{{ $projectedRequestUrls[$canonicalRequest->id] }}"
                                       class="inline-flex items-center rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-amber-700">
                                        {{ __('requests.view_request') }}
                                    </a>
                                @else
                                    <p class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ __('requests.projection_unavailable') }}</p>
                                @endif
                            @else
                                {{-- Compatibilite des help_request historiques : leur snapshot
                                     reste lisible, mais n'est jamais presente comme une demande canonique. --}}
                                <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ $meta['title'] ?? __('loops.help_request_badge') }}</h3>
                                <p class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed">{{ $msg->body }}</p>
                                @if(!empty($meta['expected_help_type']))
                                    <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                        <span>{{ __('loops.expected_help', ['type' => $meta['expected_help_type']]) }}</span>
                                    </div>
                                @endif
                            @endif
                            <div class="flex items-center gap-2 text-xs text-gray-400 dark:text-gray-500 pt-1 border-t border-amber-200/50 dark:border-amber-700/30">
                                @if($canonicalRequest?->user)
                                    <span>{{ $canonicalRequest->user_id === auth()->id() ? __('messages.me') : $canonicalRequest->user->publicDisplayName() }}</span>
                                @elseif($msg->sender)
                                    <span>{{ $isOwn ? __('messages.me') : $senderName }}</span>
                                @else
                                    <span>{{ __('messages.member') }}</span>
                                @endif
                            </div>
                        </div>
                    @elseif($msg->type === 'ai')
                        <x-conversation.message-bubble
                            type="received"
                            :time="$msg->created_at->diffForHumans()"
                            {{-- TASK-1312 : le nom porte l'identite TENANT, le badge
                                 porte le moteur. `$aiBubbleLabel` reste la forme
                                 concatenee, employee la ou aucun badge n'existe
                                 (apercu de reply, composeur). --}}
                            :name="$orgName"
                            :ai-mode="$aiModeOf($msg)"
                            :subtitle="$aiBubbleSubtitle"
                            :message-id="$msg->id"
                            :show-reply-button="$isMember"
                            :show-pin-button="$isMember"
                            show-copy-button="true"
                            :show-delete-button="$canDeleteMessages"
                            :is-pinned="$pinnedMessage?->id === $msg->id"
                            :is-edited="$msg->edited_at !== null"
                            :show-reactions="$isMember"
                            :reaction-counts="$reactionData[$msg->id] ?? []"
                            :my-reaction="$myReactions[$msg->id] ?? null"
                            :reply-to="$msg->replyTo ? ['body' => $replyBody, 'sender_name' => $replySenderName] : null"
                            :sources="$msg->metadata['sources'] ?? null"
                            :consulted-sources="$msg->metadata['consulted'] ?? null"
                            is-ai="true"
                        >
                            {!! $msg->body !!}

                            {{-- TASK-1310 : « Ajouter au Dossier ». Discret, sous
                                 la reponse, et affiche UNIQUEMENT quand le
                                 serveur a deja juge la bulle capitalisable ET
                                 l'utilisateur ecrivain quelque part
                                 (`$capitalizableMessageIds`). L'UI n'est pas la
                                 barriere — le service refait toutes les gardes —
                                 mais elle ne propose pas une action vouee au
                                 refus. --}}
                            {{-- Le `@if` vit A L'INTERIEUR du slot, jamais autour.
                                 Livewire encadre chaque bloc conditionnel de
                                 marqueurs `<!--[if BLOCK]><![endif]-->` ; places
                                 entre la balise du composant et ses slots, ils
                                 tombent dans le slot PAR DEFAUT, lequel traverse
                                 `markdown()` qui les echappe — et l'utilisateur
                                 lit ces marqueurs en clair dans la bulle.
                                 Constate en recette reelle. --}}
                            <x-slot:footer>
                            @if(in_array($msg->id, $capitalizableMessageIds, true))
                                <div class="mt-2 border-t border-violet-200/70 pt-2 dark:border-violet-800/70">
                                    <button
                                        type="button"
                                        wire:click="startCapitalization('{{ $msg->id }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="startCapitalization('{{ $msg->id }}')"
                                        data-capitalize-open="{{ $msg->id }}"
                                        class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50/80 px-2.5 py-1 text-[11px] font-semibold text-emerald-800 transition hover:border-emerald-300 hover:bg-emerald-100 disabled:opacity-50 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-200 dark:hover:bg-emerald-900/40"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5v4m2-2h-4"/></svg>
                                        {{ __('loops.capitalize_action') }}
                                    </button>
                                </div>
                            @endif
                            </x-slot:footer>
                        </x-conversation.message-bubble>
                    @else
                        <x-conversation.message-bubble
                            :type="$isOwn ? 'sent' : 'received'"
                            :time="$msg->created_at->diffForHumans()"
                            :name="$isOwn ? __('messages.me') : $senderName"
                            :avatar="$senderDisplayable ? $msg->sender?->avatar_url : null"
                            :message-id="$msg->id"
                            :show-reply-button="$isMember"
                            :show-pin-button="$isMember"
                            :show-edit-button="$canEdit"
                            show-copy-button="true"
                            :show-delete-button="$canDeleteMessages"
                            :is-pinned="$pinnedMessage?->id === $msg->id"
                            :is-edited="$msg->edited_at !== null"
                            :show-reactions="$isMember"
                            :reaction-counts="$reactionData[$msg->id] ?? []"
                            :my-reaction="$myReactions[$msg->id] ?? null"
                            :reply-to="$msg->replyTo ? ['body' => $replyBody, 'sender_name' => $replySenderName] : null"
                            :image-path="$msg->imageUrl()"
                            :url-preview="$msg->metadata['url_preview'] ?? null"
                        >
                            {!! $msg->body !!}
                        </x-conversation.message-bubble>
                    @endif
                </div>
            @empty
                <x-slot:empty>
                    <div class="flex flex-col items-center justify-center h-full text-gray-400 dark:text-gray-500 py-12">
                        <svg class="w-12 h-12 mb-3 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                        </svg>
                        <p class="text-sm">{{ __('loops.no_messages') }}</p>
                        <p class="text-xs mt-1">{{ __('loops.no_messages_hint') }}</p>
                    </div>
                </x-slot:empty>
            @endforelse
        </x-slot:messages>
    </x-conversation.message-list>

    @php
        // Calcule tot pour etre partage entre la barre desktop et le menu
        // mobile (TASK-1308).
        $clarificationEnabled = \App\Models\AiConfig::get('clarification_enabled', false);
        // TASK-1308 : dans une Boucle agent, l'agent (T-2) repond deja a
        // chaque message — les deux moteurs du composeur unifie restent
        // masques pour ne jamais laisser croire a un choix sans effet
        // (sendMessage() les neutralise deja cote serveur, section 42).
        $aiEnginesAvailable = ! $viewLoop->isAiAgent();
        // TASK-1309 : les deux actions existantes sont desormais deux
        // INTERRUPTEURS qui se combinent — les quatre etats (aucun / IA /
        // Dossiers / IA + Dossiers) s'atteignent sans troisieme bouton.
        $engineActive = [
            'ia' => in_array($composerMode, ['ia', 'ia_dossiers'], true),
            'dossiers' => in_array($composerMode, ['dossiers', 'ia_dossiers'], true),
        ];
    @endphp

    @if($isMember && $canContribute && config('ai.chatloop.enabled', true))
        {{-- TASK-1237 : le FAB dispatche `bp-open-ask-ai` / `bp-open-knowledge`
             pour ouvrir CES MEMES modales historiques (route loops.ai /
             loops.knowledge.ask) — elles restent en place, INCHANGEES, pour
             cette dependance technique documentee (brief T-1308 section 39 :
             ne pas refondre le FAB global). Les DEUX boutons ci-dessous, eux,
             ne les ouvrent plus : ils selectionnent desormais le moteur du
             composeur unique (sections 3-4). Masques sur mobile (section 33) :
             le menu du composeur (`+`) les reprend. --}}
        <div class="hidden md:flex flex-shrink-0 flex-wrap items-center gap-2 px-3 pt-2" x-data="{ askOpen: false, asking: false }"
             @bp-open-ask-ai.window="askOpen = true; $nextTick(() => $refs.askQuestion?.focus())">
            @if($aiEnginesAvailable)
            <button
                type="button"
                wire:click="toggleComposerEngine('ia')"
                data-engine-toggle="ia"
                aria-pressed="{{ $engineActive['ia'] ? 'true' : 'false' }}"
                class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition {{ $engineActive['ia']
                    ? 'border-violet-400 bg-violet-600 text-white hover:bg-violet-700'
                    : 'border-violet-100 bg-violet-50/70 text-violet-700 hover:border-violet-200 hover:bg-violet-100 dark:border-violet-800/50 dark:bg-violet-900/20 dark:text-violet-200 dark:hover:bg-violet-900/40' }}"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 11.18 18.55a.75.75 0 0 0 1.38-.031l1.745-3.83a.75.75 0 0 1 .322-.36l3.746-2.25a.75.75 0 0 0 0-1.27l-3.746-2.25a.75.75 0 0 1-.322-.36L12.56 5.48a.75.75 0 0 0-1.38-.031l-1.367 2.647a.75.75 0 0 1-.5.369L4.88 9.373a.75.75 0 0 0 0 1.463l3.432.92a.75.75 0 0 1 .5.368z"/><path stroke-linecap="round" stroke-linejoin="round" d="M18 5h.01M18 9h.01M6 4h.01"/></svg>
                {{ __('loops.ask_ai_button') }}
                @if($engineActive['ia'])<span aria-hidden="true">×</span>@endif
            </button>

            <button
                type="button"
                wire:click="toggleComposerEngine('dossiers')"
                data-knowledge-open
                data-engine-toggle="dossiers"
                aria-pressed="{{ $engineActive['dossiers'] ? 'true' : 'false' }}"
                class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition {{ $engineActive['dossiers']
                    ? 'border-sky-400 bg-sky-600 text-white hover:bg-sky-700'
                    : 'border-sky-100 bg-sky-50/70 text-sky-700 hover:border-sky-200 hover:bg-sky-100 dark:border-sky-800/50 dark:bg-sky-900/20 dark:text-sky-200 dark:hover:bg-sky-900/40' }}"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/></svg>
                {{ __('loops.knowledge_button') }}
                @if($engineActive['dossiers'])<span aria-hidden="true">×</span>@endif
            </button>

            {{-- TASK-1309 : l'etat combine se NOMME, pour qu'un membre voie
                 qu'il a atteint « IA + Dossiers » et ne croie pas avoir
                 simplement clique deux boutons sans effet. --}}
            @if($composerMode === 'ia_dossiers')
            <span data-hybrid-indicator
                  class="inline-flex items-center gap-1.5 rounded-full border border-indigo-300 bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white dark:border-indigo-500">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5a4.5 4.5 0 0 0 0-9H15M16.5 3 21 7.5"/></svg>
                {{ __('loops.hybrid_mode_label') }}
            </span>
            @endif
            @endif

            @if($clarificationEnabled)
                <button
                    type="button"
                    x-on:click="window.dispatchEvent(new CustomEvent('bp-open-help-request'))"
                    class="inline-flex items-center gap-2 rounded-full border border-amber-200 bg-amber-50/80 px-3 py-1.5 text-xs font-semibold text-amber-700 transition hover:border-amber-300 hover:bg-amber-100 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-200 dark:hover:bg-amber-900/40"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 0 1-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                    {{ __('loops.who_can_help') }}
                </button>
            @endif

            <template x-teleport="body">
                <div x-show="askOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center px-3"
                     x-effect="document.body.style.overflow = askOpen ? 'hidden' : ''"
                     @keydown.escape.window="askOpen = false">
                    <div x-show="askOpen" class="fixed inset-0 bg-black/50" x-on:click="askOpen = false"></div>
                    <form method="POST" action="{{ $aiRoute }}" x-show="askOpen" x-on:submit="asking = true"
                          class="relative w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-800">
                        @csrf
                        <input type="hidden" name="action" value="ask">
                        <label for="ai-question" class="block text-sm font-medium text-gray-800 dark:text-gray-200">{{ __('loops.ask_question') }}</label>
                        <input id="ai-question" x-ref="askQuestion" type="text" name="question" required maxlength="500"
                               placeholder="{{ __('loops.ask_question_placeholder') }}"
                               class="mt-2 w-full rounded-lg border-gray-300 bg-white text-sm text-gray-900 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        <div class="mt-4 flex items-center justify-end gap-2">
                            <button type="button" x-on:click="askOpen = false" class="text-xs font-medium text-gray-600 transition hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-100">{{ __('loops.cancel') }}</button>
                            <button type="submit" x-bind:disabled="asking" class="inline-flex items-center gap-2 rounded-lg bg-violet-600 px-3 py-2 text-xs font-medium text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-50">
                                <span x-show="!asking">{{ __('loops.ask_question_submit') }}</span>
                                <span x-show="asking" x-cloak>{{ __('loops.ai_generating') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </template>
        </div>
    @endif

    @if($isMember && ! $canContribute)
        {{-- Boucle archivee : on retire le champ plutot que de laisser ecrire un
             message que le serveur refusera sans rien dire. La conversation
             reste entierement lisible au-dessus. --}}
        <div class="flex-shrink-0 px-3 pb-3 pt-2">
            <p class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-center text-xs leading-5 text-amber-800 dark:border-amber-800/60 dark:bg-amber-900/20 dark:text-amber-200">
                {{ __('loops.archive_read_only') }}
            </p>
        </div>
    @endif

    @if($isMember && $canContribute)
        @php
            // TASK-1308 : chip + placeholder du composeur, derives du mode
            // choisi — jamais un texte "RAG"/"LLM" (section 9), toujours le
            // meme couple IA/Dossiers que la barre d'actions et le menu.
            $composerModeLabel = match ($composerMode) {
                'ia' => __('loops.ia_mode_label'),
                'dossiers' => __('loops.dossiers_mode_label'),
                'ia_dossiers' => __('loops.hybrid_mode_label'),
                default => null,
            };
            $composerPlaceholder = match ($composerMode) {
                'ia' => __('loops.composer_placeholder_ia'),
                'dossiers' => __('loops.composer_placeholder_dossiers'),
                'ia_dossiers' => __('loops.composer_placeholder_hybrid'),
                default => __('messages.write_message'),
            };
        @endphp
        <x-conversation.composer
            model="body"
            :placeholder="$composerPlaceholder"
            :replying-to="$replyingTo"
            on-cancel-reply="cancelReply"
            show-upload="true"
            :photo="$photo ?? null"
            :mode="$composerMode !== 'normal' ? $composerMode : null"
            :mode-label="$composerModeLabel"
            on-clear-mode="setComposerMode('normal')"
        >
            {{-- TASK-1308 : menu mobile (section 36) — reprend les DEUX
                 actions IA/Dossiers (masquees dans la barre desktop sur
                 mobile) + « Qui peut m'aider » + l'upload d'image existant,
                 sans dupliquer sa saisie de fichier (voir composer.blade.php). --}}
            <x-slot:leading>
                <div class="md:hidden" x-data="{ sheetOpen: false }">
                    <button
                        type="button"
                        x-on:click="sheetOpen = true"
                        class="flex-shrink-0 w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-500 dark:text-gray-400 flex items-center justify-center transition"
                        aria-label="{{ __('loops.composer_more_actions') }}"
                        aria-haspopup="true"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    </button>
                    <template x-teleport="body">
                        <div
                            x-show="sheetOpen"
                            x-cloak
                            class="fixed inset-0 z-50 flex items-end justify-center"
                            x-effect="document.body.style.overflow = sheetOpen ? 'hidden' : ''"
                            @keydown.escape.window="sheetOpen = false"
                        >
                            <div x-show="sheetOpen" class="fixed inset-0 bg-black/50" x-on:click="sheetOpen = false"></div>
                            <div
                                x-show="sheetOpen"
                                x-transition:enter="transition ease-out duration-200"
                                x-transition:enter-start="translate-y-full opacity-0"
                                x-transition:enter-end="translate-y-0 opacity-100"
                                class="relative w-full max-w-lg rounded-t-2xl bg-white p-4 shadow-2xl dark:bg-gray-800"
                                style="padding-bottom: calc(1rem + env(safe-area-inset-bottom, 0px))"
                                role="dialog"
                                aria-modal="true"
                                aria-label="{{ __('loops.composer_more_actions') }}"
                            >
                                <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-gray-300 dark:bg-gray-600"></div>
                                <div class="space-y-1">
                                    @if($aiEnginesAvailable)
                                    {{-- TASK-1309 : sur mobile, le bottom sheet du composeur
                                         (T1308) propose les MEMES quatre etats. Les deux
                                         premieres lignes restent des interrupteurs
                                         combinables (elles ne ferment donc pas la feuille,
                                         pour qu'on puisse en activer deux) ; la troisieme
                                         est un raccourci direct vers l'etat combine, parce
                                         qu'au pouce, deux gestes precis valent moins qu'un
                                         seul explicite. --}}
                                    <button type="button" wire:click="toggleComposerEngine('ia')"
                                        data-engine-toggle="ia"
                                        aria-pressed="{{ $engineActive['ia'] ? 'true' : 'false' }}"
                                        class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-700 {{ $engineActive['ia'] ? 'bg-violet-50 text-violet-800 dark:bg-violet-900/30 dark:text-violet-100' : 'text-gray-800 dark:text-gray-100' }}">
                                        <svg class="h-5 w-5 shrink-0 text-violet-600 dark:text-violet-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 11.18 18.55a.75.75 0 0 0 1.38-.031l1.745-3.83a.75.75 0 0 1 .322-.36l3.746-2.25a.75.75 0 0 0 0-1.27l-3.746-2.25a.75.75 0 0 1-.322-.36L12.56 5.48a.75.75 0 0 0-1.38-.031l-1.367 2.647a.75.75 0 0 1-.5.369L4.88 9.373a.75.75 0 0 0 0 1.463l3.432.92a.75.75 0 0 1 .5.368z"/></svg>
                                        <span class="flex-1">{{ __('loops.ask_ai_button') }}</span>
                                        @if($engineActive['ia'])<span aria-hidden="true" class="text-violet-600 dark:text-violet-300">✓</span>@endif
                                    </button>
                                    <button type="button" wire:click="toggleComposerEngine('dossiers')"
                                        data-engine-toggle="dossiers"
                                        aria-pressed="{{ $engineActive['dossiers'] ? 'true' : 'false' }}"
                                        class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-700 {{ $engineActive['dossiers'] ? 'bg-sky-50 text-sky-800 dark:bg-sky-900/30 dark:text-sky-100' : 'text-gray-800 dark:text-gray-100' }}">
                                        <svg class="h-5 w-5 shrink-0 text-sky-600 dark:text-sky-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/></svg>
                                        <span class="flex-1">{{ __('loops.knowledge_button') }}</span>
                                        @if($engineActive['dossiers'])<span aria-hidden="true" class="text-sky-600 dark:text-sky-300">✓</span>@endif
                                    </button>
                                    <button type="button" wire:click="setComposerMode('ia_dossiers')" x-on:click="sheetOpen = false"
                                        data-hybrid-shortcut
                                        aria-pressed="{{ $composerMode === 'ia_dossiers' ? 'true' : 'false' }}"
                                        class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-700 {{ $composerMode === 'ia_dossiers' ? 'bg-indigo-50 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-100' : 'text-gray-800 dark:text-gray-100' }}">
                                        <svg class="h-5 w-5 shrink-0 text-indigo-600 dark:text-indigo-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5a4.5 4.5 0 0 0 0-9H15M16.5 3 21 7.5"/></svg>
                                        <span class="flex-1">{{ __('loops.hybrid_button') }}</span>
                                    </button>
                                    @endif
                                    @if($clarificationEnabled)
                                    <button type="button" x-on:click="window.dispatchEvent(new CustomEvent('bp-open-help-request')); sheetOpen = false"
                                        class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium text-gray-800 hover:bg-gray-50 dark:text-gray-100 dark:hover:bg-gray-700">
                                        <svg class="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 0 1-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                        {{ __('loops.who_can_help') }}
                                    </button>
                                    @endif
                                    <button type="button" x-on:click="$refs.uploadInput?.click(); sheetOpen = false"
                                        class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium text-gray-800 hover:bg-gray-50 dark:text-gray-100 dark:hover:bg-gray-700">
                                        <svg class="h-5 w-5 shrink-0 text-gray-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                        {{ __('loops.composer_add_image') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </x-slot:leading>

            @error('body')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
            @enderror
            @error('photo')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
            @enderror
        </x-conversation.composer>
    @endif

    {{-- TASK-1310 : formulaire « Ajouter au Dossier ». Monte UNE seule fois,
         hors de la boucle des messages : l'etat vit cote serveur
         (`$capitalizingMessageId`), donc un seul brouillon a la fois — et un
         double-clic sur l'action ne peut pas ouvrir deux formulaires.
         `wire:loading.attr="disabled"` sur l'enregistrement couvre la double
         soumission triviale ; le verrou IA global reste hors scope (FILE-2). --}}
    @if($capitalizingMessageId !== null)
        <template x-teleport="body">
            <div class="fixed inset-0 z-50 flex items-center justify-center px-3" data-capitalize-modal
                 x-data x-effect="document.body.style.overflow = 'hidden'"
                 x-on:keydown.escape.window="$wire.cancelCapitalization()">
                <div class="fixed inset-0 bg-black/50" wire:click="cancelCapitalization"></div>
                <form wire:submit="saveCapitalization"
                      class="relative w-full max-w-xl rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-800"
                      style="max-height: calc(100dvh - 2rem); overflow-y: auto; padding-bottom: calc(1.25rem + env(safe-area-inset-bottom, 0px))">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.capitalize_title') }}</h2>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('loops.capitalize_intro') }}</p>

                    <label for="capitalize-dossier" class="mt-4 block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.capitalize_dossier_label') }}</label>
                    <select id="capitalize-dossier" wire:model="capitalizeDossierId" data-capitalize-dossier
                            class="mt-1 w-full rounded-lg border-gray-300 bg-white text-sm text-gray-900 focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        @foreach($writableDossiers as $dossier)
                            <option value="{{ $dossier->id }}">{{ $dossier->name }}</option>
                        @endforeach
                    </select>
                    @error('capitalizeDossierId')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror

                    <label for="capitalize-title" class="mt-3 block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.capitalize_article_title_label') }}</label>
                    <input id="capitalize-title" type="text" wire:model="capitalizeTitle" maxlength="255" data-capitalize-title
                           class="mt-1 w-full rounded-lg border-gray-300 bg-white text-sm text-gray-900 focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    @error('capitalizeTitle')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror

                    <label for="capitalize-content" class="mt-3 block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.capitalize_article_content_label') }}</label>
                    <textarea id="capitalize-content" wire:model="capitalizeContent" rows="10" data-capitalize-content
                              class="mt-1 w-full rounded-lg border-gray-300 bg-white text-sm text-gray-900 focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                    @error('capitalizeContent')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror

                    <div class="mt-4 flex items-center justify-end gap-2">
                        <button type="button" wire:click="cancelCapitalization"
                                class="text-xs font-medium text-gray-600 transition hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-100">
                            {{ __('loops.cancel') }}
                        </button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="saveCapitalization" data-capitalize-submit
                                class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50">
                            {{ __('loops.capitalize_submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </template>
    @endif

    {{-- Etat du composant, jamais un flash de session : le `wire:poll` de cette
         page consommerait le flash avant que l'utilisateur ne le lise. --}}
    @if($capitalizeFlash !== '')
        <div class="px-3 pb-2" data-capitalize-flash>
            <p class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-200">
                {{ $capitalizeFlash }}
            </p>
        </div>
    @endif
</div>
