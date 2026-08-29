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
                    // TASK-1316 : l'attribution humaine cesse d'etre CONCATENEE au
                    // sous-titre. Elle voyage a part, avec l'identifiant de la
                    // personne, et la bulle lui donne sa propre ligne : dans un
                    // groupe, « qui a demande cette reponse » n'est pas un detail
                    // qu'on tronque quand la place manque.
                    $aiRequestedBy = null;
                    if ($msg->type === 'ai') {
                        $aiBubbleSubtitle = match ($aiModeOf($msg)) {
                            'rag' => __('loops.dossiers_bubble_subtitle'),
                            'llm_rag' => __('loops.hybrid_bubble_subtitle'),
                            default => __('loops.ia_bubble_subtitle'),
                        };
                        // La provenance est la metadata persistee — jamais le texte
                        // de la bulle, jamais un nom relu dans le corps.
                        if (isset($requestedByNames[$msg->id]) && isset($msg->metadata['requested_by'])) {
                            $aiRequestedBy = [
                                'id' => (string) $msg->metadata['requested_by'],
                                'name' => $requestedByNames[$msg->id],
                            ];
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
                            :requested-by="$aiRequestedBy"
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
                            {{-- TASK-1329 : « Ajouter au Dossier » et « Pourquoi
                                 cette réponse ? » vivent sur la MEME ligne — deux
                                 blocs empiles, chacun avec sa bordure haute,
                                 doublaient la hauteur du pied de bulle pour deux
                                 actions de meme rang. `flex-wrap` : sur mobile,
                                 si les deux ne tiennent pas, le second passe
                                 dessous sans deborder. --}}
                            @if(in_array($msg->id, $capitalizableMessageIds, true) || $isMember)
                                <div class="mt-2 border-t border-violet-200/70 pt-2 dark:border-violet-800/70">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                    @if(in_array($msg->id, $capitalizableMessageIds, true))
                                        {{-- TASK-1313 : l'action est VISIBLE par tout membre,
                                             et seulement ACTIVE pour qui en a le droit.
                                             La masquer revenait a ce qu'un membre
                                             ordinaire ne puisse pas meme savoir qu'elle
                                             existe : un refus explique informe, une
                                             absence laisse croire que rien n'est
                                             possible. `disabled` n'est evidemment pas la
                                             garantie — le service revalide tout. --}}
                                        <button
                                            type="button"
                                            @if($canCapitalize)
                                            wire:click="startCapitalization('{{ $msg->id }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="startCapitalization('{{ $msg->id }}')"
                                            @else
                                            disabled
                                            aria-describedby="capitalize-hint-{{ $msg->id }}"
                                            @endif
                                            data-capitalize-open="{{ $msg->id }}"
                                            data-capitalize-allowed="{{ $canCapitalize ? '1' : '0' }}"
                                            class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50/80 px-2.5 py-1 text-[11px] font-semibold text-emerald-800 transition disabled:cursor-not-allowed disabled:opacity-50 enabled:hover:border-emerald-300 enabled:hover:bg-emerald-100 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-200 dark:enabled:hover:bg-emerald-900/40"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5v4m2-2h-4"/></svg>
                                            {{ __('loops.capitalize_action') }}
                                        </button>
                                    @endif
                                    {{-- TASK-1328 : « Pourquoi cette réponse ? » — sur TOUTE
                                         bulle IA, pour tout membre. L'UI n'est pas la
                                         barrière : le service refait toutes les gardes et
                                         peut rendre un panneau « trace indisponible »
                                         honnête sur une bulle antérieure au ledger. --}}
                                    @if($isMember)
                                        <button
                                            type="button"
                                            wire:click="showWhy('{{ $msg->id }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="showWhy('{{ $msg->id }}')"
                                            data-why-open="{{ $msg->id }}"
                                            class="inline-flex items-center gap-1.5 rounded-full border border-violet-200 bg-violet-50/80 px-2.5 py-1 text-[11px] font-semibold text-violet-800 transition hover:border-violet-300 hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-violet-800/60 dark:bg-violet-900/20 dark:text-violet-200 dark:hover:bg-violet-900/40"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>
                                            {{ __('loops.why_action') }}
                                        </button>
                                    @endif
                                    </div>
                                    @if(in_array($msg->id, $capitalizableMessageIds, true) && ! $canCapitalize)
                                    <p id="capitalize-hint-{{ $msg->id }}" data-capitalize-hint class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{{ __('loops.capitalize_reserved_to_facilitators') }}</p>
                                    @endif
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
                            :requested-mode="$msg->metadata['requested_mode'] ?? null"
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

        {{-- TASK-1316 : le signal PARTAGE « une reponse IA est en cours ».
             Il vit apres le fil, la ou la reponse va paraitre — et il DISPARAIT
             de lui-meme : `LoopAiTurnSignal` ne le produit plus des que la
             reponse existe (meme condition de fin que `AiTurnIdempotency`), que
             le verrou du tour a ete rendu (refus economique, panne provider) ou
             que la borne de temps du verrou est passee.

             Aucun faux streaming : rien n'est affiche du texte a venir. Le
             `wire:poll.3s` deja porte par ce composant suffit — c'est le
             transport accepte pour la V1, et il n'y a pas de second moteur de
             conversation. --}}
        <x-slot:after>
            @foreach($pendingAiTurns as $turn)
                <div wire:key="ai-turn-{{ $turn['message_id'] }}"
                     data-ai-turn-pending="{{ $turn['message_id'] }}"
                     data-ai-turn-mode="{{ $turn['ai_mode'] }}"
                     data-ai-turn-requester="{{ $turn['requester_id'] }}"
                     role="status"
                     aria-live="polite"
                     class="flex justify-start pt-1">
                    <p class="inline-flex max-w-[90%] items-center gap-2 rounded-2xl rounded-bl-sm bg-violet-50 px-3 py-2 text-[11px] font-medium leading-tight text-violet-800 ring-1 ring-violet-200 dark:bg-violet-900/40 dark:text-violet-100 dark:ring-violet-800">
                        <span aria-hidden="true" class="relative flex h-2 w-2 shrink-0">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-violet-500 opacity-75"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-violet-500"></span>
                        </span>
                        <span class="min-w-0">{{ __('loops.ai_turn_in_progress', ['ai' => $turn['identity'], 'name' => $turn['requester_name']]) }}</span>
                    </p>
                </div>
            @endforeach
        </x-slot:after>
    </x-conversation.message-list>

    @php
        // TASK-1322 (Core-2) : « Qui peut m'aider ? » n'est plus conditionne a
        // AiConfig::clarification_enabled. L'entree du parcours reste visible
        // et le modal (loops/show) degrade proprement quand l'IA n'est pas
        // disponible — message explicite + chemin manuel canonique — au lieu
        // de disparaitre ou de bloquer.
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

            <button
                type="button"
                x-on:click="window.dispatchEvent(new CustomEvent('bp-open-help-request'))"
                class="inline-flex items-center gap-2 rounded-full border border-amber-200 bg-amber-50/80 px-3 py-1.5 text-xs font-semibold text-amber-700 transition hover:border-amber-300 hover:bg-amber-100 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-200 dark:hover:bg-amber-900/40"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 0 1-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                {{ __('loops.who_can_help') }}
            </button>

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
                    {{-- TASK-1329 : bouton INTEGRE au champ (composer.blade.php
                         le positionne en absolu dans le cadre du textarea) —
                         transparent, taille reduite, jamais une pastille pleine
                         qui doublerait visuellement le bouton envoyer. --}}
                    <button
                        type="button"
                        x-on:click="sheetOpen = true"
                        class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-indigo-600 dark:text-gray-500 dark:hover:bg-gray-700 dark:hover:text-indigo-300"
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
                                {{-- TASK-1329 : GRILLE de tuiles, plus une liste — cinq
                                     lignes pleine largeur consommaient un tiers de
                                     l'ecran (constate en recette mobile). Motif des
                                     feuilles d'actions des messageries mobiles : pastille
                                     d'icone + libelle court, trois par rangee. La
                                     semantique T1308/T1309 est INCHANGEE : les deux
                                     interrupteurs IA/Dossiers restent combinables (ils ne
                                     ferment pas la feuille), le raccourci hybride ferme,
                                     et les attributs `data-engine-toggle` /
                                     `data-hybrid-shortcut` / `aria-pressed` sont
                                     identiques — ce sont eux qu'un test asserte.
                                     Etat actif = pastille remplie + fond teinte, jamais
                                     la couleur seule (aria-pressed porte l'etat). --}}
                                <div class="grid grid-cols-3 gap-1.5">
                                    @if($aiEnginesAvailable)
                                    <button type="button" wire:click="toggleComposerEngine('ia')"
                                        data-engine-toggle="ia"
                                        aria-pressed="{{ $engineActive['ia'] ? 'true' : 'false' }}"
                                        class="flex flex-col items-center gap-1.5 rounded-xl px-1.5 py-2 text-center transition {{ $engineActive['ia'] ? 'bg-violet-50 dark:bg-violet-900/30' : 'hover:bg-gray-50 dark:hover:bg-gray-700' }}">
                                        <span class="flex h-10 w-10 items-center justify-center rounded-full transition {{ $engineActive['ia'] ? 'bg-violet-600 text-white shadow-sm shadow-violet-500/30' : 'bg-violet-100 text-violet-600 dark:bg-violet-900/40 dark:text-violet-300' }}">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 11.18 18.55a.75.75 0 0 0 1.38-.031l1.745-3.83a.75.75 0 0 1 .322-.36l3.746-2.25a.75.75 0 0 0 0-1.27l-3.746-2.25a.75.75 0 0 1-.322-.36L12.56 5.48a.75.75 0 0 0-1.38-.031l-1.367 2.647a.75.75 0 0 1-.5.369L4.88 9.373a.75.75 0 0 0 0 1.463l3.432.92a.75.75 0 0 1 .5.368z"/></svg>
                                        </span>
                                        <span class="text-[11px] font-medium leading-tight {{ $engineActive['ia'] ? 'text-violet-800 dark:text-violet-100' : 'text-gray-700 dark:text-gray-200' }}">{{ __('loops.ask_ai_button') }}</span>
                                    </button>
                                    <button type="button" wire:click="toggleComposerEngine('dossiers')"
                                        data-engine-toggle="dossiers"
                                        aria-pressed="{{ $engineActive['dossiers'] ? 'true' : 'false' }}"
                                        class="flex flex-col items-center gap-1.5 rounded-xl px-1.5 py-2 text-center transition {{ $engineActive['dossiers'] ? 'bg-sky-50 dark:bg-sky-900/30' : 'hover:bg-gray-50 dark:hover:bg-gray-700' }}">
                                        <span class="flex h-10 w-10 items-center justify-center rounded-full transition {{ $engineActive['dossiers'] ? 'bg-sky-600 text-white shadow-sm shadow-sky-500/30' : 'bg-sky-100 text-sky-600 dark:bg-sky-900/40 dark:text-sky-300' }}">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/></svg>
                                        </span>
                                        <span class="text-[11px] font-medium leading-tight {{ $engineActive['dossiers'] ? 'text-sky-800 dark:text-sky-100' : 'text-gray-700 dark:text-gray-200' }}">{{ __('loops.knowledge_button') }}</span>
                                    </button>
                                    <button type="button" wire:click="setComposerMode('ia_dossiers')" x-on:click="sheetOpen = false"
                                        data-hybrid-shortcut
                                        aria-pressed="{{ $composerMode === 'ia_dossiers' ? 'true' : 'false' }}"
                                        class="flex flex-col items-center gap-1.5 rounded-xl px-1.5 py-2 text-center transition {{ $composerMode === 'ia_dossiers' ? 'bg-indigo-50 dark:bg-indigo-900/30' : 'hover:bg-gray-50 dark:hover:bg-gray-700' }}">
                                        <span class="flex h-10 w-10 items-center justify-center rounded-full transition {{ $composerMode === 'ia_dossiers' ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-500/30' : 'bg-indigo-100 text-indigo-600 dark:bg-indigo-900/40 dark:text-indigo-300' }}">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5a4.5 4.5 0 0 0 0-9H15M16.5 3 21 7.5"/></svg>
                                        </span>
                                        <span class="text-[11px] font-medium leading-tight {{ $composerMode === 'ia_dossiers' ? 'text-indigo-800 dark:text-indigo-100' : 'text-gray-700 dark:text-gray-200' }}">{{ __('loops.hybrid_button') }}</span>
                                    </button>
                                    @endif
                                    <button type="button" x-on:click="window.dispatchEvent(new CustomEvent('bp-open-help-request')); sheetOpen = false"
                                        class="flex flex-col items-center gap-1.5 rounded-xl px-1.5 py-2 text-center transition hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-300">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 0 1-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                        </span>
                                        <span class="text-[11px] font-medium leading-tight text-gray-700 dark:text-gray-200">{{ __('loops.who_can_help') }}</span>
                                    </button>
                                    <button type="button" x-on:click="$refs.uploadInput?.click(); sheetOpen = false"
                                        class="flex flex-col items-center gap-1.5 rounded-xl px-1.5 py-2 text-center transition hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                        </span>
                                        <span class="text-[11px] font-medium leading-tight text-gray-700 dark:text-gray-200">{{ __('loops.composer_add_image') }}</span>
                                    </button>
                                    {{-- TASK-1329 : `capture` ouvre directement l'appareil
                                         photo — meme upload, meme pipeline que la galerie
                                         (`$refs.cameraInput`, composer.blade.php). --}}
                                    <button type="button" x-on:click="$refs.cameraInput?.click(); sheetOpen = false"
                                        data-composer-take-photo
                                        class="flex flex-col items-center gap-1.5 rounded-xl px-1.5 py-2 text-center transition hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z"/></svg>
                                        </span>
                                        <span class="text-[11px] font-medium leading-tight text-gray-700 dark:text-gray-200">{{ __('loops.composer_take_photo') }}</span>
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

    {{-- TASK-1328 : panneau « Pourquoi cette réponse ? ». Monté UNE fois, hors
         de la boucle des messages, état côté serveur (`$whyMessageId`) — même
         motif que le formulaire T1310 : un seul panneau, il survit au
         `wire:poll`, et un snapshot rejoué ne peut montrer que ce que le
         service a déjà jugé montrable à CE spectateur. Tout le contenu vient
         de `$whyPanel` (assemblé par AiResponseExplanationService depuis les
         traces réelles de génération), rendu ÉCHAPPÉ — jamais un prompt, une
         réponse brute ou un identifiant technique de source refusée. --}}
    @if($whyMessageId !== null && $whyPanel !== null)
        <template x-teleport="body">
            <div class="fixed inset-0 z-50 flex items-center justify-center px-3" data-why-panel
                 x-data x-effect="document.body.style.overflow = 'hidden'"
                 x-on:keydown.escape.window="$wire.closeWhy()">
                {{-- Meme voile que les modales de suppression du fil :
                     gray-950/50 + blur, jamais un noir pur. --}}
                <div class="fixed inset-0 bg-gray-950/50 backdrop-blur-sm" wire:click="closeWhy"></div>
                <div class="relative w-full max-w-xl overflow-hidden rounded-2xl border border-white/70 bg-white shadow-2xl shadow-gray-950/20 dark:border-gray-700 dark:bg-gray-900"
                     style="max-height: calc(100dvh - 2rem); overflow-y: auto; padding-bottom: env(safe-area-inset-bottom, 0px)">
                    {{-- Bandeau d'identite : la provenance IA porte la teinte
                         violette du fil — la meme que la bulle qu'elle explique. --}}
                    <div class="flex items-start gap-3 border-b border-violet-100 bg-gradient-to-br from-violet-50 via-white to-white px-5 py-4 dark:border-violet-900/40 dark:from-violet-950/40 dark:via-gray-900 dark:to-gray-900">
                        <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-violet-500 to-emerald-400 text-white shadow-sm shadow-violet-500/30 ring-1 ring-white/60 dark:ring-violet-300/20" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.091-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.091L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.091 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.091ZM18.25 8.25 18 9.25l-.25-1a2.5 2.5 0 0 0-1.75-1.75L15 6.25l1-.25a2.5 2.5 0 0 0 1.75-1.75l.25-1 .25 1A2.5 2.5 0 0 0 20 6l1 .25-1 .25a2.5 2.5 0 0 0-1.75 1.75Z"/>
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold text-gray-950 dark:text-gray-100">{{ __('loops.why_title') }}</h2>
                            <p class="mt-0.5 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ __('loops.why_intro') }}</p>
                        </div>
                    </div>

                    <div class="px-5 py-4">
                    <dl class="overflow-hidden rounded-xl border border-gray-200 text-xs dark:border-gray-700">
                        <div class="flex gap-3 px-3 py-2"><dt class="w-32 shrink-0 font-medium text-gray-500 dark:text-gray-400">{{ __('loops.why_org_label') }}</dt><dd class="min-w-0 font-medium text-gray-900 dark:text-gray-100">{{ $whyPanel['organization_name'] }}</dd></div>
                        <div class="flex gap-3 border-t border-gray-100 px-3 py-2 dark:border-gray-800"><dt class="w-32 shrink-0 font-medium text-gray-500 dark:text-gray-400">{{ __('loops.why_loop_label') }}</dt><dd class="min-w-0 font-medium text-gray-900 dark:text-gray-100">{{ $whyPanel['loop_name'] }}</dd></div>
                        @if($whyPanel['ai_mode'])
                        <div class="flex items-center gap-3 border-t border-gray-100 px-3 py-2 dark:border-gray-800"><dt class="w-32 shrink-0 font-medium text-gray-500 dark:text-gray-400">{{ __('loops.why_mode_label') }}</dt><dd data-why-mode="{{ $whyPanel['ai_mode'] }}"><span class="inline-flex items-center rounded bg-violet-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-700 ring-1 ring-violet-200 dark:bg-violet-800/60 dark:text-violet-100 dark:ring-violet-700">{{ match($whyPanel['ai_mode']) {
                            'llm' => __('loops.ia_mode_label'),
                            'rag' => __('loops.dossiers_mode_label'),
                            'llm_rag' => __('loops.hybrid_mode_label'),
                            default => $whyPanel['ai_mode'],
                        } }}</span></dd></div>
                        @endif
                        @if($whyPanel['requested_by_name'])
                        <div class="flex gap-3 border-t border-gray-100 px-3 py-2 dark:border-gray-800"><dt class="w-32 shrink-0 font-medium text-gray-500 dark:text-gray-400">{{ __('loops.why_requested_by_label') }}</dt><dd class="min-w-0 font-medium text-violet-700 dark:text-violet-300">{{ $whyPanel['requested_by_name'] }}</dd></div>
                        @endif
                        @if($whyPanel['question'])
                        <div class="flex gap-3 border-t border-gray-100 px-3 py-2 dark:border-gray-800"><dt class="w-32 shrink-0 font-medium text-gray-500 dark:text-gray-400">{{ __('loops.why_question_label') }}</dt><dd class="min-w-0 italic leading-5 text-gray-700 dark:text-gray-300">« {{ $whyPanel['question'] }} »</dd></div>
                        @endif
                        @if($whyPanel['generated_at'])
                        <div class="flex gap-3 border-t border-gray-100 px-3 py-2 dark:border-gray-800"><dt class="w-32 shrink-0 font-medium text-gray-500 dark:text-gray-400">{{ __('loops.why_generated_label') }}</dt><dd class="min-w-0 text-gray-700 dark:text-gray-300">{{ $whyPanel['generated_at'] }}</dd></div>
                        @endif
                    </dl>

                    @if($whyPanel['ledger'] === null)
                        {{-- Bulle antérieure au ledger, trace introuvable ou
                             incohérente : le gap est DIT, jamais comblé par une
                             reconstruction plausible. --}}
                        <p class="mt-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs leading-5 text-amber-800 dark:border-amber-800/60 dark:bg-amber-900/20 dark:text-amber-200" data-why-trace-unavailable>
                            <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                            <span>{{ __('loops.why_trace_unavailable') }}</span>
                        </p>
                    @else
                        <div class="mt-4 space-y-3" data-why-ledger data-why-capability="{{ $whyPanel['ledger']['capability'] }}">
                            <div class="flex items-center gap-3 text-xs"><span class="w-32 shrink-0 font-medium text-gray-500 dark:text-gray-400">{{ __('loops.why_function_label') }}</span><span class="font-medium text-gray-900 dark:text-gray-100">{{ $whyPanel['ledger']['capability_label'] }}</span></div>

                            <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-3 text-xs dark:border-gray-700 dark:bg-gray-800/50" data-why-conversation>
                                <p class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    <svg class="h-3.5 w-3.5 shrink-0 text-violet-500 dark:text-violet-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                    {{ __('loops.why_conversation_title') }}
                                </p>
                                @if($whyPanel['ledger']['conversation'] === null)
                                    <p class="mt-1.5 leading-5 text-gray-600 dark:text-gray-300">{{ __('loops.why_conversation_unavailable') }}</p>
                                @else
                                    <p class="mt-1.5 leading-5 text-gray-700 dark:text-gray-200" data-why-conversation-used="{{ $whyPanel['ledger']['conversation']['used_count'] }}">{{ trans_choice('loops.why_conversation_used', $whyPanel['ledger']['conversation']['used_count']) }}</p>
                                    @if($whyPanel['ledger']['conversation']['hidden_count'] > 0)
                                    <p class="mt-0.5 leading-5 text-amber-700 dark:text-amber-300" data-why-conversation-hidden="{{ $whyPanel['ledger']['conversation']['hidden_count'] }}">{{ trans_choice('loops.why_conversation_hidden', $whyPanel['ledger']['conversation']['hidden_count']) }}</p>
                                    @endif
                                @endif
                            </div>

                            <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-3 text-xs dark:border-gray-700 dark:bg-gray-800/50" data-why-documents>
                                <p class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    <svg class="h-3.5 w-3.5 shrink-0 text-violet-500 dark:text-violet-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
                                    {{ __('loops.why_documents_title') }}
                                </p>
                                @if($whyPanel['ledger']['documents'] === null)
                                    <p class="mt-1.5 leading-5 text-gray-600 dark:text-gray-300">{{ __('loops.why_documents_unavailable') }}</p>
                                @elseif($whyPanel['ledger']['documents']['applies'] === false)
                                    <p class="mt-1.5 leading-5 text-gray-600 dark:text-gray-300" data-why-documents-none>{{ __('loops.why_documents_none') }}</p>
                                @else
                                    <p class="mt-1.5 leading-5 text-gray-700 dark:text-gray-200">
                                        {{ trans_choice('loops.why_documents_cited', $whyPanel['ledger']['documents']['cited_count']) }}
                                        · {{ trans_choice('loops.why_documents_consulted', $whyPanel['ledger']['documents']['consulted_count']) }}
                                    </p>
                                    @if($whyPanel['ledger']['documents']['entries'] !== [])
                                    <ul class="mt-2 space-y-1">
                                        @foreach($whyPanel['ledger']['documents']['entries'] as $entry)
                                        <li class="rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 dark:border-gray-700 dark:bg-gray-900" data-why-document-entry>
                                            @if($entry['ref'])<span class="font-mono text-[10px] text-sky-700 dark:text-sky-300">[{{ $entry['ref'] }}]</span>@endif
                                            <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $entry['title'] }}</span>
                                            @if($entry['dossier_name'])<span class="text-gray-500 dark:text-gray-400"> · {{ $entry['dossier_name'] }}</span>@endif
                                        </li>
                                        @endforeach
                                    </ul>
                                    @endif
                                    @if($whyPanel['ledger']['documents']['masked_count'] > 0)
                                    <p class="mt-1.5 leading-5 text-amber-700 dark:text-amber-300" data-why-documents-masked="{{ $whyPanel['ledger']['documents']['masked_count'] }}">{{ trans_choice('loops.why_documents_masked', $whyPanel['ledger']['documents']['masked_count']) }}</p>
                                    @endif
                                @endif
                            </div>

                            @if($whyPanel['ledger']['denied_count'] > 0)
                            <p class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs leading-5 text-amber-800 dark:border-amber-800/60 dark:bg-amber-900/20 dark:text-amber-200" data-why-denied="{{ $whyPanel['ledger']['denied_count'] }}">
                                <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                                <span>{{ trans_choice('loops.why_denied', $whyPanel['ledger']['denied_count']) }}</span>
                            </p>
                            @endif
                        </div>
                    @endif

                    @if($whyPanel['can_feedback'])
                    <div class="mt-4 rounded-xl border border-violet-100 bg-violet-50/50 px-3 py-2.5 dark:border-violet-900/40 dark:bg-violet-950/20" data-why-feedback>
                        <p class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.why_feedback_title') }}</p>
                        <div class="mt-2 flex items-center gap-2">
                            <button type="button" wire:click="submitWhyFeedback('helpful')" wire:loading.attr="disabled" wire:target="submitWhyFeedback"
                                    data-why-feedback-helpful data-why-feedback-active="{{ $whyPanel['my_verdict'] === 'helpful' ? '1' : '0' }}"
                                    class="rounded-full border px-3 py-1 text-[11px] font-semibold transition disabled:opacity-50 {{ $whyPanel['my_verdict'] === 'helpful' ? 'border-emerald-300 bg-emerald-100 text-emerald-900 dark:border-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-100' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-700' }}">
                                {{ __('loops.why_feedback_helpful') }}
                            </button>
                            <button type="button" wire:click="submitWhyFeedback('improve')" wire:loading.attr="disabled" wire:target="submitWhyFeedback"
                                    data-why-feedback-improve data-why-feedback-active="{{ $whyPanel['my_verdict'] === 'improve' ? '1' : '0' }}"
                                    class="rounded-full border px-3 py-1 text-[11px] font-semibold transition disabled:opacity-50 {{ $whyPanel['my_verdict'] === 'improve' ? 'border-amber-300 bg-amber-100 text-amber-900 dark:border-amber-700 dark:bg-amber-900/40 dark:text-amber-100' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-700' }}">
                                {{ __('loops.why_feedback_improve') }}
                            </button>
                        </div>
                    </div>
                    @endif

                    <div class="mt-4 flex justify-end">
                        <button type="button" wire:click="closeWhy" data-why-close
                                class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-50 hover:text-gray-900 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-gray-100">
                            {{ __('loops.why_close') }}
                        </button>
                    </div>
                    </div>
                </div>
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
