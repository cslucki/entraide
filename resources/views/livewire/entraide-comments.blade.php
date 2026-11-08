{{-- TASK-1786 — « Commentaires et suggestions » sous une annonce d'entraide.

     Forme alignee sur le fil de commentaires du blog (`livewire/blog-comments`),
     comme le CDC §2 le demande : des SEPARATEURS plutot que des bulles, l'avatar
     a gauche, le nom et l'instant sur une ligne, les actions en dessous en petit
     et gris.

     V1 volontairement plus simple que le blog : AUCUNE reponse imbriquee, aucune
     piece jointe, aucun editeur riche. Le CDC le dit explicitement.

     `wire:poll.visible.15s` : B voit le commentaire que A vient d'ecrire sans
     rafraichir. `visible` pour ne pas interroger un onglet d'arriere-plan, et
     15 s comme le blog — une annonce n'est pas une conversation. --}}
<div id="commentaires" class="scroll-mt-24 mt-8" wire:poll.visible.15s data-entraide-comments>

    <div class="flex items-baseline justify-between border-t border-gray-200 dark:border-gray-700 pt-6 mb-2">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
            {{ __('entraide.commentaires.titre') }}
        </h2>
        <span class="text-xs text-gray-400" data-entraide-comments-total>
            {{ trans_choice('entraide.commentaires.total', $total, ['count' => $total]) }}
        </span>
    </div>

    {{-- Le message d'accompagnement du CDC §2, adapte au type d'annonce. --}}
    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400" data-entraide-comments-invite>
        {{ $estDemande ? __('entraide.commentaires.invite_demande') : __('entraide.commentaires.invite_proposition') }}
    </p>

    @if($confirmation !== '')
    {{-- Le message de confirmation du CDC §2. Une propriete, pas un flash : avec
         `wire:poll`, un flash serait consomme par le premier rafraichissement. --}}
    <p class="mb-4 rounded-xl border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/30 dark:text-green-200"
       data-entraide-comment-confirmation>
        {{ $confirmation }}
        @if($dernierPublie !== null)
        {{-- Une cle DEDIEE, et non le titre de section : « Votre commentaire est
             publie. Commentaires et suggestions » se lisait mal — un libelle de
             lien doit dire ou il mene. Defaut vu a l'ecran. --}}
        <a href="#commentaire-{{ $dernierPublie }}" class="underline hover:no-underline" data-entraide-comment-goto="{{ $dernierPublie }}">
            {{ __('entraide.commentaires.voir_mon_commentaire') }}
        </a>
        @endif
    </p>
    @endif

    @if($ouverte)
    <div class="flex gap-3 mb-6" data-entraide-comment-compose>
        <img src="{{ auth()->user()->avatar_url }}" alt="" class="hidden sm:block w-9 h-9 rounded-full flex-shrink-0">
        <div class="flex-1 min-w-0">
            <textarea wire:model="content" rows="2" data-entraide-comment-input
                      maxlength="{{ \App\Models\EntraideComment::CONTENU_MAX }}"
                      placeholder="{{ __('entraide.commentaires.placeholder') }}"
                      aria-label="{{ __('entraide.commentaires.placeholder') }}"
                      class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm resize-y"></textarea>
            @error('content')
            <p class="mt-1 text-xs text-red-500" data-entraide-comment-error>{{ $message }}</p>
            @enderror
            <div class="mt-2 flex justify-end">
                <button type="button" wire:click="addComment" wire:loading.attr="disabled" data-entraide-comment-submit
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-semibold rounded-full transition">
                    {{ __('entraide.commentaires.publier') }}
                </button>
            </div>
        </div>
    </div>
    @else
    {{-- L'annonce ne prend plus de commentaires. Les anciens restent LISIBLES —
         decision explicite du CDC §4 — et on dit POURQUOI le formulaire a
         disparu, plutot que de le faire disparaitre en silence. --}}
    <p class="mb-6 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400"
       data-entraide-comments-closed>
        {{ $estDemande ? __('entraide.commentaires.fermee_demande') : __('entraide.commentaires.fermee_proposition') }}
    </p>
    @endif

    @if($commentaires->isEmpty())
    <p class="py-8 text-center text-sm text-gray-400" data-entraide-comments-empty>{{ __('entraide.commentaires.vide') }}</p>
    @else
    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
        @foreach($commentaires as $commentaire)
        {{-- `id` sur CHAQUE commentaire : c'est la cible de l'ancre que le
             resolver de notification construit (`#commentaire-<uuid>`), et le
             CDC §7 demande « un lien d'ancrage vers le commentaire concerne »
             et non vers la section. `scroll-mt-24` evite que l'en-tete collante
             recouvre le commentaire vise. --}}
        <li class="py-4 scroll-mt-24" id="commentaire-{{ $commentaire->id }}"
            wire:key="entraide-commentaire-{{ $commentaire->id }}"
            data-entraide-comment="{{ $commentaire->id }}">

            <div class="flex gap-3">
                @if($commentaire->user?->isDisplayableIn(currentOrganization()))
                <img src="{{ $commentaire->user->avatar_url }}" alt="" class="w-9 h-9 rounded-full flex-shrink-0">
                @endif
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                            {{ $commentaire->user?->publicDisplayName() ?? __('profile.deactivated_user') }}
                        </span>
                        {{-- `datetime` lisible par une machine, `diffForHumans`
                             pour l'oeil : la date est une information du CDC §2. --}}
                        <time datetime="{{ $commentaire->created_at->toIso8601String() }}" class="text-xs text-gray-400">
                            · {{ $commentaire->created_at->diffForHumans() }}
                        </time>
                    </div>

                    {{-- `{{ }}` et non `{!! !!}` : le texte d'un membre est
                         ECHAPPE par Blade, et c'est la protection XSS. Aucun
                         HTML n'est admis — le CDC exclut l'editeur riche de
                         cette V1, donc il n'y a rien a assainir. --}}
                    <p class="mt-0.5 text-sm text-gray-700 dark:text-gray-300 whitespace-pre-line break-words"
                       data-entraide-comment-body>{{ $commentaire->content }}</p>

                    @if(auth()->id() === $commentaire->user_id || auth()->user()->is_admin)
                    <div class="mt-1.5">
                        <button type="button" wire:click="deleteComment('{{ $commentaire->id }}')"
                                wire:confirm="{{ __('entraide.commentaires.confirmer_suppression') }}"
                                data-entraide-comment-delete="{{ $commentaire->id }}"
                                class="text-xs font-medium text-gray-400 hover:text-red-600 transition">
                            {{ __('entraide.commentaires.supprimer') }}
                        </button>
                    </div>
                    @endif
                </div>
            </div>
        </li>
        @endforeach
    </ul>
    @endif
</div>
