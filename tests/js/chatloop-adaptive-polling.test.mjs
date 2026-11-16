/**
 * TASK-1841 — reducteur pur du polling adaptatif de ChatLoop, testable sans
 * navigateur ni `window.Echo`, meme motif que
 * `tests/js/echo-realtime-guard.test.mjs` (TASK-1839).
 *
 * Lancer avec : node --test tests/js/chatloop-adaptive-polling.test.mjs
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    intervalForState,
    reduceAdaptivePollingState,
    chatLoopAdaptivePolling,
    STATE_CONNECTING,
    STATE_SUBSCRIBED,
    STATE_LOST,
    POLL_INTERVAL_DEFAULT_MS,
    POLL_INTERVAL_SAFETY_MS,
    MESSAGE_CREATED_EVENT,
} from '../../resources/js/chatloop-adaptive-polling.js';

/**
 * TASK-1841 (revue MASTER, 3e passe) — bancs minimaux pour `window`/
 * `document`, suffisants pour exercer `init()`/`destroy()` sans navigateur.
 * `EventTarget` est un global Node natif (>= 18) : pas besoin de jsdom pour
 * tester addEventListener/removeEventListener/dispatchEvent.
 */
function fakeBrowserGlobals() {
    class FakeWindow extends EventTarget {}
    class FakeDocument extends EventTarget {
        hidden = false;
    }

    const win = new FakeWindow();
    const doc = new FakeDocument();
    win.Echo = undefined;

    globalThis.window = win;
    globalThis.document = doc;

    return { window: win, document: doc };
}

function fakeWire() {
    const calls = [];

    return { calls, pollChat: () => calls.push(Date.now()) };
}

/**
 * TASK-1841 (revue MASTER, 4e passe) — banc minimal d'un canal pusher-js/
 * Echo, suffisant pour tester `init()`/`destroy()` sans navigateur : l'API
 * publique utilisee par ce module (`.listen()`, `.stopListening()`,
 * `.subscribed()`, `.error()`, `.subscription.subscribed`), plus `_emit`
 * pour simuler un evenement reel depuis les tests.
 */
function fakeChannel(key) {
    const listeners = {};
    const bind = (event, cb) => { (listeners[event] ??= []).push(cb); };

    return {
        key,
        subscription: { subscribed: true },
        listen(event, cb) { bind(event, cb); return this; },
        stopListening(event, cb) {
            listeners[event] = (listeners[event] || []).filter((f) => f !== cb);
            return this;
        },
        subscribed(cb) { bind('_subscribed', cb); return this; },
        error(cb) { bind('_error', cb); return this; },
        _emit(event, ...args) { (listeners[event] || []).forEach((cb) => cb(...args)); },
        _listenerCount(event) { return (listeners[event] || []).length; },
    };
}

/**
 * Banc minimal de `window.Echo` : met en cache ses canaux par cle PREFIXEE
 * (comme le vrai `PusherConnector`), et `leave(rawName)` reproduit fidelement
 * le comportement NON reference-compte de la vraie API — il supprime
 * QUELLE QUE SOIT l'entree presente a cette cle, sans savoir si ce module
 * est le seul a la detenir (voir docblock du fichier source).
 */
function fakeEcho() {
    const channels = {};
    const leaveCalls = [];

    return {
        connector: {
            channels,
            onConnectionChange() { return () => {}; },
        },
        private(rawName) {
            const key = `private-${rawName}`;
            channels[key] ??= fakeChannel(key);
            return channels[key];
        },
        leave(rawName) {
            leaveCalls.push(rawName);
            for (const prefix of ['', 'private-', 'private-encrypted-', 'presence-']) {
                delete channels[`${prefix}${rawName}`];
            }
        },
        leaveCalls,
    };
}

test('MESSAGE_CREATED_EVENT porte le point initial attendu par EventFormatter.format() (pusher-js)', () => {
    // `node_modules/laravel-echo/dist/echo.js` : un nom commencant par "."
    // ou "\\" est pris TEL QUEL (point retire) ; sans ce point, le nom
    // serait prefixe par un namespace Echo par defaut et ne matcherait
    // jamais `LoopMessageCreated::broadcastAs()` ('loop.message.created').
    assert.equal(MESSAGE_CREATED_EVENT, '.loop.message.created');
});

test('intervalForState : seul SUBSCRIBED donne le palier de securite 20s', () => {
    assert.equal(intervalForState(STATE_CONNECTING), POLL_INTERVAL_DEFAULT_MS);
    assert.equal(intervalForState(STATE_SUBSCRIBED), POLL_INTERVAL_SAFETY_MS);
    assert.equal(intervalForState(STATE_LOST), POLL_INTERVAL_DEFAULT_MS);
});

test('premier abonnement du canal prive : passage a SUBSCRIBED, synchronisation immediate', () => {
    const { state, syncNow } = reduceAdaptivePollingState(STATE_CONNECTING, 'channel-subscribed');

    assert.equal(state, STATE_SUBSCRIBED);
    assert.equal(syncNow, true);
});

