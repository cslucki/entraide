/**
 * TASK-1846 — reception instantanee des messages de conversation privee,
 * polling adaptatif pour les conversations DIRECTES uniquement.
 *
 * Reprend A L'IDENTIQUE les trois lecons de TASK-1841 (audit TASK-1842,
 * section 3.6), deja reutilisees par `notifications-badge-realtime.js`
 * (TASK-1843) : jamais de reference Echo/handler dans une propriete
 * reactive Alpine (fermeture, pas `this.x`), un seul chemin d'ecoute pose
 * une fois le canal reellement abonne, `destroy()` via l'API OFFICIELLE
 * `Echo.leave()` et seulement si le canal est encore le notre.
 *
 * ## Pourquoi CE module verifie SON PROPRE drapeau, contrairement a
 * `chatLoopAdaptivePolling`
 *
 * `LoopMessageCreated` (TASK-1839) est diffuse SANS AUCUNE condition cote
 * serveur (`LoopMessageService::sendUserMessage()` etc. : `event(new
 * LoopMessageCreated(...))`, jamais derriere `config('loop_chat.
 * realtime_enabled')`) — seul le CLIENT decide d'ecouter ou non. Verifier
 * uniquement `window.Echo` suffit donc pour ChatLoop : si `window.Echo`
 * existe (pour N'IMPORTE quelle raison — ChatLoop, notifications...), le
 * serveur a de toute facon DEJA toujours emis le signal.
 *
 * `ConversationMessageCreated` (TASK-1845) est DIFFERENT : `App\Support\
 * Messaging\ConversationBroadcaster::diffuserApresCommit()` ne diffuse QUE
 * si `config('conversation_realtime.enabled')` vaut vrai. Si ce drapeau
 * est FAUX alors qu'un AUTRE pilote (ChatLoop ou notifications) a quand
 * meme fait naitre `window.Echo`, s'abonner ici reussirait quand meme
 * (l'autorisation du canal, `routes/channels.php`, ne depend d'AUCUN
 * drapeau) — et ce module croirait a tort qu'un signal temps reel existe,
 * passerait a l'etat SUBSCRIBED et reduirait le polling a 20s, alors
 * qu'AUCUN evenement ne sera JAMAIS emis cote serveur. Une degradation
 * silencieuse de fraicheur, exactement ce que le mandat interdit. Ce
 * module verifie donc SON PROPRE drapeau (`VITE_CONVERSATION_REALTIME_
 * ENABLED`) avant toute tentative d'abonnement — pas seulement `window.
 * Echo`.
 *
 * ## Deux composants Alpine, UN SEUL module
 *
 * `messageThreadAdaptivePolling` (conversations DIRECTES) remplace le
 * minuteur `wire:poll.3000ms` par un minuteur JS pilote par l'etat REEL du
 * canal (3s par defaut, 20s de securite une fois abonne, resynchronisation
 * immediate a l'entree en abonnement ET a chaque message recu, retour a 3s
 * des la moindre perte de connexion) — meme reducteur que `chatloop-
 * adaptive-polling.js`.
 *
 * `messageThreadRealtimeNudge` (echanges d'ENTRAIDE) NE TOUCHE A AUCUN
 * minuteur : `wire:poll.3000ms="pollThread"` reste pose tel quel dans le
 * gabarit Blade (mandat MASTER — regles metier, Flux, evaluation,
 * progression hors de toute optimisation). Ce second composant se contente
 * d'appeler `$wire.pollThread()` IMMEDIATEMENT a chaque message recu, en
 * plus du cycle de 3s existant — jamais a la place.
 *
 * Les deux partagent `attachConversationChannel()` : la seule difference
 * entre eux porte sur ce qu'ils font de l'evenement, jamais sur la
 * mecanique d'abonnement/nettoyage elle-meme.
 *
 * ## REVUE MASTER (correctif avant fusion) — regroupement des appels `$wire.pollThread()`
 *
 * Plusieurs sources peuvent declencher un `pollThread()` tres rapprochees :
 * le minuteur periodique (3s/20s), un evenement Reverb (message recu), et
 * l'entree en abonnement/reconnexion (`syncNow`). Sans protection, une
 * rafale rapprochee ouvrait potentiellement PLUSIEURS requetes Livewire
 * concurrentes pour le MEME composant. `createPollThreadResync()` applique
 * exactement le motif deja valide par `notificationsBadgeRealtime()`
 * (TASK-1843, revue MASTER « controle de synchronisation ») : AU PLUS UNE
 * requete en vol a la fois ; un declenchement recu PENDANT une requete en
 * cours ne fait que POSER UNE INTENTION (jamais un second fetch
 * concurrent) ; des que la requete en vol se termine, UNE SEULE relecture
 * supplementaire part si une intention a ete posee entre-temps (jamais une
 * par evenement recu — coalescence) ; et `isDestroyed()` est revérifiee
 * avant CHAQUE etape pour ne jamais agir sur un composant demonte. Les
 * appels PERIODIQUES du minuteur restent inchanges (le minuteur continue
 * de tiquer a 3s/20s pile) : c'est seulement le `pollThread()` qu'il
 * declenche a chaque tic qui passe desormais par ce regroupement, au lieu
 * d'ouvrir une requete inconditionnelle meme si une autre etait deja en
 * vol.
 */

