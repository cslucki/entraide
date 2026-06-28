@php $settings = $organization->homepage_settings ?? []; @endphp
<x-admin-layout title="Page d'accueil — {{ $organization->name }}">
    <div class="max-w-2xl">
        <form method="POST" action="{{ route('admin.organizations.homepage.update', $organization) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <div class="flex items-center justify-between">
                <div>
                    <a href="{{ route('admin.homepages') }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">&larr; Retour à Homepage design</a>
                </div>
                <a href="{{ route('organization.home', $organization) }}" target="_blank" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">Prévisualiser &nearr;</a>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Template</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Template de page d'accueil</label>
                    <select name="homepage_template" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                        <option value="default" @selected(($organization->homepage_template ?? 'default') === 'default')>Default (existant)</option>
                        <option value="bouclepro_hero_v2" @selected($organization->homepage_template === 'bouclepro_hero_v2')>BouclePro_Hero</option>
                        <option value="artscilab_hero" @selected($organization->homepage_template === 'artscilab_hero')>ArtSciLab_Hero</option>
                    </select>
                </div>
            </div>

            @if ($organization->homepage_template === 'bouclepro_hero_v2')
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Contenu</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Subheadline (sous-titre)</label>
                    <textarea name="subheadline" rows="2" maxlength="500" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">{{ old('subheadline', $settings['subheadline'] ?? '') }}</textarea>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Card 1 — "J'explore une piste"</label>
                        <input type="text" name="card_create_label" value="{{ old('card_create_label', $settings['card_create_label'] ?? '') }}" maxlength="100" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Card 2 — "Je crée du lien"</label>
                        <input type="text" name="card_meet_label" value="{{ old('card_meet_label', $settings['card_meet_label'] ?? '') }}" maxlength="100" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Card 3 — "J'ai besoin d'aide"</label>
                        <input type="text" name="card_help_label" value="{{ old('card_help_label', $settings['card_help_label'] ?? '') }}" maxlength="100" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Card 4 — "Je peux aider"</label>
                        <input type="text" name="card_offer_label" value="{{ old('card_offer_label', $settings['card_offer_label'] ?? '') }}" maxlength="100" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Texte sur rôle de l'IA</label>
                    <textarea name="ai_note" rows="2" maxlength="255" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">{{ old('ai_note', $settings['ai_note'] ?? '') }}</textarea>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Footer</h2>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">CTA primaire — label</label>
                        <input type="text" name="primary_cta_label" value="{{ old('primary_cta_label', $settings['primary_cta_label'] ?? '') }}" maxlength="100" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">CTA primaire — URL</label>
                        <input type="text" name="primary_cta_url" value="{{ old('primary_cta_url', $settings['primary_cta_url'] ?? '') }}" maxlength="500" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm font-mono">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">CTA secondaire — label</label>
                        <input type="text" name="secondary_cta_label" value="{{ old('secondary_cta_label', $settings['secondary_cta_label'] ?? '') }}" maxlength="100" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">CTA secondaire — URL</label>
                        <input type="text" name="secondary_cta_url" value="{{ old('secondary_cta_url', $settings['secondary_cta_url'] ?? '') }}" maxlength="500" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm font-mono">
                    </div>
                </div>
            </div>

            @elseif($organization->homepage_template === 'artscilab_hero')
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Contenu</h2>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Titre — partie pleine</label>
                        <input type="text" name="headline_solid" value="{{ old('headline_solid', $settings['headline_solid'] ?? '') }}" maxlength="100" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Titre — partie contour</label>
                        <input type="text" name="headline_outline" value="{{ old('headline_outline', $settings['headline_outline'] ?? '') }}" maxlength="200" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Subheadline (sous-titre)</label>
                    <textarea name="subheadline" rows="2" maxlength="500" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">{{ old('subheadline', $settings['subheadline'] ?? '') }}</textarea>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Carte 1 — "Je peux aider"</label>
                        <input type="text" name="card_1_label" value="{{ old('card_1_label', $settings['card_1_label'] ?? '') }}" maxlength="100" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Carte 2 — "Je cherche de l'aide"</label>
                        <input type="text" name="card_2_label" value="{{ old('card_2_label', $settings['card_2_label'] ?? '') }}" maxlength="100" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Carte 3 — "Je suis fasciné par"</label>
                        <input type="text" name="card_3_label" value="{{ old('card_3_label', $settings['card_3_label'] ?? '') }}" maxlength="100" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Carte 4 — "Ces deux personnes devraient se rencontrer"</label>
                        <input type="text" name="card_4_label" value="{{ old('card_4_label', $settings['card_4_label'] ?? '') }}" maxlength="100" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Note IA (retours à la ligne autorisés)</label>
                    <textarea name="ai_note" rows="3" maxlength="255" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">{{ old('ai_note', $settings['ai_note'] ?? '') }}</textarea>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Footer</h2>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">CTA primaire — label</label>
                        <input type="text" name="primary_cta_label" value="{{ old('primary_cta_label', $settings['primary_cta_label'] ?? '') }}" maxlength="100" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">CTA primaire — URL</label>
                        <input type="text" name="primary_cta_url" value="{{ old('primary_cta_url', $settings['primary_cta_url'] ?? '') }}" maxlength="500" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm font-mono">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">CTA secondaire — label</label>
                        <input type="text" name="secondary_cta_label" value="{{ old('secondary_cta_label', $settings['secondary_cta_label'] ?? '') }}" maxlength="100" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">CTA secondaire — URL</label>
                        <input type="text" name="secondary_cta_url" value="{{ old('secondary_cta_url', $settings['secondary_cta_url'] ?? '') }}" maxlength="500" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm font-mono">
                    </div>
                </div>
            </div>

            @endif

            <div class="flex items-center gap-3">
                <button type="submit" class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Enregistrer</button>
            </div>
        </form>
    </div>
</x-admin-layout>
