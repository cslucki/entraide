<x-admin-layout title="Design / thèmes">
    @php
        $tokenLabels = [
            'page' => 'Fond page',
            'surface' => 'Surface',
            'surface-soft' => 'Surface douce',
            'panel' => 'Panneau',
            'primary' => 'Action principale',
            'primary-deep' => 'Action profonde',
            'accent' => 'Accent / Loop',
            'progress' => 'Progression',
            'validation' => 'Validation',
            'info' => 'Information',
            'warning' => 'Alerte douce',
            'text' => 'Texte principal',
            'muted' => 'Texte secondaire',
            'disabled' => 'Texte désactivé',
            'border' => 'Bordures',
            'card-loop' => 'Carte Boucles',
            'card-exchange' => 'Carte Échanges',
            'card-directory' => 'Carte Annuaire',
            'card-news' => 'Carte Actus',
            'card-welcome' => 'Carte Bienvenue',
        ];
    @endphp

    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div>
            <p class="text-sm font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">Design system</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">Éditeur de thème</h1>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.themes.create') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Nouveau thème
            </a>
            <a href="{{ route('admin.themes.edit', $currentTheme) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 dark:border-gray-600 px-3 py-2 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                Mode tokens
            </a>
        </div>
    </div>

    {{-- Theme navigation --}}
    <div class="mb-6 flex items-center justify-between rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-4 py-3">
        <div class="flex items-center gap-3">
            @if($prevTheme)
                <a href="{{ route('admin.themes', ['theme' => $prevTheme->key]) }}"
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
                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200">Défaut</span>
            @endif
            @if($currentTheme->organization && !$currentTheme->is_default)
                <span class="rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-400">{{ $currentTheme->organization->name }}</span>
            @endif
        </div>

        <div class="flex items-center gap-3">
            @if($nextTheme)
                <a href="{{ route('admin.themes', ['theme' => $nextTheme->key]) }}"
                   class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    {{ $nextTheme->label }}
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
            @else
                <span class="px-3 py-1.5 text-sm text-gray-300 dark:text-gray-600">—</span>
            @endif
        </div>
    </div>

    {{-- Editor form --}}
    <script>
        window.__themeTokens = @json($currentTheme->tokens ?? []);
        window.__themeDarkTokens = @json($currentTheme->dark_tokens ?? []);
    </script>
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('themeEditor', () => ({
                tokens: { ...window.__themeTokens },
                darkTokens: { ...window.__themeDarkTokens },
                editMode: 'visual',
                previewMode: 'light',
                jsonLight: '',
                jsonDark: '',
                highlightedToken: null,
                highlightTimeout: null,
                defaultTokens: { ...window.__themeTokens },
                defaultDarkTokens: { ...window.__themeDarkTokens },

                init() {
                    this.jsonLight = JSON.stringify(this.tokens, null, 2);
                    this.jsonDark = JSON.stringify(this.darkTokens, null, 2);
                },

                resetToken(token) {
                    this.tokens[token] = this.defaultTokens[token];
                    this.darkTokens[token] = this.defaultDarkTokens[token];
                },

                highlight(token) {
                    this.highlightedToken = token;
                    if (this.highlightTimeout) clearTimeout(this.highlightTimeout);
                    this.highlightTimeout = setTimeout(() => { this.highlightedToken = null; }, 2500);
                },

                switchToVisual() {
                    try {
                        const parsed = JSON.parse(this.jsonLight);
                        const parsedDark = JSON.parse(this.jsonDark);
                        Object.keys(parsed).forEach(k => { this.tokens[k] = parsed[k]; });
                        Object.keys(parsedDark).forEach(k => { this.darkTokens[k] = parsedDark[k]; });
                    } catch (e) {}
                    this.editMode = 'visual';
                },

                switchToTokens() {
                    this.jsonLight = JSON.stringify(this.tokens, null, 2);
                    this.jsonDark = JSON.stringify(this.darkTokens, null, 2);
                    this.editMode = 'tokens';
                },

                syncBeforeSubmit() {
                    if (this.editMode === 'tokens') {
                        try {
                            const parsed = JSON.parse(this.jsonLight);
                            const parsedDark = JSON.parse(this.jsonDark);
                            Object.keys(parsed).forEach(k => { this.tokens[k] = parsed[k]; });
                            Object.keys(parsedDark).forEach(k => { this.darkTokens[k] = parsedDark[k]; });
                        } catch (e) {}
                    }
                },

                previewPage() { return this.previewMode === 'dark' ? this.darkTokens.page : this.tokens.page; },
                previewPanel() { return this.previewMode === 'dark' ? this.darkTokens.panel : this.tokens.panel; },
                previewBorder() { return this.previewMode === 'dark' ? this.darkTokens.border : this.tokens.border; },
                previewText() { return this.previewMode === 'dark' ? this.darkTokens.text : this.tokens.text; },
                previewMuted() { return this.previewMode === 'dark' ? this.darkTokens.muted : this.tokens.muted; },
                previewPrimary() { return this.previewMode === 'dark' ? this.darkTokens.primary : this.tokens.primary; },
                previewPrimaryDeep() { return this.previewMode === 'dark' ? this.darkTokens['primary-deep'] : this.tokens['primary-deep']; },
                previewAccent() { return this.previewMode === 'dark' ? this.darkTokens.accent : this.tokens.accent; },
                previewProgress() { return this.previewMode === 'dark' ? this.darkTokens.progress : this.tokens.progress; },
                previewValidation() { return this.previewMode === 'dark' ? this.darkTokens.validation : this.tokens.validation; },
                previewInfo() { return this.previewMode === 'dark' ? this.darkTokens.info : this.tokens.info; },
                previewWarning() { return this.previewMode === 'dark' ? this.darkTokens.warning : this.tokens.warning; },
                previewSurface() { return this.previewMode === 'dark' ? this.darkTokens.surface : this.tokens.surface; },
                previewSurfaceSoft() { return this.previewMode === 'dark' ? this.darkTokens['surface-soft'] : this.tokens['surface-soft']; },
                previewDisabled() { return this.previewMode === 'dark' ? this.darkTokens.disabled : this.tokens.disabled; },
                previewCardLoop() { return this.previewMode === 'dark' ? this.darkTokens['card-loop'] : this.tokens['card-loop']; },
                previewCardExchange() { return this.previewMode === 'dark' ? this.darkTokens['card-exchange'] : this.tokens['card-exchange']; },
                previewCardDirectory() { return this.previewMode === 'dark' ? this.darkTokens['card-directory'] : this.tokens['card-directory']; },
                previewCardNews() { return this.previewMode === 'dark' ? this.darkTokens['card-news'] : this.tokens['card-news']; },
                previewCardWelcome() { return this.previewMode === 'dark' ? this.darkTokens['card-welcome'] : this.tokens['card-welcome']; },
            }));
        });
    </script>
    <form id="theme-edit-form" method="POST" action="{{ route('admin.themes.update', $currentTheme) }}"
          x-data="themeEditor()"
          @submit.prevent="syncBeforeSubmit(); $el.submit()">
        @csrf
        @method('PUT')

        {{-- Hidden info fields --}}
        <input type="hidden" name="key" value="{{ $currentTheme->key }}">
        <input type="hidden" name="label" value="{{ $currentTheme->label }}">
        <input type="hidden" name="description" value="{{ $currentTheme->description }}">
        <input type="hidden" name="is_default" value="{{ $currentTheme->is_default ? '1' : '0' }}">

        {{-- Mode tabs --}}
        <div class="mb-6 flex gap-1 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40 p-1 w-fit">
            <button type="button" @click="switchToVisual()"
                    :class="editMode === 'visual' ? 'bg-white dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition">
                <svg class="inline w-4 h-4 -mt-0.5 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                Visuel
            </button>
            <button type="button" @click="switchToTokens()"
                    :class="editMode === 'tokens' ? 'bg-white dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition">
                <svg class="inline w-4 h-4 -mt-0.5 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                Tokens
            </button>
            <button type="button" @click="editMode = 'colors'"
                    :class="editMode === 'colors' ? 'bg-white dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition">
                <svg class="inline w-4 h-4 -mt-0.5 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                Code couleurs
            </button>
        </div>

        {{-- Mode toggle (above grid) --}}
        <div class="mb-4 flex items-center justify-center gap-2">
            <div class="flex gap-1 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40 p-1">
                <button type="button" @click="previewMode = 'light'"
                        :class="previewMode === 'light' ? 'bg-white dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                        class="rounded-lg px-5 py-2 text-sm font-medium transition">
                    ☀️ Mode clair
                </button>
                <button type="button" @click="previewMode = 'dark'"
                        :class="previewMode === 'dark' ? 'bg-white dark:bg-gray-700 shadow-sm text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                        class="rounded-lg px-5 py-2 text-sm font-medium transition">
                    🌙 Mode sombre
                </button>
            </div>
        </div>

        {{-- ============ 2-COLUMN LAYOUT ============ --}}
        <div class="grid grid-cols-1 lg:grid-cols-[1fr_400px] gap-6 mb-8">
            {{-- LEFT: Colors --}}
            <div>
                {{-- VISUAL MODE --}}
                <div x-show="editMode === 'visual'">
                    {{-- Hidden inputs for non-active mode (needed for form submit) --}}
                    <template x-if="previewMode === 'dark'">
                        <div class="hidden">
                            @foreach($tokenLabels as $token => $label)
                            <input type="hidden" name="tokens[{{ $token }}]" :value="tokens['{{ $token }}']">
                            @endforeach
                        </div>
                    </template>
                    <template x-if="previewMode === 'light'">
                        <div class="hidden">
                            @foreach($tokenLabels as $token => $label)
                            <input type="hidden" name="dark_tokens[{{ $token }}]" :value="darkTokens['{{ $token }}']">
                            @endforeach
                        </div>
                    </template>

                    {{-- Light tokens (shown when previewMode === 'light') --}}
                    <div x-show="previewMode === 'light'" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5">
                        <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-4">
                            <svg class="inline w-4 h-4 -mt-0.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            Tokens — Mode clair
                        </h2>
                        <div class="grid gap-2">
                            @foreach($tokenLabels as $token => $label)
                            <label @click="highlight('{{ $token }}')"
                                   class="flex items-center gap-2 rounded-lg border px-3 py-2 transition-all cursor-pointer"
                                   :class="highlightedToken === '{{ $token }}' ? 'border-indigo-500 ring-2 ring-indigo-300 dark:ring-indigo-700 bg-indigo-50 dark:bg-indigo-900/30' : 'border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40'">
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 w-24 shrink-0">{{ $label }}
                                    <span x-show="highlightedToken === '{{ $token }}'" class="text-[10px] text-indigo-500 dark:text-indigo-400 font-bold ml-0.5">◄</span>
                                </span>
                                <input type="color" x-model="tokens['{{ $token }}']" name="tokens[{{ $token }}]"
                                       class="h-7 w-10 rounded border border-gray-200 dark:border-gray-600 bg-transparent p-0 cursor-pointer">
                                <input type="text" x-model="tokens['{{ $token }}']" name="tokens[{{ $token }}]"
                                       @click.stop
                                       class="min-w-0 flex-1 rounded border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 px-1.5 py-1 text-xs font-mono text-gray-700 dark:text-gray-200">
                                <button type="button" @click.stop="resetToken('{{ $token }}')"
                                        x-show="tokens['{{ $token }}'] !== defaultTokens['{{ $token }}']"
                                        class="shrink-0 p-1 rounded text-gray-300 hover:text-gray-500 dark:text-gray-600 dark:hover:text-gray-400 transition"
                                        title="Réinitialiser">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 4v6h6"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
                                </button>
                            </label>
                            @endforeach
                        </div>
                    </div>

                    {{-- Dark tokens (shown when previewMode === 'dark') --}}
                    <div x-show="previewMode === 'dark'" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5">
                        <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-4">
                            <svg class="inline w-4 h-4 -mt-0.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                            Tokens — Mode sombre
                        </h2>
                        <div class="grid gap-2">
                            @foreach($tokenLabels as $token => $label)
                            <label @click="highlight('{{ $token }}')"
                                   class="flex items-center gap-2 rounded-lg border px-3 py-2 transition-all cursor-pointer"
                                   :class="highlightedToken === '{{ $token }}' ? 'border-indigo-500 ring-2 ring-indigo-300 dark:ring-indigo-700 bg-indigo-50 dark:bg-indigo-900/30' : 'border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40'">
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 w-24 shrink-0">{{ $label }}
                                    <span x-show="highlightedToken === '{{ $token }}'" class="text-[10px] text-indigo-500 dark:text-indigo-400 font-bold ml-0.5">◄</span>
                                </span>
                                <input type="color" x-model="darkTokens['{{ $token }}']" name="dark_tokens[{{ $token }}]"
                                       class="h-7 w-10 rounded border border-gray-200 dark:border-gray-600 bg-transparent p-0 cursor-pointer"
                                       :value="darkTokens['{{ $token }}']">
                                <input type="text" x-model="darkTokens['{{ $token }}']" name="dark_tokens[{{ $token }}]"
                                       @click.stop
                                       class="min-w-0 flex-1 rounded border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 px-1.5 py-1 text-xs font-mono text-gray-700 dark:text-gray-200">
                                <button type="button" @click.stop="resetToken('{{ $token }}')"
                                        x-show="darkTokens['{{ $token }}'] !== defaultDarkTokens['{{ $token }}']"
                                        class="shrink-0 p-1 rounded text-gray-300 hover:text-gray-500 dark:text-gray-600 dark:hover:text-gray-400 transition"
                                        title="Réinitialiser">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 4v6h6"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
                                </button>
                            </label>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- TOKENS MODE --}}
                <div x-show="editMode === 'tokens'" x-cloak>
                    <div x-show="previewMode === 'light'" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5">
                        <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-3">Tokens — mode clair</h3>
                        <textarea x-model="jsonLight" rows="16"
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-900 px-3 py-2 font-mono text-sm text-green-400"></textarea>
                    </div>
                    <div x-show="previewMode === 'dark'" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5">
                        <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-3">Tokens — mode sombre</h3>
                        <textarea x-model="jsonDark" rows="16"
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-900 px-3 py-2 font-mono text-sm text-green-400"></textarea>
                    </div>
                </div>

                {{-- COULEURS MODE --}}
                <div x-show="editMode === 'colors'" x-cloak>
                    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5">
                        <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-4">
                            <svg class="inline w-4 h-4 -mt-0.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                            Code couleurs
                        </h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3"
                           x-text="previewMode === 'dark' ? 'Mode sombre' : 'Mode clair'"></p>
                        <div class="grid gap-1.5">
                            <template x-for="(value, key) in (previewMode === 'dark' ? darkTokens : tokens)" :key="key">
                                <div @click="highlight(key)"
                                     class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-mono transition-all border cursor-pointer"
                                     :class="highlightedToken === key ? 'border-indigo-500 ring-2 ring-indigo-300 dark:ring-indigo-700 bg-indigo-50 dark:bg-indigo-900/30' : 'border-transparent'">
                                    <span class="inline-block w-3 h-3 rounded-full shrink-0" :style="{ backgroundColor: value }"></span>
                                    <span class="text-gray-600 dark:text-gray-400 w-20 shrink-0 font-medium" x-text="key"></span>
                                    <span class="text-gray-800 dark:text-gray-200 font-semibold" x-text="value"></span>
                                    <button type="button" @click.stop="resetToken(key)"
                                            x-show="(previewMode === 'dark' ? defaultDarkTokens[key] : defaultTokens[key]) !== value"
                                            class="shrink-0 p-0.5 rounded text-gray-300 hover:text-gray-500 dark:text-gray-600 dark:hover:text-gray-400 transition"
                                            title="Réinitialiser">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 4v6h6"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
                                    </button>
                                    <span x-show="highlightedToken === key" class="text-[10px] text-indigo-500 dark:text-indigo-400 font-bold">◄</span>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            {{-- RIGHT: Preview — smartphone mockup (sticky) --}}
            <div class="lg:sticky lg:top-6 lg:self-start lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto">
                <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-4"
                    x-text="previewMode === 'dark' ? 'Aperçu téléphone — Mode sombre' : 'Aperçu téléphone — Mode clair'"></h3>

                <p class="text-[10px] text-gray-400 dark:text-gray-500 mb-2">Cliquez sur un élément pour repérer son token.</p>

                {{-- Smartphone frame --}}
                <div class="mx-auto w-full max-w-[400px] rounded-[2.5rem] border-4 p-2 shadow-xl transition-all"
                     :style="{ borderColor: previewText(), backgroundColor: previewPage() }">

                    {{-- Status bar (muted) --}}
                    <div @click="highlight('muted')"
                         class="h-4 cursor-pointer transition-all"
                         :class="highlightedToken === 'muted' ? 'ring-2 ring-indigo-400 ring-inset' : ''"
                         :style="{ backgroundColor: previewPage() }"></div>

                    {{-- Header bar (surface) --}}
                    <div @click="highlight('surface')"
                         class="flex items-center justify-between rounded-t-2xl px-4 py-3 transition-all cursor-pointer"
                         :class="highlightedToken === 'surface' ? 'ring-2 ring-indigo-400 ring-inset' : ''"
                         :style="{ backgroundColor: previewSurface(), borderBottom: '1px solid ' + previewBorder(), color: previewText() }">
                        <div class="flex items-center gap-2">
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                            <span class="text-sm font-bold">BouclePro</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span @click.stop="highlight('progress')"
                                  class="rounded-full w-2 h-2 cursor-pointer transition-all"
                                  :class="highlightedToken === 'progress' ? 'ring-2 ring-indigo-400 ring-offset-1' : ''"
                                  :style="{ backgroundColor: previewProgress() }"></span>
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        </div>
                    </div>

                    {{-- Divider (border) — clickable on header bottom border --}}
                    <div @click="highlight('border')"
                         class="cursor-pointer h-1 transition-all"
                         :class="highlightedToken === 'border' ? 'ring-2 ring-indigo-400 ring-inset' : ''"
                         :style="{ backgroundColor: previewBorder() }"></div>

                    {{-- Search bar (surface-soft) --}}
                    <div class="px-4 py-3 transition-all" :style="{ backgroundColor: previewPage() }">
                        <div @click="highlight('surface-soft')"
                             class="flex items-center gap-2 rounded-xl px-3 py-2 transition-all cursor-pointer"
                             :class="highlightedToken === 'surface-soft' ? 'ring-2 ring-indigo-400' : ''"
                             :style="{ backgroundColor: previewSurfaceSoft(), color: previewMuted(), border: '1px solid ' + previewBorder() }">
                            <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <span class="text-xs" x-text="previewDisabled() ? 'Rechercher...' : 'Rechercher...'" :style="{ color: previewDisabled() }"></span>
                        </div>
                    </div>

                    {{-- Main content (page bg) --}}
                    <div class="px-4 pb-4 space-y-4 transition-all" :style="{ backgroundColor: previewPage() }">

                        {{-- Card (panel) --}}
                        <div @click="highlight('panel')"
                             class="rounded-2xl p-4 shadow-sm transition-all cursor-pointer"
                             :class="highlightedToken === 'panel' ? 'ring-2 ring-indigo-400' : ''"
                             :style="{ backgroundColor: previewPanel(), border: '1px solid ' + previewBorder() }">

                            {{-- Category badge (info) + points (accent) --}}
                            <div class="flex items-center justify-between mb-2">
                                <span @click.stop="highlight('info')"
                                      class="rounded-full px-2.5 py-0.5 text-[10px] font-bold transition-all cursor-pointer"
                                      :class="highlightedToken === 'info' ? 'ring-2 ring-indigo-400 ring-offset-1' : ''"
                                      :style="{ backgroundColor: previewInfo(), color: previewPrimaryDeep() }">Loop</span>
                                <span @click.stop="highlight('accent')"
                                      class="text-xs font-bold transition-all cursor-pointer"
                                      :class="highlightedToken === 'accent' ? 'ring-2 ring-indigo-400 px-0.5' : ''"
                                      :style="{ color: previewAccent() }">150 pts</span>
                            </div>

                            {{-- Title (text), description (muted) --}}
                            <h4 @click.stop="highlight('text')"
                                class="text-sm font-bold transition-all cursor-pointer"
                                :class="highlightedToken === 'text' ? 'ring-2 ring-indigo-400 ring-inset' : ''"
                                :style="{ color: previewText() }">Qui peut m'aider ?</h4>
                            <p @click.stop="highlight('muted')"
                               class="mt-1 text-xs leading-relaxed transition-all cursor-pointer"
                               :class="highlightedToken === 'muted' ? 'ring-2 ring-indigo-400 ring-inset' : ''"
                               :style="{ color: previewMuted() }">
                                Description courte d'un service avec les tokens du thème actif.
                            </p>

                            {{-- Skill tags --}}
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                <span @click.stop="highlight('disabled')"
                                      class="rounded-md px-2 py-0.5 text-[10px] font-semibold transition-all cursor-pointer"
                                      :class="highlightedToken === 'disabled' ? 'ring-2 ring-indigo-400' : ''"
                                      :style="{ backgroundColor: previewSurfaceSoft(), color: previewDisabled() }">Design</span>
                                <span @click.stop="highlight('text')"
                                      class="rounded-md px-2 py-0.5 text-[10px] font-semibold transition-all cursor-pointer"
                                      :class="highlightedToken === 'text' ? 'ring-2 ring-indigo-400' : ''"
                                      :style="{ backgroundColor: previewSurfaceSoft(), color: previewText() }">Web</span>
                            </div>

                            {{-- Badges: validation, progress, warning --}}
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                <span @click.stop="highlight('validation')"
                                      class="rounded-full px-2 py-0.5 text-[10px] font-extrabold transition-all cursor-pointer"
                                      :class="highlightedToken === 'validation' ? 'ring-2 ring-indigo-400 ring-offset-1' : ''"
                                      :style="{ backgroundColor: previewValidation(), color: previewText() }">À valider</span>
                                <span @click.stop="highlight('progress')"
                                      class="rounded-full px-2 py-0.5 text-[10px] font-extrabold transition-all cursor-pointer"
                                      :class="highlightedToken === 'progress' ? 'ring-2 ring-indigo-400 ring-offset-1' : ''"
                                      :style="{ backgroundColor: previewProgress(), color: previewText() }">En cours</span>
                                <span @click.stop="highlight('warning')"
                                      class="rounded-full px-2 py-0.5 text-[10px] font-extrabold transition-all cursor-pointer"
                                      :class="highlightedToken === 'warning' ? 'ring-2 ring-indigo-400 ring-offset-1' : ''"
                                      :style="{ backgroundColor: previewWarning(), color: previewText() }">Urgent</span>
                            </div>

                            {{-- Divider (border) + user info --}}
                            <div class="mt-3 pt-3 transition-all" :style="{ borderTop: '1px solid ' + previewBorder() }">
                                <div class="flex items-center gap-2">
                                    <div @click.stop="highlight('accent')"
                                         class="w-6 h-6 rounded-full transition-all cursor-pointer"
                                         :class="highlightedToken === 'accent' ? 'ring-2 ring-indigo-400 ring-offset-1' : ''"
                                         :style="{ backgroundColor: previewAccent(), opacity: 0.6 }"></div>
                                    <span @click.stop="highlight('text')"
                                          class="text-xs font-medium transition-all cursor-pointer"
                                          :class="highlightedToken === 'text' ? 'ring-2 ring-indigo-400 ring-inset' : ''"
                                          :style="{ color: previewText() }">Marie</span>
                                    <span @click.stop="highlight('muted')"
                                          class="text-[10px] transition-all cursor-pointer"
                                          :class="highlightedToken === 'muted' ? 'ring-2 ring-indigo-400 ring-inset' : ''"
                                          :style="{ color: previewMuted() }">· 2h</span>
                                </div>
                            </div>

                            {{-- Primary button --}}
                            <button @click.stop="highlight('primary')" type="button"
                                    class="mt-4 w-full rounded-xl py-2.5 text-xs font-bold text-white shadow-sm transition-all cursor-pointer"
                                    :class="highlightedToken === 'primary' ? 'ring-2 ring-indigo-400 ring-offset-1' : ''"
                                    :style="{ backgroundColor: previewPrimary() }">
                                Proposer un échange
                            </button>

                            {{-- Secondary button (primary deep) --}}
                            <button @click.stop="highlight('primary-deep')" type="button"
                                    class="mt-2 w-full rounded-xl py-2 text-xs font-semibold transition-all cursor-pointer"
                                    :class="highlightedToken === 'primary-deep' ? 'ring-2 ring-indigo-400' : ''"
                                    :style="{ border: '1px solid ' + previewBorder(), color: previewPrimaryDeep() }">
                                En savoir plus
                            </button>

                            {{-- Disabled button --}}
                            <button @click.stop="highlight('disabled')" type="button" disabled
                                    class="mt-2 w-full rounded-xl py-2 text-xs font-semibold cursor-pointer transition-all"
                                    :class="highlightedToken === 'disabled' ? 'ring-2 ring-indigo-400' : ''"
                                    :style="{ backgroundColor: previewSurfaceSoft(), color: previewDisabled() }">
                                Action désactivée
                            </button>
                        </div>

                        {{-- Welcome card (page d'accueil) --}}
                        <div @click.stop="highlight('card-welcome')"
                             class="rounded-2xl p-4 shadow-sm transition-all cursor-pointer"
                             :class="highlightedToken === 'card-welcome' ? 'ring-2 ring-indigo-400' : ''"
                             :style="{ backgroundColor: previewCardWelcome() }">
                            <p class="text-[10px] font-bold text-black dark:text-black">Interface sobre</p>
                            <p class="mt-2 text-sm font-bold text-black dark:text-black">Que voulez-vous faire aujourd'hui ?</p>
                            <p class="mt-1 text-[10px] leading-relaxed text-black dark:text-black">Entrez dans une boucle, trouvez un échange, suivez vos objectifs ou lisez les actus.</p>
                            <div class="mt-3 flex gap-2">
                                <span class="rounded-full bg-black/10 px-3 py-1 text-[9px] font-semibold text-black dark:text-black">Créer un compte</span>
                                <span class="rounded-full border border-black/20 px-3 py-1 text-[9px] font-semibold text-black dark:text-black">Découvrir</span>
                            </div>
                        </div>

                        {{-- Feature cards (page d'accueil) --}}
                        <div class="space-y-2">
                            <p class="text-[10px] font-semibold uppercase tracking-wider" :style="{ color: previewMuted() }">Accès rapides</p>
                            <div class="grid grid-cols-2 gap-2">
                                <div @click.stop="highlight('card-loop')"
                                     class="rounded-2xl p-3 shadow-sm transition-all cursor-pointer"
                                     :class="highlightedToken === 'card-loop' ? 'ring-2 ring-indigo-400' : ''"
                                     :style="{ backgroundColor: previewCardLoop() }">
                                    <p class="text-xs font-bold text-black dark:text-black">Boucles</p>
                                    <p class="mt-1.5 text-[10px] leading-tight opacity-85 text-black dark:text-black">ChatLoop comme point de départ.</p>
                                </div>
                                <div @click.stop="highlight('card-exchange')"
                                     class="rounded-2xl p-3 shadow-sm transition-all cursor-pointer"
                                     :class="highlightedToken === 'card-exchange' ? 'ring-2 ring-indigo-400' : ''"
                                     :style="{ backgroundColor: previewCardExchange() }">
                                    <p class="text-xs font-bold text-black dark:text-black">Échanges</p>
                                    <p class="mt-1.5 text-[10px] leading-tight opacity-85 text-black dark:text-black">Services et conversations utiles.</p>
                                </div>
                                <div @click.stop="highlight('card-directory')"
                                     class="rounded-2xl p-3 shadow-sm transition-all cursor-pointer"
                                     :class="highlightedToken === 'card-directory' ? 'ring-2 ring-indigo-400' : ''"
                                     :style="{ backgroundColor: previewCardDirectory() }">
                                    <p class="text-xs font-bold text-black dark:text-black">Annuaire</p>
                                    <p class="mt-1.5 text-[10px] leading-tight opacity-85 text-black dark:text-black">Membres, profils et contacts.</p>
                                </div>
                                <div @click.stop="highlight('card-news')"
                                     class="rounded-2xl p-3 shadow-sm transition-all cursor-pointer"
                                     :class="highlightedToken === 'card-news' ? 'ring-2 ring-indigo-400' : ''"
                                     :style="{ backgroundColor: previewCardNews() }">
                                    <p class="text-xs font-bold text-black dark:text-black">Actus</p>
                                    <p class="mt-1.5 text-[10px] leading-tight opacity-85 text-black dark:text-black">Nouvelles de la communauté.</p>
                                </div>
                            </div>
                        </div>

                        {{-- Warning banner --}}
                        <div @click="highlight('warning')"
                             class="flex items-center gap-2 rounded-xl px-4 py-3 text-xs font-medium transition-all cursor-pointer"
                             :class="highlightedToken === 'warning' ? 'ring-2 ring-indigo-400' : ''"
                             :style="{ backgroundColor: previewWarning(), color: previewText() }">
                            <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                            <span>Votre abonnement expire bientôt.</span>
                        </div>
                    </div>

                    {{-- Bottom navigation (surface) — real app tabs --}}
                    <div @click="highlight('surface')"
                         class="flex items-center justify-around rounded-b-2xl px-2 py-2 transition-all cursor-pointer"
                         :class="highlightedToken === 'surface' ? 'ring-2 ring-indigo-400 ring-inset' : ''"
                         :style="{ backgroundColor: previewSurface(), borderTop: '1px solid ' + previewBorder() }">
                        <span class="flex flex-col items-center gap-0.5 transition-all" :style="{ color: previewMuted() }">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <span class="text-[8px] font-medium">Boucles</span>
                        </span>
                        <span class="flex flex-col items-center gap-0.5 transition-all" :style="{ color: previewMuted() }">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 16V4m0 0L3 8m4-4 4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
                            <span class="text-[8px] font-medium">Échanges</span>
                        </span>
                        <span @click.stop="highlight('accent')"
                              class="flex flex-col items-center gap-0.5 transition-all cursor-pointer"
                              :class="highlightedToken === 'accent' ? 'ring-2 ring-indigo-400 rounded px-1' : ''"
                              :style="{ color: previewAccent() }">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                            <span class="text-[8px] font-medium">Annuaire</span>
                        </span>
                        <span class="flex flex-col items-center gap-0.5 transition-all" :style="{ color: previewMuted() }">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
                            <span class="text-[8px] font-medium">Actus</span>
                        </span>
                    </div>

                    {{-- Home indicator (muted) --}}
                    <div @click="highlight('muted')"
                         class="flex justify-center py-2 transition-all cursor-pointer"
                         :class="highlightedToken === 'muted' ? 'ring-2 ring-indigo-400 ring-inset' : ''"
                         :style="{ backgroundColor: previewPage() }">
                        <div class="w-24 h-1 rounded-full transition-all"
                             :style="{ backgroundColor: previewMuted() }"></div>
                    </div>
                </div>
            </div>
        </div>

    </form>

    {{-- Actions --}}
    <div class="flex items-center gap-3 border-t border-gray-100 dark:border-gray-700 pt-6">
        <button type="submit" form="theme-edit-form" class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
            Enregistrer
        </button>
        @if(!$currentTheme->is_default)
        <form method="POST" action="{{ route('admin.themes.destroy', $currentTheme) }}"
              onsubmit="return confirm('Supprimer le thème « {{ $currentTheme->label }} » ?')" class="inline">
            @csrf @method('DELETE')
            <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 dark:border-red-800 px-5 py-2.5 text-sm font-semibold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                Supprimer
            </button>
        </form>
        @endif
    </div>
</x-admin-layout>
