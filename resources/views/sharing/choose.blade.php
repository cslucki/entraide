{{--
    TASK-1738 — l'ecran de choix du partage : quelle Boucle, quel commentaire.

    Une seule page, deux etats :

    1. Une intention est en attente (elle vient de la feuille de partage
       d'Android, ou du collage ci-dessous) : apercu du lien, liste des
       Boucles ou CETTE personne peut publier, commentaire FACULTATIF,
       validation humaine explicite — rien ne part sans ce bouton (CDC 4.1).

    2. Aucune intention : le formulaire de collage, qui est le chemin
       OFFICIEL d'iPhone et du desktop (Safari iOS ne connait pas la Web
       Share Target API) et l'entree « Partager un lien » du menu. Il repasse
       par `partage.recevoir` : un seul chemin de validation d'URL, pas deux.

    La liste affichee n'autorise rien : la permission est revalidee au POST,
    sur la Boucle reellement soumise.
--}}
<x-app-layout>
    <div class="max-w-xl mx-auto px-4 py-8">

        @if (session('partage_statut'))
            <div class="mb-4 p-3 rounded-lg bg-indigo-50 dark:bg-indigo-900/30 text-sm text-indigo-800 dark:text-indigo-200">
                {{ session('partage_statut') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/30 text-sm text-red-700 dark:text-red-300">
                <ul class="list-disc list-inside space-y-0.5">
                    @foreach ($errors->all() as $erreur)
                        <li>{{ $erreur }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($intention !== null)
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('sharing.title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 mb-5">{{ __('sharing.intro') }}</p>

            {{-- Le lien en attente, avec son apercu quand il existe. Un
                 apercu absent n'est pas un echec : LinkedIn bloque les
                 robots, le lien part quand meme (CDC 4.5). --}}
            <div class="mb-6 p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                <p class="text-[10px] uppercase tracking-wider text-gray-400 mb-2">{{ __('sharing.shared_link') }}</p>

                @if ($apercu !== null)
                    <x-conversation.url-preview-card :preview="$apercu" />
                @else
                    @if ($intention->titre)
                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $intention->titre }}</p>
                    @endif
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('sharing.no_preview') }}</p>
                @endif

                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2 break-all">{{ $intention->url }}</p>
            </div>

            @if ($boucles->isEmpty())
                <div class="p-4 rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 text-sm text-amber-800 dark:text-amber-200">
                    {{ __('sharing.no_loops') }}
                </div>
            @else
                <form method="POST" action="{{ route('partage.publier') }}">
                    @csrf
                    <input type="hidden" name="jeton" value="{{ $intention->jeton }}">

                    <fieldset class="mb-5">
                        <legend class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            {{ __('sharing.loop_label') }}
                        </legend>
                        <div class="space-y-2">
                            @foreach ($boucles as $boucle)
                                <label class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 cursor-pointer hover:border-indigo-400 has-[:checked]:border-indigo-500 has-[:checked]:ring-1 has-[:checked]:ring-indigo-500 transition">
                                    <input type="radio" name="loop" value="{{ $boucle->id }}" required
                                           @checked(old('loop') === $boucle->id || $boucles->count() === 1)
                                           class="text-indigo-600 focus:ring-indigo-500">
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100 break-words">{{ $boucle->name }}</span>
                                </label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('loop')" class="mt-1.5" />
                    </fieldset>

                    <div class="mb-5">
                        <label for="commentaire" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            {{ __('sharing.comment_label') }}
                        </label>
                        <textarea id="commentaire" name="commentaire" rows="3"
                                  maxlength="{{ \App\Support\Sharing\ShareIntent::LONGUEUR_MAX_COMMENTAIRE }}"
                                  placeholder="{{ __('sharing.comment_placeholder') }}"
                                  class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">{{ old('commentaire') }}</textarea>
                        <x-input-error :messages="$errors->get('commentaire')" class="mt-1.5" />
                    </div>

                    <button type="submit"
                            class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg shadow-sm transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                        {{ __('sharing.submit') }}
                    </button>
                </form>
            @endif
        @else
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('sharing.paste_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 mb-5">{{ __('sharing.paste_intro') }}</p>

            {{-- GET vers `partage.recevoir`, deliberement : le collage emprunte
                 EXACTEMENT le chemin de la feuille de partage — meme
                 extraction, memes refus, meme 303 vers une URL nue. --}}
            <form method="GET" action="{{ route('partage.recevoir') }}">
                <div class="mb-4">
                    <label for="url" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('sharing.paste_label') }}
                    </label>
                    <input id="url" type="url" name="url" required inputmode="url"
                           maxlength="{{ \App\Support\Sharing\ShareIntent::LONGUEUR_MAX_URL }}"
                           placeholder="{{ __('sharing.paste_placeholder') }}"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                </div>

                <button type="submit"
                        class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg shadow-sm transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    {{ __('sharing.paste_submit') }}
                </button>
            </form>

            <p class="text-xs text-gray-400 dark:text-gray-500 mt-4">{{ __('sharing.paste_hint') }}</p>
        @endif
    </div>
</x-app-layout>
