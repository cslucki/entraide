/**
 * TASK-1612 — Open Source Explorer : le pilote du drawer.
 *
 * JS nu, sans Alpine. Raison mesuree avant d'ecrire : `layouts/guest`
 * (connexion, inscription) inclut le pied de page — donc le declencheur —
 * mais ne monte ni Livewire ni Alpine. Un `x-data` y aurait ete inerte, et
 * silencieusement : ni erreur console, ni indice, juste un bouton mort.
 *
 * Ce fichier ne fabrique AUCUNE classe Tailwind : `tailwind.config.js` ne
 * scanne que `resources/views/**` et les purgerait. Toutes les classes
 * viennent du gabarit `<template data-os-row>` de
 * `resources/views/partials/open-source-drawer.blade.php`.
 */

const SELECTOR_FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([type="hidden"]):not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

function prefersReducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Temps relatif, a partir d'une date ISO. Les libelles sont traduits cote
 * serveur et transmis dans `data-os-label-ago`.
 */
function formatAgo(iso, labels) {
    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const seconds = Math.max(0, Math.round((Date.now() - date.getTime()) / 1000));
    const minutes = Math.round(seconds / 60);
    const hours = Math.round(seconds / 3600);
    const days = Math.floor(seconds / 86400);

    if (seconds < 60) return labels.now;
    if (minutes < 60) return labels.minutes.replace(':count', String(minutes));
    if (hours < 24) return labels.hours.replace(':count', String(hours));
    if (days < 2) return labels.yesterday;
    if (days < 30) return labels.days.replace(':count', String(days));

    if (days < 365) {
        return labels.months.replace(':count', String(Math.round(days / 30)));
    }

    const years = Math.round(days / 365);

    return (years > 1 ? labels.years_other : labels.years_one).replace(':count', String(years));
}

class OpenSourceDrawer {
    constructor(root) {
        this.root = root;
        this.panel = root.querySelector('[data-os-panel]');
        this.backdrop = root.querySelector('[data-os-backdrop]');
        this.rowTemplate = root.querySelector('[data-os-row]');

        this.endpoint = root.dataset.osEndpoint;
        this.locale = root.dataset.osLocale || 'fr';

        try {
            this.agoLabels = JSON.parse(root.dataset.osLabelAgo || '{}');
        } catch (error) {
            this.agoLabels = {};
        }

        this.open = false;
        this.loaded = false;
        this.loading = false;
        this.lastFocused = null;

        this.onKeydown = this.onKeydown.bind(this);

        this.bind();
    }

    bind() {
        this.backdrop?.addEventListener('click', () => this.close());

        this.root.querySelectorAll('[data-os-close]').forEach((button) => {
            button.addEventListener('click', () => this.close());
        });
    }

    /* ------------------------------------------------------------------ */
    /* Ouverture / fermeture                                               */
    /* ------------------------------------------------------------------ */

    show(trigger) {
        if (this.open) {
            return;
        }

        this.open = true;
        this.lastFocused = trigger || document.activeElement;

        this.root.classList.remove('hidden');
        document.body.classList.add('overflow-y-hidden');
        document.addEventListener('keydown', this.onKeydown);

        // Une frame d'ecart : sans elle, le navigateur applique l'etat
        // d'arrivee dans le meme calcul de style et la transition ne joue pas.
        requestAnimationFrame(() => {
            this.panel?.classList.remove('translate-x-full');
            this.backdrop?.classList.remove('opacity-0');
        });

        this.root.querySelector('[data-os-close]')?.focus();

        this.load();
    }

    close() {
        if (!this.open) {
            return;
        }

        this.open = false;
        document.removeEventListener('keydown', this.onKeydown);
        document.body.classList.remove('overflow-y-hidden');

        this.panel?.classList.add('translate-x-full');
        this.backdrop?.classList.add('opacity-0');

        const hide = () => {
            // Un second clic pendant la sortie rouvre le drawer : ne pas le
            // masquer si c'est le cas.
            if (!this.open) {
                this.root.classList.add('hidden');
            }
        };

        if (prefersReducedMotion()) {
            hide();
        } else {
            window.setTimeout(hide, 300);
        }

        this.lastFocused?.focus?.();
        this.lastFocused = null;
    }

    onKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.close();

