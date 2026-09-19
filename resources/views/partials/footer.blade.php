@php
    $organizationRouteParam = request()->route('organization');
    $usesOrganizationRoutes = request()->routeIs('organization.*') && $organizationRouteParam;
    $bugReportIndexRoute = $usesOrganizationRoutes
        ? route('organization.bug-reports.index', ['organization' => $organizationRouteParam])
        : route('bug-reports.index');
    // TASK-1605 — `$bugReportStoreRoute` et `$loginRoute` vivaient ici pour le
    // popup de signalement. Le popup a cede la place a un lien vers la page
    // bornee, qui porte desormais le formulaire et le message invite : ces deux
    // variables n'avaient plus d'usage.
@endphp

<footer class="flex-shrink-0">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
        <div class="flex flex-col sm:flex-row items-center justify-center gap-2 text-xs text-gray-400 dark:text-gray-500">
            <div class="relative flex flex-wrap items-center justify-center text-center divide-x divide-gray-300 dark:divide-gray-700">
                {{-- TASK-1349 : le credit de portage cede sa place au lien de
                     gouvernance. Un lien discret, au meme rang que les autres :
                     le footer n'est pas surcharge, une entree en remplace une. --}}
                <a href="{{ organizationScopedUrl('mycelium', 'organization.mycelium') }}"
                   class="px-2 hover:text-gray-700 dark:hover:text-gray-200 hover:underline transition-colors"
                   data-footer-mycelium>
                    {{ __('mycelium.footer_link') }}
                </a>
                <a href="{{ organizationScopedUrl('mentions-legales', 'organization.mentions-legales') }}"
                   class="px-2 hover:text-gray-700 dark:hover:text-gray-200 hover:underline transition-colors">
                    {{ __('footer.mentions_legales') }}
                </a>
                <a href="https://bouclepro.com/demo"
                   target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center gap-1 px-2 hover:text-gray-700 dark:hover:text-gray-200 hover:underline transition-colors">
                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 2l1.5 6.5L20 10l-6.5 1.5L12 18l-1.5-6.5L4 10l6.5-1.5z"/>
                        <path d="M18 14l.5 2.5L21 17l-2.5.5L18 20l-.5-2.5L15 17l2.5-.5z"/>
                    </svg>
                    <span>{{ __('footer.kit_demo') }}</span>
                </a>
                <a href="https://github.com/cslucki/entraide"
                   target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center gap-1 px-2 hover:text-gray-700 dark:hover:text-gray-200 transition-colors">
                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.942.359.31.678.921.678 1.856 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd"/>
                    </svg>
                    <span>{{ __('footer.opensource') }}</span>
                </a>
                {{-- TASK-1605 — arbitrage MASTER : PAS de popup Alpine.
                     « Un bug ? » mene a la vraie page de signalement, deja
                     bornee a l'Organization (`organization.bug-reports.index`,
                     `GET /org/{organization}/bugs`). Le backend existait deja :
                     rien n'a ete reinvente, seul le popup disparait.

                     Ce qui etait ici : un `<button>` basculant
                     `x-data="{ bugOpen: false }"`, avec le formulaire embarque
                     pour les membres et un message pour les invites. Le
                     formulaire et le message vivent desormais sur la page, ou
                     ils ont la place de s'expliquer. --}}
                <a href="{{ $bugReportIndexRoute }}"
                   class="px-2 hover:text-gray-700 dark:hover:text-gray-200 hover:underline transition-colors"
                   data-footer-bug-report>
                    {{ __('footer.bug') }}
                </a>
            </div>

            <span class="text-[11px] opacity-60">{{ config('app.version') }}</span>
        </div>
    </div>
</footer>
