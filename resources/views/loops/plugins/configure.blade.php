{{--
    Regler les trois assistants d'une Boucle — TASK-1616.

    Un seul gabarit pour les DEUX surfaces (/admin et /org) : elles posent la
    meme question, et `$saveUrl` / `$backUrl` sont calcules par le controleur.
    Deux vues auraient fait deux endroits ou oublier une garde.

    Ce que cet ecran NE FAIT PAS, et c'est la V0 : il ne renomme pas les
    assistants, n'en ajoute pas, ne choisit ni modele ni provider, et ne
    declenche aucun appel IA. Il ecrit des postures, rien d'autre.
--}}
<x-app-layout>
    <x-slot name="title">{{ $pluginLabel }} — {{ $loop->name }}</x-slot>

    <x-page-container>
        <div class="mx-auto max-w-3xl">

            <a href="{{ $backUrl }}" class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                &larr; {{ $loop->name }}
            </a>

            <header class="mt-3 mb-6">
                <h1 class="flex flex-wrap items-center gap-2 text-2xl font-bold text-gray-900 dark:text-gray-100">
                    {{ $pluginLabel }}
                    @if($experimental)
                        <span data-plugin-status="experimental"
                              class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                            {{ __('loops.plugins_admin_status_experimental') }}
                        </span>
                    @endif
                </h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('loops.plugins_assistants_intro') }}</p>
            </header>

            @if(session('success'))
                <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300">{{ session('success') }}</p>
            @endif

            {{-- Le plugin peut etre eteint pendant qu'on regle ses postures :
                 elles restent enregistrees, et on le DIT plutot que de laisser
                 croire a un reglage sans effet ou a une perte. --}}
            @unless($enabled)
                <p data-notice="plugin-inactive"
                   class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:border-amber-800/60 dark:bg-amber-900/20 dark:text-amber-300">
                    {{ __('loops.plugins_assistants_inactive_notice') }}
                </p>
            @endunless

            <form method="POST" action="{{ $saveUrl }}">
                @csrf
                @method('PUT')

                <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('loops.plugins_assistants_title') }}</h2>

                <div class="mt-3 space-y-4">
                    @foreach($assistants as $assistant)
                        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
                                 data-assistant="{{ $assistant['key'] }}">

                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <p class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ $assistant['label'] }}</p>

                                <label class="inline-flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                                    {{-- Le champ cache porte le 0 : sans lui, une case
                                         decochee n'est pas postee du tout et le serveur
                                         lirait « inchange » la ou l'utilisateur a
                                         eteint. --}}
                                    <input type="hidden" name="assistants[{{ $assistant['key'] }}][enabled]" value="0">
                                    <input type="checkbox" value="1"
                                           name="assistants[{{ $assistant['key'] }}][enabled]"
                                           data-assistant-enabled="{{ $assistant['key'] }}"
                                           @checked($assistant['enabled'])
                                           class="h-4 w-4 rounded border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-800">
                                    {{ __('loops.plugins_assistants_enabled') }}
                                </label>
                            </div>

                            <label for="instruction-{{ $assistant['key'] }}"
                                   class="mt-3 block text-xs font-semibold uppercase tracking-wide text-gray-400">
                                {{ __('loops.plugins_assistants_instruction') }}
                            </label>
                            <textarea id="instruction-{{ $assistant['key'] }}"
                                      name="assistants[{{ $assistant['key'] }}][instruction]"
                                      rows="3" maxlength="4000"
                                      class="mt-1 w-full rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                      placeholder="{{ $assistant['default_instruction'] }}">{{ $assistant['own_instruction'] }}</textarea>

                            {{-- Ce dont l'assistant heriterait si on vidait le champ.
                                 Le recopier dans le champ figerait la posture au
                                 premier enregistrement. --}}
                            <p class="mt-1 text-[11px] leading-5 text-gray-400">
                                {{ __('loops.plugins_assistants_reset_hint') }}
                                @if($assistant['customised'])
                                    <span class="block">{{ $assistant['default_instruction'] }}</span>
                                @endif
                            </p>
                        </section>
                    @endforeach
                </div>

                <button type="submit"
                        class="mt-5 inline-flex min-h-[44px] items-center rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700">
                    {{ __('loops.plugins_assistants_save') }}
                </button>
            </form>
        </div>
    </x-page-container>
</x-app-layout>
