<x-app-layout>
    <div class="max-w-5xl mx-auto px-4 py-12">
        <div class="grid md:grid-cols-[1.2fr_0.8fr] gap-10 items-center">
            <div>
                <span class="inline-block text-xs font-semibold uppercase tracking-widest text-indigo-500 dark:text-indigo-400 mb-3">Partenaires</span>
                <h1 class="text-3xl md:text-4xl font-bold text-gray-900 dark:text-gray-100 mb-4">Faites grandir votre réseau avec BouclePro</h1>
                <p class="text-gray-600 dark:text-gray-400 leading-relaxed mb-8">
                    BouclePro accompagne les réseaux professionnels, associations et collectifs qui veulent faciliter l'échange de services entre leurs membres.
                </p>

                <a href="{{ route('partenaires.request.create') }}"
                   class="inline-flex items-center gap-2 px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Devenir partenaire
                </a>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6 shadow-sm">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100 mb-4">Pour qui ?</h2>
                <div class="space-y-4 text-sm text-gray-600 dark:text-gray-400">
                    <div class="flex gap-3">
                        <span class="mt-1 w-2 h-2 rounded-full bg-indigo-500 flex-shrink-0"></span>
                        <p>Réseaux professionnels locaux</p>
                    </div>
                    <div class="flex gap-3">
                        <span class="mt-1 w-2 h-2 rounded-full bg-indigo-500 flex-shrink-0"></span>
                        <p>Associations et collectifs d'entrepreneurs</p>
                    </div>
                    <div class="flex gap-3">
                        <span class="mt-1 w-2 h-2 rounded-full bg-indigo-500 flex-shrink-0"></span>
                        <p>Communautés qui veulent structurer l'entraide entre membres</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
