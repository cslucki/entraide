{{--
    TASK-1866 — tiroir d'apercu du mode GRILLE (maquette N).

    Tiroir lateral droit des 640 px, feuille du bas en dessous. Son contenu est
    le fragment `loops.preview` (T1865) TEL QUEL, recharge a chaque ouverture :
    la page initiale ne porte aucun message ni membre, et rien n'est garde en
    memoire d'une ouverture a l'autre.

    Etat purement LOCAL (decision MASTER) : ni parametre d'URL, ni ancre, ni
    `pushState`, ni stockage. La tuile garde son vrai lien vers la Boucle.

    Robustesse du chargement :
    - une seule requete vivante : la precedente est annulee (AbortController),
      et un numero de sequence ecarte toute reponse arrivee apres coup ;
    - succes HTTP exige, puis IDENTITE du fragment : seule la section
      `[data-loop-preview="<id de la tuile>"]` est injectee — une page de
      connexion (session expiree) ou toute autre reponse inattendue donne
      l'etat d'erreur, jamais une page dans le tiroir ;
    - 404 (droit perdu, SuperAdmin hors tenant) : repli sur le lien normal.

    Accessibilite : `role="dialog"` + `aria-modal`, nom accessible = nom de la
    Boucle, focus pose sur « Fermer », piege de focus (Tab / Maj+Tab), Echap,
    voile et bouton ferment, focus rendu a la tuile si elle existe encore,
    defilement de la page bloque puis restaure, `motion-reduce` partout.
--}}
<div x-show="preview.open" x-cloak
     x-transition:leave="transition duration-200 motion-reduce:duration-0"
     data-loops-preview-drawer
     @keydown.escape.window="closeLoopPreview()"
     class="fixed inset-0 z-[70]">
    <div aria-hidden="true" @click="closeLoopPreview()"
         x-show="preview.open"
         x-transition:enter="transition-opacity duration-200 motion-reduce:transition-none motion-reduce:duration-0"
         x-transition:enter-start="opacity-0"
         x-transition:leave="transition-opacity duration-150 motion-reduce:transition-none motion-reduce:duration-0"
         x-transition:leave-end="opacity-0"
         class="absolute inset-0 bg-gray-950/60"></div>

    <div x-ref="loopPreviewPanel"
         role="dialog" aria-modal="true" :aria-label="preview.title"
         data-loops-preview-panel
         @keydown.tab="trapLoopPreviewFocus($event)"
         x-show="preview.open"
         x-transition:enter="transition-transform duration-300 ease-out motion-reduce:transition-none motion-reduce:duration-0"
         x-transition:enter-start="translate-y-full sm:translate-x-full sm:translate-y-0"
         x-transition:leave="transition-transform duration-200 ease-in motion-reduce:transition-none motion-reduce:duration-0"
         x-transition:leave-end="translate-y-full sm:translate-x-full sm:translate-y-0"
         class="absolute inset-x-0 bottom-0 flex max-h-[85vh] flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl supports-[height:100dvh]:max-h-[85dvh] dark:bg-gray-800 sm:inset-y-0 sm:left-auto sm:right-0 sm:max-h-none sm:w-[28rem] sm:rounded-none sm:border-l sm:border-gray-200 sm:supports-[height:100dvh]:max-h-none dark:sm:border-gray-700">
        {{-- Poignee de la feuille du bas : decor, le bouton « Fermer » reste le geste. --}}
        <div class="flex shrink-0 justify-center bg-white py-2 dark:bg-gray-800 sm:hidden" aria-hidden="true">
            <span class="h-1 w-10 rounded-full bg-gray-300 dark:bg-gray-600"></span>
        </div>

        <button type="button" x-ref="loopPreviewClose" @click="closeLoopPreview()"
                data-loops-preview-close
                aria-label="{{ __('ui.close') }}" title="{{ __('ui.close') }}"
                class="absolute right-3 top-7 z-20 inline-flex h-9 w-9 items-center justify-center rounded-full bg-black/55 text-white transition hover:bg-black/75 focus:outline-none focus-visible:ring-2 focus-visible:ring-white sm:top-3">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
            </svg>
        </button>

        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain pb-[env(safe-area-inset-bottom)]">
            <div x-show="preview.state === 'loading'" role="status"
                 class="flex items-center gap-3 px-5 pb-6 pt-14 text-sm text-gray-500 dark:text-gray-400">
                <svg class="h-4 w-4 animate-spin motion-reduce:animate-none" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
                </svg>
                {{ __('loops.preview_loading') }}
            </div>

            <div x-show="preview.state === 'error'" role="alert" data-loops-preview-error
                 class="px-5 pb-6 pt-14 text-sm text-gray-700 dark:text-gray-200">
                <p>{{ __('loops.preview_error') }}</p>
                <a :href="preview.fallbackHref"
                   class="mt-3 inline-flex items-center rounded-lg bg-gray-100 px-3 py-2 font-semibold text-gray-700 transition hover:bg-gray-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                    {{ __('loops.cta_open_workspace') }}
                </a>
            </div>

            <div x-show="preview.state === 'ready'" x-html="preview.html" data-loops-preview-content></div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // TASK-1866 — etat du tiroir, etale dans la racine `x-data` du catalogue
    // (`{ q, domain, type, mine, ...loopsPreviewDrawer() }`) : les tuiles et
    // le tiroir partagent ainsi la meme portee que les filtres.
    window.loopsPreviewDrawer = () => ({
        preview: { open: false, state: 'idle', html: '', title: '', fallbackHref: '' },
        loopPreviewTrigger: null,
        loopPreviewController: null,
        loopPreviewSeq: 0,
        loopPreviewOverflow: '',

        openLoopPreview(event) {
            // Clic modifie, molette ou clic deja traite : le lien fait son travail.
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            const link = event.currentTarget;
            const url = link.dataset.loopPreviewUrl;
            const loopId = link.closest('[data-loops-catalog-tile]')?.dataset.loopsCatalogTile;
            if (! url || ! loopId) {
                return;
            }

            event.preventDefault();

            if (! this.preview.open) {
                this.loopPreviewOverflow = document.documentElement.style.overflow;
                document.documentElement.style.overflow = 'hidden';
            }

            this.loopPreviewTrigger = link;
            this.preview.title = link.dataset.loopName || '';
            this.preview.fallbackHref = link.href;
            this.preview.html = '';
            this.preview.state = 'loading';
            this.preview.open = true;
            this.$nextTick(() => this.$refs.loopPreviewClose?.focus());

            this.loadLoopPreview(url, loopId, link.href);
        },

        async loadLoopPreview(url, loopId, fallbackHref) {
            this.loopPreviewController?.abort();
            const controller = new AbortController();
            this.loopPreviewController = controller;
            const seq = ++this.loopPreviewSeq;
            const stale = () => seq !== this.loopPreviewSeq || ! this.preview.open;

            try {
                const response = await fetch(url, {
                    headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                });
                if (stale()) {
                    return;
                }
                if (response.status === 404) {
                    window.location.assign(fallbackHref);

                    return;
                }
                if (! response.ok) {
                    throw new Error('loop-preview-http-' + response.status);
                }

                const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
                if (stale()) {
                    return;
                }
                const section = doc.querySelector('[data-loop-preview="' + CSS.escape(loopId) + '"]');
                if (! section) {
                    throw new Error('loop-preview-identity');
                }

                this.preview.html = section.outerHTML;
                this.preview.state = 'ready';
            } catch (error) {
                if (error?.name === 'AbortError' || stale()) {
                    return;
                }
                this.preview.state = 'error';
            }
        },

        closeLoopPreview() {
            if (! this.preview.open) {
                return;
            }

            this.loopPreviewController?.abort();
            this.loopPreviewSeq++;
            this.preview.open = false;
            this.preview.state = 'idle';
            this.preview.html = '';
            document.documentElement.style.overflow = this.loopPreviewOverflow;

            const trigger = this.loopPreviewTrigger;
            this.loopPreviewTrigger = null;
            if (trigger && trigger.isConnected) {
                this.$nextTick(() => trigger.focus());
            }
        },

        trapLoopPreviewFocus(event) {
            const panel = this.$refs.loopPreviewPanel;
            const focusable = [...panel.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
                .filter((el) => el.getClientRects().length > 0);
            if (focusable.length === 0) {
                event.preventDefault();

                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            const active = document.activeElement;

            if (! panel.contains(active)) {
                event.preventDefault();
                first.focus();
            } else if (event.shiftKey && active === first) {
                event.preventDefault();
                last.focus();
            } else if (! event.shiftKey && active === last) {
                event.preventDefault();
                first.focus();
            }
        },
    });
</script>
@endpush