export const POLL_INTERVAL_DEFAULT_MS = 3000;
export const POLL_INTERVAL_SAFETY_MS = 20000;
export const MESSAGE_CREATED_EVENT = '.conversation.message.created';

export const STATE_CONNECTING = 'connecting';
export const STATE_SUBSCRIBED = 'subscribed';
export const STATE_LOST = 'lost';

export function intervalForState(state) {
    return state === STATE_SUBSCRIBED ? POLL_INTERVAL_SAFETY_MS : POLL_INTERVAL_DEFAULT_MS;
}

/**
 * Reducteur pur (testable sans navigateur, voir
 * tests/js/message-thread-adaptive-polling.test.mjs) — identique en forme a
 * `reduceAdaptivePollingState()` de `chatloop-adaptive-polling.js`, dont le
 * mandat interdit explicitement le refactor general : duplique a dessein
 * plutot que d'y introduire une dependance croisee entre deux modules
 * jusqu'ici independants.
 */
export function reduceAdaptivePollingState(currentState, event) {
    switch (event) {
        case 'channel-subscribed':
            return { state: STATE_SUBSCRIBED, syncNow: currentState !== STATE_SUBSCRIBED };
        case 'channel-error':
        case 'connection-lost':
            return { state: STATE_LOST, syncNow: false };
        default:
            return { state: currentState, syncNow: false };
    }
}

export function conversationRealtimeEnabled(env) {
    return (env.VITE_CONVERSATION_REALTIME_ENABLED ?? '') === 'true';
}

function privateChannelName(transactionId) {
    return `conversation.${transactionId}`;
}

/**
 * Abonnement partage par les deux composants Alpine de ce module. Pose le
 * handler de message et les sondes d'abonnement/erreur UNE SEULE FOIS, au
 * canal REELLEMENT courant au moment de l'appel (meme motif que TASK-1841 :
 * jamais de re-resolution a chaque cycle).
 *
 * @param {Window} win
 * @param {string} transactionId
 * @param {{onMessage: () => void, onConnectionEvent: (event: string) => void}} handlers
 */
function attachConversationChannel(win, transactionId, { onMessage, onConnectionEvent }) {
    const channel = win.Echo.private(privateChannelName(transactionId));
    const messageHandler = () => onMessage();

    channel.listen(MESSAGE_CREATED_EVENT, messageHandler);
    channel.subscribed(() => onConnectionEvent('channel-subscribed'));
    channel.error(() => onConnectionEvent('channel-error'));

    // Course possible : le canal a pu reussir son abonnement AVANT que les
    // deux lignes ci-dessus ne s'executent (meme motif que TASK-1841).
    if (channel.subscription?.subscribed) {
        onConnectionEvent('channel-subscribed');
    }

    return {
        channel,
        detach() {
            channel.stopListening(MESSAGE_CREATED_EVENT, messageHandler);

            if (win.Echo) {
                const stillOurs = win.Echo.connector?.channels?.[`private-${privateChannelName(transactionId)}`] === channel;

                if (stillOurs) {
                    win.Echo.leave(privateChannelName(transactionId));
                }
            }
        },
    };
}

/**
 * Regroupe les appels a `$wire.pollThread()` — voir le docblock en tete de
 * fichier. Partage par les deux composants Alpine de ce module : seule la
 * SOURCE des declenchements differe entre eux (minuteur + evenements pour
 * `messageThreadAdaptivePolling`, evenements seuls pour
 * `messageThreadRealtimeNudge`), jamais cette mecanique de regroupement.
 *
 * @param {{pollThread: () => Promise<unknown>}} $wire
 * @param {() => boolean} isDestroyed
 * @returns {() => Promise<void>}
 */
function createPollThreadResync($wire, isDestroyed) {
    let syncEnCours = false;
    let resyncReclamee = false;

    return async function resync() {
        if (isDestroyed()) {
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
                    await $wire.pollThread();
                } catch {
                    // Une relecture en echec ne doit jamais casser le
                    // minuteur ni la liaison Echo : le prochain cycle ou le
                    // prochain evenement recu retentera de lui-meme.
                }

                if (isDestroyed()) {
                    return;
                }
            } while (resyncReclamee && !isDestroyed());
        } finally {
            syncEnCours = false;
        }
    };
}

/**
 * Conversations DIRECTES — minuteur adaptatif complet.
 *
 * `env` est INJECTABLE (second parametre optionnel) pour rester testable
 * sans navigateur ni Vite : `import.meta.env` est une liaison PROPRE A CE
 * MODULE, pas une propriete lisible/ecrivable via `globalThis` — contrairement
 * a `shouldEnableRealtime(env)` (echo.js), une fonction pure qui recoit son
 * `env` en parametre, ce composant Alpine DOIT lire `import.meta.env`
 * directement quelque part pour que Vite le remplace au build ; la valeur
 * par defaut ci-dessous reste la REFERENCE LITTERALE necessaire a ce
 * remplacement statique, tout en acceptant un `env` de test en 2e argument.
 *
 * @param {string} transactionId
 * @param {{env?: Record<string, string>}} [options]
 */
