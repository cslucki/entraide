<x-guest-layout>
    @php
        $isOrg = !is_null($currentOrganization);
        $ref = request()->input('ref');
        $loginAction = $isOrg ? route('organization.login', $currentOrganization) : route('login');
        $registerLink = $isOrg
            ? route('organization.register', array_filter(['organization' => $currentOrganization->slug, 'ref' => $ref]))
            : route('register', array_filter(['ref' => $ref]));
        $forgotPasswordLink = $isOrg ? route('organization.password.request', $currentOrganization) : route('password.request');
    @endphp

    <div class="px-8 pt-8 pb-2 border-b border-gray-100 dark:border-gray-700">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('auth.login_title') }}</h1>
        @if($isOrg)
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('auth.login_subtitle_org', ['name' => $currentOrganization->name]) }}</p>
        @endif
    </div>

    <div class="px-8 py-6">
        <x-auth-session-status class="mb-5" :status="session('status')" />

        <form method="POST" action="{{ $loginAction }}">
            @csrf

            <div class="space-y-5">
                <!-- Email -->
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.email_label') }}
                    </label>
                    {{-- ?email= is a convenience when arriving from a Loop invitation link.
                         It is never a guarantee: the server re-checks the address against
                         the invitation after authentication. old() still wins on redisplay. --}}
                    <input id="email" type="email" name="email" value="{{ old('email', request('email')) }}"
                           required autofocus autocomplete="username"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                    <x-input-error :messages="$errors->get('email')" class="mt-1.5" />

                    {{-- TASK-1728 (A3) — la sortie de secours du parcours
                         d'Organization (arbitrage MASTER D1).

                         `/org/{slug}/login` n'authentifie plus que les comptes
                         DE ce tenant. Quelqu'un qui arrive ici par un favori,
                         alors que son compte vit ailleurs, recevrait sinon un
                         « identifiants incorrects » definitif sans savoir quoi
                         faire — et on ne peut pas lui dire ou est son compte
                         sans reveler son appartenance.

                         Ce lien est donc affiche a TOUT echec, sans condition
                         sur la cause : il ne distingue pas le mauvais mot de
                         passe du mauvais tenant, et ne constitue donc aucun
                         oracle. Il dit seulement qu'une autre porte existe. --}}
                    @if($isOrg && $errors->has('email'))
                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400" data-login-global-fallback>
                        <a href="{{ route('login') }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">
                            {{ __('auth.login_try_global') }}
                        </a>
                    </p>
                    @endif
                </div>

                {{-- TASK-1728 (A3) — le selecteur d'Organization, FACULTATIF.

                     ## Ce qu'il est, et ce qu'il n'est pas

                     Il ne liste que des Organizations PUBLIQUES et ACTIVES. La
                     liste est donc la meme pour tout visiteur et ne dit RIEN
                     de personne : aucune appartenance individuelle n'y
                     apparait. C'est la condition qui rend acceptable un tel
                     champ sur une page ouverte a qui n'est pas authentifie —
                     proposer les Organizations de la personne aurait fait de
                     ce formulaire un oracle d'appartenance.

                     ## Pourquoi « facultatif » et pas « requis »

                     Tant que `users.email` est globalement unique, une adresse
                     ne designe qu'un compte : la selection n'a rien a
                     departager et le parcours historique continue sans elle.
                     La rendre obligatoire aurait casse toutes les connexions
                     existantes pour un besoin qui n'existe pas encore.

                     ## Les Organizations privees

                     Absentes de cette liste, par construction. Leur equivalent
                     est leur propre porte — `/org/{slug}/login` —, et elle n'a
                     pas besoin d'etre annoncee ici pour fonctionner.

                     Rien sur `/org/{slug}/login` : l'Organization y vient de la
                     ROUTE, et le serveur refuse qu'un champ poste la
                     contredise. --}}
                @if(! $isOrg && ($organizationsProposables ?? collect())->isNotEmpty())
                <div>
                    <label for="organization" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.login_organization_label') }}
                    </label>
                    <select id="organization" name="organization"
                            data-login-organization-selector
                            class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                        <option value="">{{ __('auth.login_organization_any') }}</option>
                        @foreach($organizationsProposables as $proposable)
                        <option value="{{ $proposable->slug }}" {{ old('organization') === $proposable->slug ? 'selected' : '' }}>
                            {{ $proposable->name }}
                        </option>
                        @endforeach
                    </select>
                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('auth.login_organization_hint') }}
                    </p>
                    <x-input-error :messages="$errors->get('organization')" class="mt-1.5" />
                </div>
                @endif

                <!-- Mot de passe -->
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ __('auth.password_label') }}
                        </label>
                        @if (Route::has($isOrg ? 'organization.password.request' : 'password.request'))
                        <a href="{{ $forgotPasswordLink }}"
                           class="text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 transition">
                            {{ __('auth.forgot_password_link') }}
                        </a>
                        @endif
                    </div>
                    <input id="password" type="password" name="password"
                           required autocomplete="current-password"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                    <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
                </div>

                <!-- Se souvenir de moi -->
                <div>
                    <label for="remember_me" class="inline-flex items-center gap-2 cursor-pointer">
                        <input id="remember_me" type="checkbox" name="remember"
                               class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:bg-gray-700">
                        <span class="text-sm text-gray-600 dark:text-gray-400">{{ __('auth.remember_me') }}</span>
                    </label>
                </div>

                <!-- Bouton connexion -->
                <button type="submit"
                        class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg shadow-sm transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    {{ __('auth.login_button') }}
                </button>
            </div>
        </form>
    </div>

    <!-- Lien inscription -->
    <div class="px-8 py-5 bg-gray-50 dark:bg-gray-700/40 border-t border-gray-100 dark:border-gray-700 text-center">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('auth.no_account') }}
            <a href="{{ $registerLink }}" class="font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 transition">
                {{ __('auth.register_link') }}
            </a>
        </p>
    </div>
</x-guest-layout>
