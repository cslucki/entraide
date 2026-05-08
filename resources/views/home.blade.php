<x-app-layout>
    <div class="bg-white dark:bg-[#09090b] min-h-screen font-sans selection:bg-indigo-100 dark:selection:bg-indigo-500/30">

        <!-- Hero Section -->
        <section class="relative pt-24 pb-32 overflow-hidden">
            {{-- Atmospheric background elements --}}
            <div class="absolute top-0 left-1/2 -translate-x-1/2 w-full h-[800px] pointer-events-none overflow-hidden">
                <div class="absolute top-[-15%] left-[5%] w-[50%] h-[70%] bg-indigo-50/40 dark:bg-indigo-900/10 blur-[140px] rounded-full opacity-60"></div>
                <div class="absolute top-[10%] right-[5%] w-[40%] h-[60%] bg-slate-50/60 dark:bg-slate-800/10 blur-[120px] rounded-full opacity-60"></div>
            </div>

            <div class="relative max-w-5xl mx-auto px-6 text-center">
                {{-- Product Status / Badge --}}
                <div class="inline-flex items-center gap-2.5 px-4 py-1.5 rounded-full bg-white dark:bg-zinc-900 border border-zinc-200/60 dark:border-zinc-800/60 shadow-sm mb-12 animate-in fade-in slide-in-from-bottom-2 duration-1000">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-indigo-500"></span>
                    </span>
                    <span class="text-[10px] font-bold uppercase tracking-[0.15em] text-zinc-500 dark:text-zinc-400">Collaboration Intelligente</span>
                </div>

                <h1 class="text-5xl md:text-7xl font-bold text-zinc-900 dark:text-white mb-8 tracking-tight leading-[1.05]">
                    BouclePro
                </h1>

                <p class="max-w-2xl mx-auto text-lg md:text-xl text-zinc-500 dark:text-zinc-400 mb-16 leading-relaxed font-medium">
                    La plateforme d'échanges professionnels où vos talents créent une valeur réelle, sans intermédiaire financier.
                </p>

                {{-- AI Interaction Area --}}
                <div class="max-w-2xl mx-auto mb-16">
                    <livewire:home-ai-input />
                </div>

                {{-- Primary Actions --}}
                <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                    <a href="{{ route('explorer') }}" class="w-full sm:w-auto px-10 py-4 bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 font-bold rounded-2xl hover:bg-zinc-800 dark:hover:bg-zinc-100 transition-all shadow-lg shadow-zinc-900/10 dark:shadow-none">
                        Parcourir les échanges
                    </a>
                    <a href="{{ route('register') }}" class="w-full sm:w-auto px-10 py-4 bg-white dark:bg-zinc-900 text-zinc-600 dark:text-zinc-300 font-bold rounded-2xl border border-zinc-200 dark:border-zinc-800 hover:bg-zinc-50 dark:hover:bg-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700 transition-all">
                        Rejoindre la boucle
                    </a>
                </div>
            </div>
        </section>

        <!-- Metrics Grid -->
        <section class="py-20 border-y border-zinc-100 dark:border-zinc-900/50">
            <div class="max-w-7xl mx-auto px-6">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-12 md:gap-8 divide-zinc-100 dark:divide-zinc-900 md:divide-x">
                    <div class="text-center px-4">
                        <p class="text-3xl font-bold text-zinc-900 dark:text-white mb-1 tracking-tight">{{ $stats['users'] }}</p>
                        <p class="text-[10px] font-bold text-zinc-400 dark:text-zinc-500 uppercase tracking-widest">Membres Actifs</p>
                    </div>
                    <div class="text-center px-4">
                        <p class="text-3xl font-bold text-zinc-900 dark:text-white mb-1 tracking-tight">{{ $stats['services'] }}</p>
                        <p class="text-[10px] font-bold text-zinc-400 dark:text-zinc-500 uppercase tracking-widest">Compétences</p>
                    </div>
                    <div class="text-center px-4">
                        <p class="text-3xl font-bold text-zinc-900 dark:text-white mb-1 tracking-tight">{{ $stats['requests'] }}</p>
                        <p class="text-[10px] font-bold text-zinc-400 dark:text-zinc-500 uppercase tracking-widest">Besoin d'Aide</p>
                    </div>
                    <div class="text-center px-4">
                        <p class="text-3xl font-bold text-zinc-900 dark:text-white mb-1 tracking-tight">{{ $stats['exchanges'] }}</p>
                        <p class="text-[10px] font-bold text-zinc-400 dark:text-zinc-500 uppercase tracking-widest">Échanges Réalisés</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Philosophical Pillars -->
        <section class="py-32 bg-white dark:bg-[#09090b]">
            <div class="max-w-5xl mx-auto px-6">
                <div class="grid md:grid-cols-3 gap-16 md:gap-24">
                    <div class="group">
                        <div class="w-10 h-10 rounded-xl bg-zinc-50 dark:bg-zinc-900 flex items-center justify-center mb-8 border border-zinc-100 dark:border-zinc-800 group-hover:border-indigo-200 dark:group-hover:border-indigo-900 transition-colors">
                            <span class="text-xs font-bold text-zinc-400">01</span>
                        </div>
                        <h3 class="text-base font-bold text-zinc-900 dark:text-white mb-4 tracking-tight">Expertise Partagée</h3>
                        <p class="text-zinc-500 dark:text-zinc-400 text-sm leading-relaxed">Chaque membre apporte un savoir-faire unique, créant un écosystème de compétences riche et diversifié.</p>
                    </div>
                    <div class="group">
                        <div class="w-10 h-10 rounded-xl bg-zinc-50 dark:bg-zinc-900 flex items-center justify-center mb-8 border border-zinc-100 dark:border-zinc-800 group-hover:border-indigo-200 dark:group-hover:border-indigo-900 transition-colors">
                            <span class="text-xs font-bold text-zinc-400">02</span>
                        </div>
                        <h3 class="text-base font-bold text-zinc-900 dark:text-white mb-4 tracking-tight">Économie Circulaire</h3>
                        <p class="text-zinc-500 dark:text-zinc-400 text-sm leading-relaxed">Les échanges se font via un système de points, garantissant l'équilibre et la pérennité du réseau local.</p>
                    </div>
                    <div class="group">
                        <div class="w-10 h-10 rounded-xl bg-zinc-50 dark:bg-zinc-900 flex items-center justify-center mb-8 border border-zinc-100 dark:border-zinc-800 group-hover:border-indigo-200 dark:group-hover:border-indigo-900 transition-colors">
                            <span class="text-xs font-bold text-zinc-400">03</span>
                        </div>
                        <h3 class="text-base font-bold text-zinc-900 dark:text-white mb-4 tracking-tight">Simplicité d'Usage</h3>
                        <p class="text-zinc-500 dark:text-zinc-400 text-sm leading-relaxed">Une interface épurée pensée pour la productivité, vous permettant de vous concentrer sur l'essentiel.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Final CTA Card -->
        @guest
        <section class="pb-32 px-6">
            <div class="max-w-4xl mx-auto relative group">
                <div class="absolute -inset-1 bg-gradient-to-r from-indigo-500 to-purple-500 rounded-[2.5rem] blur opacity-10 group-hover:opacity-20 transition duration-1000"></div>
                <div class="relative bg-zinc-900 dark:bg-zinc-900/80 backdrop-blur-sm rounded-[2.5rem] p-12 md:p-20 text-center overflow-hidden">
                    <div class="absolute top-0 right-0 w-64 h-64 bg-indigo-500/10 blur-[100px] rounded-full -translate-y-1/2 translate-x-1/2"></div>
                    <h2 class="text-3xl md:text-4xl font-bold text-white mb-6 tracking-tight">Prêt à rejoindre la boucle ?</h2>
                    <p class="text-zinc-400 mb-12 max-w-sm mx-auto text-lg">Inscrivez-vous dès aujourd'hui et commencez à échanger vos talents.</p>
                    <a href="{{ route('register') }}" class="inline-flex px-12 py-4 bg-white text-zinc-900 font-bold rounded-2xl hover:bg-indigo-50 transition-all shadow-xl active:scale-95">
                        Créer mon compte gratuitement
                    </a>
                </div>
            </div>
        </section>
        @endguest
    </div>
</x-app-layout>
