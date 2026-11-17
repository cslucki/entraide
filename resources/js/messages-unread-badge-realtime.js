/**
 * TASK-1847 — compteur GLOBAL de messages non lus (`User::unreadMessagesCount()`)
 * en temps reel.
 *
 * Reprend A L'IDENTIQUE la doctrine de `notifications-badge-realtime.js`
 * (TASK-1843, lecons TASK-1841 — audit TASK-1842 section 3.6) : jamais de
 * reference Echo/handler dans une propriete reactive, un seul chemin
 * d'ecoute pose une fois le canal reellement abonne, AU PLUS UNE requete
 * `fetchUnreadCount()` en vol (un evenement recu pendant une requete en
 * cours se contente de reclamer une relecture finale, jamais un second
 * fetch concurrent), `destroy()` n'applique jamais un resultat a un
 * composant deja detruit.
 *
 * ## A NE JAMAIS CONFONDRE avec le badge de notifications internes
 *
 * Deux compteurs DISTINCTS : celui-ci porte sur `messages.read_at`
 * (voir `App\Events\MessageUnreadCountChanged`), l'autre sur
 * `member_notifications`. Meme canal `user.{userId}` (TASK-1843, deja
 * durci), mais noms d'evenement et points de montage DOM distincts.
 *
 * ## `user.{userId}` est un canal PARTAGE — PAS de `Echo.leave()` ici
 *
 * Ce module et `notifications-badge-realtime.js` ouvrent tous deux
 * `window.Echo.private('user.{userId}')` : `laravel-echo` met ce canal en
 * cache PAR NOM, les deux modules recoivent donc le MEME objet. Chacun y
 * pose son propre `.listen()` sur un evenement different (cohabitation
 * sans conflit), mais `Echo.leave()` n'est PAS reference-compte — un
 * appel ici detruirait le canal pour l'AUTRE module aussi. `destroy()` se
 * contente donc de `stopListening()` SA PROPRE ecoute et laisse le canal
 * vivre pour la duree de la page (sans risque : `user.{userId}` est scope
 * a l'identite du visiteur, pas a une ressource ponctuelle a quitter en
 * changeant de page, contrairement a `conversation.{id}`/`loop.{id}`).
 *
 * ## Le compteur WebSocket n'est JAMAIS la source de verite
 *
 * Le payload de l'evenement est VIDE (mandat MASTER) : chaque evenement
 * recu, ou chaque (re)abonnement reussi, declenche UNE relecture HTTP
 * authentifiee de `GET /messages/unread-count` — jamais une lecture du
 * payload. Une resynchronisation apres coupure est donc triviale : l'etat
 * COMPLET est relu, jamais un delta qui pourrait diverger.
 */

export const EVENT_MESSAGE_UNREAD_COUNT_CHANGED = '.message.unread-count-changed';

/**
 * Applique un compte a jour sur les points de montage du badge de
 * MESSAGES (rail desktop ; aucun equivalent mobile aujourd'hui — audit
 * TASK-1847 : `mobile-bottom-nav.blade.php` porte une entree « messages »
 * sans aucun badge existant a mettre a niveau, contrairement aux
 * notifications qui en ont un sur les deux surfaces). Meme convention
 * visuelle que `applyBadgeCount()` (TASK-1843) : valeur BRUTE dans
 * l'attribut de donnees, texte plafonne a « 9+ », aucun badge visible a
 * zero.
 *
 * @param {Array<{mount: Element|null, staticBadge: Element|null}>} cibles
 * @param {number} count
 */
export function applyMessagesBadgeCount(cibles, count) {
    const visible = count > 0;
    const label = count > 9 ? '9+' : String(count);

    cibles.forEach(({ mount, staticBadge }) => {
        if (!mount) {
            return;
        }

        if (staticBadge) {
            staticBadge.style.display = 'none';
        }

        mount.style.display = visible ? '' : 'none';
        mount.textContent = visible ? label : '';

        if (visible) {
            mount.setAttribute('data-nav-badge-messages', String(count));
        } else {
            mount.removeAttribute('data-nav-badge-messages');
        }
    });
}

/**
 * @param {object} options
 * @param {() => Promise<number>} options.fetchUnreadCount
 * @param {Window} [options.win]
 * @param {Document} [options.doc]
 */
export function messagesUnreadBadgeRealtime({ userId, fetchUnreadCount, win = window, doc = document }) {
    let channel;
    let eventHandler;
    let echoReadyHandler;
    let destroyed = false;

    // Meme regroupement que `notifications-badge-realtime.js` (TASK-1843,
    // revue MASTER « controle de synchronisation ») : au plus une requete
    // `fetchUnreadCount()` en vol, une seule relecture finale si un
    // evenement arrive pendant celle-ci.
    let syncEnCours = false;
    let resyncReclamee = false;

    function cibles() {
        return [
            {
                mount: doc.querySelector('[data-realtime-badge-mount="messages-rail"]'),
                staticBadge: doc.querySelector('[data-nav-badge-messages]'),
            },
        ];
    }

    async function resync() {
        if (destroyed) {
            return;
        }

        if (syncEnCours) {
            resyncReclamee = true;
            return;
        }

        syncEnCours = true;

        try {
            do {
                resyncReclamee = false;

                try {
                    const count = await fetchUnreadCount();

                    if (destroyed) {
                        return;
                    }

                    applyMessagesBadgeCount(cibles(), count);
                } catch {
                    // Une relecture HTTP en echec ne doit jamais casser la
                    // page : le badge garde sa derniere valeur connue, et
                    // le prochain evenement ou la prochaine reconnexion
                    // retentera.
                }
            } while (resyncReclamee && !destroyed);
        } finally {
            syncEnCours = false;
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

        channel.listen(EVENT_MESSAGE_UNREAD_COUNT_CHANGED, eventHandler);

        // Resynchronisation a CHAQUE abonnement reussi, premiere connexion
        // ET reconnexion apres coupure comprises : un evenement manque
        // pendant la coupure est rattrape ici, jamais par un delta.
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
                channel.stopListening(EVENT_MESSAGE_UNREAD_COUNT_CHANGED, eventHandler);
            }

            // Pas de `Echo.leave()` ici — voir le docblock en tete de
            // fichier : ce canal est PARTAGE avec le badge de
            // notifications internes, et `Echo.leave()` n'est pas
            // reference-compte.
        },
    };
}
