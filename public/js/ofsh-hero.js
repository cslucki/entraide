/*
 * OFSH Hero — le seul comportement de la landing : le menu mobile.
 * TASK-1699, repris de design/HomePage/OFSH-Homepage-Couleurs/assets/home.js.
 *
 * Aucun theme, aucun `localStorage`, aucune lecture de preference : une
 * landing publique pose sa cle de theme cote SERVEUR (voir
 * `components/theme-tokens.blade.php`, TASK-1471) et son volet sombre suit la
 * preference systeme, en CSS. Ce fichier n'y touche pas.
 */
(function () {
    const toggle = document.querySelector('[data-menu-toggle]');
    const menu = document.querySelector('[data-mobile-menu]');

    if (!toggle || !menu) {
        return;
    }

    const close = () => {
        menu.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
    };

    toggle.addEventListener('click', () => {
        const expand = menu.hidden;
        menu.hidden = !expand;
        toggle.setAttribute('aria-expanded', String(expand));
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            close();
        }
    });

    document.addEventListener('click', (e) => {
        if (!menu.hidden && !menu.contains(e.target) && !toggle.contains(e.target)) {
            close();
        }
    });

    menu.querySelectorAll('a, button').forEach((el) => el.addEventListener('click', close));
})();
