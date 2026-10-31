{{--
    TASK-1734 — la section « Mes organisations » du menu avatar.

    UN composant pour les TROIS surfaces (bureau + responsive du gabarit
    `navigation`, side-nav, topbar mobile) : la logique — quelles entrees,
    quels etats — vit dans `SiblingAccountsPanel` (memoise par requete), et
    seul le MARQUAGE varie par `variant`. N'en poser qu'une ou deux surfaces
    etait le defaut de T1725.

    La section ne se rend QUE si le panneau a des entrees : l'immense
    majorite des comptes n'a ni frere ni lien, et leur menu ne doit pas
    porter une rubrique vide. Les anciens parcours (bascule par mot de
    passe, liaison d'adresses differentes) restent atteignables depuis
    Profil -> Securite.

    Quatre etats, et ce qu'ils disent :
    - courante     : l'Organization du compte connecte, cochee, inerte ;
    - basculable   : lien existant -> le POST bascule, UN clic, RIEN a saisir ;
    - a_confirmer  : frere verifie sans lien -> le MEME POST envoie l'email
                     de premiere association (le serveur decide, le menu ne
                     promet rien qu'il ne tienne) ;
    - indisponible : preflight non passe -> desactive, libelle GENERIQUE,
                     le motif reste au journal.
--}}
@props(['variant' => 'dropdown'])

@php
    $panneauMesOrgs = app(\App\Services\Users\SiblingAccountsPanel::class);
    $entreesMesOrgs = auth()->check() ? $panneauMesOrgs->entrees(auth()->user()) : collect();
    $orgCouranteMesOrgs = auth()->user()?->organization;
@endphp

@if($entreesMesOrgs->isNotEmpty())
    @if($variant === 'dropdown')
        <div class="border-t border-gray-100 dark:border-gray-600 my-1"></div>
        <div class="px-4 pt-2 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500" data-nav-my-organizations>
            {{ __('navigation.my_organizations') }}
        </div>
        @if($orgCouranteMesOrgs)
            <div class="flex items-center justify-between px-4 py-2 text-sm font-medium text-indigo-700 dark:text-indigo-300 bg-indigo-50 dark:bg-indigo-900/40">
                <span class="truncate">{{ $orgCouranteMesOrgs->name }}</span>
                <span aria-hidden="true">&#10003;</span>
            </div>
        @endif
        @foreach($entreesMesOrgs as $entree)
            @if($entree->etat === \App\Services\Users\SiblingAccountsPanel::ETAT_INDISPONIBLE)
                <div class="flex items-center justify-between px-4 py-2 text-sm text-gray-400 dark:text-gray-500" data-my-org-state="indisponible" aria-disabled="true">
                    <span class="truncate">{{ $entree->organization?->name ?? '—' }}</span>
                    <span class="ml-2 shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-400 dark:bg-gray-700 dark:text-gray-500">{{ __('navigation.my_org_unavailable') }}</span>
                </div>
            @else
                <form method="POST" action="{{ route('my-organizations.switch', ['organization' => $entree->organization->slug]) }}">
                    @csrf
                    <button type="submit"
                            data-my-org-switch="{{ $entree->organization->slug }}"
                            data-my-org-state="{{ $entree->etat }}"
                            class="flex w-full items-center justify-between px-4 py-2 text-start text-sm leading-5 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 focus:outline-none focus:bg-gray-100 dark:focus:bg-gray-800 transition duration-150 ease-in-out">
                        <span class="truncate">{{ $entree->organization->name }}</span>
                        @if($entree->etat === \App\Services\Users\SiblingAccountsPanel::ETAT_A_CONFIRMER)
                            <span class="ml-2 shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ __('navigation.my_org_confirm_by_email') }}</span>
                        @else
                            <span class="ml-2 shrink-0 rounded-full bg-green-50 px-2 py-0.5 text-[10px] font-semibold text-green-700 dark:bg-green-900/40 dark:text-green-300">{{ __('navigation.my_org_switch') }}</span>
                        @endif
                    </button>
                </form>
            @endif
        @endforeach
    @elseif($variant === 'responsive')
        <div class="px-3 pt-3 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500" data-nav-my-organizations>
            {{ __('navigation.my_organizations') }}
        </div>
        @if($orgCouranteMesOrgs)
            <div class="block w-full ps-3 pe-4 py-2 border-l-4 border-indigo-400 dark:border-indigo-600 text-start text-base font-medium text-indigo-700 dark:text-indigo-300 bg-indigo-50 dark:bg-indigo-900/50">
                {{ $orgCouranteMesOrgs->name }} <span aria-hidden="true">&#10003;</span>
            </div>
        @endif
        @foreach($entreesMesOrgs as $entree)
            @if($entree->etat === \App\Services\Users\SiblingAccountsPanel::ETAT_INDISPONIBLE)
                <div class="block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-gray-400 dark:text-gray-500" data-my-org-state="indisponible" aria-disabled="true">
                    {{ $entree->organization?->name ?? '—' }} · {{ __('navigation.my_org_unavailable') }}
                </div>
            @else
                <form method="POST" action="{{ route('my-organizations.switch', ['organization' => $entree->organization->slug]) }}">
                    @csrf
                    <button type="submit"
                            data-my-org-switch="{{ $entree->organization->slug }}"
                            data-my-org-state="{{ $entree->etat }}"
                            class="block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 hover:border-gray-300 dark:hover:border-gray-600 focus:outline-none transition duration-150 ease-in-out">
                        {{ $entree->organization->name }}
                        @if($entree->etat === \App\Services\Users\SiblingAccountsPanel::ETAT_A_CONFIRMER)
                            <span class="ml-1 text-xs text-amber-700 dark:text-amber-300">· {{ __('navigation.my_org_confirm_by_email') }}</span>
                        @endif
                    </button>
                </form>
            @endif
        @endforeach
    @else
        {{-- variant 'side' : la side-nav applicative --}}
        <div class="border-t border-gray-100 pt-2 dark:border-gray-700">
            <div class="px-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500" data-nav-my-organizations>
                {{ __('navigation.my_organizations') }}
            </div>
            @if($orgCouranteMesOrgs)
                <div class="flex items-center justify-between px-4 py-2 text-sm font-medium text-indigo-700 dark:text-indigo-300 bg-indigo-50 dark:bg-indigo-900/40">
                    <span class="truncate">{{ $orgCouranteMesOrgs->name }}</span>
                    <span aria-hidden="true">&#10003;</span>
                </div>
            @endif
            @foreach($entreesMesOrgs as $entree)
                @if($entree->etat === \App\Services\Users\SiblingAccountsPanel::ETAT_INDISPONIBLE)
                    <div class="flex items-center justify-between px-4 py-2 text-sm text-gray-400 dark:text-gray-500" data-my-org-state="indisponible" aria-disabled="true">
                        <span class="truncate">{{ $entree->organization?->name ?? '—' }}</span>
                        <span class="ml-2 shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-400 dark:bg-gray-700 dark:text-gray-500">{{ __('navigation.my_org_unavailable') }}</span>
                    </div>
                @else
                    <form method="POST" action="{{ route('my-organizations.switch', ['organization' => $entree->organization->slug]) }}">
                        @csrf
                        <button type="submit"
                                data-my-org-switch="{{ $entree->organization->slug }}"
                                data-my-org-state="{{ $entree->etat }}"
                                class="flex w-full items-center justify-between gap-3 px-4 py-2 text-left text-sm text-gray-700 transition hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">
                            <span class="truncate">{{ $entree->organization->name }}</span>
                            @if($entree->etat === \App\Services\Users\SiblingAccountsPanel::ETAT_A_CONFIRMER)
                                <span class="ml-2 shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ __('navigation.my_org_confirm_by_email') }}</span>
                            @else
                                <span class="ml-2 shrink-0 rounded-full bg-green-50 px-2 py-0.5 text-[10px] font-semibold text-green-700 dark:bg-green-900/40 dark:text-green-300">{{ __('navigation.my_org_switch') }}</span>
                            @endif
                        </button>
                    </form>
                @endif
            @endforeach
        </div>
    @endif
@endif
