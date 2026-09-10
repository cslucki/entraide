<x-admin-layout :title="__('admin.root_destination_title')">
    {{-- TASK-1506 — ce que sert la RACINE. Cinq modalites, une seule active.
         L'ecran annonce AVANT le choix laquelle exige une connexion : decouvrir
         un mur d'authentification apres coup serait le pire des retours.

         Aucune couleur `var(--bp-*)` ici : `layouts/admin` n'emet PAS les
         jetons de theme (mesure : bouton blanc sur transparent, bordure noire).
         La palette du superadmin, c'est `indigo-600`. Un test le garde. --}}
    @php
        // Pas de `use` dans un bloc @php : Blade le compile DANS le corps de la
        // fonction de rendu, ou PHP refuse une importation. Nom qualifie.
        $labels = [
            \App\Support\Homepage\RootDestination::HOMEPAGE => ['label' => __('admin.root_destination_mode_homepage'), 'hint' => __('admin.root_destination_mode_homepage_hint'), 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
            \App\Support\Homepage\RootDestination::SHELL_WELCOME => ['label' => __('admin.root_destination_mode_shell_welcome'), 'hint' => __('admin.root_destination_mode_shell_welcome_hint'), 'icon' => 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z'],
            \App\Support\Homepage\RootDestination::BLOG => ['label' => __('admin.root_destination_mode_blog'), 'hint' => __('admin.root_destination_mode_blog_hint'), 'icon' => 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253'],
            \App\Support\Homepage\RootDestination::DIRECTORY => ['label' => __('admin.root_destination_mode_directory'), 'hint' => __('admin.root_destination_mode_directory_hint'), 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
            \App\Support\Homepage\RootDestination::LOOPS => ['label' => __('admin.root_destination_mode_loops'), 'hint' => __('admin.root_destination_mode_loops_hint'), 'icon' => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15'],
        ];
    @endphp

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('admin.root_destination_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 max-w-2xl">{{ __('admin.root_destination_hint') }}</p>
        </div>
        <a href="{{ url('/') }}" target="_blank" rel="noopener"
           class="inline-flex items-center gap-2 min-h-[40px] px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700/50"
           data-root-destination-preview>
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
            {{ __('admin.root_destination_preview') }}
        </a>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 dark:border-emerald-500/30 bg-emerald-50 dark:bg-emerald-500/10 px-3 py-2 text-sm text-emerald-800 dark:text-emerald-200" data-root-destination-saved>{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="mb-4 rounded-lg border border-red-200 dark:border-red-500/30 bg-red-50 dark:bg-red-500/10 px-3 py-2 text-sm text-red-800 dark:text-red-200" data-root-destination-error>{{ session('error') }}</div>
    @endif

    @if($organization === null)
        {{-- Sans Organization par defaut, la racine n'a pas de contexte : on le
             dit, on ne propose pas un choix qui ne s'appliquerait a rien. --}}
        <div class="rounded-2xl border border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 p-6 text-sm text-amber-900 dark:text-amber-100" data-root-destination-no-org>
            {{ __('admin.root_destination_no_default_org') }}
        </div>
    @else
        <div class="mb-4 rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-5 py-4" data-root-destination-org>
            <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ __('admin.root_destination_default_org') }}</p>
            <p class="mt-1 text-base font-semibold text-gray-900 dark:text-gray-100">{{ $organization->name }} <span class="font-normal text-gray-400 dark:text-gray-500">/{{ $organization->slug }}</span></p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('admin.root_destination_default_org_hint') }}</p>
        </div>

        @if($defaultOrganizationIsPrivate)
            {{-- Mesure : le blog d'une Organization privee repond 404 a un anonyme.
                 Le dire ici, pas apres le choix. --}}
            <div class="mb-4 rounded-2xl border border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 px-5 py-4 text-sm text-amber-900 dark:text-amber-100" data-root-destination-private-org>
                {{ __('admin.root_destination_private_org') }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.homepage.update') }}" data-root-destination-form>
            @csrf
            @method('PUT')

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($modes as $mode)
                    @php
                        $meta = $labels[$mode];
                        $isCurrent = $current === $mode;
                        $needsAuth = \App\Support\Homepage\RootDestination::requiresAuthentication($mode);
                    @endphp
                    <label class="relative flex cursor-pointer flex-col gap-2 rounded-2xl border-2 bg-white dark:bg-gray-800 p-5 transition min-h-[160px]
                                  {{ $isCurrent ? 'border-indigo-600 dark:border-indigo-500 shadow-sm' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600' }}"
                           data-root-destination-option="{{ $mode }}" @if($isCurrent) data-current="true" @endif>
                        <input type="radio" name="root_destination" value="{{ $mode }}" class="sr-only peer" @checked($isCurrent)>

                        <div class="flex items-start justify-between gap-2">
                            <svg class="w-5 h-5 flex-shrink-0 {{ $isCurrent ? 'text-indigo-600 dark:text-indigo-400' : 'text-gray-400 dark:text-gray-500' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $meta['icon'] }}"/>
                            </svg>
                            @if($isCurrent)
                                <span class="rounded-full bg-indigo-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white" data-root-destination-current>{{ __('admin.root_destination_current') }}</span>
                            @endif
                        </div>

                        <div>
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $meta['label'] }}</p>
                            <p class="mt-1 text-xs leading-snug text-gray-500 dark:text-gray-400">{{ $meta['hint'] }}</p>
                        </div>

                        <div class="mt-auto pt-1">
                            @if($needsAuth)
                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 dark:bg-amber-500/15 px-2 py-0.5 text-[10px] font-semibold text-amber-800 dark:text-amber-200"
                                      title="{{ __('admin.root_destination_authenticated_hint') }}" data-root-destination-auth="required">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                    {{ __('admin.root_destination_authenticated') }}
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 dark:bg-emerald-500/15 px-2 py-0.5 text-[10px] font-semibold text-emerald-800 dark:text-emerald-200" data-root-destination-auth="public">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    {{ __('admin.root_destination_public') }}
                                </span>
                            @endif
                        </div>
                    </label>
                @endforeach
            </div>

            @if(\App\Support\Homepage\RootDestination::requiresAuthentication($current))
                <p class="mt-3 text-xs text-amber-700 dark:text-amber-300" data-root-destination-auth-note>{{ __('admin.root_destination_authenticated_hint') }}</p>
            @endif

            <div class="mt-5">
                <button type="submit" class="inline-flex items-center justify-center min-h-[44px] px-5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-sm font-semibold text-white shadow-sm transition" data-root-destination-submit>
                    {{ __('admin.root_destination_save') }}
                </button>
            </div>
        </form>
    @endif
</x-admin-layout>
