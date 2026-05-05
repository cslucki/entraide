<x-app-layout>
    <x-slot name="title">Mentions légales</x-slot>

    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-8">Mentions légales</h1>

        <div class="space-y-8 text-gray-700 dark:text-gray-300">

            {{-- Éditeur --}}
            <section>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-3">Éditeur du site</h2>
                <p>Le site <strong>amteletravail.fr</strong> est édité par :</p>
                <address class="not-italic mt-2 space-y-1">
                    <p class="font-medium text-gray-900 dark:text-white">
                        ASSOCIATION EURO-MÉDITERRANÉENNE DU TÉLÉTRAVAIL
                        <span class="font-normal text-gray-500 dark:text-gray-400">(Sigle : AMT)</span>
                    </p>
                    <p>RNA : W133002043</p>
                    <p>SIRET : 47874023600029</p>
                    <p>Code APE : 8559A — Formation continue d'adultes</p>
                    <p>N° de déclaration d'activité d'organisme de formation : 93131908813</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">auprès de la DREETS PACA</p>
                </address>
            </section>

            {{-- Directeur de publication --}}
            <section>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-3">Directeur de la publication</h2>
                <p>Cyril SLUCKI</p>
            </section>

            {{-- Hébergeur --}}
            <section>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-3">Hébergeur</h2>
                <p>
                    <a href="https://cloud.laravel.com" target="_blank" rel="noopener noreferrer"
                       class="text-indigo-600 dark:text-indigo-400 hover:underline">
                        Laravel Cloud
                    </a>
                </p>
            </section>

            {{-- Open source --}}
            <section>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-3">Projet open source</h2>
                <p>
                    BouclePro est un projet open source porté par l'association AMT.
                    Le code source est disponible sur GitHub. Toute contribution est bienvenue —
                    développement, suggestions, ou signalement de bugs.
                </p>
                <a href="https://github.com/cslucki/entraide"
                   target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center gap-2 mt-3 text-indigo-600 dark:text-indigo-400 hover:underline font-medium">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.942.359.31.678.921.678 1.856 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd"/>
                    </svg>
                    github.com/cslucki/entraide
                </a>
            </section>

        </div>
    </div>
</x-app-layout>
