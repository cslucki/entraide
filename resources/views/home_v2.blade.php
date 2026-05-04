<x-app-layout>
    <!-- Hero Confidence -->
    <div class="bg-white dark:bg-gray-900 pt-20 pb-32">
        <div class="max-w-6xl mx-auto px-4 grid lg:grid-cols-2 gap-12 items-center">
            <div>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-green-100 text-green-700 text-sm font-bold mb-6">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"></path></svg>
                    Communauté vérifiée
                </div>
                <h1 class="text-6xl font-black text-gray-900 dark:text-gray-100 leading-tight mb-6">
                    Échangez vos <span class="text-indigo-600">talents</span>, gagnez en <span class="text-indigo-600">liberté</span>.
                </h1>
                <p class="text-xl text-gray-600 dark:text-gray-400 mb-10 leading-relaxed">
                    Déjà {{ $stats['exchanges'] }} échanges réussis entre professionnels passionnés. Proposez vos services et accumulez des points pour bénéficier de l'expertise des autres.
                </p>
                <div class="flex gap-4">
                    <a href="{{ route('register') }}" class="px-8 py-4 bg-indigo-600 text-white font-bold rounded-2xl hover:bg-indigo-700 shadow-xl shadow-indigo-200 transition-all">Démarrer maintenant</a>
                    <a href="#testimonials" class="px-8 py-4 bg-gray-100 text-gray-700 font-bold rounded-2xl hover:bg-gray-200 transition-all">Voir les avis</a>
                </div>
            </div>
            <div class="relative">
                <div class="absolute -top-10 -left-10 w-32 h-32 bg-indigo-100 rounded-full blur-3xl opacity-50"></div>
                <div class="bg-gradient-to-tr from-indigo-500 to-purple-600 rounded-[3rem] p-1 shadow-2xl rotate-3">
                    <div class="bg-white dark:bg-gray-800 rounded-[2.8rem] p-8 -rotate-3 transition-transform hover:rotate-0 duration-500">
                        <div class="flex items-center gap-4 mb-6">
                            <img src="https://ui-avatars.com/api/?name=Alice+Bernard&background=6366f1&color=fff" class="w-16 h-16 rounded-2xl shadow-lg">
                            <div>
                                <p class="font-bold text-lg dark:text-white">Alice Bernard</p>
                                <p class="text-sm text-gray-500">Graphiste Freelance</p>
                            </div>
                        </div>
                        <p class="text-gray-700 dark:text-gray-300 italic text-lg leading-relaxed">
                            "J'ai pu faire créer mon site web en échange de logos. Un gain de temps et d'argent incroyable pour lancer mon activité !"
                        </p>
                        <div class="mt-6 flex text-yellow-400 gap-1">
                            @for($i=0; $i<5; $i++) <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg> @endfor
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Section -->
    <div class="bg-gray-50 dark:bg-gray-800 py-16">
        <div class="max-w-6xl mx-auto px-4 grid grid-cols-2 md:grid-cols-4 gap-8">
            <div class="text-center">
                <p class="text-4xl font-black text-indigo-600 mb-2">{{ $stats['users'] }}</p>
                <p class="text-gray-500 font-medium">Membres actifs</p>
            </div>
            <div class="text-center">
                <p class="text-4xl font-black text-indigo-600 mb-2">{{ $stats['services'] }}</p>
                <p class="text-gray-500 font-medium">Annonces publiées</p>
            </div>
            <div class="text-center">
                <p class="text-4xl font-black text-indigo-600 mb-2">{{ $stats['exchanges'] }}</p>
                <p class="text-gray-500 font-medium">Échanges terminés</p>
            </div>
            <div class="text-center">
                <p class="text-4xl font-black text-indigo-600 mb-2">100%</p>
                <p class="text-gray-500 font-medium">Sans argent</p>
            </div>
        </div>
    </div>

    <!-- Testimonials -->
    <div id="testimonials" class="py-24 bg-white dark:bg-gray-900">
        <div class="max-w-6xl mx-auto px-4">
            <h2 class="text-3xl font-bold text-center mb-16 dark:text-white">Ce qu'en disent nos membres</h2>
            <div class="grid md:grid-cols-3 gap-8">
                <div class="bg-gray-50 dark:bg-gray-800 p-8 rounded-3xl border border-gray-100 dark:border-gray-700">
                    <p class="text-gray-600 dark:text-gray-400 mb-6 leading-relaxed">"Une plateforme simple et intuitive. J'ai trouvé un consultant SEO en quelques jours seulement."</p>
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-indigo-200"></div>
                        <span class="font-bold dark:text-white">Marc D.</span>
                    </div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-800 p-8 rounded-3xl border border-gray-100 dark:border-gray-700">
                    <p class="text-gray-600 dark:text-gray-400 mb-6 leading-relaxed">"Le système de points est génial. Ça valorise vraiment le travail de chacun sans pression financière."</p>
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-purple-200"></div>
                        <span class="font-bold dark:text-white">Julie L.</span>
                    </div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-800 p-8 rounded-3xl border border-gray-100 dark:border-gray-700">
                    <p class="text-gray-600 dark:text-gray-400 mb-6 leading-relaxed">"Enfin une solution pour s'entraider entre entrepreneurs locaux. Je recommande vivement !"</p>
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-green-200"></div>
                        <span class="font-bold dark:text-white">Thomas R.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
