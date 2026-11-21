{{--
    TASK-1867 — composant Alpine `loopsListPane` de la liste G. Partial a part
    (pousse UNE fois, `@once`) : la racine `x-data` du catalogue l'appelle en vue
    liste MEME quand le catalogue est vide (volet absent) ; index.blade.php
    l'inclut donc aussi hors du rendu non vide, sans dupliquer le script.
--}}
@include('loops.partials.preview-loader-script')

@once
@push('scripts')
<script>
    // TASK-1867 — volet de detail de la liste G, etale dans la racine `x-data`
    // du catalogue.
    //
    // - SELECTION INITIALE : sur ecran large seulement, la premiere ligne
    //   visible, une seule requete, sans toucher au focus ;
    // - CLIC normal sur une ligne = selection (>= 1024 px) ou feuille mobile
    //   (en dessous) ; clic modifie, molette ou sans JS = le vrai lien ;
    // - FLECHES haut/bas depuis le lien d'une ligne : ligne visible suivante ou
    //   precedente (les champs de recherche, filtres et boutons d'action ne
    //   portent pas ce gestionnaire) ;
    // - FILTRES : si la Boucle choisie n'est plus visible, la requete est
    //   annulee et le volet revient a « Selectionnez une Boucle », sans
    //   relancer de requete ;
    // - 404 : etat d'erreur avec le lien direct, jamais de redirection.
    window.loopsListPane = ({ announceTemplate = '' } = {}) => {
        const loader = window.loopsPreviewLoader();
        const isDesktop = () => window.matchMedia('(min-width: 1024px)').matches;
        const isVisible = (el) => !! el && el.isConnected && el.getClientRects().length > 0;
        const emptyPane = { id: null, state: 'empty', html: '', title: '', fallbackHref: '', announce: '' };

        return {
            loopPane: { ...emptyPane },

            init() {
                // La visibilite se mesure APRES les `x-show` des lignes. Ce
                // watcher, pose a l'init, passe AVANT leurs effets, qui ne
                // masquent eux-memes qu'a la frame suivante (mesure en recette :
                // `$nextTick` et une seule frame voient encore la ligne). D'ou
                // deux frames.
                const afterRowsUpdate = (callback) => requestAnimationFrame(() => requestAnimationFrame(callback));
                ['q', 'domain', 'type', 'mine'].forEach((key) => {
                    this.$watch(key, () => afterRowsUpdate(() => this.dropHiddenLoopSelection()));
                });

                // Passage au-dela de 1024 px feuille ouverte : la feuille n'y
                // existe plus (lg:hidden), on la ferme pour rendre le scroll.
                window.matchMedia('(min-width: 1024px)').addEventListener('change', (media) => {
                    if (media.matches && this.preview?.open) {
                        this.closeLoopPreview();
                    }
                });

                this.$nextTick(() => {
                    if (! isDesktop()) {
                        return;
                    }
                    const first = this.visibleLoopRowLinks()[0];
                    if (first) {
                        this.selectLoopRow(first);
                    }
                });
            },

            visibleLoopRowLinks() {
                return [...this.$root.querySelectorAll('[data-loops-row-link]')].filter(isVisible);
            },

            onLoopRowClick(event) {
                if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                    return;
                }
                // Sous 1024 px : la feuille mobile du tiroir (loopsPreviewDrawer,
                // etale dans la meme racine), jamais la navigation directe.
                if (! isDesktop()) {
                    this.openLoopPreview(event);

                    return;
                }

                event.preventDefault();
                this.selectLoopRow(event.currentTarget);
            },

            onLoopRowArrow(event, step) {
                const links = this.visibleLoopRowLinks();
                const next = links[links.indexOf(event.currentTarget) + step];
                if (! next) {
                    return;
                }

                event.preventDefault();
                next.focus();
                if (isDesktop()) {
                    this.selectLoopRow(next);
                }
            },

            async selectLoopRow(link) {
                const id = link.dataset.loopId;
                const url = link.dataset.loopPreviewUrl;
                if (! id || ! url) {
                    return;
                }

                Object.assign(this.loopPane, {
                    id,
                    title: link.dataset.loopName || '',
                    fallbackHref: link.href,
                    html: '',
                    state: 'loading',
                    announce: '',
                });

                const result = await loader.load(url, id);
                if (result.status === 'stale' || this.loopPane.id !== id) {
                    return;
                }
                if (result.status !== 'ready') {
                    this.loopPane.state = 'error';

                    return;
                }

                this.loopPane.html = result.html;
                this.loopPane.state = 'ready';
                this.loopPane.announce = announceTemplate.replace('__NAME__', this.loopPane.title);
            },

            dropHiddenLoopSelection() {
                if (! this.loopPane.id) {
                    return;
                }
                const link = this.$root.querySelector('[data-loops-row-link][data-loop-id="' + CSS.escape(this.loopPane.id) + '"]');
                if (isVisible(link)) {
                    return;
                }

                loader.abort();
                Object.assign(this.loopPane, emptyPane);
            },
        };
    };
</script>
@endpush
@endonce
