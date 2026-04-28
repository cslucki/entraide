<x-app-layout>
    <!-- Hero -->
    <div class="bg-gradient-to-br from-indigo-600 to-purple-700 text-white py-20">
        <div class="max-w-4xl mx-auto px-4 text-center">
            <h1 class="text-4xl md:text-5xl font-bold mb-4">Échangez vos compétences, sans argent</h1>
            <p class="text-xl text-indigo-100 mb-8">Proposez vos services, trouvez ce dont vous avez besoin, et échangez avec des points.</p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="{{ route('explorer') }}" class="px-8 py-3 bg-white text-indigo-700 font-semibold rounded-lg hover:bg-indigo-50 transition">Explorer les services</a>
                @guest
                <a href="{{ route('register') }}" class="px-8 py-3 bg-indigo-500 text-white font-semibold rounded-lg hover:bg-indigo-400 border border-indigo-400 transition">Rejoindre gratuitement</a>
                @endguest
            </div>
        </div>
    </div>

    <!-- How it works -->
    <div class="py-16 bg-white dark:bg-gray-900">
        <div class="max-w-5xl mx-auto px-4">
            <h2 class="text-2xl font-bold text-center text-gray-900 dark:text-gray-100 mb-10">Comment ça marche ?</h2>
            <div class="grid md:grid-cols-3 gap-8">
                <div class="text-center">
                    <div class="w-14 h-14 bg-indigo-100 dark:bg-indigo-900 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="text-2xl font-bold text-indigo-600">1</span>
                    </div>
                    <h3 class="font-semibold text-lg mb-2 dark:text-gray-100">Publiez vos services</h3>
                    <p class="text-gray-500 dark:text-gray-400">Décrivez ce que vous savez faire et fixez un prix en points.</p>
                </div>
                <div class="text-center">
                    <div class="w-14 h-14 bg-indigo-100 dark:bg-indigo-900 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="text-2xl font-bold text-indigo-600">2</span>
                    </div>
                    <h3 class="font-semibold text-lg mb-2 dark:text-gray-100">Négociez & échangez</h3>
                    <p class="text-gray-500 dark:text-gray-400">Proposez, discutez, et finalisez l'échange en messagerie.</p>
                </div>
                <div class="text-center">
                    <div class="w-14 h-14 bg-indigo-100 dark:bg-indigo-900 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="text-2xl font-bold text-indigo-600">3</span>
                    </div>
                    <h3 class="font-semibold text-lg mb-2 dark:text-gray-100">Accumulez des points</h3>
                    <p class="text-gray-500 dark:text-gray-400">Les points se transfèrent automatiquement à la validation mutuelle.</p>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
