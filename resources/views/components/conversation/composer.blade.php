@props([
    'model' => 'message',
    'placeholder' => null,
    'disabled' => false,
    'loading' => false,
    'error' => null,
    'rows' => 1,
    'replyingTo' => null,
    'onCancelReply' => null,
    'showUpload' => false,
    'photo' => null,
    // TASK-1308 : moteur du prochain tour ('ia'|'dossiers'|null), affiche en
    // chip compact au-dessus du composeur — jamais un verrou (voir $onClearMode).
    // TASK-1309 : quatrieme etat 'ia_dossiers' (IA + Dossiers).
    'mode' => null,
    'modeLabel' => null,
    'onClearMode' => null,
])

@php
    $placeholder ??= __('messages.write_message');
@endphp

{{-- Le gabarit compact mobile est SCOPE au ChatLoop (seul appelant qui
     fournit `$leading`) : Message Thread et le chat agent gardent leur
     gabarit historique a l'identique. --}}
<div class="border-t border-gray-200 dark:border-gray-700 {{ isset($leading) ? 'px-3 py-2.5 md:px-5 md:py-4' : 'px-5 py-4' }}">
    @if($replyingTo)
    <div class="flex items-center justify-between mb-2 px-3 py-1.5 bg-indigo-50 dark:bg-indigo-900/30 rounded-lg text-xs">
        <span class="text-gray-600 dark:text-gray-300 truncate">
            <span class="font-medium text-indigo-600 dark:text-indigo-400">{{ __('messages.reply_to') }}</span>
            <span class="text-gray-500 dark:text-gray-400">{{ $replyingTo['sender_name'] ?? '' }} :</span>
            <span class="text-gray-400 dark:text-gray-500 truncate ml-1">{{ $replyingTo['body'] }}</span>
        </span>
        @if($onCancelReply)
        <button
            wire:click="{{ $onCancelReply }}"
            class="flex-shrink-0 ml-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition"
        >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
        @endif
    </div>
    @endif

    {{-- TASK-1308 : chip compacte du moteur choisi — sections 30/31/37.
         `$onClearMode` ramene le composeur en mode normal ; le mode n'est
         jamais un verrou, seulement un DEFAUT visible. --}}
    @if($mode)
    <div class="flex items-center gap-2 mb-2">
        <span data-composer-mode="{{ $mode }}" class="inline-flex items-center gap-1.5 rounded-full {{ match ($mode) {
                'dossiers' => 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-200',
                'ia_dossiers' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200',
                default => 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-200',
            } }} px-2.5 py-1 text-xs font-semibold">
            {{ $modeLabel }}
            @if($onClearMode)
            <button type="button" wire:click="{{ $onClearMode }}" class="ml-0.5 -mr-1 inline-flex h-4 w-4 items-center justify-center rounded-full text-current transition hover:bg-black/10 dark:hover:bg-white/10" aria-label="{{ __('loops.composer_mode_clear') }}">
                <span aria-hidden="true">×</span>
            </button>
            @endif
        </span>
    </div>
    @endif

    @if($showUpload && $photo)
    <div class="flex items-center gap-2 mb-2 px-3 py-2 bg-gray-50 dark:bg-gray-900 rounded-lg">
        <img src="{{ $photo->temporaryUrl() }}" class="w-10 h-10 rounded object-cover flex-shrink-0" alt="{{ __('messages.image_preview') }}">
        <span class="text-xs text-gray-500 dark:text-gray-400 truncate flex-1">{{ $photo->getClientOriginalName() }}</span>
        <button
            wire:click="removePhoto"
            class="flex-shrink-0 text-gray-400 hover:text-red-500 transition"
        >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>
    @endif

    {{-- Racine Alpine du composeur : elle permet au menu mobile (`$leading`,
         TASK-1308) de declencher le selecteur de fichier via `$refs`, et porte
         `hasText` (TASK-1329) — la cinematique du bouton envoyer, gris et en
         retrait tant que le champ est vide, indigo et a pleine taille des le
         premier caractere (motif des messageries mobiles). Etat VISUEL
         seulement : le bouton reste cliquable a vide (une photo seule
         s'envoie), le serveur valide. --}}
    <form wire:submit="sendMessage" class="flex items-end {{ isset($leading) ? 'gap-2 md:gap-3' : 'gap-3' }}" x-data="{ hasText: false }">
        @if($showUpload)
        <label class="{{ isset($leading) ? 'hidden md:flex' : 'flex' }} mb-1 flex-shrink-0 w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-500 dark:text-gray-400 items-center justify-center cursor-pointer transition disabled:opacity-50">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            <input type="file" x-ref="uploadInput" wire:model.live="photo" accept="image/*" class="hidden">
        </label>
        {{-- TASK-1329 : second declencheur du MEME upload (`photo`), avec
             `capture` — sur mobile, il ouvre directement l'appareil photo au
             lieu de la galerie. Aucun pipeline nouveau : le fichier suit
             exactement le meme chemin que la galerie. Inerte tant qu'aucun
             bouton ne l'invoque (`$refs.cameraInput`, menu mobile T1308). --}}
        <input type="file" x-ref="cameraInput" wire:model.live="photo" accept="image/*" capture="environment" class="hidden">
        @endif

        {{-- TASK-1329 : le declencheur du menu mobile (`$leading`, le « + »)
             vit DANS le cadre du champ, plus a cote de lui — un bouton rond
             externe consommait ~3rem de largeur sur un ecran qui n'en a pas
             (motif des messageries mobiles). `items-end` + `pb` : il suit le
             BAS du champ quand le textarea grandit, comme le bouton envoyer.
             Le textarea DOIT etre `block` : en inline-block (defaut), le
             wrapper garde ~4px de descendante sous la ligne de base, le champ
             remonte d'autant et le bouton envoyer comme le « + » paraissent
             cales trop bas. --}}
        <div class="relative min-w-0 flex-1">
            @isset($leading)
            <div class="absolute bottom-1.5 left-1.5 z-10 flex md:hidden">
                {{ $leading }}
            </div>
            @endisset
            {{-- TASK-1329 : `wire:ignore.self` — le morph du `wire:poll`
                 realignait les attributs sur le HTML serveur et EFFACAIT la
                 hauteur `style` posee par `resize()` (piege connu du depot,
                 meme motif que le bloc sources T1312) : un brouillon de trois
                 lignes retombait visuellement a une seule au poll suivant,
                 constate en recette mobile. Le serveur n'ecrit JAMAIS de
                 style sur ce champ, geler ses attributs est sans perte ; la
                 saisie et le reset apres envoi passent par `wire:model`,
                 un canal distinct du morph d'attributs. --}}
            <textarea
                wire:ignore.self
                x-data="{ resize() { $el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 176) + 'px'; $el.scrollTop = $el.scrollHeight } }"
                x-init="resize(); hasText = $el.value.trim().length > 0"
                x-on:input="resize(); hasText = $el.value.trim().length > 0"
                x-on:message-sent.window="$nextTick(() => { resize(); hasText = $el.value.trim().length > 0 })"
                wire:model="{{ $model }}"
                rows="{{ $rows }}"
                @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $wire.sendMessage() }"
                class="block max-h-44 min-h-11 w-full resize-none overflow-y-hidden rounded-2xl border border-gray-300 bg-white px-4 py-3 {{ isset($leading) ? 'pl-11 md:pl-4' : '' }} text-sm text-gray-900 placeholder-gray-400 shadow-sm transition [scrollbar-width:none] focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 [&::-webkit-scrollbar]:hidden"
                placeholder="{{ $placeholder }}"
                @if($disabled || $loading) disabled @endif
            ></textarea>
        </div>

        {{-- TASK-1329 : `mb-1` cale le bouton (36px) sur l'axe du champ
             (44px min) — il etait pose sur la ligne de base du flex, en
             dessous du cadre. La couleur et l'echelle suivent CAN_SEND —
             texte non vide OU piece jointe prete — jamais le texte seul :
             une photo sans legende est un envoi valide, le bouton ne doit
             pas paraitre eteint ({{ '$photo' }} est re-rendu par le morph a
             chaque upload, l'expression suit). `active:scale-90` donne le
             pop tactile a l'envoi. Etat VISUEL seulement : le bouton reste
             cliquable, le serveur valide. --}}
        <button
            type="submit"
            class="mb-1 flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full transition-all duration-200 ease-out active:scale-90 disabled:opacity-50"
            {{-- TASK-1365 : la couleur active vient du THEME (--bp-primary, « Action
                 principale » de /admin/themes) et non plus d'un indigo en dur. Le Shell
                 utilise exactement le meme token : les deux composers changent ensemble
                 quand le locataire change de theme.
                 L'ECHELLE, l'etat inactif et le contrat CAN_SEND de T1329 sont INCHANGES —
                 seule la teinte active bouge. Le halo `shadow-indigo-500/30` disparait avec
                 l'indigo : une ombre teintee en dur aurait survecu au changement de theme. --}}
            x-bind:class="(hasText || {{ ($showUpload && $photo) ? 'true' : 'false' }})
                ? 'scale-100 text-white shadow-sm hover:opacity-90'
                : 'scale-95 bg-gray-200 text-gray-400 dark:bg-gray-700 dark:text-gray-500'"
            x-bind:style="(hasText || {{ ($showUpload && $photo) ? 'true' : 'false' }})
                ? 'background-color: var(--bp-primary)'
                : ''"
            @if($disabled || $loading) disabled @endif
        >
            @if($loading)
                <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            @else
                <svg class="w-4 h-4 rotate-45" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19V5m0 0l-7 7m7-7l7 7"/>
                </svg>
            @endif
        </button>
    </form>

    @if($error)
        <p class="text-xs text-red-500 mt-2">{{ $error }}</p>
    @endif

    @if($slot)
        {{ $slot }}
    @endif
</div>