test('deja SUBSCRIBED et le canal reconfirme son abonnement : aucune synchronisation en plus', () => {
    const { state, syncNow } = reduceAdaptivePollingState(STATE_SUBSCRIBED, 'channel-subscribed');

    assert.equal(state, STATE_SUBSCRIBED);
    assert.equal(syncNow, false);
});

test('erreur de souscription au canal (ex. acces retire) : repli immediat sur LOST, aucune synchronisation', () => {
    const { state, syncNow } = reduceAdaptivePollingState(STATE_SUBSCRIBED, 'channel-error');

    assert.equal(state, STATE_LOST);
    assert.equal(syncNow, false);
});

test('connexion transport perdue depuis SUBSCRIBED : repli immediat sur LOST', () => {
    const { state, syncNow } = reduceAdaptivePollingState(STATE_SUBSCRIBED, 'connection-lost');

    assert.equal(state, STATE_LOST);
    assert.equal(syncNow, false);
});

test('reconnexion apres LOST : le canal reparle, passage a SUBSCRIBED ET synchronisation immediate', () => {
    const { state, syncNow } = reduceAdaptivePollingState(STATE_LOST, 'channel-subscribed');

    assert.equal(state, STATE_SUBSCRIBED);
    assert.equal(syncNow, true);
});

test('evenement inconnu : etat inchange, aucune synchronisation', () => {
    const { state, syncNow } = reduceAdaptivePollingState(STATE_SUBSCRIBED, 'un-evenement-qui-n-existe-pas');

    assert.equal(state, STATE_SUBSCRIBED);
    assert.equal(syncNow, false);
});

// ────────────────────────────────────────────────────────────────────────
// TASK-1841 (revue MASTER, 3e passe) — cycle de vie init()/destroy(),
// fuite de `bouclepro:echo-ready`, navigation/reinitialisation.
// ────────────────────────────────────────────────────────────────────────

test('destroy() retire explicitement l ecouteur bouclepro:echo-ready, meme s il n a jamais eu l occasion de se declencher', () => {
    const { window: win } = fakeBrowserGlobals();
    const removedEvents = [];
    const originalRemove = win.removeEventListener.bind(win);
    win.removeEventListener = (type, ...rest) => {
        removedEvents.push(type);
        return originalRemove(type, ...rest);
    };

    const component = chatLoopAdaptivePolling('loop-test-1');
    component.$wire = fakeWire();

    // `window.Echo` est absent (voir fakeBrowserGlobals) : `init()` doit
    // poser l'ecouteur `bouclepro:echo-ready` plutot que s'abonner tout de
    // suite — c'est exactement le cas « composant detruit avant que
    // l'import dynamique de echo.js ait fini ».
    component.init();
    component.destroy();

    assert.ok(
        removedEvents.includes('bouclepro:echo-ready'),
        'destroy() doit retirer l ecouteur echo-ready meme quand il n a jamais ete declenche — sinon il reste vivant sur `window`, '
        + 'en fermeture sur le $wire d une instance detruite.'
    );
});

test('un bouclepro:echo-ready emis APRES destroy() ne relance ni Echo ni pollChat() sur l instance detruite', () => {
    const { window: win } = fakeBrowserGlobals();

    const component = chatLoopAdaptivePolling('loop-test-2');
    const wire = fakeWire();
    component.$wire = wire;

    component.init();
    component.destroy();

    // `window.Echo` reste absent ici : si l ecouteur avait fui (ou si le
    // drapeau `destroyed` ne gardait pas `attachEcho()`), ce dispatch
    // tenterait `window.Echo.private(...)` sur `undefined` et planterait.
    assert.doesNotThrow(() => {
        win.dispatchEvent(new CustomEvent('bouclepro:echo-ready'));
    });

    assert.equal(wire.calls.length, 0, 'aucun pollChat() ne doit partir sur une instance deja detruite');
});

test('navigation : init()/destroy() repetes (remontage du composant) ne fait JAMAIS grossir le nombre net d ecouteurs globaux', () => {
    const { window: win, document: doc } = fakeBrowserGlobals();

    let added = 0;
    let removed = 0;
    for (const target of [win, doc]) {
        const originalAdd = target.addEventListener.bind(target);
        const originalRemove = target.removeEventListener.bind(target);
        target.addEventListener = (...args) => { added++; return originalAdd(...args); };
        target.removeEventListener = (...args) => { removed++; return originalRemove(...args); };
    }

    // Trois cycles successifs, comme si Livewire remontait le composant
    // trois fois de suite (morph, navigation) — chaque cycle doit se
    // nettoyer completement avant le suivant.
    for (let i = 0; i < 3; i++) {
        const component = chatLoopAdaptivePolling(`loop-nav-${i}`);
        component.$wire = fakeWire();
        component.init();
        component.destroy();
    }

    assert.equal(added, removed, 'chaque ecouteur pose par init() doit etre retire par destroy() — net egal a zero, aucune fuite au fil des remontages');
});

