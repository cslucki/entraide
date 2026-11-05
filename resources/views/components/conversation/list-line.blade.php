@props(['conv', 'href', 'actif' => false, 'nonLus' => 0])

{{--
    TASK-1755 §5.2 (maquette M3) — UNE ligne de la liste des conversations.

    ## Pourquoi un composant

    Ce balisage existait en DEUX exemplaires, byte pour byte : le volet mobile
    (`md:hidden`) et le volet bureau (`hidden md:flex`). Toute correction devait
    donc etre faite deux fois, et c'est le genre de duplication qui finit par
    diverger en silence — on corrige celle qu'on voit.

    ## Et c'est le terrain du regroupement par personne

    Arbitrage Q4 de Cyril : le regroupement par interlocuteur est souhaite A
    TERME. Une liste groupee rendra exactement CE composant, simplement place
    sous un en-tete de personne. En laissant le balisage dans les deux vues, ce
    chantier aurait demande de tout reprendre.

    ## Ce que la ligne dit maintenant

    Avant : un pseudo, un badge, un titre d'annonce tronque. Cinq conversations
    avec la meme personne etaient indistinguables sans les ouvrir — constat de
    recette du 04/11.

    Desormais la ligne porte le SENS DE L'AIDE, avec son emoji et sa couleur :
    🤝 on vous demande (ambre) · 👀 vous aidez (vert) · 💬 discussion · ✓
    terminee. Le sens se lit AVANT le texte.
--}}
@php
    $lecteur = auth()->user();
    $autre = $conv->estLAide($lecteur) ? $conv->seller : $conv->buyer;
    $autreAffichable = $autre?->isDisplayableIn(currentOrganization()) ?? false;
    $dernier = $conv->messages->first();
    $sens = \App\Support\Entraide\CarteEntraide::sens($conv, $lecteur);
    $gras = $nonLus > 0 && ! $actif;
@endphp
<a href="{{ $href }}"
   data-conversation-ligne="{{ $conv->id }}"
   class="flex items-start gap-3 p-4 hover:bg-gray-50 dark:hover:bg-gray-700 border-b border-gray-100 dark:border-gray-700 transition {{ $actif ? 'bg-indigo-50 dark:bg-indigo-900/30' : '' }}">
    <div class="relative flex-shrink-0">
        @if($autreAffichable)
            <img src="{{ $autre->avatar_url }}" class="w-10 h-10 rounded-full" alt="">
        @else
            <span class="flex w-10 h-10 items-center justify-center rounded-full bg-gray-200 text-gray-500 dark:bg-gray-700 dark:text-gray-300">?</span>
        @endif
        @if($nonLus > 0 && ! $actif)
            {{-- TASK-1693 bug V — la valeur BRUTE dans le marqueur, pour qu'un
                 sabotage de ce compteur ne puisse pas rester vert. --}}
            <span data-thread-unread="{{ $conv->id }}:{{ $nonLus }}"
                  class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center">
                {{ $nonLus > 9 ? '9+' : $nonLus }}
            </span>
        @endif
    </div>

    <div class="min-w-0 flex-1">
        <div class="flex items-center justify-between gap-1">
            <p class="font-medium text-sm text-gray-900 dark:text-gray-100 truncate {{ $gras ? 'font-bold' : '' }}">
                {{ $autre?->publicDisplayName() ?? __('profile.deactivated_user') }}
            </p>
            @unless($conv->isDirectConversation())
                <span class="text-xs px-1.5 py-0.5 rounded-full flex-shrink-0
                    {{ match($conv->status) {
                        'pending' => 'bg-orange-100 text-orange-700',
                        'accepted' => 'bg-blue-100 text-blue-700',
                        'buyer_done' => 'bg-purple-100 text-purple-700',
                        'completed' => 'bg-green-100 text-green-700',
                        default => 'bg-gray-100 text-gray-600',
                    } }}">
                    {{ __('messages.status.' . $conv->status) }}
                </span>
            @endunless
        </div>

        {{-- LE SENS DE L'AIDE — la ligne qui manquait. --}}
        <p class="truncate text-xs mt-0.5 {{ $sens['classe'] }}" data-conversation-sens>
            <span aria-hidden="true">{{ $sens['emoji'] }}</span> {{ $sens['texte'] }}
        </p>

        @unless($conv->isDirectConversation())
            <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $conv->subject }}</p>
        @endunless

        @if($dernier)
            <p class="text-xs truncate mt-0.5 {{ $gras ? 'text-gray-700 dark:text-gray-300 font-medium' : 'text-gray-400' }}">
                {{ Str::limit(\App\Support\Entraide\MessageSysteme::rendre($dernier), 40) }}
            </p>
        @endif
    </div>
</a>
