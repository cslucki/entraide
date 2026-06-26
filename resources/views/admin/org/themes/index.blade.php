<x-org-admin-layout title="{{ __('navigation.org_admin_design') }}" :organization="$organization">
    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div>
            <p class="text-sm font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">{{ __('themes.design_system') }}</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('themes.org_theme_editor') }}</h1>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('organization.admin.themes.create', $organization) }}"
               class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                {{ __('themes.new_theme') }}
            </a>
        </div>
    </div>

    {{-- Theme navigation --}}
    <div class="mb-6 flex items-center justify-between rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-4 py-3">
        <div class="flex items-center gap-3">
            @if($prevTheme)
                <a href="{{ route('organization.admin.themes', [$organization, 'theme' => $prevTheme->key]) }}"
                   class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    {{ $prevTheme->label }}
                </a>
            @else
                <span class="px-3 py-1.5 text-sm text-gray-300 dark:text-gray-600">—</span>
            @endif
        </div>

        <div class="flex items-center gap-2 text-center">
            <span class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ $currentTheme->label }}</span>
            @if($currentTheme->is_default)
                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200">{{ __('themes.default') }}</span>
            @endif
            @if($organization->theme_id === $currentTheme->id)
                <span class="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">{{ __('themes.active') }}</span>
            @endif
        </div>

        <div class="flex items-center gap-3">
            @if($nextTheme)
                <a href="{{ route('organization.admin.themes', [$organization, 'theme' => $nextTheme->key]) }}"
                   class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    {{ $nextTheme->label }}
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
            @else
                <span class="px-3 py-1.5 text-sm text-gray-300 dark:text-gray-600">—</span>
            @endif
        </div>
    </div>

    {{-- Theme cards — horizontal tablet previews with per-card dark/light toggle --}}
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-5 mb-8">
        @foreach($themes as $theme)
            @php
                $isOwn = $theme->organization_id === $organization->id;
                $isActive = $organization->theme_id === $theme->id;
                $primaryColor = ($theme->tokens['primary'] ?? '#0B4DFF');
            @endphp
            <div x-data="{ dark: false }"
                 class="rounded-xl border transition-all overflow-hidden @if($isActive) ring-2 ring-indigo-500 shadow-lg @else border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md @endif">

                {{-- Light preview --}}
                <div x-show="!dark" x-cloak>
                    @include('admin.org.themes._tablet-preview', ['tokens' => $theme->tokens ?? []])
                </div>

                {{-- Dark preview --}}
                <div x-show="dark" x-cloak>
                    @include('admin.org.themes._tablet-preview', ['tokens' => $theme->dark_tokens ?? $theme->tokens ?? []])
                </div>

                {{-- Card footer: meta + actions + light/dark toggle --}}
                <div class="flex items-center justify-between gap-3 border-t border-gray-100 dark:border-gray-700 px-4 py-2.5">

                    <div class="flex items-center gap-2 min-w-0">
                        <span class="font-semibold text-sm text-gray-900 dark:text-gray-100 truncate">{{ $theme->label }}</span>
                        @if($theme->is_default)
                            <span class="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200">{{ __('themes.default') }}</span>
                        @endif
                        @if($isActive)
                            <span class="shrink-0 rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-semibold text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">{{ __('themes.current') }}</span>
                        @endif
                        @if($theme->organization)
                            <span class="shrink-0 text-[10px] font-medium text-gray-400 dark:text-gray-500">{{ $theme->organization->name }}</span>
                        @endif
                    </div>

                    <div class="flex items-center gap-1.5 shrink-0">
                        {{-- Light/dark preview toggle --}}
                        <button @click="dark = !dark" type="button"
                                class="rounded-lg border px-2 py-1.5 transition hover:bg-gray-50 dark:hover:bg-gray-700"
                                style="border-color: {{ $primaryColor }}; color: {{ $primaryColor }};"
                                :title="dark ? '{{ __('themes.preview_light') }}' : '{{ __('themes.preview_dark') }}'">
                            <template x-if="!dark">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                            </template>
                            <template x-if="dark">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            </template>
                        </button>

                        @if(!$isActive)
                            <form method="POST" action="{{ route('organization.admin.themes.assign', [$organization, $theme]) }}">
                                @csrf
                                <button type="submit" class="rounded-lg px-2.5 py-1.5 text-[11px] font-semibold text-white transition"
                                        style="background-color: {{ $primaryColor }};">
                                    {{ __('themes.select') }}
                                </button>
                            </form>
                        @endif
                        @if($isOwn)
                            <a href="{{ route('organization.admin.themes.edit', [$organization, $theme]) }}"
                               class="rounded-lg border px-2.5 py-1.5 text-[11px] font-semibold transition hover:bg-gray-50 dark:hover:bg-gray-700"
                               style="border-color: {{ $primaryColor }}; color: {{ $primaryColor }};">
                                {{ __('themes.edit') }}
                            </a>
                            @if(!$theme->is_default)
                                <form method="POST" action="{{ route('organization.admin.themes.destroy', [$organization, $theme]) }}"
                                      onsubmit="return confirm('{{ __('themes.delete_confirm', ['label' => $theme->label]) }}')" class="inline">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="rounded-lg border px-2.5 py-1.5 text-[11px] font-semibold text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition"
                                            style="border-color: {{ $primaryColor }};">
                                        {{ __('themes.delete') }}
                                    </button>
                                </form>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</x-org-admin-layout>
