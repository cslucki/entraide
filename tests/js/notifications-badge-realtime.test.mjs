/**
 * TASK-1843 — badge de notifications internes en temps reel, testable sans
 * navigateur (meme motif que `chatloop-adaptive-polling.test.mjs`,
 * TASK-1841).
 *
 * Lancer avec : node --test tests/js/notifications-badge-realtime.test.mjs
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    applyBadgeCount,
    notificationsBadgeRealtime,
    EVENT_NOTIFICATION_COUNT_CHANGED,
} from '../../resources/js/notifications-badge-realtime.js';

function fakeElement() {
    return {
        style: { display: '' },
        textContent: '',
        attributes: {},
        setAttribute(name, value) { this.attributes[name] = String(value); },
        removeAttribute(name) { delete this.attributes[name]; },
    };
}

/** Meme banc que chatloop-adaptive-polling.test.mjs (TASK-1841). */
function fakeBrowserGlobals() {
    class FakeWindow extends EventTarget {}
    class FakeDocument extends EventTarget {}

    const win = new FakeWindow();
    const doc = new FakeDocument();
    win.Echo = undefined;

    return { win, doc };
}

function fakeChannel(key) {
    const listeners = {};
    const bind = (event, cb) => { (listeners[event] ??= []).push(cb); };

    return {
        key,
        listen(event, cb) { bind(event, cb); return this; },
        stopListening(event, cb) {
            listeners[event] = (listeners[event] || []).filter((f) => f !== cb);
            return this;
        },
        subscribed(cb) { bind('_subscribed', cb); return this; },
        _emit(event, ...args) { (listeners[event] || []).forEach((cb) => cb(...args)); },
        _listenerCount(event) { return (listeners[event] || []).length; },
    };
}

function fakeEcho() {
    const channels = {};
    const leaveCalls = [];

    return {
        connector: { channels },
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

test('EVENT_NOTIFICATION_COUNT_CHANGED porte le point initial attendu par EventFormatter.format() (pusher-js)', () => {
    assert.equal(EVENT_NOTIFICATION_COUNT_CHANGED, '.notification.count-changed');
});

test('applyBadgeCount : compte > 0 rend visible, pose la valeur brute et le texte plafonne a 9+', () => {
    const mount = fakeElement();
    const staticBadge = fakeElement();

    applyBadgeCount([{ mount, staticBadge }], 12);

    assert.equal(mount.style.display, '');
    assert.equal(mount.textContent, '9+');
    assert.equal(mount.attributes['data-nav-badge-notifications'], '12');
    // Le badge rendu cote serveur cede la place au mount JS, jamais superpose.
    assert.equal(staticBadge.style.display, 'none');
});

test('applyBadgeCount : compte exact sous 10 n\'est jamais plafonne', () => {
    const mount = fakeElement();

    applyBadgeCount([{ mount, staticBadge: null }], 7);

    assert.equal(mount.textContent, '7');
    assert.equal(mount.attributes['data-nav-badge-notifications'], '7');
});

test('applyBadgeCount : retour a zero masque le mount et retire l\'attribut', () => {
    const mount = fakeElement();
    mount.style.display = '';
    mount.setAttribute('data-nav-badge-notifications', '3');

    applyBadgeCount([{ mount, staticBadge: null }], 0);

    assert.equal(mount.style.display, 'none');
    assert.equal(mount.textContent, '');
    assert.equal(mount.attributes['data-nav-badge-notifications'], undefined);
});

test('applyBadgeCount : un mount absent (page sans ce point de montage) ne leve jamais', () => {
    assert.doesNotThrow(() => applyBadgeCount([{ mount: null, staticBadge: null }], 4));
});

test('aucun drapeau Echo pret : l\'ecoute attend l\'evenement bouclepro:echo-ready', () => {
    const { win, doc } = fakeBrowserGlobals();
    let appels = 0;

    const badge = notificationsBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => { appels += 1; return 1; },
        win,
        doc,
    });

    assert.equal(appels, 0);
    badge.destroy();
});

test('Echo deja pret au moment de l\'init : abonnement immediat, resynchronisation au premier "subscribed"', async () => {
    const { win, doc } = fakeBrowserGlobals();
    win.Echo = fakeEcho();
    let appels = 0;

    const badge = notificationsBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => { appels += 1; return 2; },
        win,
        doc,
    });

    const channel = win.Echo.connector.channels['private-user.user-1'];
    assert.ok(channel, 'le canal prive doit etre cree immediatement');

    channel._emit('_subscribed');
    // `resync()` est asynchrone (await fetchUnreadCount()) : laisser le
    // microtask se derouler avant d'observer son effet.
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(appels, 1);

    badge.destroy();
});

