{{-- TASK-1787 — l'ecran rendu a qui se presente devant une Organization qui
     refuse les inscriptions.

     Il existe parce que le CDC §3 demande, pour une Organization fermee autre
     que celle par defaut, « un message explicite et un lien vers sa connexion ».
     Un 404 ou une redirection muette auraient laisse la personne sans rien
     comprendre : elle est arrivee la deliberement, le plus souvent par un lien
     qu'on lui a donne.

     Trois sorties, et aucune n'est un cul-de-sac :

     - se CONNECTER a cette Organization — c'est la plus probable, quelqu'un qui
       a deja un compte et s'est trompe de page ;
     - s'inscrire AILLEURS, quand d'autres Organizations restent ouvertes ;
     - revenir a l'accueil.

     Quand aucune Organization n'accepte plus d'inscription, le bloc du milieu
     disparait et le texte le dit — plutot que de proposer une liste vide. --}}
<x-guest-layout>
    <x-slot name="title">{{ __('auth.registration_closed_title') }}</x-slot>

    <div class="w-full max-w-md mx-auto" data-marqueur="inscriptions-fermees">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 sm:p-8">

            <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">
                {{ __('auth.registration_closed_title') }}
            </h1>

            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300" data-marqueur="inscriptions-fermees-message">
                {{ \App\Support\Tenancy\RegistrationGuard::messageFermeture($organization) }}
            </p>

            {{-- Le lien de CONNEXION de cette Organization, exige par le CDC.
                 Il vise sa route prefixee quand elle existe : la personne a un
                 compte ICI, pas sur la plateforme en general. --}}
            <a href="{{ \Illuminate\Support\Facades\Route::has('organization.login')
                        ? route('organization.login', ['organization' => $organization->slug])
                        : route('login') }}"
               data-marqueur="inscriptions-fermees-connexion"
               class="mt-5 inline-flex w-full items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700">
                {{ __('auth.registration_closed_login_hint') }}
            </a>

            @if($ouvertes->isNotEmpty())
            <div class="mt-6 border-t border-gray-200 dark:border-gray-700 pt-5">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ __('auth.registration_closed_elsewhere') }}
                </p>
                <ul class="mt-3 space-y-2" data-marqueur="inscriptions-fermees-alternatives">
                    @foreach($ouvertes as $ouverte)
                    <li>
                        <a href="{{ route('organization.register', ['organization' => $ouverte->slug]) }}"
                           data-marqueur-organisation="{{ $ouverte->slug }}"
                           class="flex items-center justify-between rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2 text-sm text-gray-800 dark:text-gray-200 transition hover:border-indigo-400 hover:bg-indigo-50 dark:hover:bg-gray-700">
                            <span class="font-medium">{{ $ouverte->name }}</span>
                            <span class="text-xs text-gray-400">{{ __('auth.register_button') }}</span>
                        </a>
                    </li>
                    @endforeach
                </ul>
            </div>
            @else
            {{-- Aucune Organization n'accepte d'inscription : on le DIT, plutot
                 que de rendre une liste vide qui laisserait croire a un bogue. --}}
            <p class="mt-6 border-t border-gray-200 dark:border-gray-700 pt-5 text-sm text-gray-500 dark:text-gray-400"
               data-marqueur="inscriptions-fermees-partout">
                {{ __('auth.registration_closed_everywhere') }}
            </p>
            @endif

            <a href="{{ url('/') }}" class="mt-5 block text-center text-xs text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-200">
                {{ __('dashboard.back_to_home') }}
            </a>
        </div>
    </div>
</x-guest-layout>