export function messageThreadAdaptivePolling(transactionId, options = {}) {
    const env = options.env ?? (import.meta.env ?? {});
    // Variables de FERMETURE — jamais de proprietes de l'objet Alpine
    // retourne plus bas (proxy reactif, meme piege documente par TASK-1841).
    let timer = null;
    let currentIntervalMs = null;
    let state = STATE_CONNECTING;
    let unbindConnection = null;
    let attached = null;
    let visibilityHandler = null;
    let echoReadyHandler = null;
    let destroyed = false;

    function stop() {
        clearInterval(timer);
        timer = null;
    }

    return {
        init() {
            const $wire = this.$wire;
            const win = window;
            const enabled = conversationRealtimeEnabled(env);
            const resync = createPollThreadResync($wire, () => destroyed);

            const schedule = (ms) => {
                if (destroyed) {
                    return;
                }

                currentIntervalMs = ms;
                stop();

                if (document.hidden) {
                    return;
                }

                // Le minuteur continue de tiquer EXACTEMENT a ce rythme
                // (3s/20s inchange) ; c'est `resync()` qui absorbe le cas
                // ou une requete precedente (declenchee par un evenement,
                // ou par le tic d'avant) est encore en vol.
                timer = setInterval(() => resync(), ms);
            };

            const onEvent = (event) => {
                if (destroyed) {
                    return;
                }

                const result = reduceAdaptivePollingState(state, event);
                state = result.state;
                schedule(intervalForState(state));

                if (result.syncNow) {
                    resync();
                }
            };

            const attachEcho = () => {
                if (destroyed) {
                    return;
                }

                attached = attachConversationChannel(win, transactionId, {
                    onMessage: () => resync(),
                    onConnectionEvent: onEvent,
                });

                unbindConnection = win.Echo.connector.onConnectionChange((status) => {
                    if (status !== 'connected') {
                        onEvent('connection-lost');
                    }
                });

                schedule(intervalForState(state));
            };

            visibilityHandler = () => {
                if (destroyed || document.hidden) {
                    stop();
                    return;
                }
                resync();
                schedule(currentIntervalMs ?? intervalForState(state));
            };
            document.addEventListener('visibilitychange', visibilityHandler);

            // Drapeau OFF : comportement historique a l'identique,
            // POUR TOUJOURS — jamais d'attente d'un `window.Echo` qui, meme
            // s'il finit par exister (pour une AUTRE surface), ne recevrait
            // jamais le moindre evenement pour CETTE conversation. Voir le
            // docblock en tete de fichier.
            if (!enabled) {
                schedule(POLL_INTERVAL_DEFAULT_MS);
                return;
            }

            if (!win.Echo) {
                schedule(POLL_INTERVAL_DEFAULT_MS);
                echoReadyHandler = attachEcho;
                win.addEventListener('bouclepro:echo-ready', echoReadyHandler, { once: true });
                return;
            }

            attachEcho();
        },

        destroy() {
            destroyed = true;
            stop();
            unbindConnection?.();
            attached?.detach();

            document.removeEventListener('visibilitychange', visibilityHandler);

            if (echoReadyHandler) {
                window.removeEventListener('bouclepro:echo-ready', echoReadyHandler);
                echoReadyHandler = null;
            }
        },
    };
}

/**
 * Echanges d'ENTRAIDE — aucun minuteur gere ici. `wire:poll.3000ms="pollThread"`
 * reste pose dans le gabarit Blade, inchange : ce composant ajoute
 * seulement une resynchronisation IMMEDIATE a chaque message recu.
 *
 * `env` injectable pour les memes raisons que `messageThreadAdaptivePolling`.
 *
 * @param {string} transactionId
 * @param {{env?: Record<string, string>}} [options]
 */
export function messageThreadRealtimeNudge(transactionId, options = {}) {
    const env = options.env ?? (import.meta.env ?? {});
    let attached = null;
    let echoReadyHandler = null;
    let destroyed = false;

    return {
        init() {
            const $wire = this.$wire;
            const win = window;
            const enabled = conversationRealtimeEnabled(env);
            const resync = createPollThreadResync($wire, () => destroyed);

            if (!enabled) {
                // Drapeau OFF : rien a faire, `wire:poll.3000ms` suffit
                // deja a l'identique du comportement avant cette TASK.
                return;
            }

            const attachEcho = () => {
                if (destroyed) {
                    return;
                }

                attached = attachConversationChannel(win, transactionId, {
                    onMessage: () => resync(),
                    onConnectionEvent: () => {},
                });
            };

            if (!win.Echo) {
                echoReadyHandler = attachEcho;
                win.addEventListener('bouclepro:echo-ready', echoReadyHandler, { once: true });
                return;
            }

            attachEcho();
        },

        destroy() {
            destroyed = true;
            attached?.detach();

            if (echoReadyHandler) {
                window.removeEventListener('bouclepro:echo-ready', echoReadyHandler);
                echoReadyHandler = null;
            }
        },
    };
}
