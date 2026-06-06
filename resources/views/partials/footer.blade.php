@php
    $organizationRouteParam = request()->route('organization');
    $usesOrganizationRoutes = request()->routeIs('organization.*') && $organizationRouteParam;
    $bugReportIndexRoute = $usesOrganizationRoutes
        ? route('organization.bug-reports.index', ['organization' => $organizationRouteParam])
        : route('bug-reports.index');
    $bugReportStoreRoute = $usesOrganizationRoutes
        ? route('organization.bug-reports.store', ['organization' => $organizationRouteParam])
        : route('bug-reports.store');
    $loginRoute = $usesOrganizationRoutes
        ? route('organization.login', ['organization' => $organizationRouteParam])
        : route('login');
@endphp

<footer class="flex-shrink-0 border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
        <div class="flex flex-col sm:flex-row items-center justify-between gap-2 text-xs text-gray-400 dark:text-gray-500">
            <div x-data="{ bugOpen: false }" class="relative flex flex-wrap items-center justify-center sm:justify-start gap-x-2 gap-y-1 text-center sm:text-left">
                <a href="{{ route('mentions-legales') }}"
                   class="hover:text-gray-700 dark:hover:text-gray-200 hover:underline transition-colors">
                    Mentions légales
                </a>
                <span class="text-gray-300 dark:text-gray-700">|</span>
                <a href="https://github.com/cslucki/entraide"
                   target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center gap-1 hover:text-gray-700 dark:hover:text-gray-200 transition-colors">
                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.942.359.31.678.921.678 1.856 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd"/>
                    </svg>
                    <span>OpenSource</span>
                </a>
                <span class="text-gray-300 dark:text-gray-700">|</span>
                <button type="button"
                        @click="bugOpen = !bugOpen"
                        class="hover:text-gray-700 dark:hover:text-gray-200 hover:underline transition-colors">
                    Un bug ?
                </button>

                <div x-show="bugOpen"
                     x-cloak
                     @click.outside="bugOpen = false"
                     x-transition
                     class="absolute bottom-full left-1/2 z-40 mb-3 w-80 max-w-[calc(100vw-2rem)] -translate-x-1/2 rounded-xl border border-gray-200 bg-white p-4 text-left text-sm text-gray-700 shadow-xl dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 sm:left-0 sm:translate-x-0">
                    @auth
                        <form method="POST" action="{{ $bugReportStoreRoute }}" x-data x-init="$refs.pageUrl.value = window.location.href" class="space-y-3">
                            @csrf
                            <input x-ref="pageUrl" type="hidden" name="page_url" value="{{ request()->fullUrl() }}">
                            <div>
                                <p class="font-semibold text-gray-900 dark:text-gray-100">Signaler un bug</p>
                                <a href="{{ $bugReportIndexRoute }}" class="mt-1 inline-block text-xs text-indigo-600 hover:underline dark:text-indigo-400">
                                    Voir les bugs signalés et corrigés
                                </a>
                            </div>
                            <select name="reason" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                <option value="">Type de bug...</option>
                                <option value="Affichage mobile">Affichage mobile</option>
                                <option value="Fonctionnement">Fonctionnement</option>
                                <option value="Navigation">Navigation</option>
                                <option value="Autre">Autre</option>
                            </select>
                            <textarea name="details" rows="3" required placeholder="Décris ce qui ne fonctionne pas..."
                                      class="w-full resize-none rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
                            <div class="flex items-center gap-2">
                                <button type="submit" class="flex-1 rounded-lg bg-gray-900 px-3 py-2 text-xs font-semibold text-white transition hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                                    Envoyer
                                </button>
                                <button type="button" @click="bugOpen = false" class="rounded-lg border border-gray-200 px-3 py-2 text-xs transition hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700">
                                    Annuler
                                </button>
                            </div>
                        </form>
                    @else
                        <p class="font-semibold text-gray-900 dark:text-gray-100">Un bug ?</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Connectez-vous pour nous envoyer un signalement précis.</p>
                        <div class="mt-3 flex items-center gap-3 text-xs">
                            <a href="{{ $loginRoute }}" class="font-semibold text-indigo-600 hover:underline dark:text-indigo-400">Se connecter</a>
                            <a href="{{ $bugReportIndexRoute }}" class="text-gray-500 hover:underline dark:text-gray-400">Voir les bugs</a>
                        </div>
                    @endauth
                </div>
            </div>

            <span class="text-[11px] opacity-60">{{ config('app.version') }}</span>
        </div>
    </div>
</footer>
