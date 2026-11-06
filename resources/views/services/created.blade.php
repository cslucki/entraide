{{-- ========================================================================
     TASK-1773 — L'ECRAN QUI SUIT LA PUBLICATION D'UNE PROPOSITION.
     ========================================================================
     But PRODUIT, et il gouverne la forme : inviter a partager un autre
     savoir-faire pendant qu'on y est. Ce n'est donc pas un simple accuse de
     reception — la deuxieme moitie de l'ecran est une invitation.

     Une page en GET, atteinte par redirection : un rafraichissement ne recree
     RIEN. C'est la regle posee par la demande, et c'est la forme qui la tient,
     pas une garde ajoutee apres coup.

     Les trois sorties sont rangees par ce qu'on veut encourager : proposer a
     nouveau (plein), voir ses propositions (contour), puis un retour discret
     pour qui veut simplement continuer sa navigation. --}}
<x-page :heading="__('marketplace.service_created_heading')" width="2xl"
        :back="organizationScopedUrl('dashboard', 'organization.dashboard')"
        :back-label="__('dashboard.back_to_dashboard')">

    <div class="mx-auto max-w-xl text-center" data-service-created>
        {{-- La pastille et le titre disent l'ETAT REEL. Une proposition mise en
             pause — ce que `store()` ne fait pas aujourd'hui, mais que rien
             n'interdit demain — ne doit pas s'annoncer comme publiee. --}}
        @if($estPubliee)
            <span class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-300">
                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
            </span>
            <h2 data-service-created-title class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('marketplace.service_created_title') }}</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('marketplace.service_created_subtitle') }}</p>
        @else
            <span class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-300">
                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
            </span>
            <h2 data-service-created-title class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('marketplace.service_created_title_paused') }}</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('marketplace.service_created_subtitle_paused') }}</p>
        @endif

        {{-- Le titre de la proposition, pour qu'on sache de LAQUELLE on parle :
             sur un parcours ou l'on en publie plusieurs d'affilee, l'ecran se
             repete, et c'est cette ligne qui le distingue. --}}
        <p class="mt-4 inline-flex max-w-full items-center gap-2 rounded-full bg-gray-100 px-4 py-1.5 text-sm font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">
            <span class="truncate">{{ $service->title }}</span>
        </p>

        <div class="mt-8 rounded-2xl border border-indigo-200 bg-indigo-50/70 p-6 text-left dark:border-indigo-800/60 dark:bg-indigo-900/20">
            <h3 class="text-base font-semibold text-indigo-900 dark:text-indigo-100">{{ __('marketplace.service_created_more_title') }}</h3>
            <p class="mt-2 text-sm leading-relaxed text-indigo-800/90 dark:text-indigo-200/90">{{ __('marketplace.service_created_more_body_skills') }}</p>
            <p class="mt-2 text-sm leading-relaxed text-indigo-800/90 dark:text-indigo-200/90">{{ __('marketplace.service_created_more_body_directory') }}</p>
        </div>

        {{-- `organizationScopedUrl` et jamais une route en dur : la meme page
             sert la surface courte et la surface prefixee par l'Organization. --}}
        <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
            <a href="{{ organizationScopedUrl('services.create', 'organization.services.create') }}"
               data-service-created-again
               class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700">
                {{ __('marketplace.service_created_again') }}
            </a>
            <a href="{{ organizationScopedUrl('dashboard.services', 'organization.dashboard.services') }}"
               data-service-created-list
               class="inline-flex items-center justify-center rounded-xl border border-gray-300 px-5 py-3 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">
                {{ __('marketplace.service_created_see_mine') }}
            </a>
        </div>

        <p class="mt-6 text-sm">
            <a href="{{ organizationScopedUrl('dashboard', 'organization.dashboard') }}"
               data-service-created-exit
               class="text-gray-500 underline underline-offset-2 transition hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                {{ __('marketplace.service_created_exit') }}
            </a>
        </p>
    </div>
</x-page>
