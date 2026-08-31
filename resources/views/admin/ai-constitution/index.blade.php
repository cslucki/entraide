{{--
    TASK-1348 — Constitution IA de la PLATEFORME (zone Super Admin).

    Trois blocs, trois natures, visuellement distincts :
      1. les regles fondamentales appliquees EN CODE — non modifiables ;
      2. le texte SERVI aujourd'hui (version publiee, ou graine du code) ;
      3. l'historique des versions.
--}}
<x-app-layout :title="__('ai.constitution_admin_title')">
    <x-page-container>
        <div class="max-w-4xl space-y-6">

            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('ai.constitution_admin_title') }}</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('ai.constitution_admin_help') }}</p>
            </div>

            @if(session('success'))
                <div class="rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-200">{{ session('success') }}</div>
            @endif
            @if(session('info'))
                <div class="rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ session('info') }}</div>
            @endif

            {{-- 1. LE SOCLE DE CODE — la seule partie qui ne s'edite jamais. --}}
            <section class="bg-gray-50 dark:bg-gray-900/40 rounded-xl border border-gray-300 dark:border-gray-600 p-6" data-constitution-guards>
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.constitution_admin_guards_title') }}</h2>
                <p class="mt-1 mb-3 text-xs text-gray-500 dark:text-gray-400">{{ __('ai.constitution_admin_guards_help') }}</p>
                <pre class="whitespace-pre-wrap font-sans text-sm text-gray-700 dark:text-gray-300" data-constitution-guards-text>{{ $guards }}</pre>
            </section>

            {{-- 2. LE TEXTE ADMINISTRABLE. --}}
            <section class="bg-white dark:bg-gray-800 rounded-xl border-2 border-violet-200 dark:border-violet-800/60 p-6" data-constitution-editor>
                <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.constitution_admin_title') }}</h2>
                    @if($active)
                        <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300" data-constitution-status="active" data-constitution-version="{{ $active->version }}">{{ __('ai.constitution_admin_active', ['version' => $active->version]) }}</span>
                    @else
                        <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300" data-constitution-status="seed">{{ $seedVersion }}</span>
                    @endif
                </div>

                @unless($active)
                    <p class="mb-3 text-sm text-amber-700 dark:text-amber-300" data-constitution-seed-notice>{{ __('ai.constitution_admin_seed_notice') }}</p>
                @endunless

                <form method="POST" action="{{ route('admin.ai-constitution.update') }}">
                    @csrf
                    @method('PUT')
                    <textarea name="body" rows="14" maxlength="{{ $maxChars }}"
                              class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm font-mono"
                              data-constitution-input>{{ old('body', $composedText) }}</textarea>
                    @error('body')
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <button type="submit" class="px-4 py-2 rounded-lg bg-violet-600 text-white text-sm font-medium hover:bg-violet-700" data-constitution-save>{{ __('ai.constitution_admin_save') }}</button>
                        <span class="text-xs text-gray-400">{{ $maxChars }}</span>
                    </div>
                </form>

                @if($active)
                    <form method="POST" action="{{ route('admin.ai-constitution.withdraw') }}" class="mt-3">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-xs text-gray-500 hover:text-red-600 dark:text-gray-400 underline" data-constitution-withdraw>{{ __('ai.constitution_admin_withdraw') }}</button>
                    </form>
                @endif
            </section>

            {{-- 3. L'HISTORIQUE — jamais reecrit. --}}
            @if($historyTotal > 0)
                <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6" data-constitution-history data-constitution-history-total="{{ $historyTotal }}">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">{{ __('ai.constitution_admin_history') }}</h2>
                    <ul class="space-y-1 text-xs text-gray-500 dark:text-gray-400">
                        @foreach($history as $version)
                            <li data-constitution-history-version="{{ $version->version }}">
                                @if($version->author)
                                    {{ __('ai.constitution_admin_version_by', [
                                        'version' => $version->version,
                                        'author' => $version->author->publicDisplayName(),
                                        'date' => optional($version->activated_at ?? $version->created_at)->isoFormat('LLL'),
                                    ]) }}
                                @else
                                    {{ __('ai.constitution_admin_version_by_system', [
                                        'version' => $version->version,
                                        'date' => optional($version->activated_at ?? $version->created_at)->isoFormat('LLL'),
                                    ]) }}
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

        </div>
    </x-page-container>
</x-app-layout>
