{{--
    TASK-1714 volet 2 — ce que voit un compte en attente de validation.

    L'ecran dit trois choses, et pas une de plus : ce qui est FAIT (l'adresse
    est confirmee), ce qui MANQUE (un administrateur doit valider), et ce qui
    va se passer (un email a l'approbation). Une personne qui attend a besoin
    de savoir qu'elle n'a rien oublie — pas d'un formulaire de plus.

    Aucun bouton d'action : il n'y a RIEN a faire de son cote. Seule la
    deconnexion reste offerte, parce qu'enfermer quelqu'un dans une session
    qu'il ne peut pas quitter serait pire que l'attente.

    Textes localises, contrairement a `verify-email.blade.php` qui les porte en
    dur — on ne recopie pas une dette.
--}}
<x-guest-layout>
    <div class="px-8 pt-8 pb-2 border-b border-gray-100 dark:border-gray-700">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white" data-marqueur="titre-attente-approbation">
            {{ __('auth.approval_pending_title') }}
        </h1>
    </div>

    <div class="px-8 py-6" data-marqueur="attente-approbation">
        <div class="flex items-start gap-3 p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700">
            <span class="mt-1.5 w-2 h-2 rounded-full bg-amber-500 flex-shrink-0" aria-hidden="true"></span>
            <p class="text-sm text-amber-800 dark:text-amber-300">
                @if ($organisation)
                    {{ __('auth.approval_pending_intro_org', ['name' => $organisation->name]) }}
                @else
                    {{ __('auth.approval_pending_intro') }}
                @endif
            </p>
        </div>

        <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">
            {{ __('auth.approval_pending_email_done') }}
        </p>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
            {{ __('auth.approval_pending_next') }}
        </p>

        <div class="mt-6 flex justify-end">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 underline transition">
                    {{ __('auth.logout') }}
                </button>
            </form>
        </div>
    </div>
</x-guest-layout>
