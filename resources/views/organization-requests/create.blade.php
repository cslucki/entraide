<x-app-layout>
    <div class="max-w-2xl mx-auto px-4 py-12">

        <!-- Header -->
        <div class="text-center mb-10">
            <div class="w-16 h-16 bg-indigo-100 dark:bg-indigo-900 rounded-2xl flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </div>
            <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100 mb-2">Devenir partenaire</h1>
            <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto">
                Présentez votre réseau, association ou collectif professionnel. Nous vous accompagnons pour créer un espace BouclePro adapté.
            </p>
        </div>

        <!-- Form -->
        <form method="POST" action="{{ route('partenaires.request.store') }}"
              class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-8 space-y-6">
            @csrf

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Nom de votre boucle <span class="text-red-500">*</span>
                </label>
                <input type="text" name="boucle_name" value="{{ old('boucle_name') }}" required
                       placeholder="Ex : BNI Lyon Est, Réseau artisans 06, Startup Nation…"
                       class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:ring-indigo-500 focus:border-indigo-500">
                @error('boucle_name')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Décrivez votre projet <span class="text-red-500">*</span>
                </label>
                <textarea name="description" rows="4" required
                          placeholder="Qui sont les membres ? Quel type de services seront échangés ? Quelle est la taille envisagée ?"
                          class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:ring-indigo-500 focus:border-indigo-500">{{ old('description') }}</textarea>
                @error('description')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Contexte ou association porteuse
                    <span class="text-gray-400 font-normal">(facultatif)</span>
                </label>
                <input type="text" name="context" value="{{ old('context') }}"
                       placeholder="Ex : association AMT, club BNI, réseau de co-working…"
                       class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Votre nom <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="contact_name" required
                           value="{{ old('contact_name', auth()->user()?->name) }}"
                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:ring-indigo-500 focus:border-indigo-500">
                    @error('contact_name')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Votre email <span class="text-red-500">*</span>
                    </label>
                    <input type="email" name="contact_email" required
                           value="{{ old('contact_email', auth()->user()?->email) }}"
                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:ring-indigo-500 focus:border-indigo-500">
                    @error('contact_email')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Info box -->
            <div class="flex gap-3 bg-indigo-50 dark:bg-indigo-900/30 rounded-xl p-4 text-sm text-indigo-700 dark:text-indigo-300">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p>Nous étudions chaque demande et vous répondons sous 48h. La création d'une boucle est gratuite.</p>
            </div>

            <div class="flex gap-3 justify-end">
                <a href="{{ route('partenaires.index') }}"
                   class="px-5 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    Annuler
                </a>
                <button type="submit"
                        class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">
                    Envoyer ma demande
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
