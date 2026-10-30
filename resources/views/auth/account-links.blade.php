{{--
    TASK-1731 (A5b) — gerer ses comptes lies.

    Exigence MASTER : « la creation d'un lien doit rester comprehensible pour
    un utilisateur non technique. Il doit comprendre qu'il autorise une bascule
    simplifiee entre deux comptes distincts, qu'il peut revoquer le lien et que
    les donnees des deux Organizations restent separees. Aucune interface
    d'administration complexe. »

    D'ou un seul ecran : ce qui est lie, un bouton pour revoquer, un bouton
    pour passer, et un formulaire pour lier. Rien d'autre.

    `x-app-layout` et non `x-guest-layout` — contrairement a l'ecran de
    bascule : ici on GERE ses liens depuis son compte, on ne le quitte pas.
--}}
<x-app-layout>
    <div class="max-w-3xl mx-auto px-4 py-8 space-y-8">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ __('auth.links_title') }}
            </h1>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                {{ __('auth.links_intro') }}
            </p>
        </div>

        @if(session('success'))
            <div class="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-200">
                {{ session('success') }}
            </div>
        @endif

        <div class="rounded-lg border border-gray-200 dark:border-gray-700">
            @forelse($liens as $lien)
                @php($autre = $lien->autreQue($moi))
                @continue($autre === null)
                <div class="flex items-center justify-between gap-4 px-4 py-3 border-b border-gray-100 last:border-b-0 dark:border-gray-700"
                     data-account-link="{{ $lien->getKey() }}">
                    <div class="min-w-0">
                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ $autre->organization?->name ?? '—' }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $autre->email }}</div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        {{-- La bascule demande le mot de passe DU COMPTE COURANT, pas
                             celui du compte vise : c'est tout l'interet du lien. Si une
                             confirmation recente existe deja, le champ reste vide et le
                             serveur ne la redemande pas. --}}
                        <form method="POST" action="{{ route('account.links.switch', $lien) }}" class="flex items-center gap-2">
                            @csrf
                            <input type="password" name="password" autocomplete="off"
                                   placeholder="{{ __('auth.links_confirm_password') }}"
                                   class="w-44 px-2 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            <button type="submit" data-account-link-switch
                                    class="px-3 py-1.5 text-sm font-medium rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">
                                {{ __('auth.links_switch') }}
                            </button>
                        </form>

                        <form method="POST" action="{{ route('account.links.destroy', $lien) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" data-account-link-revoke
                                    class="px-3 py-1.5 text-sm font-medium rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                                {{ __('auth.links_revoke') }}
                            </button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400">{{ __('auth.links_empty') }}</p>
            @endforelse
        </div>

        <x-input-error :messages="$errors->get('password')" />

        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('auth.links_add_title') }}</h2>

            <form method="POST" action="{{ route('account.links.store') }}" class="mt-4 space-y-4">
                @csrf

                <div>
                    <label for="organization" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.switch_organization_label') }}
                    </label>
                    <select id="organization" name="organization" required
                            class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                        <option value="">{{ __('auth.switch_organization_placeholder') }}</option>
                        @foreach($organizationsProposables as $proposable)
                            <option value="{{ $proposable->slug }}" {{ old('organization') === $proposable->slug ? 'selected' : '' }}>
                                {{ $proposable->name }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('organization')" class="mt-1.5" />
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.links_target_email') }}
                    </label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
                </div>

                <div>
                    <label for="target_password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.links_target_password') }}
                    </label>
                    <input id="target_password" type="password" name="target_password" required autocomplete="off"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    <x-input-error :messages="$errors->get('target_password')" class="mt-1.5" />
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.links_own_password') }}
                    </label>
                    <input id="password" type="password" name="password" required autocomplete="off"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                </div>

                <button type="submit"
                        class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg shadow-sm transition">
                    {{ __('auth.links_submit') }}
                </button>
            </form>
        </div>
    </div>
</x-app-layout>
