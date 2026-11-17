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
 *
 * TASK-1843 (revue MASTER, controle de synchronisation) — `resync()` ne
 * laisse jamais deux requetes `fetchUnreadCount()` en vol simultanement :
 * un evenement recu pendant une requete en cours se CONTENTE de reclamer
 * une relecture finale, jamais un second fetch concurrent (voir son
 * commentaire). Aucune reponse ne peut donc arriver dans le desordre, et
 * `destroy()` empeche explicitement d'appliquer un resultat a un composant
 * deja detruit.
 *
 * TASK-1847 — `user.{userId}` porte desormais un SECOND consommateur
 * independant, `messages-unread-badge-realtime.js` (compteur de MESSAGES
 * non lus, un modele de donnees et un evenement totalement distincts de
 * `MemberNotification` — ne jamais confondre les deux compteurs). Les
 * deux modules appellent `win.Echo.private('user.{userId}')` separement,
 * et recoivent le MEME objet canal (`laravel-echo` le met en cache par
 * nom) : chacun y pose son propre `.listen()` sur un NOM D'EVENEMENT
 * different, ce qui cohabite sans conflit. `destroy()` ne doit en
 * revanche JAMAIS appeler `Echo.leave('user.{userId}')` ici — voir le
 * commentaire a l'endroit ou cet appel a ete retire.
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

    // TASK-1843 (revue MASTER, controle de synchronisation) — AU PLUS UNE
    // requete `fetchUnreadCount()` en vol a la fois.
    //
    // Deux evenements rapproches (ou un evenement pendant une
    // resynchronisation de reconnexion) ne declenchent JAMAIS deux fetch
    // concurrents : le second appel ne fait que POSER UNE INTENTION
    // (`resyncReclamee`) et revenir immediatement. C'est la boucle
    // `do...while` qui, une fois la requete en cours terminee, relance
    // UNE SEULE fois de plus si une intention a ete posee pendant son vol —
    // jamais une par evenement recu entre-temps (coalescence).
    //
    // Puisqu'il n'existe jamais plus d'une requete HTTP en vol, il ne peut
    // jamais y avoir deux REPONSES a departager dans le desordre : chaque
    // requete part APRES que la precedente a fini d'etre attendue. La vraie
    // garantie n'est donc pas un numero de sequence a comparer apres coup,
    // mais l'absence meme de concurrence.
    let syncEnCours = false;
    let resyncReclamee = false;

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

                    // TASK-1843 — `destroy()` peut survenir PENDANT cette
                    // attente (navigation, nettoyage). Le resultat d'une
                    // requete deja partie ne doit jamais etre applique a un
                    // composant qui n'est plus actif : ni DOM touche, ni
                    // relance suivante.
                    if (destroyed) {
                        return;
                    }

                    applyBadgeCount(cibles(), count);
                } catch {
                    // TASK-1843 — une relecture HTTP en echec ne doit jamais
                    // casser la page : le badge garde sa derniere valeur
                    // connue (celle du rendu serveur ou de la derniere
                    // resynchronisation reussie), et le prochain evenement ou
                    // la prochaine reconnexion retentera.
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

            // TASK-1847 — PLUS de `Echo.leave('user.{userId}')` ici. Voir
            // le docblock en tete de fichier (section ajoutee par
            // TASK-1847) : ce canal a desormais un SECOND consommateur
            // independant (`messages-unread-badge-realtime.js`), qui
            // reutilise le MEME objet canal (`Echo.private()` le met en
            // cache par nom). `Echo.leave()` n'est pas reference-compte :
            // l'appeler ici detruirait le canal pour l'AUTRE module aussi,
            // meme s'il est toujours actif. Ce module se contente donc de
            // retirer SA PROPRE ecoute (`stopListening()` ci-dessus) et
            // laisse le canal lui-meme vivre pour la duree de la page —
            // sans risque, puisque `user.{userId}` est scope a l'identite
            // du visiteur courant, pas a une ressource ponctuelle comme
            // `conversation.{id}`/`loop.{id}` qu'il faudrait vraiment
            // quitter en changeant de page.
        },
    };
}