test('evenement notification.count-changed recu : UNE resynchronisation HTTP, jamais une lecture du payload', async () => {
    const { win, doc } = fakeBrowserGlobals();
    win.Echo = fakeEcho();
    let appels = 0;
    let valeurRendue = null;

    const badge = notificationsBadgeRealtime({
        userId: 'user-1',
        // Rend 5 quel que soit le payload recu : si le module lisait le
        // payload au lieu d'appeler fetchUnreadCount(), `valeurRendue`
        // finirait a 999 (la valeur empoisonnee ci-dessous), jamais a 5.
        fetchUnreadCount: async () => { appels += 1; valeurRendue = 5; return 5; },
        win,
        doc,
    });

    const channel = win.Echo.connector.channels['private-user.user-1'];

    // Le payload WebSocket est volontairement VIDE (MemberNotificationCreated
    // ne porte rien) : meme recu avec des donnees, le module ne doit JAMAIS
    // les lire — seul fetchUnreadCount() fait foi.
    channel._emit(EVENT_NOTIFICATION_COUNT_CHANGED, { count: 999, recipient_id: 'fuite' });
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(appels, 1);
    assert.equal(valeurRendue, 5);

    badge.destroy();
});

test('echo-ready apres coup : l\'abonnement se pose a ce moment-la, pas avant', () => {
    const { win, doc } = fakeBrowserGlobals();

    const badge = notificationsBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => 0,
        win,
        doc,
    });

    assert.equal(win.Echo, undefined);

    win.Echo = fakeEcho();
    win.dispatchEvent(new Event('bouclepro:echo-ready'));

    const channel = win.Echo.connector.channels['private-user.user-1'];
    assert.ok(channel, 'le canal doit etre cree des que echo-ready est recu');

    badge.destroy();
});

test('destroy() AVANT echo-ready : retire le listener, aucun abonnement ne se pose jamais', () => {
    const { win, doc } = fakeBrowserGlobals();

    const badge = notificationsBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => 0,
        win,
        doc,
    });

    badge.destroy();

    win.Echo = fakeEcho();
    win.dispatchEvent(new Event('bouclepro:echo-ready'));

    assert.equal(Object.keys(win.Echo.connector.channels).length, 0);
});

test('destroy() quitte le canal via l\'API officielle Echo.leave(), jamais leaveChannel() avec une cle prefixee', () => {
    const { win, doc } = fakeBrowserGlobals();
    win.Echo = fakeEcho();

    const badge = notificationsBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => 0,
        win,
        doc,
    });

    badge.destroy();

    assert.deepEqual(win.Echo.leaveCalls, ['user.user-1']);
    assert.equal(win.Echo.connector.channels['private-user.user-1'], undefined);
});

test('destroy() ne quitte PAS un canal qu\'un AUTRE consommateur a repris depuis (Echo.leave n\'est pas reference-compte)', () => {
    const { win, doc } = fakeBrowserGlobals();
    win.Echo = fakeEcho();

    const badge = notificationsBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => 0,
        win,
        doc,
    });

    // Un autre module reprend le MEME canal entre-temps (ex. rechargement
    // partiel hypothetique) : la cle est reassignee a une AUTRE instance.
    win.Echo.connector.channels['private-user.user-1'] = fakeChannel('private-user.user-1');

    badge.destroy();

    assert.deepEqual(win.Echo.leaveCalls, [], 'ne doit jamais appeler leave() sur un canal qui ne lui appartient plus');
});

test('destroy() stoppe l\'ecoute de l\'evenement : aucune resynchronisation apres destruction', async () => {
    const { win, doc } = fakeBrowserGlobals();
    win.Echo = fakeEcho();
    let appels = 0;

    const badge = notificationsBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => { appels += 1; return 1; },
        win,
        doc,
    });

    const channel = win.Echo.connector.channels['private-user.user-1'];
    assert.equal(channel._listenerCount(EVENT_NOTIFICATION_COUNT_CHANGED), 1);

    badge.destroy();

    assert.equal(appels, 0);
});

test('une relecture HTTP en echec ne leve jamais (le badge garde sa derniere valeur connue)', async () => {
    const { win, doc } = fakeBrowserGlobals();
    win.Echo = fakeEcho();

    const badge = notificationsBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => { throw new Error('panne HTTP simulee'); },
        win,
        doc,
    });

    const channel = win.Echo.connector.channels['private-user.user-1'];

    assert.doesNotThrow(() => channel._emit('_subscribed'));
    await Promise.resolve();
    await Promise.resolve();

    badge.destroy();
});

test('cycle complet reconnexion : une coupure puis un nouvel abonnement resynchronise encore', async () => {
    const { win, doc } = fakeBrowserGlobals();
    win.Echo = fakeEcho();
    let appels = 0;

    const badge = notificationsBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => { appels += 1; return appels; },
        win,
        doc,
    });

    const channel = win.Echo.connector.channels['private-user.user-1'];

    channel._emit('_subscribed');
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(appels, 1);

    // Reconnexion : pusher-js re-appelle `subscribed()` a chaque nouvel
    // abonnement reussi, premiere connexion comme reconnexion comprises.
    channel._emit('_subscribed');
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(appels, 2);

    badge.destroy();
});
