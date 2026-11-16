/**
 * TASK-1841 — polling adaptatif de ChatLoop, pilote par l'etat REEL du canal
 * prive Reverb de la Boucle courante (pas seulement la connexion transport :
 * voir `reduceAdaptivePollingState()` plus bas).
 *
 * Remplace la directive statique `wire:poll.3s="pollChat"`
 * (`loop-chat.blade.php`, TASK-1835/1836/1839) par un minuteur pilote cote
 * client — SANS jamais toucher a `pollChat()` lui-meme (fingerprint, soupape,
 * re-derivation de `isMember` a chaque appel : TASK-1835/1836, revue MASTER
 * du 15/11), qui continue seul a decider ce qui merite un rendu. Ce module ne
 * decide jamais QUOI afficher — seulement A QUEL RYTHME appeler `pollChat()`.
 *
 * Reverb desactive (`window.Echo` absent, voir `shouldEnableRealtime()` dans
 * `echo.js`) : ce module reste a 3s pour toujours — comportement inchange
 * pour tout environnement sans Reverb configure (PROD aujourd'hui, et tout
 * poste hors de ce pilote local).
 *
 * CORRECTIF MASTER (NO-GO benchmark initial, p95 19,5s au lieu d'un
 * declenchement quasi instantane) — diagnostic mesure en recette :
 * `getListeners()` de `LoopChat.php` (`"echo-private:loop.{id},.loop.
 * message.created" => 'pollChat'`) delegue a Livewire sa PROPRE liaison
 * d'ecoute cote JS (`vendor/livewire/livewire/dist/livewire.js`, hook
 * `effect`). Deux failles constatees EN DIRECT (console + introspection du
 * canal pusher-js) :
 *  1. cette liaison ne se pose qu'une fois, a l'arrivee de l'effet
 *     `listeners` ; si `window.Echo` n'existe pas encore a cet instant-la
 *     (import dynamique de `echo.js`, course quasi garantie au chargement),
 *     Livewire abandonne silencieusement (`console.warn`, jamais retente) ;
 *  2. meme quand la liaison initiale reussit, l'objet canal retourne par
 *     `Echo.connector.channels['private-loop.{id}']` a ete observe
 *     DIFFERENT de celui capture a l'ouverture, peu apres le premier
 *     abonnement (`sameChannelRef: false`, `_callbacks: []` sur
 *     l'instance REELLEMENT active) — un abonnement/reconnexion precoce
 *     a pu faire remplacer l'objet canal, orphelinant tout ecouteur deja
 *     pose dessus, Livewire comme ce module.
 *
 * Correctif, DEUXIEME PASSE (la premiere, insuffisante, pensait re-attacher
 * a chaque cycle — voir historique Git) : la cause reelle n'etait PAS un
 * canal remplace en cours de session, mais Alpine lui-meme. Le canal, le
 * handler et le minuteur etaient geres comme des PROPRIETES de l'objet
 * retourne par `Alpine.data(...)` — donc rendues REACTIVES (proxy Alpine,
 * meme mecanique que Vue). Lire `this._channel` depuis l'interieur du
 * composant renvoie alors le PROXY, jamais l'instance brute : comparer ce
 * proxy a une instance fraiche (`window.Echo.private(...)`) avec `===`
 * echoue TOUJOURS, meme quand c'est litteralement le meme canal (`.name`,
 * `.constructor` identiques, confirme en recette). Chaque cycle de minuteur
 * croyait donc devoir re-attacher — detachant/reattachant `.listen()` en
 * boucle, 3s ou 20s durant, jamais stable.
 *
 * Correctif : le canal, le handler de message, le minuteur et l'etat ne
 * sont PLUS des proprietes de l'objet Alpine — ce sont des variables de la
 * FERMETURE de `chatLoopAdaptivePolling(loopId)`, jamais exposees a la
 * reactivite. Rien dans le gabarit Blade ne lit ces champs (aucun `x-text`/
 * `x-show` dessus) : ils n'avaient de toute facon aucune raison d'etre
 * reactifs. Le canal est resolu UNE SEULE FOIS a l'abonnement reussi, et
 * n'est plus jamais re-resolu a chaque cycle.
 *
 * REVUE MASTER (3e passe, avant fusion) — deux points securises en plus :
 *
 *  1. UN SEUL CHEMIN D'ECOUTE GARANTI. `LoopChat::getListeners()` (la cle
 *     `"echo-private:loop.{id},.loop.message.created" => 'pollChat'`) a
 *     ete SUPPRIMEE cote PHP plutot que laissee « par prudence » : la
 *     garder a cote de l'ecoute directe de ce module n'aurait jamais pu
 *     etre prouvee inoffensive dans tous les environnements (si la liaison
 *     JS native de Livewire finissait par se poser — `window.Echo` pret a
 *     temps —, CHAQUE message aurait declenche DEUX requetes Livewire, pas
 *     visible dans un controle de doublons en base puisque `pollChat()` ne
 *     cree jamais de ligne). Ce module est maintenant la SEULE source de
 *     declenchement temps reel — garanti par construction, pas observe.
 *  2. FUITE DE L'ECOUTEUR `bouclepro:echo-ready`. Si le composant est
 *     detruit (morph Livewire, navigation) AVANT que `echo.js` ait fini son
 *     import dynamique, l'ecouteur `{ once: true }` pose dans `init()`
 *     restait vivant sur `window` : son callback (`attachEcho`, qui ferme
 *     sur le `$wire` de l'instance DETRUITE) aurait pu s'executer plus
 *     tard, plantant sur `window.Echo.private(...)` si Echo finissait par
 *     ne jamais se charger, ou pire, posant un minuteur orphelin sur un
 *     composant mort. `destroy()` retire desormais explicitement cet
 *     ecouteur (il ne s'auto-retire tout seul que s'il a eu l'occasion de
 *     se DECLENCHER), et chaque fermeture re-verifie un drapeau `destroyed`
 *     avant d'agir — defense en profondeur si un autre appel async
 *     survenait malgre tout apres la destruction.
 */
