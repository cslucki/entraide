/**
 * TASK-1846 — reducteur pur et cycle de vie des deux composants Alpine de
 * `message-thread-adaptive-polling.js`, testables sans navigateur — meme
 * motif que `tests/js/chatloop-adaptive-polling.test.mjs` (TASK-1841).
 *
 * Lancer avec : node --test tests/js/message-thread-adaptive-polling.test.mjs
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    intervalForState,
    reduceAdaptivePollingState,
    conversationRealtimeEnabled,
    messageThreadAdaptivePolling,
    messageThreadRealtimeNudge,
    STATE_CONNECTING,
    STATE_SUBSCRIBED,
    STATE_LOST,
    POLL_INTERVAL_DEFAULT_MS,
    POLL_INTERVAL_SAFETY_MS,
    MESSAGE_CREATED_EVENT,
} from '../../resources/js/message-thread-adaptive-polling.js';

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

    return { calls, pollThread: () => calls.push(Date.now()) };
}

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

// ────────────────────────────────────────────────────────────────────────
// Reducteur pur et constantes
// ────────────────────────────────────────────────────────────────────────

test('MESSAGE_CREATED_EVENT porte le point initial attendu par EventFormatter.format() (pusher-js)', () => {
    assert.equal(MESSAGE_CREATED_EVENT, '.conversation.message.created');
});

test('intervalForState : seul SUBSCRIBED donne le palier de securite 20s', () => {
    assert.equal(intervalForState(STATE_CONNECTING), POLL_INTERVAL_DEFAULT_MS);
    assert.equal(intervalForState(STATE_SUBSCRIBED), POLL_INTERVAL_SAFETY_MS);
    assert.equal(intervalForState(STATE_LOST), POLL_INTERVAL_DEFAULT_MS);
});

test('premier abonnement : passage a SUBSCRIBED, synchronisation immediate', () => {
    const { state, syncNow } = reduceAdaptivePollingState(STATE_CONNECTING, 'channel-subscribed');
    assert.equal(state, STATE_SUBSCRIBED);
    assert.equal(syncNow, true);
});

test('reconnexion apres LOST : passage a SUBSCRIBED ET synchronisation immediate', () => {
    const { state, syncNow } = reduceAdaptivePollingState(STATE_LOST, 'channel-subscribed');
    assert.equal(state, STATE_SUBSCRIBED);
    assert.equal(syncNow, true);
});

test('perte de connexion depuis SUBSCRIBED : repli immediat sur LOST, aucune synchronisation', () => {
    const { state, syncNow } = reduceAdaptivePollingState(STATE_SUBSCRIBED, 'connection-lost');
    assert.equal(state, STATE_LOST);
    assert.equal(syncNow, false);
});

// ────────────────────────────────────────────────────────────────────────
// conversationRealtimeEnabled — garde du drapeau PROPRE a ce module
// ────────────────────────────────────────────────────────────────────────

test('conversationRealtimeEnabled : FAUX si la variable est absente', () => {
    assert.equal(conversationRealtimeEnabled({}), false);
});

test('conversationRealtimeEnabled : FAUX sur une chaine tronquee ("1", "tru")', () => {
    assert.equal(conversationRealtimeEnabled({ VITE_CONVERSATION_REALTIME_ENABLED: '1' }), false);
});

test('conversationRealtimeEnabled : VRAI uniquement sur la chaine exacte "true"', () => {
    assert.equal(conversationRealtimeEnabled({ VITE_CONVERSATION_REALTIME_ENABLED: 'true' }), true);
});

// ────────────────────────────────────────────────────────────────────────
// messageThreadAdaptivePolling — conversations DIRECTES
// ────────────────────────────────────────────────────────────────────────

test('drapeau OFF : jamais de tentative Echo, meme si window.Echo existe deja (pour une AUTRE surface)', () => {
    const { window: win } = fakeBrowserGlobals();
    win.Echo = fakeEcho(); // une AUTRE surface (ChatLoop/notifications) a deja fait naitre Echo.

    const component = messageThreadAdaptivePolling('tx-off-1', { env: { VITE_CONVERSATION_REALTIME_ENABLED: 'false' } });
    const wire = fakeWire();
    component.$wire = wire;
    component.init();

    assert.equal(
        Object.keys(win.Echo.connector.channels).length,
        0,
        'aucun canal conversation.* ne doit etre ouvert quand le drapeau PROPRE a ce module est FAUX, meme avec window.Echo deja present'
    );

    component.destroy();
});

test('drapeau ON, Echo pas encore pret : attend bouclepro:echo-ready avant de s abonner', () => {
    const { window: win } = fakeBrowserGlobals();

    const component = messageThreadAdaptivePolling('tx-wait-1', { env: { VITE_CONVERSATION_REALTIME_ENABLED: 'true' } });
    component.$wire = fakeWire();
    component.init();

    const echo = fakeEcho();
    win.Echo = echo;
    win.dispatchEvent(new CustomEvent('bouclepro:echo-ready'));

    assert.ok(echo.connector.channels['private-conversation.tx-wait-1'], 'le canal doit s ouvrir des que echo-ready arrive');

    component.destroy();
});

test('cycle complet : abonnement immediat, passage a 20s, message recu declenche pollThread(), perte de connexion ramene a 3s', () => {
    const { window: win } = fakeBrowserGlobals();
    const echo = fakeEcho();
    win.Echo = echo;

    const component = messageThreadAdaptivePolling('tx-cycle-1', { env: { VITE_CONVERSATION_REALTIME_ENABLED: 'true' } });
    const wire = fakeWire();
    component.$wire = wire;
    component.init();

    // Le banc marque `subscription.subscribed: true` des l ouverture (course
    // documentee par TASK-1841) : une synchronisation immediate est due.
    assert.equal(wire.calls.length, 1, 'premier abonnement : synchronisation immediate');

    const channel = echo.connector.channels['private-conversation.tx-cycle-1'];
    channel._emit(MESSAGE_CREATED_EVENT);
    assert.equal(wire.calls.length, 2, 'un message recu doit declencher pollThread() immediatement');

    channel._emit('_error');
    channel._emit('_subscribed');
    assert.equal(wire.calls.length, 3, 'une reconnexion doit redeclencher une synchronisation immediate');

    component.destroy();
});

test('destroy() quitte le canal via Echo.leave() quand ce composant en est toujours le proprietaire', () => {
    const { window: win } = fakeBrowserGlobals();
    const echo = fakeEcho();
    win.Echo = echo;

    const component = messageThreadAdaptivePolling('tx-leave-1', { env: { VITE_CONVERSATION_REALTIME_ENABLED: 'true' } });
    component.$wire = fakeWire();
    component.init();
    component.destroy();

    assert.deepEqual(echo.leaveCalls, ['conversation.tx-leave-1']);
    assert.equal(echo.connector.channels['private-conversation.tx-leave-1'], undefined);
});

test('navigation : init()/destroy() repetes ne fait jamais grossir le nombre net d ecouteurs globaux', () => {
    const { window: win, document: doc } = fakeBrowserGlobals();

    let added = 0;
    let removed = 0;
    for (const target of [win, doc]) {
        const originalAdd = target.addEventListener.bind(target);
        const originalRemove = target.removeEventListener.bind(target);
        target.addEventListener = (...args) => { added++; return originalAdd(...args); };
        target.removeEventListener = (...args) => { removed++; return originalRemove(...args); };
    }

    for (let i = 0; i < 3; i++) {
        const component = messageThreadAdaptivePolling(`tx-nav-${i}`, { env: { VITE_CONVERSATION_REALTIME_ENABLED: 'true' } });
        component.$wire = fakeWire();
        component.init();
        component.destroy();
    }

    assert.equal(added, removed, 'chaque ecouteur pose par init() doit etre retire par destroy()');
});

test('apres destroy(), un evenement emis sur l ancien canal ne declenche plus pollThread()', () => {
    const { window: win } = fakeBrowserGlobals();
    const echo = fakeEcho();
    win.Echo = echo;

    const component = messageThreadAdaptivePolling('tx-dead-1', { env: { VITE_CONVERSATION_REALTIME_ENABLED: 'true' } });
    const wire = fakeWire();
    component.$wire = wire;
    component.init();

    const oldChannel = echo.connector.channels['private-conversation.tx-dead-1'];
    component.destroy();
    const callsAtDestroy = wire.calls.length;

    oldChannel._emit(MESSAGE_CREATED_EVENT);

    assert.equal(wire.calls.length, callsAtDestroy, 'aucun pollThread() apres destroy()');
});

// ────────────────────────────────────────────────────────────────────────
// messageThreadRealtimeNudge — echanges d ENTRAIDE
// ────────────────────────────────────────────────────────────────────────

test('nudge : drapeau OFF, jamais de tentative Echo', () => {
    const { window: win } = fakeBrowserGlobals();
    win.Echo = fakeEcho();

    const component = messageThreadRealtimeNudge('tx-nudge-off', { env: { VITE_CONVERSATION_REALTIME_ENABLED: 'false' } });
    const wire = fakeWire();
    component.$wire = wire;
    component.init();

    assert.equal(Object.keys(win.Echo.connector.channels).length, 0);

    component.destroy();
});

test('nudge : drapeau ON, un message recu declenche pollThread() immediatement — aucun minuteur gere ici', () => {
    const { window: win } = fakeBrowserGlobals();
    const echo = fakeEcho();
    win.Echo = echo;

    const component = messageThreadRealtimeNudge('tx-nudge-on', { env: { VITE_CONVERSATION_REALTIME_ENABLED: 'true' } });
    const wire = fakeWire();
    component.$wire = wire;
    component.init();

    assert.equal(wire.calls.length, 0, 'l abonnement seul ne doit JAMAIS appeler pollThread() — wire:poll.3000ms garde ce role, ce module ne fait qu ecouter les messages');

    const channel = echo.connector.channels['private-conversation.tx-nudge-on'];
    channel._emit(MESSAGE_CREATED_EVENT);

    assert.equal(wire.calls.length, 1, 'un message recu doit declencher pollThread() immediatement');

    component.destroy();
});

test('nudge : destroy() quitte le canal via Echo.leave() quand ce composant en est toujours le proprietaire', () => {
    const { window: win } = fakeBrowserGlobals();
    const echo = fakeEcho();
    win.Echo = echo;

    const component = messageThreadRealtimeNudge('tx-nudge-leave', { env: { VITE_CONVERSATION_REALTIME_ENABLED: 'true' } });
    component.$wire = fakeWire();
    component.init();
    component.destroy();

    assert.deepEqual(echo.leaveCalls, ['conversation.tx-nudge-leave']);
});

test('nudge : destroy() ne quitte PAS le canal si un autre consommateur l a repris entre-temps', () => {
    const { window: win } = fakeBrowserGlobals();
    const echo = fakeEcho();
    win.Echo = echo;

    const component = messageThreadRealtimeNudge('tx-nudge-taken', { env: { VITE_CONVERSATION_REALTIME_ENABLED: 'true' } });
    component.$wire = fakeWire();
    component.init();

    const takenOverChannel = fakeChannel('private-conversation.tx-nudge-taken');
    echo.connector.channels['private-conversation.tx-nudge-taken'] = takenOverChannel;

    component.destroy();

    assert.deepEqual(echo.leaveCalls, []);
    assert.equal(echo.connector.channels['private-conversation.tx-nudge-taken'], takenOverChannel);
});
