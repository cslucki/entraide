{{--
    Observatoire vivant des connaissances Organization (TASK-1217 console,
    TASK-1226 vivant), read-only.

    La page ne porte que l'enveloppe : titre, badge de mise a jour, bouton
    « Actualiser », et le conteneur vivant. Tout le contenu (etat de
    l'infrastructure, perimetres, compteurs, sources, diagnostics) vit dans le
    partiel `partials/ai-knowledge-live`, rendu ici ET renvoye par l'endpoint
    `ai-knowledge.live` : le poll remplace le conteneur, jamais la page.

    Le poll est read-only et bon marche (0 appel IA). Il tourne toutes les
    2 s quand l'onglet est visible, se met en pause quand il est masque, et
    reprend immediatement au retour. Aucun statut n'est devine : ce qui n'est
    pas demontrable par une requete n'est pas affiche.
--}}
<x-org-admin-layout :title="__('ai.observatory_title')" :organization="$organization">
    <div x-data="knowledgeObservatory({
            url: @js($liveUrl),
            sourceUrlTemplate: @js($sourceUrlTemplate),
            searchUrl: @js($searchUrl),
            intervalMs: 2000,
            labels: {
                live: @js(__('ai.observatory_auto_refresh')),
                paused: @js(__('ai.observatory_auto_refresh_paused')),
                error: @js(__('ai.observatory_auto_refresh_error')),
                stopped: @js(__('ai.observatory_auto_refresh_stopped')),
                lastChecked: @js(__('ai.observatory_last_checked')),
                filterCount: @js(__('ai.knowledge_console_filter_count')),
            },
        })"
        x-init="start()"
        @click="handleClick($event)"
        @input.debounce.200ms="handleInput($event)"
        data-knowledge-observatory
        data-knowledge-live-url="{{ $liveUrl }}">

        <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100" data-knowledge-title>{{ __('ai.observatory_title') }}</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('ai.observatory_intro') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 md:justify-end" data-knowledge-status>
                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                      :class="{
                          'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200': status === 'live',
                          'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300': status === 'paused' || status === 'idle',
                          'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200': status === 'error',
                          'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200': status === 'stopped',
                      }"
                      data-knowledge-refresh-badge
                      :data-status="status">
                    <span class="relative flex h-2 w-2" aria-hidden="true">
                        <span class="absolute inline-flex h-full w-full rounded-full opacity-75"
                              :class="{ 'animate-ping bg-emerald-400': status === 'live', 'bg-gray-400': status !== 'live' }"></span>
                        <span class="relative inline-flex h-2 w-2 rounded-full"
                              :class="{ 'bg-emerald-500': status === 'live', 'bg-gray-400': status === 'paused' || status === 'idle', 'bg-amber-500': status === 'error', 'bg-red-500': status === 'stopped' }"></span>
                    </span>
                    <span x-text="badgeLabel()">{{ __('ai.observatory_auto_refresh') }}</span>
                </span>
                <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400" data-knowledge-last-checked x-text="lastCheckedLabel()">{{ __('ai.observatory_last_checked', ['seconds' => 0]) }}</span>
                <button type="button"
                        @click="refresh(true)"
                        :disabled="busy"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-60 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                        data-knowledge-refresh-button>
                    <svg class="h-3.5 w-3.5" :class="{ 'animate-spin': busy }" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                    {{ __('ai.observatory_refresh_now') }}
                </button>
            </div>
        </div>

        {{-- TASK-1307 : recherche documentaire BRUTE — pgvector seul, aucune
             generation LLM. Hors de la zone rafraichie automatiquement : un
             resultat obtenu ne disparait pas au prochain poll. --}}
        <div class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800" data-knowledge-search>
            <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.knowledge_console_search_title') }}</h2>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_search_hint') }}</p>
            </div>
            <form class="flex flex-wrap items-center gap-2 px-4 py-3" @submit.prevent="runSearch()">
                <input type="search" x-model="searchQuery" placeholder="{{ __('ai.knowledge_console_search_placeholder') }}" required
                       class="min-w-[16rem] flex-1 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 placeholder:text-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                <select x-model="searchLoopId" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    <option value="">{{ __('ai.knowledge_console_search_scope_all') }}</option>
                    @foreach($loops as $loopOption)
                        <option value="{{ $loopOption->id }}">{{ $loopOption->name }}</option>
                    @endforeach
                </select>
                <button type="submit" :disabled="searchBusy || !searchQuery"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                    <svg x-show="searchBusy" class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                    {{ __('ai.knowledge_console_search_submit') }}
                </button>
            </form>
            <div class="border-t border-gray-200 px-4 py-3 dark:border-gray-700" data-search-results x-show="searchHtml" x-html="searchHtml"></div>
        </div>

        <div x-ref="live" data-knowledge-live aria-live="polite" :aria-busy="busy ? 'true' : 'false'">
            @include('admin.org.partials.ai-knowledge-live')
        </div>

        {{-- TASK-1307 : drawer « Inspecter » — chunks reellement indexes d'une source. --}}
        <div x-show="inspectOpen" x-cloak
             class="fixed inset-0 z-50 flex items-start justify-end bg-gray-900/40 p-4 sm:p-6"
             @click.self="inspectOpen = false" @keydown.escape.window="inspectOpen = false">
            <div class="flex h-full w-full max-w-lg flex-col overflow-hidden rounded-xl bg-white shadow-xl dark:bg-gray-800">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.knowledge_console_inspect') }}</h2>
                    <button type="button" @click="inspectOpen = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" aria-label="{{ __('ai.knowledge_console_close') }}">&times;</button>
                </div>
                <div class="flex-1 overflow-y-auto px-4 py-4">
                    <p x-show="inspectLoading" class="text-sm text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_loading') }}</p>
                    <div x-show="!inspectLoading" x-html="inspectHtml"></div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('knowledgeObservatory', (config) => ({
                url: config.url,
                sourceUrlTemplate: config.sourceUrlTemplate || '',
                searchUrl: config.searchUrl || '',
                intervalMs: config.intervalMs || 2000,
                labels: config.labels || {},
                status: 'idle',
                busy: false,
                timer: null,
                ticker: null,
                failures: 0,
                currentIntervalMs: config.intervalMs || 2000,
                lastCheckedAt: Date.now(),
                secondsAgo: 0,
                onVisibilityChange: null,

                // TASK-1307 — filtres cote client (texte + Boucle active).
                filterText: '',
                filterLoopId: null,
                filterLoopName: '',

                // TASK-1307 — drawer « Inspecter ».
                inspectOpen: false,
                inspectLoading: false,
                inspectHtml: '',

                // TASK-1307 — recherche documentaire brute.
                searchQuery: '',
                searchLoopId: '',
                searchBusy: false,
                searchHtml: '',

                start() {
                    this.onVisibilityChange = () => this.applyVisibility();
                    document.addEventListener('visibilitychange', this.onVisibilityChange);
                    this.ticker = window.setInterval(() => this.updateAgo(), 1000);
                    this.applyVisibility();
                },

                destroy() {
                    this.stopTimer();
                    if (this.ticker) window.clearInterval(this.ticker);
                    if (this.onVisibilityChange) document.removeEventListener('visibilitychange', this.onVisibilityChange);
                },

                applyVisibility() {
                    if (this.status === 'stopped') return;
                    if (document.hidden) {
                        this.stopTimer();
                        this.status = 'paused';
                        return;
                    }
                    this.refresh();
                    this.startTimer();
                },

                startTimer() {
                    this.stopTimer();
                    this.currentIntervalMs = Math.min(this.intervalMs * Math.pow(2, this.failures), 30000);
                    this.timer = window.setInterval(() => this.refresh(), this.currentIntervalMs);
                },

                stopTimer() {
                    if (this.timer) {
                        window.clearInterval(this.timer);
                        this.timer = null;
                    }
                },

                async refresh(manual = false) {
                    if (this.busy || this.status === 'stopped') return;
                    if (!manual && document.hidden) return;
                    this.busy = true;
                    try {
                        const response = await fetch(this.url, {
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
                            credentials: 'same-origin',
                            cache: 'no-store',
                        });
                        if (response.status === 401 || response.status === 403 || response.status === 419) {
                            // Session expiree ou droit retire : on n'insiste pas.
                            this.stopTimer();
                            this.status = 'stopped';
                            return;
                        }
                        if (!response.ok) throw new Error('HTTP ' + response.status);
                        const html = await response.text();
                        // Session expiree : le middleware `auth` repond par une
                        // redirection vers /login (200 apres suivi). Un fragment
                        // legitime porte toujours son horodatage serveur ; tout
                        // autre contenu n'entre jamais dans l'Observatoire.
                        if (response.redirected || !html.includes('data-knowledge-generated-at=')) {
                            this.stopTimer();
                            this.status = 'stopped';
                            return;
                        }
                        this.swap(html);
                        this.lastCheckedAt = Date.now();
                        this.failures = 0;
                        if (this.timer && this.currentIntervalMs !== this.intervalMs) this.startTimer();
                        this.status = document.hidden ? 'paused' : 'live';
                    } catch (error) {
                        // Panne serveur/reseau : on reessaie, de moins en moins
                        // souvent (2 s -> 4 s -> ... -> 30 s max), sans jamais
                        // fabriquer un etat.
                        this.failures = Math.min(this.failures + 1, 6);
                        this.status = 'error';
                        if (this.timer) this.startTimer();
                    } finally {
                        this.busy = false;
                        this.updateAgo();
                    }
                },

                // Remplace le conteneur vivant en conservant l'ouverture des
                // diagnostics, et signale visuellement (3 s) les lignes
                // apparues ou dont l'etat/le nombre d'extraits a change —
                // depuis les attributs data-* rendus par le serveur, jamais
                // depuis un etat devine cote client.
                swap(html) {
                    const live = this.$refs.live;
                    const before = this.snapshot(live);
                    const wasOpen = live.querySelector('details[data-rag-diagnostics]')?.open === true;
                    live.innerHTML = html;
                    if (wasOpen) live.querySelector('details[data-rag-diagnostics]')?.setAttribute('open', '');
                    live.querySelectorAll('tr[data-source-key]').forEach((row) => {
                        const key = row.dataset.sourceKey;
                        const previous = before.get(key);
                        const changed = !previous
                            || previous.indexed !== row.dataset.sourceIndexed
                            || previous.chunks !== row.dataset.sourceChunks;
                        if (changed) this.flash(row);
                    });
                    this.applyFilter();
                },

                snapshot(root) {
                    const map = new Map();
                    root.querySelectorAll('tr[data-source-key]').forEach((row) => {
                        map.set(row.dataset.sourceKey, { indexed: row.dataset.sourceIndexed, chunks: row.dataset.sourceChunks });
                    });
                    return map;
                },

                flash(row) {
                    row.classList.add('bg-emerald-50', 'dark:bg-emerald-900/20');
                    row.setAttribute('data-source-changed', '1');
                    window.setTimeout(() => {
                        row.classList.remove('bg-emerald-50', 'dark:bg-emerald-900/20');
                        row.removeAttribute('data-source-changed');
                    }, 3000);
                },

                updateAgo() {
                    this.secondsAgo = Math.max(0, Math.round((Date.now() - this.lastCheckedAt) / 1000));
                },

                badgeLabel() {
                    return this.labels[this.status] || this.labels.live || '';
                },

                lastCheckedLabel() {
                    return (this.labels.lastChecked || ':seconds').replace(':seconds', String(this.secondsAgo));
                },

                // TASK-1307 — un seul point d'entree, delegue depuis la
                // racine (survit au remplacement de $refs.live par le poll).
                handleClick(event) {
                    const loopButton = event.target.closest('[data-filter-loop]');
                    if (loopButton) {
                        this.filterLoopId = loopButton.dataset.filterLoop;
                        this.filterLoopName = loopButton.textContent.trim();
                        this.applyFilter();
                        return;
                    }

                    if (event.target.closest('[data-filter-loop-clear]')) {
                        this.filterLoopId = null;
                        this.filterLoopName = '';
                        this.applyFilter();
                        return;
                    }

                    const inspectButton = event.target.closest('[data-inspect-source]');
                    if (inspectButton) {
                        this.openInspect(inspectButton.dataset.inspectSource, inspectButton.dataset.inspectId);
                    }
                },

                handleInput(event) {
                    if (event.target.matches('[data-filter-text]')) {
                        this.filterText = event.target.value;
                        this.applyFilter();
                    }
                },

                // Filtre les lignes DEJA rendues — aucune requete. Le
                // perimetre reel (tenant, permissions) reste celui du
                // serveur : ceci ne fait que montrer/cacher.
                applyFilter() {
                    const live = this.$refs.live;
                    if (!live) return;

                    const text = this.filterText.trim().toLowerCase();
                    let visible = 0;

                    live.querySelectorAll('tr[data-rag-source]').forEach((row) => {
                        const matchesLoop = !this.filterLoopId || row.dataset.sourceLoopId === this.filterLoopId;
                        const matchesText = !text
                            || (row.dataset.sourceTitle || '').includes(text)
                            || (row.dataset.sourceDossier || '').includes(text);
                        const show = matchesLoop && matchesText;
                        row.classList.toggle('hidden', !show);
                        if (show) visible++;
                    });

                    const chip = live.parentElement?.querySelector('[data-filter-loop-chip]')
                        || document.querySelector('[data-filter-loop-chip]');
                    if (chip) {
                        chip.classList.toggle('hidden', !this.filterLoopId);
                        const nameEl = chip.querySelector('[data-filter-loop-name]');
                        if (nameEl) nameEl.textContent = this.filterLoopName;
                    }

                    const countEl = document.querySelector('[data-filter-count]');
                    if (countEl && (this.filterLoopId || text)) {
                        countEl.textContent = (this.labels.filterCount || ':count').replace(':count', String(visible));
                    } else if (countEl) {
                        countEl.textContent = '';
                    }
                },

                async openInspect(type, id) {
                    this.inspectOpen = true;
                    this.inspectLoading = true;
                    this.inspectHtml = '';
                    try {
                        const url = this.sourceUrlTemplate.replace('__TYPE__', type).replace('__ID__', id);
                        const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                        this.inspectHtml = response.ok ? await response.text() : '';
                    } catch (error) {
                        this.inspectHtml = '';
                    } finally {
                        this.inspectLoading = false;
                    }
                },

                async runSearch() {
                    if (!this.searchQuery || this.searchBusy) return;
                    this.searchBusy = true;
                    try {
                        const params = new URLSearchParams({ q: this.searchQuery });
                        if (this.searchLoopId) params.set('loop_id', this.searchLoopId);
                        const response = await fetch(this.searchUrl + '?' + params.toString(), {
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        });
                        this.searchHtml = response.ok ? await response.text() : '';
                    } catch (error) {
                        this.searchHtml = '';
                    } finally {
                        this.searchBusy = false;
                    }
                },
            }));
        });
    </script>
    @endpush
</x-org-admin-layout>
