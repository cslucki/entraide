{{--
    TASK-1867 — VOLET DE DETAIL de la liste G (maquette `g-trois-volets`), a
    partir de 1024 px. Il recoit le fragment `loops.preview` (T1865) TEL QUEL,
    charge par le chargeur partage `loopsPreviewLoader` (T1866/T1867).

    Trois volets « quand la largeur reelle le permet » : le volet est un
    conteneur CSS (`container-type: inline-size`) ; une regle `@container`
    NATIVE, limitee a ce volet, place les membres a cote des messages quand il
    est assez large. Aucun plugin, aucun changement du layout global, et le
    tiroir de la grille n'est pas concerne (il n'est pas dans ce conteneur).
    Le fragment n'expose pour cela que des attributs structurels.

    Etat local Alpine (`loopPane`, composant `loopsListPane`) : ni URL, ni
    ancre, ni stockage.
--}}
<style>
    [data-loops-list-pane] { container-type: inline-size; container-name: loops-list-pane; }
    [data-loops-list-pane] [data-loop-preview-cover] > :first-child { aspect-ratio: auto !important; height: 9rem; }
    @container loops-list-pane (min-width: 40rem) {
        [data-loops-list-pane] [data-loop-preview-body] {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 13rem;
            column-gap: 2rem;
            align-items: start;
        }
        [data-loops-list-pane] [data-loop-preview-body] > * { grid-column: 1; }
        [data-loops-list-pane] [data-loop-preview-body] > [data-loop-preview-members] {
            grid-column: 2;
            grid-row: 1 / span 6;
            border-left: 1px solid rgb(229 231 235);
            padding-left: 1.5rem;
        }
        .dark [data-loops-list-pane] [data-loop-preview-body] > [data-loop-preview-members] { border-left-color: rgb(55 65 81); }
    }
</style>
<aside data-loops-list-pane
       class="hidden min-w-0 lg:sticky lg:top-4 lg:block">
    <div role="region"
         :aria-label="loopPane.title || @js(__('loops.list_pane_label'))"
         :aria-busy="loopPane.state === 'loading' ? 'true' : 'false'"
         data-loops-list-pane-region
         class="max-h-[calc(100vh-2rem)] overflow-y-auto overscroll-contain rounded-2xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <p class="sr-only" aria-live="polite" x-text="loopPane.announce"></p>

        <div x-show="loopPane.state === 'empty'" data-loops-list-pane-empty
             class="flex min-h-[16rem] items-center justify-center px-6 py-16 text-center text-sm text-gray-500 dark:text-gray-400">
            {{ __('loops.list_pane_empty') }}
        </div>

        <div x-show="loopPane.state === 'loading'" x-cloak
             class="flex min-h-[16rem] items-center justify-center gap-3 px-6 py-16 text-sm text-gray-500 dark:text-gray-400">
            <svg class="h-4 w-4 animate-spin motion-reduce:animate-none" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
            </svg>
            {{ __('loops.preview_loading') }}
        </div>

        <div x-show="loopPane.state === 'error'" x-cloak data-loops-list-pane-error
             class="px-6 py-10 text-sm text-gray-700 dark:text-gray-200">
            <p>{{ __('loops.preview_error') }}</p>
            <a :href="loopPane.fallbackHref"
               class="mt-3 inline-flex items-center rounded-lg bg-gray-100 px-3 py-2 font-semibold text-gray-700 transition hover:bg-gray-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                {{ __('loops.cta_open_workspace') }}
            </a>
        </div>

        <div x-show="loopPane.state === 'ready'" x-cloak x-html="loopPane.html" data-loops-list-pane-content></div>
    </div>
</aside>

@include('loops.partials.preview-loader-script')

@once
@push('scripts')
<script>
    // TASK-1867 — volet de detail de la liste G, etale dans la racine `x-data`
    // du catalogue.
    window.loopsListPane = ({ announceTemplate = '' } = {}) => {
        const loader = window.loopsPreviewLoader();

        return {
            loopPane: { id: null, state: 'empty', html: '', title: '', fallbackHref: '', announce: '' },
        };
    };
</script>
@endpush
@endonce
