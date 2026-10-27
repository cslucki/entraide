{{-- TASK-1697 — le fil de commentaires d'un article.

     Forme retenue : Twitter (reference donnee par Cyril). Donc des
     SEPARATEURS plutot que des bulles, l'avatar a gauche, le nom et l'instant
     sur une ligne, les actions en dessous en petit et gris, et UNE seule
     profondeur de reponses. Pleine largeur sur mobile, retrait modeste.

     `wire:poll.visible.15s` : Y voit le commentaire de X sans rafraichir.
     `visible` pour ne pas interroger un onglet d'arriere-plan. 15 s et non 3 s
     comme `loop-chat` : un article n'est pas une conversation. --}}
<div id="fil-commentaires" class="scroll-mt-24" wire:poll.visible.15s>

    <div class="flex items-baseline justify-between border-t border-gray-200 dark:border-gray-700 pt-6 mb-4">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
            {{ __('blog.comments_heading') }}
        </h2>
        <span class="text-xs text-gray-400" data-comments-total>
            {{ trans_choice('blog.comments_total', $total, ['count' => $total]) }}
        </span>
    </div>

    @auth
    {{-- La zone de saisie, TOUJOURS visible : c'est la l'essentiel du
         correctif. Elle vivait derriere le picto. --}}
    <div class="flex gap-3 mb-6" data-comment-compose>
        <img src="{{ auth()->user()->avatar_url }}" alt="" class="hidden sm:block w-9 h-9 rounded-full flex-shrink-0">
        <div class="flex-1 min-w-0">
            <textarea wire:model="content" rows="2" data-comment-input
                      placeholder="{{ __('blog.placeholder_comment') }}"
                      class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm resize-y"></textarea>
            @error('content')
            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
            @enderror
            <div class="mt-2 flex justify-end">
                <button type="button" wire:click="addComment" wire:loading.attr="disabled" data-comment-submit
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-semibold rounded-full transition">
                    {{ __('blog.btn_add_comment') }}
                </button>
            </div>
        </div>
    </div>
    {{-- Pas d'invite de connexion ici : `blog/show.blade.php` en porte deja
         une juste sous le fil (`blog.guest_prompt` — « se connecter pour
         commenter et reagir »). Deux invites a trois lignes d'intervalle
         seraient du bruit. --}}
    @endauth

    @if($commentaires->isEmpty())
    <p class="py-8 text-center text-sm text-gray-400" data-comments-empty>{{ __('blog.comments_empty') }}</p>
    @else
    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
        @foreach($commentaires as $commentaire)
        <li class="py-4" wire:key="commentaire-{{ $commentaire->id }}" data-comment="{{ $commentaire->id }}">

            <div class="flex gap-3">
                @if($commentaire->user?->isDisplayableIn(currentOrganization()))
                <img src="{{ $commentaire->user->avatar_url }}" alt="" class="w-9 h-9 rounded-full flex-shrink-0">
                @endif
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                            {{ $commentaire->user?->publicDisplayName() ?? __('profile.deactivated_user') }}
                        </span>
                        <span class="text-xs text-gray-400">· {{ $commentaire->created_at->diffForHumans() }}</span>
                    </div>
                    <p class="mt-0.5 text-sm text-gray-700 dark:text-gray-300 whitespace-pre-line break-words">{{ $commentaire->content }}</p>

                    <div class="mt-1.5 flex items-center gap-4">
                        @auth
                        <button type="button" wire:click="startReply('{{ $commentaire->id }}')"
                                data-comment-reply="{{ $commentaire->id }}"
                                class="text-xs font-medium text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                            {{ __('blog.btn_reply') }}
                        </button>
                        @if(auth()->id() === $commentaire->user_id || auth()->user()->is_admin)
                        <button type="button" wire:click="deleteComment('{{ $commentaire->id }}')"
                                wire:confirm="{{ __('blog.confirm_delete_comment') }}"
                                data-comment-delete="{{ $commentaire->id }}"
                                class="text-xs font-medium text-gray-400 hover:text-red-600 transition">
                            {{ __('blog.delete_post') }}
                        </button>
                        @endif
                        @endauth
                    </div>

                    {{-- Le champ de reponse, EN LIGNE sous le commentaire
                         vise — et un seul a la fois, `$replyTo` ne portant
                         qu'un identifiant. --}}
                    @auth
                    @if($replyTo === (string) $commentaire->id)
                    <div class="mt-3" data-reply-compose="{{ $commentaire->id }}">
                        <textarea wire:model="replyContent" rows="2" autofocus
                                  placeholder="{{ __('blog.placeholder_reply') }}"
                                  class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm resize-y"></textarea>
                        @error('replyContent')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                        <div class="mt-2 flex items-center justify-end gap-3">
                            <button type="button" wire:click="cancelReply" class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                                {{ __('blog.btn_cancel_reply') }}
                            </button>
                            <button type="button" wire:click="addReply" wire:loading.attr="disabled" data-reply-submit
                                    class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-xs font-semibold rounded-full transition">
                                {{ __('blog.btn_reply') }}
                            </button>
                        </div>
                    </div>
                    @endif
                    @endauth

                    {{-- Les reponses, TOUJOURS visibles. Un trait a gauche
                         rattache le fil sans creuser le retrait : sur mobile
                         un retrait profond ne laisse plus de place au texte. --}}
                    @if($commentaire->replies->isNotEmpty())
                    <ul class="mt-3 space-y-3 border-l-2 border-gray-100 dark:border-gray-700 pl-3 sm:pl-4">
                        @foreach($commentaire->replies as $reponse)
                        <li class="flex gap-2" wire:key="reponse-{{ $reponse->id }}" data-comment-reply-item="{{ $reponse->id }}">
                            @if($reponse->user?->isDisplayableIn(currentOrganization()))
                            <img src="{{ $reponse->user->avatar_url }}" alt="" class="w-7 h-7 rounded-full flex-shrink-0">
                            @endif
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-baseline gap-x-2">
                                    <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                        {{ $reponse->user?->publicDisplayName() ?? __('profile.deactivated_user') }}
                                    </span>
                                    <span class="text-xs text-gray-400">· {{ $reponse->created_at->diffForHumans() }}</span>
                                </div>
                                <p class="mt-0.5 text-sm text-gray-700 dark:text-gray-300 whitespace-pre-line break-words">{{ $reponse->content }}</p>
                                @auth
                                @if(auth()->id() === $reponse->user_id || auth()->user()->is_admin)
                                <button type="button" wire:click="deleteComment('{{ $reponse->id }}')"
                                        wire:confirm="{{ __('blog.confirm_delete_comment') }}"
                                        data-comment-delete="{{ $reponse->id }}"
                                        class="mt-1 text-xs font-medium text-gray-400 hover:text-red-600 transition">
                                    {{ __('blog.delete_post') }}
                                </button>
                                @endif
                                @endauth
                            </div>
                        </li>
                        @endforeach
                    </ul>
                    @endif
                </div>
            </div>
        </li>
        @endforeach
    </ul>
    @endif
</div>
