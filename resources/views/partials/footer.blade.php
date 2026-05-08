<footer class="flex-shrink-0 border-t border-zinc-100 dark:border-zinc-900 bg-white dark:bg-zinc-950">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <div class="flex flex-col sm:flex-row items-center justify-between gap-6 text-xs font-medium text-zinc-400 dark:text-zinc-500">

            {{-- AMT + Mentions légales --}}
            <div class="flex flex-col sm:flex-row items-center gap-3 sm:gap-6 text-center sm:text-left">
                <span>
                    BouclePro est un projet porté par
                    <a href="https://www.amteletravail.fr" target="_blank" rel="noopener noreferrer"
                       class="font-bold text-indigo-600/80 dark:text-indigo-400/80 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
                        l'association AMT
                    </a>
                </span>
                <span class="hidden sm:inline text-zinc-100 dark:text-zinc-800">|</span>
                <a href="{{ route('mentions-legales') }}"
                   class="hover:text-zinc-900 dark:hover:text-zinc-200 transition-colors">
                    Mentions légales
                </a>
            </div>

            {{-- GitHub + Issues --}}
            <div class="flex items-center gap-6">
                <a href="https://github.com/cslucki/entraide"
                   target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center gap-2 hover:text-zinc-900 dark:hover:text-zinc-200 transition-colors">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.942.359.31.678.921.678 1.856 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd"/>
                    </svg>
                    <span>Contribuer sur GitHub</span>
                </a>
                <a href="https://github.com/cslucki/entraide/issues"
                   target="_blank" rel="noopener noreferrer"
                   class="hover:text-zinc-900 dark:hover:text-zinc-200 transition-colors">
                    Signaler un bug
                </a>
                <span class="text-[10px] uppercase tracking-widest opacity-40">{{ config('app.version') }}</span>
            </div>

        </div>
    </div>
</footer>
