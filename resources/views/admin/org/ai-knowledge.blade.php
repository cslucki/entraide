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
            intervalMs: 2000,
            labels: {
                live: @js(__('ai.observatory_auto_refresh')),
                paused: @js(__('ai.observatory_auto_refresh_paused')),
                error: @js(__('ai.observatory_auto_refresh_error')),
                stopped: @js(__('ai.observatory_auto_refresh_stopped')),
                lastChecked: @js(__('ai.observatory_last_checked')),
                lastCheckedNever: @js(__('ai.observatory_last_checked_never')),
            },
        })"
        x-init="start()"
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

        <div x-ref="live" data-knowledge-live>
            @include('admin.org.partials.ai-knowledge-live')
        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('knowledgeObservatory', (config) => ({
                url: config.url,
                intervalMs: config.intervalMs || 2000,
                labels: config.labels || {},
                status: 'idle',
                busy: false,
                timer: null,
                ticker: null,
                lastCheckedAt: Date.now(),
                secondsAgo: 0,
                onVisibilityChange: null,

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
                    this.timer = window.setInterval(() => this.refresh(), this.intervalMs);
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
                        this.swap(await response.text());
                        this.lastCheckedAt = Date.now();
                        this.status = document.hidden ? 'paused' : 'live';
                    } catch (error) {
                        this.status = 'error';
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
                    if (!this.lastCheckedAt) return this.labels.lastCheckedNever || '';
                    return (this.labels.lastChecked || ':seconds').replace(':seconds', String(this.secondsAgo));
                },
            }));
        });
    </script>
    @endpush
</x-org-admin-layout>
