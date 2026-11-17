/**
 * TASK-1843 — badge de notifications internes (`MemberNotification`) en
 * temps reel.
 *
 * Reprend les 3 lecons TASK-1841 (audit TASK-1842, section 3.6) : jamais de
 * reference Echo/handler dans une propriete reactive, un seul chemin
 * d'ecoute pose une fois le canal reellement abonne, `destroy()` via l'API
 * OFFICIELLE `Echo.leave()` et seulement si le canal est encore le notre.
 *
 * Contrairement a `chatloop-adaptive-polling.js`, AUCUN minuteur de
 * secours : le badge n'a jamais eu de polling (choix delibere TASK-1373),
 * et le defaut reste le rechargement complet de page. Ce module est
 * purement ADDITIF — sans drapeau actif ni Reverb, rien ne s'execute et le
 * comportement existant (compteur recalcule au rechargement) reste intact.
 *
 * Le payload WebSocket ne porte JAMAIS le compte ni aucune donnee
 * personnelle (mandat MASTER TASK-1843, voir `MemberNotificationCreated`) :
 * chaque evenement recu, ou chaque (re)abonnement reussi, declenche UNE
 * relecture HTTP authentifiee de la source de verite serveur
 * (`fetchUnreadCount`), jamais une lecture du payload lui-meme — ce qui
 * rend aussi une resynchronisation apres coupure triviale (section 3.5 de
 * l'audit : l'etat COMPLET est relu, jamais un delta).
 */

export const EVENT_NOTIFICATION_COUNT_CHANGED = '.notification.count-changed';

/**
 * Applique un compte a jour sur les points de montage du badge (rail
 * desktop, barre mobile). Une liste, et non un seul : les deux surfaces
 * existent simultanement sur desktop large (TASK-1816 documente deja ce
 * double rendu CSS, jamais deux montages Livewire).
 *
 * @param {Array<{mount: Element|null, staticBadge: Element|null}>} cibles
 * @param {number} count
 */
export function applyBadgeCount(cibles, count) {
    const visible = count > 0;
    const label = count > 9 ? '9+' : String(count);

    cibles.forEach(({ mount, staticBadge }) => {
        if (!mount) {
            return;
        }

        // Le badge rendu cote serveur au premier chargement cede la place
        // au mount JS des la premiere mise a jour recue — jamais les deux
        // superposes.
        if (staticBadge) {
            staticBadge.style.display = 'none';
        }

        // `style.display`, PAS l'attribut `hidden` : le mount porte aussi
        // `flex` (meme specificite CSS que la regle UA `[hidden]`), qui
        // l'emporterait selon l'ordre de build Tailwind. Un style en ligne
        // n'a pas ce probleme — voir le commentaire Blade du mount.
        mount.style.display = visible ? '' : 'none';
        mount.textContent = visible ? label : '';

        if (visible) {
            mount.setAttribute('data-nav-badge-notifications', String(count));
        } else {
            mount.removeAttribute('data-nav-badge-notifications');
        }
    });
}

/**
 * @param {object} options
 * @param {string} options.userId
 * @param {() => Promise<number>} options.fetchUnreadCount
 * @param {Window} [options.win]
 * @param {Document} [options.doc]
 */
export function notificationsBadgeRealtime({ userId, fetchUnreadCount, win = window, doc = document }) {
    let channel;
    let eventHandler;
    let echoReadyHandler;
    let destroyed = false;

    function cibles() {
        return [
            {
                mount: doc.querySelector('[data-realtime-badge-mount="notifications-rail"]'),
                staticBadge: doc.querySelector('[data-nav-badge-notifications]'),
            },
            {
                mount: doc.querySelector('[data-realtime-badge-mount="notifications-mobile"]'),
                staticBadge: doc.querySelector('[data-mobile-topbar-notifications-unread]'),
            },
        ];
    }

    async function resync() {
        if (destroyed) {
            return;
        }

        try {
            const count = await fetchUnreadCount();
            applyBadgeCount(cibles(), count);
        } catch {
            // TASK-1843 — une relecture HTTP en echec ne doit jamais casser
            // la page : le badge garde sa derniere valeur connue (celle du
            // rendu serveur ou de la derniere resynchronisation reussie), et
            // le prochain evenement ou la prochaine reconnexion retentera.
        }
    }

    function bind() {
        if (destroyed || !win.Echo) {
            return;
        }

        channel = win.Echo.private(`user.${userId}`);

        eventHandler = () => {
            resync();
        };

        channel.listen(EVENT_NOTIFICATION_COUNT_CHANGED, eventHandler);

        // TASK-1843 — resynchronisation a CHAQUE abonnement reussi, premiere
        // connexion ET reconnexion apres coupure comprises : un evenement
        // manque pendant la coupure est rattrape ici, jamais par un delta.
        channel.subscribed(() => resync());
    }

    if (win.Echo) {
        bind();
    } else {
        echoReadyHandler = () => {
            win.removeEventListener('bouclepro:echo-ready', echoReadyHandler);
            echoReadyHandler = null;
            bind();
        };
        win.addEventListener('bouclepro:echo-ready', echoReadyHandler);
    }

    return {
        destroy() {
            destroyed = true;

            if (echoReadyHandler) {
                win.removeEventListener('bouclepro:echo-ready', echoReadyHandler);
                echoReadyHandler = null;
            }

            if (channel && eventHandler) {
                channel.stopListening(EVENT_NOTIFICATION_COUNT_CHANGED, eventHandler);
            }

            if (win.Echo && channel) {
                const stillOurs = win.Echo.connector?.channels?.[`private-user.${userId}`] === channel;

                if (stillOurs) {
                    win.Echo.leave(`user.${userId}`);
                }
            }
        },
    };
}
