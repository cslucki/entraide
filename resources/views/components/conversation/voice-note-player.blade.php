@props([
    'id' => '',
    'src' => null,
    'duration' => null,
    'peaks' => null,
    'expired' => false,
    'expiresIn' => null,
    'isSent' => false,
])

{{-- ========================================================================
     TASK-1886 — LE LECTEUR D'UN MESSAGE VOCAL, facon WhatsApp.
     ========================================================================
     Une seule chose y est appuyee : la FORME de la voix. Elle est mesuree a
     l'enregistrement et voyage avec le message (`metadata.voice_waveform`) —
     chaque bulle montre la silhouette reelle de ce qui a ete dit, et se
     colore a mesure qu'on l'ecoute. Le reste se tait : pas d'avatar (la
     bulle dit deja qui parle), la vitesse n'apparait qu'une fois la lecture
     lancee.

     `preload="none"` : rien n'est telecharge avant l'appui sur lecture. Un
     fil de vingt vocaux ne coute pas un octet du forfait de celui qui le
     parcourt. La duree affichee vient du serveur (voir `voice-notes.js`).

     La source est la route GARDEE des pieces jointes (meme policy que le
     fil) ; elle refuse le vocal des son echeance. --}}

@php
    $joue = $isSent ? 'bg-white' : 'bg-[color:var(--bp-primary)]';
    $reste = $isSent ? 'bg-white/40' : 'bg-gray-400/60 dark:bg-gray-400/50';
    $secondaire = $isSent ? 'text-indigo-100' : 'text-gray-500 dark:text-gray-400';
@endphp

@if($expired || ! $src)
    <div data-message-voice-expired class="flex items-center gap-2 py-1 pr-1 {{ $secondaire }}">
        <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full {{ $isSent ? 'bg-white/15' : 'bg-gray-200 dark:bg-gray-600' }}">
            {{-- Micro barre : le vocal a existe, il n'est plus la. --}}
            <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 9v2a3 3 0 0 0 5.1 2.1M15 10V6a3 3 0 0 0-5.7-1.3M19 11a7 7 0 0 1-1.1 3.8M5 11a7 7 0 0 0 11.1 5.7M12 18v3M3 3l18 18"/>
            </svg>
        </span>
        <span class="text-sm italic">{{ __('messages.voice_expired') }}</span>
    </div>
@else
    <div data-message-voice
         x-data="voiceNotePlayer({ id: @js((string) $id), duration: @js($duration), peaks: @js($peaks) })"
         x-on:bp-vocal-lecture.window="autreLecture($event.detail)"
         class="flex w-[15rem] items-center gap-2.5 py-0.5 sm:w-[17rem]">
        <audio x-ref="audio" preload="none" src="{{ $src }}"></audio>

        <button type="button"
                x-on:click="basculer()"
                data-voice-toggle
                x-bind:aria-label="lecture ? @js(__('messages.voice_pause')) : @js(__('messages.voice_play'))"
                class="relative flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full shadow-sm transition active:scale-95 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 {{ $isSent ? 'bg-white text-indigo-600 focus-visible:ring-white focus-visible:ring-offset-indigo-600' : 'bg-[color:var(--bp-primary)] text-white focus-visible:ring-[color:var(--bp-primary)] focus-visible:ring-offset-gray-100 dark:focus-visible:ring-offset-gray-700' }}">
            {{-- Lecture : le triangle est decale d'un pixel a droite, sinon
                 son centre optique parait tire vers la gauche. --}}
            <svg x-show="! lecture && ! chargement" class="ml-0.5 h-[18px] w-[18px]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M7 4.9v14.2a1 1 0 0 0 1.5.86l11.6-7.1a1 1 0 0 0 0-1.72L8.5 4.04A1 1 0 0 0 7 4.9Z"/>
            </svg>
            <svg x-show="lecture && ! chargement" x-cloak class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <rect x="6" y="4.5" width="4" height="15" rx="1.3"/><rect x="14" y="4.5" width="4" height="15" rx="1.3"/>
            </svg>
            <svg x-show="chargement" x-cloak class="h-5 w-5 motion-safe:animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="2.5"/>
                <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
        </button>

        <div class="min-w-0 flex-1">
            {{-- L'onde EST le curseur : on la touche pour se deplacer, on la
                 parcourt aux fleches au clavier. --}}
            <div role="slider"
                 tabindex="0"
                 data-voice-wave
                 aria-label="{{ __('messages.voice_position') }}"
                 aria-valuemin="0"
                 x-bind:aria-valuemax="Math.round(duree)"
                 x-bind:aria-valuenow="Math.round(position)"
                 x-bind:aria-valuetext="compteur"
                 x-on:pointerdown="glisse = true; $el.setPointerCapture?.($event.pointerId); chercher($event)"
                 x-on:pointermove="glisse && chercher($event)"
                 x-on:pointerup="glisse = false"
                 x-on:pointercancel="glisse = false"
                 x-on:keydown="clavier($event)"
                 class="relative flex h-8 cursor-pointer touch-none select-none items-center gap-[2px] rounded focus:outline-none focus-visible:ring-2 {{ $isSent ? 'focus-visible:ring-white/70' : 'focus-visible:ring-[color:var(--bp-primary)]' }}">
                <template x-for="(hauteur, index) in barres" :key="index">
                    <span class="block min-w-[2px] flex-1 rounded-full transition-colors duration-150"
                          x-bind:class="barreJouee(index) ? @js($joue) : @js($reste)"
                          x-bind:style="`height: ${Math.max(14, hauteur)}%`"></span>
                </template>
                {{-- Le pouce de WhatsApp : il dit OU l'on en est, meme quand
                     l'onde est presque plate. --}}
                <span aria-hidden="true"
                      class="pointer-events-none absolute top-1/2 h-3 w-3 -translate-x-1/2 -translate-y-1/2 rounded-full shadow ring-2 {{ $isSent ? 'bg-white ring-indigo-600' : 'bg-[color:var(--bp-primary)] ring-gray-100 dark:ring-gray-700' }}"
                      x-bind:style="`left: ${ratio * 100}%`"></span>
            </div>

            <div class="mt-0.5 flex items-center justify-between gap-2 text-[11px] leading-4 {{ $secondaire }}">
                <span class="tabular-nums" x-text="compteur">{{ $duration !== null ? intdiv((int) $duration, 60).':'.str_pad((string) ((int) $duration % 60), 2, '0', STR_PAD_LEFT) : '' }}</span>
                @if($expiresIn)
                    <span data-voice-expires class="truncate">{{ $expiresIn }}</span>
                @endif
            </div>
        </div>

        {{-- La vitesse ne s'invite qu'une fois l'ecoute commencee : avant,
             elle n'est qu'une option de plus a lire. --}}
        <button type="button"
                x-show="dejaLance"
                x-cloak
                x-on:click="changerVitesse()"
                x-text="vitesseLisible"
                data-voice-speed
                aria-label="{{ __('messages.voice_speed') }}"
                class="flex h-6 min-w-[2.6rem] flex-shrink-0 items-center justify-center rounded-full px-1.5 text-[11px] font-semibold tabular-nums transition focus:outline-none focus-visible:ring-2 {{ $isSent ? 'bg-white/20 text-white hover:bg-white/30 focus-visible:ring-white/70' : 'bg-gray-300/70 text-gray-700 hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-100 focus-visible:ring-[color:var(--bp-primary)]' }}"></button>
    </div>
@endif
