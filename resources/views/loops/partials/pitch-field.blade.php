{{--
    TASK-1885 C7 (demande Cyril, 22/11) — le champ « Pitch », UN seul gabarit
    pour les trois ecrans d'edition : proprietaire (`loops/edit`), admin
    d'Organization (`admin/org/loop-edit`), SuperAdmin (`admin/loops/edit`).

    Attend : $value (pitch actuel ou null).
--}}
<div data-loop-pitch-field>
    <label for="pitch" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('loops.pitch_label') }}</label>
    <input type="text" name="pitch" id="pitch" value="{{ old('pitch', $value) }}" maxlength="{{ \App\Models\Loop::PITCH_MAX }}"
           placeholder="{{ __('loops.pitch_placeholder') }}" autocomplete="off"
           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm uppercase tracking-wide focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('loops.pitch_help', ['max' => \App\Models\Loop::PITCH_MAX]) }}</p>
    @error('pitch')
        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
    @enderror
</div>
