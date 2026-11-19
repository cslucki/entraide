{{--
    TASK-1867 — CHARGEUR de l'apercu d'une Boucle (fragment `loops.preview`, T1865),
    extrait du tiroir de la grille (T1866) pour etre partage avec les volets de la
    liste G, sans recopier sa logique. Pousse UNE fois (`@once`), quel que soit le
    nombre de consommateurs sur la page.

    `window.loopsPreviewLoader()` cree un chargeur independant ; chaque
    consommateur (tiroir, volet) a le sien. `load(url, loopId)` renvoie toujours
    une promesse resolue en `{ status, html }` :

    - `ready`     : succes HTTP ET identite verifiee — seule la section
                    `[data-loop-preview="<loopId>"]` est rendue dans `html` ;
    - `not-found` : 404 (droit perdu, SuperAdmin hors tenant) — au consommateur
                    de decider (la grille redirige, la liste propose le lien) ;
    - `error`     : autre statut, page inattendue (connexion apres session
                    expiree...), reseau ;
    - `stale`     : la requete a ete annulee ou depassee par une plus recente —
                    a ignorer.

    Une seule requete vivante par chargeur : la precedente est annulee
    (AbortController) et un numero de sequence ecarte toute reponse tardive.
    Toujours fraiche : `cache: 'no-store'`, aucun cache memoire.

    L'etat (controleur, sequence) vit dans une FERMETURE, hors des donnees
    reactives d'Alpine.
--}}
@once
@push('scripts')
<script>
    window.loopsPreviewLoader = () => {
        let controller = null;
        let seq = 0;

        return {
            abort() {
                controller?.abort();
                controller = null;
                seq++;
            },

            async load(url, loopId) {
                controller?.abort();
                const own = new AbortController();
                controller = own;
                const mine = ++seq;
                const stale = () => mine !== seq;

                try {
                    const response = await fetch(url, {
                        headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        cache: 'no-store',
                        signal: own.signal,
                    });
                    if (stale()) {
                        return { status: 'stale', html: '' };
                    }
                    if (response.status === 404) {
                        return { status: 'not-found', html: '' };
                    }
                    if (! response.ok) {
                        return { status: 'error', html: '' };
                    }

                    const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
                    if (stale()) {
                        return { status: 'stale', html: '' };
                    }
                    const section = doc.querySelector('[data-loop-preview="' + CSS.escape(String(loopId)) + '"]');
                    if (! section) {
                        return { status: 'error', html: '' };
                    }

                    return { status: 'ready', html: section.outerHTML };
                } catch (error) {
                    if (error?.name === 'AbortError' || stale()) {
                        return { status: 'stale', html: '' };
                    }

                    return { status: 'error', html: '' };
                }
            },
        };
    };
</script>
@endpush
@endonce
