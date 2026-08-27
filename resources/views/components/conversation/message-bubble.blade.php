@props([
    'type' => 'received',
    'time' => null,
    'avatar' => null,
    'name' => null,
    'subtitle' => null,
    'isAi' => false,
    'class' => '',
    'replyTo' => null,
    'messageId' => null,
    'showReplyButton' => false,
    'imagePath' => null,
    'urlPreview' => null,
    'showPinButton' => false,
    'showEditButton' => false,
    'showDeleteButton' => false,
    'showCopyButton' => false,
    'isPinned' => false,
    'isEdited' => false,
    'showReactions' => false,
    'reactionCounts' => [],
    'myReaction' => null,
    'sources' => null,
    // TASK-1309 : documents dont le CONTENU a ete lu sans qu'aucune citation
    // valide n'en sorte. Rendus sous « Sources consultées », et seulement
    // quand il n'y a aucune source utilisee — jamais melanges aux deux.
    'consultedSources' => null,
])

@php
$isSent = $type === 'sent';
$containerClasses = $isSent
    ? 'flex justify-end'
    : 'flex justify-start';

$bubbleClasses = $isSent
    ? 'bg-indigo-600 text-white rounded-2xl rounded-br-sm'
    : ($isAi
        ? 'bg-violet-50 dark:bg-violet-900 ring-1 ring-violet-200 dark:ring-violet-800 text-gray-900 dark:text-gray-100 rounded-2xl rounded-bl-sm'
        : 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-2xl rounded-bl-sm');

$nameClasses = $isSent
    ? 'text-xs font-medium text-indigo-200'
    : ($isAi
        ? 'text-xs font-medium text-violet-600 dark:text-violet-300'
        : 'text-xs font-medium text-gray-500 dark:text-gray-400');

$timeClasses = $isSent
    ? 'text-indigo-200'
    : 'text-gray-400';

$reactionTypes = App\Models\Reaction::REACTION_TYPES;
$primaryReactionTypes = ['thumbs_up', 'heart', 'thanks', 'surprised', 'sad'];
$secondaryReactionTypes = array_values(array_diff($reactionTypes, $primaryReactionTypes));
$reactionEmojis = App\Models\Reaction::emojiMap();
$visibleReactionCounts = array_filter($reactionCounts, fn ($count) => $count > 0);

$escapePlainMarkdown = static function (string $text): string {
    $text = str_replace('\\', '\\\\', $text);

    return preg_replace('/([`*_{}\[\]()#+\-.!|>])/', '\\\\$1', $text) ?? $text;
};

$renderableBody = preg_replace_callback(
    '/```[a-z0-9_+-]*[ \t]*\r?\n([\s\S]*?)```[ \t]*/i',
    static fn (array $match): string => "\n".$escapePlainMarkdown(trim($match[1]))."\n",
    (string) $slot,
) ?? (string) $slot;

$renderableBody = preg_replace_callback(
    '/`([^`\n]+)`/',
    static fn (array $match): string => $escapePlainMarkdown($match[1]),
    $renderableBody,
) ?? $renderableBody;

$renderableBody = preg_replace_callback(
    '/(?:^|\n)([^\n]*\|[^\n]*\n[ \t]*\|?[ \t]*:?-{3,}:?[ \t]*(?:\|[ \t]*:?-{3,}:?[ \t]*)+\|?[ \t]*(?:\n[^\n]*\|[^\n]*)*)/m',
    static function (array $match) use ($escapePlainMarkdown): string {
        return "\n".collect(explode("\n", trim($match[1])))
            ->map(static fn (string $line): string => $escapePlainMarkdown($line))
            ->implode("\n");
    },
    $renderableBody,
) ?? $renderableBody;
@endphp

<div
    {{ $attributes->merge(['class' => $containerClasses . ' group ' . $class]) }}
    x-data="{
        id: @js((string) $messageId),
        open: false,
        hover: false,
        showMore: false,
        pressTimer: null,
        copied: false,
        deleteOpen: false,
        copyTimer: null,
        async copyMessage() {
            const text = (this.$refs.copyContent?.innerText || '').replace(/\n{3,}/g, '\n\n').trim();
            if (!text) return;

            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);
                } else {
                    const textarea = document.createElement('textarea');
                    textarea.value = text;
                    textarea.setAttribute('readonly', '');
                    textarea.style.position = 'fixed';
                    textarea.style.left = '-9999px';
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textarea);
                }

                this.copied = true;
                clearTimeout(this.copyTimer);
                this.copyTimer = setTimeout(() => this.copied = false, 1400);
            } catch (error) {
                this.$dispatch('chatloop-copy-failed');
            }
        },
    }"
    @if($showReactions && $messageId)
    x-on:mouseover="hover = true"
    x-on:mouseleave="hover = false"
    x-on:reaction-menu-opened.window="if ($event.detail.id !== id) { open = false; showMore = false }"
    x-on:keydown.escape.window="open = false; showMore = false"
    x-on:click.outside="open = false; showMore = false"
    @endif
