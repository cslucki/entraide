<x-app-layout>
    <!-- Playful Hero -->
    <div class="relative overflow-hidden bg-indigo-50 dark:bg-gray-950 pt-20 pb-32">
        <div class="absolute top-0 right-0 -mr-20 -mt-20 w-96 h-96 bg-purple-200 dark:bg-purple-900/20 rounded-full blur-3xl"></div>
        <div class="absolute bottom-0 left-0 -ml-20 -mb-20 w-80 h-80 bg-indigo-200 dark:bg-indigo-900/20 rounded-full blur-3xl"></div>

        <div class="max-w-4xl mx-auto px-4 text-center relative">
            <h1 class="text-6xl font-black text-gray-900 dark:text-white mb-6 tracking-tight">
                L'économie du <span class="text-indigo-600">partage</span> réinventée.
            </h1>
            <p class="text-xl text-gray-600 dark:text-gray-400 mb-12 max-w-2xl mx-auto">
                Publiez, échangez, progressez. Une plateforme faite par des pros, pour des pros.
            </p>

            @guest
            <div class="bg-white dark:bg-gray-800 p-8 rounded-[2rem] shadow-2xl border border-indigo-100 dark:border-gray-700 max-w-lg mx-auto transform hover:scale-105 transition duration-300">
                <div class="flex items-center justify-center gap-4 mb-6">
                    <div class="text-left">
                        <p class="text-sm font-bold text-indigo-600 uppercase tracking-widest">Offre de bienvenue</p>
                        <p class="text-3xl font-black text-gray-900 dark:text-white">+ 100 points offerts</p>
                    </div>
                    <div class="w-16 h-16 bg-yellow-400 rounded-2xl flex items-center justify-center text-3xl shadow-lg animate-bounce">
                        🎁
                    </div>
                </div>
                <a href="{{ route('register') }}" class="block w-full py-4 bg-indigo-600 text-white font-black rounded-xl hover:bg-indigo-700 transition shadow-lg shadow-indigo-200">Créer mon compte gratuitement</a>
                <p class="mt-4 text-xs text-gray-400 italic">Inscription en moins de 2 minutes.</p>
            </div>
            @endguest
        </div>
    </div>

    <!-- Steps with Illustrations -->
    <div class="py-24 bg-white dark:bg-gray-900">
        <div class="max-w-6xl mx-auto px-4">
            <h2 class="text-4xl font-black text-center mb-20 dark:text-white">Comment ça marche ?</h2>
            <div class="grid md:grid-cols-3 gap-16">
                <div class="relative group">
                    <div class="mb-8 w-24 h-24 bg-indigo-600 text-white rounded-3xl flex items-center justify-center text-4xl font-black shadow-xl group-hover:rotate-12 transition duration-300">1</div>
                    <h3 class="text-2xl font-bold mb-4 dark:text-white">Créez votre service</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-lg leading-relaxed">Que vous soyez développeur, jardinier ou coach, listez vos talents et fixez votre prix en points.</p>
                    <div class="absolute -z-10 top-10 left-10 w-full h-full border-2 border-dashed border-gray-100 dark:border-gray-800 rounded-3xl"></div>
                </div>
                <div class="relative group">
                    <div class="mb-8 w-24 h-24 bg-purple-600 text-white rounded-3xl flex items-center justify-center text-4xl font-black shadow-xl group-hover:rotate-12 transition duration-300">2</div>
                    <h3 class="text-2xl font-bold mb-4 dark:text-white">Échangez & Discutez</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-lg leading-relaxed">Trouvez un service, envoyez une demande et utilisez notre messagerie pour finaliser les détails.</p>
                </div>
                <div class="relative group">
                    <div class="mb-8 w-24 h-24 bg-pink-600 text-white rounded-3xl flex items-center justify-center text-4xl font-black shadow-xl group-hover:rotate-12 transition duration-300">3</div>
                    <h3 class="text-2xl font-bold mb-4 dark:text-white">Cumulez & Profitez</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-lg leading-relaxed">Une fois le service rendu, les points sont transférés. Réutilisez-les pour obtenir ce qu'il vous manque !</p>
                </div>
            </div>
        </div>
    </div>

    <!-- CTA Final -->
    <div class="py-20">
        <div class="max-w-5xl mx-auto px-4">
            <div class="bg-gradient-to-r from-indigo-600 to-indigo-800 rounded-[3rem] p-12 text-center text-white shadow-2xl shadow-indigo-300">
                <h2 class="text-4xl font-black mb-6">Prêt à transformer vos compétences en opportunités ?</h2>
                <p class="text-xl text-indigo-100 mb-10">Rejoignez une communauté bienveillante d'échange de services.</p>
                <div class="flex flex-col sm:flex-row gap-4 justify-center">
                    <a href="{{ route('register') }}" class="px-10 py-4 bg-white text-indigo-700 font-bold rounded-2xl hover:scale-105 transition shadow-lg">Je m'inscris</a>
                    <a href="{{ route('explorer') }}" class="px-10 py-4 bg-indigo-500 text-white font-bold rounded-2xl hover:bg-indigo-400 transition">Explorer les annonces</a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
