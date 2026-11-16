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
 */
export const POLL_INTERVAL_DEFAULT_MS = 3000;
export const POLL_INTERVAL_SAFETY_MS = 20000;

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
    return {
        _timer: null,
        _currentIntervalMs: null,
        _state: STATE_CONNECTING,
        _unbindConnection: null,
        _channel: null,
        _visibilityHandler: null,

        init() {
            // Meme principe que les minuteurs deja existants de ce bundle
            // (cartes Boucle/Todo de la barre laterale) : onglet en
            // arriere-plan -> minuteur coupe ; retour au premier plan ->
            // rattrapage immediat puis reprise normale. Remplace le throttle
            // probabiliste propre a `wire:poll` (`vendor/livewire/livewire`,
            // `wire-poll.js`) par ce motif deja etabli ICI, plus simple a
            // tester et strictement moins couteux (zero cycle gaspille,
            // plutot que ~5% laisses passer au hasard).
            this._visibilityHandler = () => {
                if (document.hidden) {
                    this._stop();
                    return;
                }
                this.$wire.pollChat();
                this._schedule(this._currentIntervalMs ?? intervalForState(this._state));
            };
            document.addEventListener('visibilitychange', this._visibilityHandler);

            if (!window.Echo) {
                // Pilote desactive, ou import dynamique de `echo.js` pas
                // encore termine — `bouclepro:echo-ready` rattrape ce second
                // cas. Si le pilote est desactive, cet ecouteur ne se
                // declenchera jamais : ce module reste alors a 3s pour
                // toujours, sans aucun autre effet.
                this._schedule(POLL_INTERVAL_DEFAULT_MS);
                window.addEventListener('bouclepro:echo-ready', () => this._attachEcho(), { once: true });
                return;
            }

            this._attachEcho();
        },

        _attachEcho() {
            // Meme canal, meme nom, que celui deja ouvert par le binding
            // `getListeners()` de `LoopChat.php` (TASK-1839) : Echo met en
            // cache ses canaux par nom (`PusherConnector.channels`), donc cet
            // appel ne souscrit JAMAIS une seconde fois — il recupere la MEME
            // instance pour y attacher une ecoute d'etat, jamais une
            // deuxieme ecoute du message lui-meme (qui reste entierement
            // portee par `getListeners()` -> `pollChat()`, inchangee).
            this._channel = window.Echo.private(`loop.${loopId}`);

            this._channel.subscribed(() => this._onEvent('channel-subscribed'));
            this._channel.error(() => this._onEvent('channel-error'));

            // Course possible : le canal a pu reussir son abonnement AVANT
            // que ces deux lignes ne s'executent (le binding de
            // `LoopChat.php` tourne independamment de ce module).
            // `.subscribed()` ne rejoue pas un evenement deja passe —
            // `subscription.subscribed` (pusher-js, booleen public,
            // `node_modules/pusher-js/dist/web/pusher.js`) dit lui l'etat
            // PRESENT, sans dependre de l'ordre d'attachement.
            if (this._channel.subscription?.subscribed) {
                this._onEvent('channel-subscribed');
            }

            // Niveau TRANSPORT (pas seulement ce canal) : une perte de
            // connexion Reverb doit ramener a 3s immediatement, quelle que
            // soit la raison. `onConnectionChange()` est l'API publique de
            // `PusherConnector` (meme classe pour 'reverb' et 'pusher'),
            // deja normalisee a 'connected' | 'connecting' | 'failed' |
            // 'disconnected'.
            this._unbindConnection = window.Echo.connector.onConnectionChange((status) => {
                if (status !== 'connected') {
                    this._onEvent('connection-lost');
                }
            });

            this._schedule(intervalForState(this._state));
        },

        _onEvent(event) {
            const { state, syncNow } = reduceAdaptivePollingState(this._state, event);
            this._state = state;
            this._schedule(intervalForState(state));

            if (syncNow) {
                this.$wire.pollChat();
            }
        },

        _schedule(ms) {
            this._currentIntervalMs = ms;
            this._stop();

            if (document.hidden) {
                return;
            }

            this._timer = setInterval(() => this.$wire.pollChat(), ms);
        },

        _stop() {
            clearInterval(this._timer);
            this._timer = null;
        },

        destroy() {
            this._stop();
            this._unbindConnection?.();
            document.removeEventListener('visibilitychange', this._visibilityHandler);
        },
    };
}
