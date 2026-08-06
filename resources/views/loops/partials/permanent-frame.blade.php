{{--
    Le cadre permanent : Manifeste et Membres.

    Ils sont dans toutes les Boucles, donc ils ne distinguent rien — les laisser
    dans la grille des outils revenait a repeter la meme chose partout et a noyer
    les trois Cards qui disent vraiment ce qu'on fait ici (TASK-1090).

    Ils restent declares dans le registre, gardes par leurs permissions et
    comptes par l'administration. Seule leur place a l'ecran change : un acces
    compact, qui ouvre le meme panneau qu'avant.
--}}
@php($frame = collect($frameCards ?? []))

@if($frame->isNotEmpty())
    <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
        @foreach($frame as $card)
            @if($card['key'] === 'core.members')
                {{-- Membres : les visages d'abord. Savoir qui est la se lit d'un
                     coup d'oeil ; la liste complete est a un clic. --}}
                <button type="button"
                        x-on:click="openCard(@js($card['key']))"
                        x-bind:aria-pressed="activeCard === @js($card['key'])"
                        x-bind:class="activeCard === @js($card['key']) ? 'border-violet-300 bg-violet-50 dark:border-violet-600/70 dark:bg-violet-900/20' : 'border-gray-200 bg-white hover:border-violet-200 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-violet-700'"
                        class="inline-flex items-center gap-1.5 rounded-full border py-0.5 pl-1 pr-2 transition"
                        :title="@js(__($card['label_key']))">
                    <span class="flex -space-x-1.5">
                        @foreach($loopMembers->take(3) as $frameMember)
                            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full border border-white bg-violet-100 text-[9px] font-bold text-violet-700 dark:border-gray-800 dark:bg-violet-900/40 dark:text-violet-200">
                                {{ mb_strtoupper(mb_substr($frameMember->user?->publicDisplayName() ?? '?', 0, 1)) }}
                            </span>
                        @endforeach
                    </span>
                    <span class="text-[11px] font-semibold text-gray-600 dark:text-gray-300">{{ $loopMembers->count() }}</span>
                </button>
            @else
                {{-- Manifeste, et tout autre element du cadre : une pastille
                     nommee, discrete. --}}
                <button type="button"
                        x-on:click="openCard(@js($card['key']))"
                        x-bind:aria-pressed="activeCard === @js($card['key'])"
                        x-bind:class="activeCard === @js($card['key']) ? 'border-violet-300 bg-violet-50 text-violet-800 dark:border-violet-600/70 dark:bg-violet-900/20 dark:text-violet-200' : 'border-gray-200 bg-white text-gray-600 hover:border-violet-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-violet-700'"
                        class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold transition">
                    <svg class="h-3 w-3 text-sky-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-6a2.25 2.25 0 0 0-.659-1.591l-3-3A2.25 2.25 0 0 0 14.25 3H6.75A2.25 2.25 0 0 0 4.5 5.25v13.5A2.25 2.25 0 0 0 6.75 21h10.5a2.25 2.25 0 0 0 2.25-2.25v-4.5z"/></svg>
                    {{ __($card['label_key']) }}
                </button>
            @endif
        @endforeach
    </div>
@endif