            return;
        }

        if (event.key === 'Tab') {
            this.trapFocus(event);
        }
    }

    trapFocus(event) {
        if (!this.panel) {
            return;
        }

        const focusables = Array.from(this.panel.querySelectorAll(SELECTOR_FOCUSABLE))
            .filter((element) => element.offsetParent !== null || element === document.activeElement);

        if (focusables.length === 0) {
            return;
        }

        const first = focusables[0];
        const last = focusables[focusables.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    /* ------------------------------------------------------------------ */
    /* Donnees                                                             */
    /* ------------------------------------------------------------------ */

    load() {
        if (this.loaded || this.loading || !this.endpoint) {
            return;
        }

        this.loading = true;

        fetch(this.endpoint, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
            .then((snapshot) => {
                this.loaded = true;
                this.render(snapshot);
            })
            .catch(() => {
                // Une panne reseau se raconte comme une panne GitHub : le
                // drawer reste propre, et les CTA restent la. `loaded` n'est
                // pas pose, la prochaine ouverture reessaiera.
                this.render({ available: false, entries: [] });
            })
            .finally(() => {
                this.loading = false;
            });
    }

    render(snapshot) {
        const skeleton = this.root.querySelector('[data-os-skeleton]');
        const list = this.root.querySelector('[data-os-entries]');
        const empty = this.root.querySelector('[data-os-empty]');
        const notice = this.root.querySelector('[data-os-notice]');
        const structure = this.root.querySelector('[data-os-structure]');

        skeleton?.classList.add('hidden');

        /*
         * `render()` peut etre rejoue : un echec ne pose pas `loaded`, donc
         * la reouverture suivante refait la requete. Sans cette remise a
         * zero, la section « Structure du projet » masquee par l'echec
         * precedent le serait restee alors meme que la donnee est arrivee,
         * et les entrees se seraient empilees en double.
         */
        structure?.classList.remove('hidden');
        empty?.classList.add('hidden');
        notice?.classList.add('hidden');

        if (list) {
            list.replaceChildren();
            list.classList.add('hidden');
        }

        if (snapshot.available === false) {
            // Le bandeau porte l'explication ; la section « Structure du
            // projet » n'a plus rien a montrer et s'efface entierement.
            // La repeter en boite vide dirait deux fois la meme chose.
            notice && this.showNotice(notice, this.root.dataset.osLabelUnavailable);
            structure?.classList.add('hidden');

            return;
        }

        if (snapshot.stale === true) {
            notice && this.showNotice(notice, this.root.dataset.osLabelStale);
        }

        this.renderBadges(snapshot);
        this.renderActivity(snapshot.activity);

        const entries = Array.isArray(snapshot.entries) ? snapshot.entries : [];

        if (entries.length === 0 || !list || !this.rowTemplate) {
            empty?.classList.remove('hidden');

            return;
        }

        const fragment = document.createDocumentFragment();

        entries.forEach((entry) => {
            const row = this.buildRow(entry);

            if (row) {
                fragment.appendChild(row);
            }
        });

        list.appendChild(fragment);
        list.classList.remove('hidden');
    }

    showNotice(notice, text) {
        if (!text) {
            return;
        }

        notice.textContent = text;
        notice.classList.remove('hidden');
    }

    renderBadges(snapshot) {
        const language = snapshot.badges && snapshot.badges.language;
        const badge = this.root.querySelector('[data-os-language]');

        if (!badge || !language) {
            return;
        }

        badge.textContent = language;
        badge.classList.remove('hidden');
    }

    renderActivity(activity) {
        if (!activity) {
            return;
        }

        ['branches', 'tags', 'commits'].forEach((key) => {
            const element = this.root.querySelector(`[data-os-stat="${key}"]`);
            const count = activity[key];

            // Zero n'est pas une information : ce depot n'a aucun tag, et
            // afficher « 0 tag » serait du bruit. `null` non plus : la
            // valeur n'a pas ete obtenue, on se tait.
            if (!element || typeof count !== 'number' || count <= 0) {
                return;
            }

            const label = count > 1 ? element.dataset.labelOther : element.dataset.labelOne;
            const value = element.querySelector('[data-os-stat-value]');

            if (!label || !value) {
                return;
            }

            value.textContent = label.replace(':count', this.formatNumber(count));
            element.classList.remove('hidden');
        });

        const last = this.root.querySelector('[data-os-last-activity]');
        const ago = activity.last_activity_at ? formatAgo(activity.last_activity_at, this.agoLabels) : null;

        if (last && ago) {
            const value = last.querySelector('[data-os-last-activity-value]');

            if (value) {
                value.textContent = (last.dataset.label || ':ago').replace(':ago', ago);
                last.classList.remove('hidden');
            }
        }
    }

    buildRow(entry) {
        if (!entry || typeof entry.name !== 'string') {
            return null;
        }

        const row = this.rowTemplate.content.firstElementChild.cloneNode(true);
        const isDirectory = entry.type === 'dir';

        row.querySelector(`[data-os-icon="${isDirectory ? 'file' : 'dir'}"]`)?.remove();
        row.querySelector('[data-os-name]').textContent = entry.name;

        const subject = row.querySelector('[data-os-subject]');
        const time = row.querySelector('[data-os-time]');
        const commit = entry.commit;

        // Un fichier n'a pas de commit associe dans cet instantane : son
        // message ne sera ni invente, ni repris de son voisin.
        if (commit && typeof commit.subject === 'string') {
            subject.textContent = commit.subject;
            subject.title = commit.subject;
        }

        const ago = commit && commit.at ? formatAgo(commit.at, this.agoLabels) : null;

        if (ago) {
            time.textContent = ago;
            time.dateTime = commit.at;
        } else {
            time.classList.add('hidden');
        }

        return row;
    }

    formatNumber(value) {
        try {
            return new Intl.NumberFormat(this.locale).format(value);
        } catch (error) {
            return String(value);
        }
    }
}

export function registerOpenSourceDrawer() {
    const root = document.querySelector('[data-open-source-drawer]');
    const triggers = document.querySelectorAll('[data-open-source-trigger]');

    if (!root || triggers.length === 0 || root.dataset.osRegistered === 'true') {
        return;
    }

    root.dataset.osRegistered = 'true';

    const drawer = new OpenSourceDrawer(root);

    triggers.forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            // Sans JS, le declencheur reste un lien vers `/open-source/github`
            // qui redirige cote serveur. On ne le neutralise donc qu'ici,
            // une fois qu'on sait pouvoir ouvrir le drawer.
            event.preventDefault();
            drawer.show(trigger);
        });
    });
}
