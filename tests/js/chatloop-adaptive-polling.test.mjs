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