test('destroy() avant reponse d Echo : aucun minuteur ne continue d appeler pollChat() ensuite', () => {
    fakeBrowserGlobals();

    const component = chatLoopAdaptivePolling('loop-test-3');
    const wire = fakeWire();
    component.$wire = wire;

    component.init();
    const callsAtInit = wire.calls.length;
    component.destroy();

    // `clearInterval` sur un minuteur deja arrete est un no-op sans danger ;
    // ce test documente surtout que `destroy()` ne laisse rien en file
    // d attente qui se declencherait de façon differee (aucune promesse,
    // aucun `setTimeout` dans ce module — seulement `setInterval`, arrete
    // de façon synchrone).
    assert.equal(wire.calls.length, callsAtInit, 'destroy() ne doit declencher aucun pollChat() supplementaire');
});

// ────────────────────────────────────────────────────────────────────────
// TASK-1841 (revue MASTER, 4e passe) — destroy() doit REELLEMENT quitter
// le canal Echo (pas seulement retirer notre propre ecoute du message),
// via l'API officielle, et sans jamais couper le sol sous les pieds d'un
// EVENTUEL futur autre consommateur du meme canal.
// ────────────────────────────────────────────────────────────────────────

test('destroy() quitte le canal via l API officielle Echo.leave() quand ce composant en est toujours le proprietaire', () => {
    const { window: win } = fakeBrowserGlobals();
    const echo = fakeEcho();
    win.Echo = echo;

    const component = chatLoopAdaptivePolling('loop-leave-1');
    component.$wire = fakeWire();
    component.init();

    assert.ok(echo.connector.channels['private-loop.loop-leave-1'], 'le canal doit exister apres init()');

    component.destroy();

    assert.deepEqual(echo.leaveCalls, ['loop.loop-leave-1'], 'destroy() doit appeler Echo.leave() avec le nom BRUT (non prefixe) du canal');
    assert.equal(echo.connector.channels['private-loop.loop-leave-1'], undefined, 'le canal doit avoir disparu du cache Echo apres le depart');
});

test('destroy() ne quitte PAS le canal si un autre consommateur l a repris entre-temps (jamais couper le sol sous ses pieds)', () => {
    const { window: win } = fakeBrowserGlobals();
    const echo = fakeEcho();
    win.Echo = echo;

    const component = chatLoopAdaptivePolling('loop-leave-2');
    component.$wire = fakeWire();
    component.init();

    // Un AUTRE consommateur hypothetique a repris ce nom de canal avant que
    // ce composant ne soit detruit (remplace l entree du cache par une
    // instance qu il ne possede plus).
    const takenOverChannel = fakeChannel('private-loop.loop-leave-2');
    echo.connector.channels['private-loop.loop-leave-2'] = takenOverChannel;

    component.destroy();

    assert.deepEqual(echo.leaveCalls, [], 'Echo.leave() ne doit JAMAIS etre appele quand le canal courant n est plus celui que ce composant a ouvert');
    assert.equal(echo.connector.channels['private-loop.loop-leave-2'], takenOverChannel, 'le canal du nouveau consommateur doit rester intact');
});

test('apres destroy(), un evenement emis sur l ANCIEN canal ne declenche plus pollChat() (aucun ancien canal ne doit encore livrer)', () => {
    const { window: win } = fakeBrowserGlobals();
    const echo = fakeEcho();
    win.Echo = echo;

    const component = chatLoopAdaptivePolling('loop-leave-3');
    const wire = fakeWire();
    component.$wire = wire;
    component.init();

    const oldChannel = echo.connector.channels['private-loop.loop-leave-3'];
    component.destroy();
    const callsAtDestroy = wire.calls.length;

    oldChannel._emit(MESSAGE_CREATED_EVENT);

    assert.equal(wire.calls.length, callsAtDestroy, 'un message emis sur l ancien canal apres destroy() ne doit declencher aucun pollChat() supplementaire');
});

test('cycle complet avec un Echo simule : abonnement, perte de connexion, reconnexion — les reconnexions restent fonctionnelles apres le correctif destroy()', () => {
    const { window: win } = fakeBrowserGlobals();
    const echo = fakeEcho();
    win.Echo = echo;

    const component = chatLoopAdaptivePolling('loop-reco-1');
    const wire = fakeWire();
    component.$wire = wire;
    component.init();

    const channel = echo.connector.channels['private-loop.loop-reco-1'];

    // Le canal est deja marque `subscribed: true` par le banc (comme un
    // canal qui vient de reussir son abonnement) : `init()` doit l avoir
    // detecte tout de suite (la "course" documentee dans le fichier
    // source) et avoir synchronise immediatement.
    assert.equal(wire.calls.length, 1, 'premier abonnement : une synchronisation immediate attendue');

    // Perte de connexion : le canal signale une erreur de souscription.
    channel._emit('_error', { type: 'AuthError' });

    // Reconnexion : le canal reparle, pusher-js resouscrit — une nouvelle
    // synchronisation immediate est due (meme reducteur que l abonnement
    // initial).
    channel._emit('_subscribed');

    assert.equal(wire.calls.length, 2, 'la reconnexion doit redeclencher une synchronisation immediate');

    component.destroy();
});
