<x-app-layout>
    @php
        $_dashOrgSlug = $organization?->slug;
        $_dashRoute = function (string $name, array $params = []) use ($_dashOrgSlug): string {
            $orgRoute = 'organization.' . $name;
            return $_dashOrgSlug && Route::has($orgRoute)
                ? route($orgRoute, ['organization' => $_dashOrgSlug] + $params)
                : route($name, $params);
        };
    @endphp
    {{-- TASK-1749 (demande Cyril, 04/11) — la barre du haut STANDARD.

         Ces deux pages portaient un en-tete a elles : un lien texte
         « ← Retour au tableau de bord » surmontant un titre de 24px, a
         l'interieur du conteneur. Le reste du produit — `services/show`,
         `requests/show`, l'annuaire — porte une barre collante en pleine
         largeur, avec une fleche ronde et le titre sur la meme ligne.

         Deux grammaires pour le meme geste, et c'est celle de ces deux pages
         qui etait l'exception.

         `hidden md:flex` : sur mobile, `x-mobile-topbar` rend deja le titre de
         ces deux routes (il les nomme explicitement). Une seconde barre y
         ferait doublon.

         Le bouton « + Nouveau » monte DANS la barre : il etait a droite de
         l'ancien en-tete, c'est le geste principal de la page, et le laisser
         plus bas l'aurait decroche du titre auquel il se rapporte. --}}
    <div class="hidden md:flex items-center gap-3 px-4 sm:px-6 lg:px-8 py-3 border-b border-gray-200 dark:border-gray-700 bg-[var(--bp-surface)] sticky top-0 z-30"
         data-dashboard-topbar="requests">
        <a href="{{ $_dashRoute('dashboard') }}" data-dashboard-topbar-back
           class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 flex-shrink-0"
           aria-label="{{ __('dashboard.back_to_dashboard') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <span class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('dashboard.my_requests_page_title') }}</span>
        <a href="{{ $_dashRoute('requests.create') }}" class="ml-auto inline-flex shrink-0 items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700">+ {{ __('dashboard.new') }}</a>
    </div>

    <x-page-container width="7xl">

        @if($serviceRequests->isEmpty())
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-12 text-center">
                <p class="text-gray-500 dark:text-gray-400">{{ __('dashboard.no_requests') }}</p>
                <a href="{{ $_dashRoute('requests.create') }}" class="mt-3 inline-block text-indigo-600 hover:underline text-sm font-medium">{{ __('dashboard.create_request') }}</a>
            </div>
        @else
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('dashboard.table_date') }}</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('dashboard.table_title') }}</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('dashboard.table_category') }}</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('dashboard.table_budget') }}</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('dashboard.table_delivery_mode') }}</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('dashboard.table_attachments') }}</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('dashboard.table_respondents') }}</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('dashboard.table_action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($serviceRequests as $req)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition">
                                <td class="px-5 py-4 whitespace-nowrap text-gray-600 dark:text-gray-400">{{ $req->created_at->isoFormat('D MMM YYYY') }}</td>
                                <td class="px-5 py-4">
                                    <a href="{{ $_dashRoute('dashboard.requests.detail', ['serviceRequest' => $req]) }}" class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600">{{ $req->title }}</a>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap">
                                    @if($req->category)
                                    <span data-request-category class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" style="background-color: {{ $req->category->color ?? '#e5e7eb' }}20; color: {{ $req->category->color ?? '#6b7280' }}">{{ $req->category->displayName('transactions') }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-gray-600 dark:text-gray-400">{{ $req->budget_min }}{{ $req->budget_max ? '–'.$req->budget_max : '+' }} pts</td>
                                <td class="px-5 py-4 whitespace-nowrap text-gray-600 dark:text-gray-400">{{ __("dashboard.delivery_{$req->delivery_mode}") }}</td>
                                <td class="px-5 py-4 whitespace-nowrap text-gray-600 dark:text-gray-400">{{ $req->attachments->isNotEmpty() ? __('dashboard.has_attachments') : __('dashboard.no_attachments') }}</td>
                                <td class="px-5 py-4 whitespace-nowrap text-gray-600 dark:text-gray-400">{{ trans_choice('dashboard.respondent_count', $req->transactions->count()) }}</td>
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <a href="{{ $_dashRoute('dashboard.requests.detail', ['serviceRequest' => $req]) }}" class="text-indigo-600 hover:underline text-xs font-medium">{{ __('dashboard.view_all') }}</a>
                                        @can('update', $req)
                                        <a href="{{ $_dashRoute('requests.edit', ['request' => $req]) }}" class="text-indigo-600 hover:underline text-xs font-medium">{{ __('dashboard.edit_request') }}</a>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="mt-6">
                {{ $serviceRequests->links() }}
            </div>
        @endif
    </x-page-container>
</x-app-layout>
