<x-admin-layout :title="__('admin.user_delete_modal_title', ['name' => $donnees['user']['name']])">
    {{-- ================================================================
         TASK-1668 — LA SUPPRESSION D'UN COMPTE, SUR UNE PAGE.

         Elle vivait dans une fenetre surgissante qui a grossi a chaque TASK :
         blocages avec un lien par element (T1665, T1667), contenus a confier
         par famille avec les leurs (T1667), choix du repreneur, et le
         caractere definitif a dire clairement.

         Le payload est EXACTEMENT celui du precheck, calcule cote serveur :
         rien du travail des TASK precedentes n'est reecrit. Et plus rien n'est
         charge en differe, donc plus rien ne peut afficher un instant l'etat du
         compte precedent.
         ================================================================ --}}
    <div class="mb-5">
        <a href="{{ route('admin.users') }}" class="text-sm text-gray-500 hover:text-indigo-600 hover:underline">
            &larr; {{ __('admin.user_delete_back_to_list') }}
        </a>
    </div>

    <div class="max-w-3xl">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-1">
            {{ __('admin.user_delete_modal_title', ['name' => $donnees['user']['name']]) }}
        </h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">{{ $cible->email }}</p>

        @if(session('error'))
        <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
            {{ session('error') }}
        </div>
        @endif

        {{-- ÉTAT A — la suppression est impossible. --}}
        @if(count($donnees['blocks']) > 0)
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5 mb-5">
            <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">{{ __('admin.user_delete_blocked_title') }}</p>
            <ul class="space-y-3">
                @foreach($donnees['blocks'] as $blocage)
                <li class="text-sm text-gray-600 dark:text-gray-400 flex gap-2">
                    <span class="text-red-500" aria-hidden="true">&bull;</span>
                    <span>
                        {{ $blocage['message'] }}
                        {{-- Quand le serveur sait OU aller pour lever le blocage, il le
                             dit. Rien ne s'affiche sinon : pas de puce vide. --}}
                        @if(! empty($blocage['links']))
                        <span class="mt-1 flex flex-col gap-0.5">
                            @foreach($blocage['links'] as $lien)
                            <a href="{{ $lien['url'] }}" class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                                {{ $lien['label'] }} &rarr;
                            </a>
                            @endforeach
                        </span>
                        @endif
                    </span>
                </li>
                @endforeach
            </ul>
        </div>
        @endif

        {{-- Ce qui changerait de main — affiche dans LES DEUX etats.
             C'est avant de lever les blocages qu'on a besoin de savoir ce qui attend. --}}
        @if(count($donnees['transfers']) > 0)
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5 mb-5">
            <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">{{ __('admin.user_delete_transfer_blocked_title') }}</p>
            <ul class="space-y-1">
                @foreach($donnees['transfers'] as $famille)
                <li class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ $famille['count'] }}</span>
                    <span>{{ $famille['label'] }}</span>
                    @if(isset($famille['url']))
                    <a href="{{ $famille['url'] }}" class="text-indigo-600 hover:underline text-xs">{{ __('admin.user_delete_transfer_see') }} &rarr;</a>
                    @else
                    <span class="text-gray-400 text-xs">({{ __('admin.user_delete_transfer_no_screen') }})</span>
                    @endif
                </li>
                @endforeach
            </ul>
        </div>
        @endif

        {{-- ÉTAT B — la suppression est possible. --}}
        @if(count($donnees['blocks']) === 0)
        <form method="POST" action="{{ route('admin.users.destroy', $cible) }}"
              class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5">
            @csrf
            @method('DELETE')
            <input type="hidden" name="preview_fingerprint" value="{{ $donnees['preview_fingerprint'] }}">

            <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">{{ __('admin.user_delete_modal_body') }}</p>

            {{-- Le caractere DEFINITIF est dit a part et mis en avant : noye dans
                 le paragraphe precedent, il se lirait comme une precision alors
                 que c'est la seule information qu'on ne peut pas rattraper. --}}
            <p class="flex items-start gap-2 text-sm font-medium text-red-600 dark:text-red-400 mb-5">
                <span aria-hidden="true">&#9888;</span>
                <span>{{ __('admin.user_delete_modal_irreversible') }}</span>
            </p>

            @if($donnees['requires_transfer'])
            <div class="mb-6">
                <label for="transfer_to" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    {{ __('admin.user_delete_modal_transfer_label', ['count' => $donnees['transfer_total']]) }}
                </label>
                <select id="transfer_to" name="transfer_to" required
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    <option value="">{{ __('admin.user_delete_modal_transfer_placeholder') }}</option>
                    @foreach($donnees['transfer_candidates'] as $candidat)
                    <option value="{{ $candidat['id'] }}">{{ $candidat['name'] }}</option>
                    @endforeach
                </select>
                @if(count($donnees['transfer_candidates']) === 0)
                <p class="text-xs text-red-600 mt-1">{{ __('admin.user_delete_modal_transfer_none') }}</p>
                @endif
            </div>
            @endif

            <div class="flex gap-3 justify-end">
                <a href="{{ route('admin.users') }}"
                   class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                    {{ __('admin.user_delete_modal_cancel') }}
                </a>
                <button type="submit"
                    @disabled($donnees['requires_transfer'] && count($donnees['transfer_candidates']) === 0)
                    class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed">
                    {{ $donnees['requires_transfer'] ? __('admin.user_delete_modal_confirm_transfer') : __('admin.user_delete_modal_confirm') }}
                </button>
            </div>
        </form>
        @else
        <div class="flex justify-end">
            <a href="{{ route('admin.users') }}"
               class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                {{ __('admin.user_delete_modal_cancel') }}
            </a>
        </div>
        @endif
    </div>

    @include('admin.partials.user-profile-modal')
</x-admin-layout>
