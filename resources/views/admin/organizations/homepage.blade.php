@php $settings = $organization->homepage_settings ?? []; @endphp
<x-admin-layout title="Page d'accueil — {{ $organization->name }}">
    <div class="max-w-2xl">
        <form method="POST" action="{{ route('admin.organizations.homepage.update', $organization) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <div class="flex items-center justify-between">
                <div>
                    <a href="{{ route('admin.organizations.edit', $organization) }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">&larr; Retour à l'organisation</a>
                </div>
                <a href="{{ route('organization.home', $organization) }}" target="_blank" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">Prévisualiser &nearr;</a>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Template</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Template de page d'accueil</label>
                    <select name="homepage_template" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                        <option value="default" @selected(($organization->homepage_template ?? 'default') === 'default')>Default (existant)</option>
                        <option value="bouclepro_hero_v2" @selected($organization->homepage_template === 'bouclepro_hero_v2')>BouclePro Hero v2</option>
                    </select>
                </div>
            </div>

            @if ($organization->homepage_template === 'bouclepro_hero_v2')
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Contenu</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Headline (titre principal)</label>
                    <input type="text" name="headline" value="{{ old('headline', $settings['headline'] ?? '') }}" maxlength="255" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Subheadline (sous-titre)</label>
                    <textarea name="subheadline" rows="2" maxlength="500" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">{{ old('subheadline', $settings['subheadline'] ?? '') }}</textarea>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Mot 1 (violet)</label>
                        <input type="text" name="word_1" value="{{ old('word_1', $settings['word_1'] ?? '') }}" maxlength="100" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Mot 2 (orange)</label>
                        <input type="text" name="word_2" value="{{ old('word_2', $settings['word_2'] ?? '') }}" maxlength="100" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Mot 3 (vert)</label>
                        <input type="text" name="word_3" value="{{ old('word_3', $settings['word_3'] ?? '') }}" maxlength="100" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">CTA</h2>

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

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Footer</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nom / crédit footer</label>
                    <input type="text" name="footer_contact_name" value="{{ old('footer_contact_name', $settings['footer_contact_name'] ?? '') }}" maxlength="255" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                </div>
            </div>
            @endif

            <div class="flex items-center gap-3">
                <button type="submit" class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Enregistrer</button>
            </div>
        </form>
    </div>
</x-admin-layout>