export const POLL_INTERVAL_DEFAULT_MS = 3000;
export const POLL_INTERVAL_SAFETY_MS = 20000;
export const MESSAGE_CREATED_EVENT = '.loop.message.created';

export const STATE_CONNECTING = 'connecting';
export const STATE_SUBSCRIBED = 'subscribed';
export const STATE_LOST = 'lost';

export function intervalForState(state) {
    return state === STATE_SUBSCRIBED ? POLL_INTERVAL_SAFETY_MS : POLL_INTERVAL_DEFAULT_MS;
}

/**
 * Reducteur pur (testable sans navigateur, voir
 * tests/js/chatloop-adaptive-polling.test.mjs) : donne le prochain etat ET si
 * une synchronisation immediate est due.
 *
 * `syncNow` ne vaut `true` qu'en ENTRANT dans SUBSCRIBED — la seule
 * transition qui peut suivre une periode ou un message a pu etre manque
 * (canal pas encore pret, ou reconnexion). Couvre a la fois le premier
 * abonnement et une reconnexion : pusher-js resouscrit tous les canaux a la
 * reconnexion, ce qui fait reparler `.subscribed()` exactement comme au
 * premier abonnement — donc « Reconnexion : synchronisation immediate puis
 * reprise a 20s » (mandat MASTER) n'a besoin d'aucun cas special ici.
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

export function chatLoopAdaptivePolling(loopId) {
    // Variables de FERMETURE, jamais proprietes de l'objet Alpine retourne
    // plus bas — voir le docblock en tete de fichier (correctif, 2e passe).
    // Alpine rend reactive TOUTE propriete d'un `Alpine.data(...)`, y
    // compris un objet SDK externe comme un canal Echo ; comparer une telle
    // propriete (`this._channel === x`) a une instance brute echoue
    // toujours, car la lecture traverse un proxy. Rien ici n'a besoin
    // d'etre reactif : aucun `x-text`/`x-show` du gabarit ne lit ces champs.
    let timer = null;
    let currentIntervalMs = null;
    let state = STATE_CONNECTING;
    let unbindConnection = null;
    let channel = null;
    let messageHandler = null;
    let visibilityHandler = null;
    let echoReadyHandler = null;
    let destroyed = false;

    function stop() {
        clearInterval(timer);
        timer = null;
    }

    return {
        init() {
            // Meme principe que les minuteurs deja existants de ce bundle
            // (cartes Boucle/Todo de la barre laterale) : onglet en
            // arriere-plan -> minuteur coupe ; retour au premier plan ->
            // rattrapage immediat puis reprise normale. Remplace le throttle
            // probabiliste propre a `wire:poll` (`vendor/livewire/livewire`,
            // `wire-poll.js`) par ce motif deja etabli ICI, plus simple a
            // tester et strictement moins couteux (zero cycle gaspille,
            // plutot que ~5% laisses passer au hasard).
            const $wire = this.$wire;

            const schedule = (ms) => {
                if (destroyed) {
                    return;
                }

                currentIntervalMs = ms;
                stop();

                if (document.hidden) {
                    return;
                }

                timer = setInterval(() => $wire.pollChat(), ms);
            };

            const onEvent = (event) => {
                if (destroyed) {
                    return;
                }

                const result = reduceAdaptivePollingState(state, event);
                state = result.state;
                schedule(intervalForState(state));

                if (result.syncNow) {
                    $wire.pollChat();
                }
            };

            // Attache les ecouteurs UNE SEULE FOIS, au canal REELLEMENT
            // courant au moment de l'appel — plus de re-resolution a
            // chaque cycle (c'etait la source du bug : voir docblock).
            const attachChannel = () => {
                if (destroyed) {
                    return;
                }

                channel = window.Echo.private(`loop.${loopId}`);

                // Ecoute DIRECTE du message, portee par ce module —
                // plus par le binding `getListeners()` de `LoopChat.php`,
                // dont le diagnostic MASTER a montre qu'il peut rater sa
                // liaison (course au chargement de `window.Echo`) sans
                // jamais se rattraper.
                messageHandler = () => $wire.pollChat();
                channel.listen(MESSAGE_CREATED_EVENT, messageHandler);

                channel.subscribed(() => onEvent('channel-subscribed'));
                channel.error(() => onEvent('channel-error'));

                // Course possible : le canal a pu reussir son abonnement
                // AVANT que les deux lignes ci-dessus ne s'executent.
                // `.subscribed()` ne rejoue pas un evenement deja passe —
                // `subscription.subscribed` (pusher-js, booleen public,
                // `node_modules/pusher-js/dist/web/pusher.js`) dit lui
                // l'etat PRESENT, sans dependre de l'ordre d'attachement.
                if (channel.subscription?.subscribed) {
                    onEvent('channel-subscribed');
                }
            };

            const attachEcho = () => {
                if (destroyed) {
                    return;
                }

                attachChannel();

                // Niveau TRANSPORT (pas seulement ce canal) : une perte de
                // connexion Reverb doit ramener a 3s immediatement, quelle
                // que soit la raison. `onConnectionChange()` est l'API
                // publique de `PusherConnector` (meme classe pour 'reverb'
                // et 'pusher'), deja normalisee a 'connected' | 'connecting'
                // | 'failed' | 'disconnected'.
                unbindConnection = window.Echo.connector.onConnectionChange((status) => {
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
                $wire.pollChat();
                schedule(currentIntervalMs ?? intervalForState(state));
            };
            document.addEventListener('visibilitychange', visibilityHandler);

            if (!window.Echo) {
                // Pilote desactive, ou import dynamique de `echo.js` pas
                // encore termine — `bouclepro:echo-ready` rattrape ce second
                // cas. Si le pilote est desactive, cet ecouteur ne se
                // declenchera jamais : ce module reste alors a 3s pour
                // toujours, sans aucun autre effet.
                //
                // L'ecouteur est memorise (`echoReadyHandler`) pour pouvoir
                // etre explicitement retire dans `destroy()` : `{ once:
                // true }` ne le retire QUE s'il a l'occasion de se
                // DECLENCHER. Un composant detruit avant que `echo.js` ait
                // fini son import (morph Livewire, navigation) le laissait
                // sinon vivant sur `window` — son `attachEcho` aurait pu
                // s'executer plus tard, en fermeture sur le `$wire` d'une
                // instance deja morte.
                schedule(POLL_INTERVAL_DEFAULT_MS);
                echoReadyHandler = attachEcho;
                window.addEventListener('bouclepro:echo-ready', echoReadyHandler, { once: true });
                return;
            }

            attachEcho();
        },

        destroy() {
            destroyed = true;
            stop();
            unbindConnection?.();
            if (channel && messageHandler) {
                channel.stopListening(MESSAGE_CREATED_EVENT, messageHandler);
            }
            document.removeEventListener('visibilitychange', visibilityHandler);

            if (echoReadyHandler) {
                window.removeEventListener('bouclepro:echo-ready', echoReadyHandler);
                echoReadyHandler = null;
            }
        },
    };
}
