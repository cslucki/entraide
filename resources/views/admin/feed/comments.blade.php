<x-admin-layout :title="__('admin.feed_comments_title')">
    {{-- TASK-1788 correctif 10 — LES COMMENTAIRES DU FLUX, et rien d'autre.

         « Voir uniquement les commentaires classes par date, et par
         utilisateur. Ceci pour pouvoir les voir / modifier / supprimer. »

         Ecrit sur le gabarit de `admin/feed/index` : memes filtres, meme
         tableau, memes gestes. Aucun second systeme — c'est la regle que la
         page des annonces s'est deja donnee.

         ## Deux gestes, et ils ne se valent pas

         « Masquer » bascule `is_approved` : le commentaire sort de la vue de
         tous et le geste SE DEFAIT. « Supprimer » detruit la ligne. L'ecran
         les distingue par la couleur et par le mot, et la suppression demande
         une confirmation qui dit le mot « definitivement ».

         Les deux boutons ne sont jamais cote a cote par hasard : la moderation
         commence par masquer. --}}

    @if(session('success'))
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-300">
            {{ session('success') }}
        </div>
    @endif

    <div class="mb-5">
        <a href="{{ route('admin.feed') }}" class="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
            &larr; {{ __('admin.feed_title') }}
        </a>
    </div>

    {{-- TASK-1788 correctif 10 (recette Cyril) — quand la page est bornee a UNE
         annonce, elle le DIT. Un filtre invisible est un filtre qu'on accuse de
         perdre des lignes. --}}
    @if($annonceChoisie)
        <div class="mb-5 flex flex-wrap items-center gap-3 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800 dark:border-indigo-800 dark:bg-indigo-900/20 dark:text-indigo-200"
             data-marqueur="borne-annonce">
            <span>{{ __('admin.feed_comments_of_post', ['post' => $annonceChoisie->title ?: \App\Support\Feed\FeedEventRenderer::libelle($annonceChoisie)]) }}</span>
            <a href="{{ route('admin.feed.comments', array_filter(['organization_id' => $organizationId])) }}"
               data-marqueur="retirer-borne-annonce"
               class="font-semibold underline">{{ __('admin.feed_comments_all') }}</a>
        </div>
    @endif

    <form method="GET" class="mb-5 flex flex-wrap items-end gap-3">
        {{-- L'annonce voyage avec les autres filtres : changer d'auteur ne doit
             pas faire sauter la borne qu'on vient de poser. --}}
        @if($feedPostId !== '')
            <input type="hidden" name="feed_post_id" value="{{ $feedPostId }}">
        @endif
        <div>
            <label for="organization_id" class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('admin.feed_filter_organization') }}</label>
            <select name="organization_id" id="organization_id" data-marqueur="filtre-organisation"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                <option value="">{{ __('admin.feed_all_organizations') }}</option>
                @foreach($organizations as $org)
                    <option value="{{ $org->id }}" {{ $organizationId === $org->id ? 'selected' : '' }}>{{ $org->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="user_id" class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('admin.feed_comments_col_author') }}</label>
            {{-- Les auteurs proposes sont ceux qui ont REELLEMENT commente :
                 lister tous les comptes de la plateforme donnerait un menu dont
                 presque aucune entree ne rendrait de ligne. --}}
            <select name="user_id" id="user_id" data-marqueur="filtre-auteur"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                <option value="">{{ __('admin.feed_comments_filter_author') }}</option>
                @foreach($auteurs as $auteur)
                    <option value="{{ $auteur->id }}" {{ $userId === $auteur->id ? 'selected' : '' }}>{{ $auteur->publicDisplayName() }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="ordre" class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('admin.feed_comments_order') }}</label>
            <select name="ordre" id="ordre" data-marqueur="filtre-ordre"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                <option value="desc" {{ $ordre === 'desc' ? 'selected' : '' }}>{{ __('admin.feed_comments_order_desc') }}</option>
                <option value="asc" {{ $ordre === 'asc' ? 'selected' : '' }}>{{ __('admin.feed_comments_order_asc') }}</option>
            </select>
        </div>

        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700">{{ __('admin.feed_filter') }}</button>
    </form>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-3">{{ __('admin.feed_comments_col_author') }}</th>
                    <th class="px-4 py-3">{{ __('admin.feed_col_organization') }}</th>
                    <th class="px-4 py-3">{{ __('admin.feed_comments_col_post') }}</th>
                    <th class="px-4 py-3">{{ __('admin.feed_comments_col_content') }}</th>
                    <th class="px-4 py-3">{{ __('admin.feed_comments_col_date') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('admin.feed_col_actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($commentaires as $commentaire)
                    <tr data-marqueur="ligne-commentaire" data-commentaire="{{ $commentaire->id }}">
                        <td class="px-4 py-3 align-top text-gray-700 dark:text-gray-200">
                            {{ $commentaire->user?->publicDisplayName() ?? '—' }}
                            @unless($commentaire->is_approved)
                                <span class="mt-1 block w-fit rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700 dark:bg-amber-900/30 dark:text-amber-200"
                                      data-marqueur="commentaire-masque">{{ __('admin.feed_comment_hidden') }}</span>
                            @endunless
                        </td>
                        <td class="px-4 py-3 align-top text-gray-600 dark:text-gray-300">{{ $commentaire->organization?->name ?? '—' }}</td>
                        <td class="px-4 py-3 align-top text-gray-600 dark:text-gray-300 max-w-xs">
                            {{-- TASK-1788 correctif 10 (recette Cyril) — la colonne
                                 rendait « — » sur toutes les lignes.

                                 Un EVENEMENT automatique n'a ni titre ni contenu :
                                 son libelle est CALCULE par
                                 `FeedEventRenderer::libelle()`. Se contenter de
                                 `title` puis `content` ne pouvait donc donner
                                 qu'un tiret — et l'administrateur ne savait pas a
                                 quoi le commentaire repondait. --}}
                            @if($commentaire->feedPost)
                                <span class="line-clamp-2">{{ $commentaire->feedPost->title
                                    ?: (\Illuminate\Support\Str::limit(strip_tags((string) $commentaire->feedPost->content), 80)
                                        ?: \App\Support\Feed\FeedEventRenderer::libelle($commentaire->feedPost)) }}</span>
                            @else
                                <span>—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 align-top max-w-md">
                            <form method="POST" action="{{ route('admin.feed.comment.update', $commentaire->id) }}" class="flex items-start gap-2">
                                @csrf
                                @method('PUT')
                                <textarea name="content" rows="2" required
                                          data-marqueur="champ-commentaire"
                                          class="w-full rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">{{ $commentaire->content }}</textarea>
                                <button type="submit" class="shrink-0 rounded-lg bg-indigo-600 px-3 py-1 text-xs font-semibold text-white hover:bg-indigo-700">{{ __('admin.feed_save') }}</button>
                            </form>
                        </td>
                        <td class="px-4 py-3 align-top text-xs text-gray-400">{{ $commentaire->created_at?->isoFormat('lll') }}</td>
                        <td class="px-4 py-3 align-top text-right">
                            <div class="inline-flex flex-wrap items-center justify-end gap-2">
                                {{-- MASQUER : reversible, et c'est par la que
                                     commence une moderation. --}}
                                <form method="POST" action="{{ route('admin.feed.comment.toggle', $commentaire->id) }}">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" data-marqueur="basculer-commentaire"
                                            class="rounded-lg border border-gray-300 px-3 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">
                                        {{ $commentaire->is_approved ? __('admin.feed_comment_hide') : __('admin.feed_comment_show') }}
                                    </button>
                                </form>

                                {{-- SUPPRIMER : irreversible, et l'ecran le dit
                                     avant de le faire. --}}
                                <form method="POST" action="{{ route('admin.feed.comment.destroy', $commentaire->id) }}"
                                      onsubmit="return confirm('{{ __('admin.feed_comment_delete_confirm') }}')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" data-marqueur="supprimer-commentaire"
                                            class="rounded-lg border border-red-300 px-3 py-1 text-xs font-semibold text-red-600 hover:bg-red-50 dark:border-red-800 dark:hover:bg-red-900/30">
                                        {{ __('admin.feed_comment_delete') }}
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('admin.feed_comments_empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-5">{{ $commentaires->links() }}</div>
</x-admin-layout>
