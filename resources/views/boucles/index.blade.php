<x-app-layout>
    @php
        $exampleLoops = collect([
            [
                'name' => 'Entrepreneurs Marseille',
                'description' => 'Un point de repère pour avancer entre pairs sur les sujets concrets du quotidien.',
            ],
            [
                'name' => 'Trouver mes premiers clients',
                'description' => 'Une Boucle orientée entraide, retours d’expérience et prochaines actions utiles.',
            ],
            [
                'name' => 'Transition numérique',
                'description' => 'Un contexte partagé pour comprendre, tester et décider ensemble avec recul.',
            ],
        ]);

        $visibleLoops = $exampleLoops;
    @endphp

    <x-page-container>
        <div class="max-w-3xl">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-indigo-600 dark:text-indigo-400">Boucles</p>
            <span class="hidden">Les Boucles sont en cours de réorganisation.</span>
            <h1 class="mt-3 text-3xl font-bold tracking-tight text-gray-950 dark:text-gray-50 sm:text-4xl">
                Avancer avec les bonnes personnes, au bon endroit.
            </h1>
            <p class="mt-4 text-base leading-7 text-gray-600 dark:text-gray-300 sm:text-lg">
                Une Boucle rassemble des Members autour d'un besoin, d'un sujet ou d'un projet. Elle donne un contexte clair pour lire, contribuer et avancer ensemble dans une Organization.
            </p>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                @guest
                    <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700">
                        Se connecter
                    </a>
                @endguest
            </div>
        </div>

        <div class="mt-10 grid gap-4 sm:grid-cols-3">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
                <p class="text-sm font-semibold text-gray-950 dark:text-gray-50">Un contexte</p>
                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">Chaque Boucle porte un sujet lisible, sans mélanger tous les échanges.</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
                <p class="text-sm font-semibold text-gray-950 dark:text-gray-50">Des Members</p>
                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">Les bonnes personnes se retrouvent autour d'un besoin ou d'un projet commun.</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
                <p class="text-sm font-semibold text-gray-950 dark:text-gray-50">Une Organization</p>
                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">La Boucle reste interne à son Organization. Elle n'est jamais un tenant.</p>
            </div>
        </div>

        <section class="mt-10">
            <div class="flex items-end justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-gray-50">Quelques Boucles</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Des exemples simples pour comprendre le rôle d'une Boucle.
                    </p>
                </div>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                @foreach($visibleLoops as $visibleLoop)
                    <article class="rounded-2xl border border-gray-200 bg-gray-50 p-5 dark:border-gray-700 dark:bg-gray-800/70">
                        <h3 class="text-base font-semibold text-gray-950 dark:text-gray-50">{{ $visibleLoop['name'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">{{ Str::limit($visibleLoop['description'], 140) }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="mt-10 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900 sm:p-6">
            <h2 class="text-lg font-semibold text-gray-950 dark:text-gray-50">Une Boucle n'est pas</h2>
            <div class="mt-4 grid gap-3 text-sm text-gray-600 dark:text-gray-400 sm:grid-cols-2">
                <p class="rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-800">Pas un chat permanent.</p>
                <p class="rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-800">Pas un groupe WhatsApp.</p>
                <p class="rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-800">Pas une marketplace.</p>
                <p class="rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-800">Pas un chatbot IA.</p>
            </div>
        </section>

        <p class="mt-8 text-sm leading-6 text-gray-500 dark:text-gray-400">
            Les Boucles aident à trouver où contribuer, avec qui avancer et dans quel contexte agir. Les contenus internes restent réservés aux Members autorisés.
        </p>
    </x-page-container>
</x-app-layout>