>
    {{-- TASK-1309 (recette mobile) : `min-w-0` sur l'element flex.
         Sans lui, sa taille MIN-CONTENT est celle de son plus long descendant
         non secable — et l'apercu de reply (`truncate`, donc `nowrap`) en est
         un : une question un peu longue poussait la bulle a 461 px dans un
         conteneur de 358, et `max-w-[90%]`, calcule sur ce parent deja trop
         large, n'y pouvait rien. Le texte etait alors coupe a droite, sur
         mobile, pour TOUTE bulle portant un reply — defaut PREEXISTANT,
         mesure sur le banc reel a l'identique sur une bulle « Dossiers »
         anterieure a cette TASK ; la recette T1309 l'a rendu visible. --}}
    <div class="relative min-w-0">
    <div
        class="max-w-[90%] sm:max-w-md md:max-w-lg {{ $bubbleClasses }} px-3 py-2"
        @if($showReactions && $messageId)
        x-on:touchstart.passive="if (!$event.target.closest('button,a,input,textarea,select')) { pressTimer = setTimeout(() => { $dispatch('reaction-menu-opened', { id }); open = true }, 450) }"
        x-on:touchend="clearTimeout(pressTimer)"
        x-on:touchmove="clearTimeout(pressTimer)"
        x-on:touchcancel="clearTimeout(pressTimer)"
        x-on:contextmenu.prevent="$dispatch('reaction-menu-opened', { id }); open = true"
        @endif
    >
        @if($avatar || $name || $subtitle || $time || $isEdited || $isAi)
        <div class="mb-1 flex items-start justify-between gap-3">
            <div class="flex min-w-0 items-center gap-2">
                @if($avatar)
                <img src="{{ $avatar }}" alt="" class="h-5 w-5 rounded-full">
                @elseif($isAi)
                <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-violet-500 to-emerald-400 text-white shadow-sm shadow-violet-500/20 ring-1 ring-white/60 dark:ring-violet-300/20" aria-hidden="true">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.091-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.091L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.091 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.091ZM18.25 8.25 18 9.25l-.25-1a2.5 2.5 0 0 0-1.75-1.75L15 6.25l1-.25a2.5 2.5 0 0 0 1.75-1.75l.25-1 .25 1A2.5 2.5 0 0 0 20 6l1 .25-1 .25a2.5 2.5 0 0 0-1.75 1.75Z"/>
                    </svg>
                </span>
                @endif
                <div class="min-w-0">
                    @if($name)
                    <span class="{{ $nameClasses }} block truncate">{{ $name }}</span>
                    @endif
                    @if($subtitle)
                    <span class="block truncate text-[10px] text-gray-400 dark:text-gray-500">{{ $subtitle }}</span>
                    @endif
                </div>
            </div>
            @if($time || $isEdited)
            <div class="shrink-0 text-right leading-none">
                @if($time)
                <span class="block text-[10px] {{ $timeClasses }}">{{ $time }}</span>
                @endif
                @if($isEdited)
                <span class="mt-0.5 block text-[10px] {{ $timeClasses }}">{{ __('messages.edited') }}</span>
                @endif
            </div>
            @endif
        </div>
        @endif

        @if($replyTo)
        <div class="{{ $isSent ? 'bg-indigo-500/40' : 'bg-gray-200 dark:bg-gray-600' }} rounded px-2.5 py-1 mb-1.5 text-xs">
            <span class="font-medium">{{ $replyTo['sender_name'] ?? '' }}</span>
            <p class="truncate {{ $isSent ? 'text-indigo-100' : 'text-gray-500 dark:text-gray-400' }}">{{ $replyTo['body'] }}</p>
        </div>
        @endif

        @if($imagePath)
        <button
            x-on:click="$dispatch('open-image', { url: '{{ $imagePath }}' })"
            class="block mb-1.5 w-full max-w-[200px] rounded-lg overflow-hidden focus:outline-none focus:ring-2 focus:ring-indigo-500"
        >
            <img src="{{ $imagePath }}" alt="{{ __('messages.image_alt') }}" class="w-full h-auto object-cover rounded-lg hover:opacity-90 transition">
        </button>
        @endif

        <div x-ref="copyContent" class="min-w-0 max-w-full cursor-default overflow-hidden whitespace-pre-wrap break-words text-sm [overflow-wrap:anywhere]" style="caret-color: transparent; word-break: break-word;">{!! markdown($renderableBody) !!}</div>

        @if($urlPreview)
            <x-conversation.url-preview-card :preview="$urlPreview" :is-sent="$isSent" />
        @endif

        {{-- TASK-1301 : sources d'une reponse IA, forme publique ecrite cote
             serveur (publicSource, T1297) — l'URL est rendue TELLE QUELLE,
             jamais re-derivee ici. title/dossier_name/excerpt sont du contenu
             de document uploade : rendu ECHAPPE ({{ }}), jamais {!! !!}.
             Presentation reprise du panneau knowledge de loops/show. --}}
        {{-- TASK-1309 : DEUX etats mutuellement exclusifs, jamais confondus —
             les sources qui ont REELLEMENT soutenu une affirmation
             (« Sources utilisées ») ou, a defaut, les documents dont le
             contenu a ete lu sans qu'aucune citation n'en sorte
             (« Sources consultées »). Un document consulte n'est jamais
             presente comme un appui. --}}
        @php
            $shownSources = $sources ?: ($consultedSources ?: null);
            $shownSourcesAreCited = (bool) $sources;
        @endphp
        @if($shownSources)
        <div class="mt-2 border-t border-violet-200/70 dark:border-violet-800/70 pt-2"
             data-message-sources
             data-sources-kind="{{ $shownSourcesAreCited ? 'used' : 'consulted' }}">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ $shownSourcesAreCited ? __('loops.knowledge_sources_title') : __('loops.knowledge_consulted_title') }}</p>
            <ul class="space-y-2">
                @foreach($shownSources as $source)
                <li class="rounded-lg border border-gray-200 dark:border-gray-700 p-2.5 text-xs" data-message-source>
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <span class="font-mono text-[10px] text-sky-700 dark:text-sky-300">[{{ $source['ref'] ?? '' }}]</span>
                            <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $source['title'] ?? '' }}</span>
                            @if(!empty($source['dossier_name']))
                            <span class="text-gray-500 dark:text-gray-400"> · {{ $source['dossier_name'] }}</span>
                            @endif
                        </div>
                        @if(!empty($source['url']))
                        <a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="flex-shrink-0 text-sky-700 dark:text-sky-300 hover:underline">{{ __('loops.knowledge_open_source') }}</a>
                        @endif
                    </div>
                    @if(!empty($source['excerpt']))
                    <p class="mt-1 text-gray-600 dark:text-gray-300 italic">{{ $source['excerpt'] }}</p>
                    @endif
                </li>
                @endforeach
            </ul>
        </div>
        @endif

        @if($messageId && ($showReplyButton || $showPinButton || $showCopyButton || $showEditButton || $showDeleteButton || $showReactions))
        <div class="mt-1 flex items-center justify-end gap-3">
            @if($showReplyButton || $showPinButton || $showCopyButton || $showEditButton || $showDeleteButton)
            <div class="ml-auto flex items-center gap-1">
                @if($showReplyButton)
                <button
                    x-on:click="$dispatch('reply-to-message', { messageId: '{{ $messageId }}' })"
                    class="inline-flex h-6 w-6 items-center justify-center rounded-full transition {{ $isSent ? 'text-indigo-200 hover:bg-white/10 hover:text-white' : 'text-gray-400 hover:bg-white/70 hover:text-indigo-600 dark:hover:bg-gray-800 dark:hover:text-indigo-300' }}"
                    title="{{ __('messages.reply') }}"
                    aria-label="{{ __('messages.reply') }}"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3"/>
                    </svg>
                    <span class="sr-only">{{ __('messages.reply') }}</span>
                </button>
                @endif
                @if($showPinButton)
                    @if($isPinned)
                    <button
                        wire:click="unpinMessage"
                        class="inline-flex h-6 w-6 items-center justify-center rounded-full text-amber-500 transition {{ $isSent ? 'hover:bg-white/10 hover:text-white' : 'hover:bg-amber-50 hover:text-amber-700 dark:hover:bg-amber-900/30 dark:hover:text-amber-300' }}"
                        title="{{ __('messages.unpin') }}"
                        aria-label="{{ __('messages.unpin') }}"
                    >
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m3 3 18 18M8 6.5V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v7l2 3H8.5M12 18v3"/>
                        </svg>
                        <span class="sr-only">{{ __('messages.unpin') }}</span>
                    </button>
                    @else
                    <button
                        wire:click="pinMessage('{{ $messageId }}')"
                        class="inline-flex h-6 w-6 items-center justify-center rounded-full transition {{ $isSent ? 'text-indigo-200 hover:bg-white/10 hover:text-white' : 'text-gray-400 hover:bg-white/70 hover:text-amber-600 dark:hover:bg-gray-800 dark:hover:text-amber-300' }}"
                        title="{{ __('messages.pin') }}"
                        aria-label="{{ __('messages.pin') }}"
                    >
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 6.5V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v7l2 3H6l2-3V6.5M12 18v3"/>
                        </svg>
                        <span class="sr-only">{{ __('messages.pin') }}</span>
                    </button>
                    @endif
                @endif
                @if($showCopyButton)
                <button
                    type="button"
                    x-on:click="copyMessage"
                    class="inline-flex h-6 w-6 items-center justify-center rounded-full transition {{ $isSent ? 'text-indigo-200 hover:bg-white/10 hover:text-white' : 'text-gray-400 hover:bg-white/70 hover:text-emerald-600 dark:hover:bg-gray-800 dark:hover:text-emerald-300' }}"
                    title="{{ __('messages.copy') }}"
                    aria-label="{{ __('messages.copy') }}"
                    x-bind:title="copied ? @js(__('messages.copied')) : @js(__('messages.copy'))"
                    x-bind:aria-label="copied ? @js(__('messages.copied')) : @js(__('messages.copy'))"
                >
                    <svg x-show="!copied" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <rect x="9" y="9" width="11" height="11" rx="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <rect x="4" y="4" width="11" height="11" rx="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <svg x-show="copied" x-cloak class="h-3.5 w-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                    </svg>
                    <span class="sr-only" x-text="copied ? @js(__('messages.copied')) : @js(__('messages.copy'))"></span>
                </button>
                @endif
                @if($showEditButton)
                <button
                    wire:click="editMessage('{{ $messageId }}')"
                    class="inline-flex h-6 w-6 items-center justify-center rounded-full transition {{ $isSent ? 'text-indigo-200 hover:bg-white/10 hover:text-white' : 'text-gray-400 hover:bg-white/70 hover:text-indigo-600 dark:hover:bg-gray-800 dark:hover:text-indigo-300' }}"
                    title="{{ __('messages.edit') }}"
                    aria-label="{{ __('messages.edit') }}"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/>
                    </svg>
                    <span class="sr-only">{{ __('messages.edit') }}</span>
                </button>
                @endif
                @if($showDeleteButton)
                <button
                    type="button"
                    x-on:click="deleteOpen = true"
                    class="inline-flex h-6 w-6 items-center justify-center rounded-full transition {{ $isSent ? 'text-indigo-200 hover:bg-white/10 hover:text-white' : 'text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/20 dark:hover:text-red-400' }}"
                    title="{{ __('messages.delete') }}"
                    aria-label="{{ __('messages.delete') }}"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166M19.228 5.79 18.16 19.673A2.25 2.25 0 0 1 15.916 21H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>
                    </svg>
                    <span class="sr-only">{{ __('messages.delete') }}</span>
                </button>
                @endif
            </div>
            @endif
        </div>
        @endif

        @if(!empty($visibleReactionCounts))
        <div class="flex items-center gap-1 mt-1 flex-wrap {{ $isSent ? 'justify-end' : 'justify-start' }}">
            @foreach($reactionTypes as $type)
                @php $emoji = $reactionEmojis[$type] ?? null; @endphp
                @if($emoji && !empty($visibleReactionCounts[$type]))
                <button
                    wire:click="toggleReaction('{{ $messageId }}', '{{ $type }}')"
                    class="inline-flex items-center gap-0.5 text-[11px] leading-none px-1.5 py-0.5 rounded-full transition
                        {{ $myReaction === $type
                            ? ($isSent ? 'bg-indigo-500/30 ring-1 ring-indigo-300/50' : 'bg-indigo-100 dark:bg-indigo-900/40 ring-1 ring-indigo-300 dark:ring-indigo-600')
                            : ($isSent ? 'bg-indigo-500/20 hover:bg-indigo-500/30' : 'bg-white/70 hover:bg-white dark:bg-gray-800/60 dark:hover:bg-gray-800') }}"
                    title="{{ $type }}"
                >
                    <span class="text-xs leading-none">{{ $emoji }}</span>
                    <span class="{{ $isSent ? 'text-indigo-200' : 'text-gray-500 dark:text-gray-400' }}">{{ $visibleReactionCounts[$type] }}</span>
                </button>
                @endif
            @endforeach
        </div>
        @endif
    </div>

    @if($messageId && $showDeleteButton)
    <template x-teleport="body">
        <div
            x-show="deleteOpen"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center px-4"
            x-on:keydown.escape.window="deleteOpen = false"
        >
            <div class="fixed inset-0 bg-gray-950/50 backdrop-blur-sm" x-on:click="deleteOpen = false"></div>
            <div class="relative w-full max-w-sm rounded-2xl border border-white/70 bg-white p-5 shadow-2xl shadow-gray-950/20 dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600 dark:bg-red-900/20 dark:text-red-300">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166M19.228 5.79 18.16 19.673A2.25 2.25 0 0 1 15.916 21H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-gray-100">{{ __('messages.delete_modal_title') }}</h3>
                        <p class="mt-1 text-sm leading-5 text-gray-500 dark:text-gray-400">{{ __('messages.delete_modal_body') }}</p>
                    </div>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" x-on:click="deleteOpen = false" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                        {{ __('messages.cancel_edit') }}
                    </button>
                    <button type="button" x-on:click="$wire.deleteMessage('{{ $messageId }}'); deleteOpen = false" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-red-700">
                        {{ __('messages.delete') }}
                    </button>
                </div>
            </div>
        </div>
    </template>
    @endif

        @if($showReactions && $messageId)
        <button
            type="button"
            x-on:click.stop="if (open) { open = false; showMore = false } else { $dispatch('reaction-menu-opened', { id }); open = true }"
            x-bind:style="'top: 50%; transform: translateY(-50%); {{ $isSent ? 'left: -1.75rem;' : 'right: -1.75rem;' }} ' + ((hover || open) ? 'opacity: 1;' : 'opacity: 0; pointer-events: none;')"
            x-bind:tabindex="(hover || open) ? 0 : -1"
            x-on:focus="hover = true"
            x-on:blur="if (!open) hover = false"
            class="inline-flex absolute h-6 w-6 items-center justify-center rounded-full bg-white text-gray-400 shadow-sm ring-1 ring-gray-200 transition hover:text-indigo-600 hover:ring-indigo-200 focus:outline-none focus:ring-2 focus:ring-indigo-400 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-700 dark:hover:text-indigo-300"
            aria-label="{{ __('messages.react_to_message') }}"
        >
            <span class="text-lg leading-none">☺</span>
        </button>

        <div
            x-show="open"
            x-cloak
            style="top: 50%; {{ $isSent ? 'left: -1rem; transform: translate(-50%, calc(-100% - 1rem));' : 'right: -1rem; transform: translate(50%, calc(-100% - 1rem));' }}"
            class="absolute z-30 flex items-center gap-1 rounded-full border border-gray-200 bg-white px-2 py-1.5 shadow-lg dark:border-gray-700 dark:bg-gray-800"
        >
            @foreach($primaryReactionTypes as $type)
                @php $emoji = $reactionEmojis[$type] ?? null; @endphp
                @if($emoji)
                <button
                    type="button"
                    wire:click="toggleReaction('{{ $messageId }}', '{{ $type }}')"
                    x-on:click="open = false"
                    class="inline-flex h-7 w-7 items-center justify-center rounded-full text-sm transition hover:scale-110 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-400 dark:hover:bg-gray-700 {{ $myReaction === $type ? 'bg-indigo-100 ring-1 ring-indigo-300 dark:bg-indigo-900/40 dark:ring-indigo-600' : '' }}"
                    title="{{ $type }}"
                    aria-label="{{ __('messages.react_with', ['type' => $type]) }}"
                >
                    {{ $emoji }}
                </button>
                @endif
            @endforeach
            @if(!empty($secondaryReactionTypes))
            <button
                type="button"
                x-on:click.stop="showMore = !showMore"
                class="inline-flex h-7 w-7 items-center justify-center rounded-full text-base text-gray-900 transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-400 dark:text-white dark:hover:bg-gray-700"
                aria-label="{{ __('messages.more_reactions') }}"
            >
                ›
            </button>
            @endif
            @foreach($secondaryReactionTypes as $type)
                @php $emoji = $reactionEmojis[$type] ?? null; @endphp
                @if($emoji)
                <button
                    x-show="showMore"
                    type="button"
                    wire:click="toggleReaction('{{ $messageId }}', '{{ $type }}')"
                    x-on:click="open = false; showMore = false"
                    class="inline-flex h-7 w-7 items-center justify-center rounded-full text-sm transition hover:scale-110 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-400 dark:hover:bg-gray-700 {{ $myReaction === $type ? 'bg-indigo-100 ring-1 ring-indigo-300 dark:bg-indigo-900/40 dark:ring-indigo-600' : '' }}"
                    title="{{ $type }}"
                    aria-label="{{ __('messages.react_with', ['type' => $type]) }}"
                >
                    {{ $emoji }}
                </button>
                @endif
            @endforeach
        </div>
        @endif
    </div>
</div>
