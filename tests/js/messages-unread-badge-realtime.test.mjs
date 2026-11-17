/**
 * TASK-1847 — compteur global de messages non lus en temps reel, testable
 * sans navigateur (meme motif que `notifications-badge-realtime.test.mjs`,
 * TASK-1843).
 *
 * Lancer avec : node --test tests/js/messages-unread-badge-realtime.test.mjs
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    applyMessagesBadgeCount,
    messagesUnreadBadgeRealtime,
    EVENT_MESSAGE_UNREAD_COUNT_CHANGED,
} from '../../resources/js/messages-unread-badge-realtime.js';
import { notificationsBadgeRealtime, EVENT_NOTIFICATION_COUNT_CHANGED } from '../../resources/js/notifications-badge-realtime.js';

function fakeElement() {
    return {
        style: { display: '' },
        textContent: '',
        attributes: {},
        setAttribute(name, value) { this.attributes[name] = String(value); },
        removeAttribute(name) { delete this.attributes[name]; },
    };
}

function fakeBrowserGlobals() {
    class FakeWindow extends EventTarget {}
    class FakeDocument extends EventTarget {}

    const win = new FakeWindow();
    const doc = new FakeDocument();
    win.Echo = undefined;

    const mountRail = fakeElement();
    const staticRail = fakeElement();
    // TASK-1853 — deuxieme surface (barre mobile), meme motif que
    // `notifications-badge-realtime.test.mjs` (TASK-1843) : un
    // `staticBadge` sous un nom d'attribut DISTINCT de celui du rail.
    const mountMobile = fakeElement();
    const staticMobile = fakeElement();

    const elements = {
        '[data-realtime-badge-mount="messages-rail"]': mountRail,
        '[data-nav-badge-messages]': staticRail,
        '[data-realtime-badge-mount="messages-mobile"]': mountMobile,
        '[data-mobile-bottom-nav-messages-unread]': staticMobile,
    };

    doc.querySelector = (selector) => elements[selector] ?? null;

    return { win, doc, mountRail, staticRail, mountMobile, staticMobile };
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

async function tick(n = 2) {
    for (let i = 0; i < n; i++) {
        await Promise.resolve();
    }
}

// ────────────────────────────────────────────────────────────────────────
// applyMessagesBadgeCount — convention visuelle (TASK-1843 reprise)
// ────────────────────────────────────────────────────────────────────────

test('EVENT_MESSAGE_UNREAD_COUNT_CHANGED porte le point initial attendu par EventFormatter.format() (pusher-js)', () => {
    assert.equal(EVENT_MESSAGE_UNREAD_COUNT_CHANGED, '.message.unread-count-changed');
});

test('applyMessagesBadgeCount : compte > 0 rend visible, pose la valeur brute et le texte plafonne a 9+', () => {
    const mount = fakeElement();
    const staticBadge = fakeElement();

    applyMessagesBadgeCount([{ mount, staticBadge }], 15);

    assert.equal(mount.style.display, '');
    assert.equal(mount.textContent, '9+');
    assert.equal(mount.attributes['data-nav-badge-messages'], '15');
    assert.equal(staticBadge.style.display, 'none');
});

test('applyMessagesBadgeCount : compte exact sous 10 n\'est jamais plafonne', () => {
    const mount = fakeElement();

    applyMessagesBadgeCount([{ mount, staticBadge: null }], 4);

    assert.equal(mount.textContent, '4');
    assert.equal(mount.attributes['data-nav-badge-messages'], '4');
});

test('applyMessagesBadgeCount : retour a zero masque le mount et retire l\'attribut', () => {
    const mount = fakeElement();
    mount.style.display = '';
    mount.setAttribute('data-nav-badge-messages', '2');

    applyMessagesBadgeCount([{ mount, staticBadge: null }], 0);

    assert.equal(mount.style.display, 'none');
    assert.equal(mount.textContent, '');
    assert.equal(mount.attributes['data-nav-badge-messages'], undefined);
});

test('applyMessagesBadgeCount : un mount absent ne leve jamais', () => {
    assert.doesNotThrow(() => applyMessagesBadgeCount([{ mount: null, staticBadge: null }], 3));
});

// ────────────────────────────────────────────────────────────────────────
// TASK-1853 — badge mobile : mise a jour simultanee avec le rail, et
// sequence complete du compteur.
// ────────────────────────────────────────────────────────────────────────

test('applyMessagesBadgeCount : rail et mobile recoivent le MEME compte au MEME appel, chacun sur son propre mount', () => {
    const mountRail = fakeElement();
    const staticRail = fakeElement();
    const mountMobile = fakeElement();
    const staticMobile = fakeElement();

    applyMessagesBadgeCount([
        { mount: mountRail, staticBadge: staticRail },
        { mount: mountMobile, staticBadge: staticMobile },
    ], 5);

    assert.equal(mountRail.textContent, '5');
    assert.equal(mountMobile.textContent, '5');
    assert.equal(mountRail.style.display, '');
    assert.equal(mountMobile.style.display, '');
    // Chaque surface porte son PROPRE badge SSR, masque independamment —
    // jamais un selecteur partage qui en oublierait un.
    assert.equal(staticRail.style.display, 'none');
    assert.equal(staticMobile.style.display, 'none');
});

test('applyMessagesBadgeCount : sequence complete 0 -> 1 -> 9+ -> 0, rail et mobile toujours d\'accord', () => {
    const mountRail = fakeElement();
    const mountMobile = fakeElement();
    const cibles = [{ mount: mountRail, staticBadge: null }, { mount: mountMobile, staticBadge: null }];

    applyMessagesBadgeCount(cibles, 0);
    assert.equal(mountRail.style.display, 'none');
    assert.equal(mountMobile.style.display, 'none');

    applyMessagesBadgeCount(cibles, 1);
    assert.equal(mountRail.textContent, '1');
    assert.equal(mountMobile.textContent, '1');
    assert.equal(mountRail.style.display, '');
    assert.equal(mountMobile.style.display, '');

    applyMessagesBadgeCount(cibles, 12);
    assert.equal(mountRail.textContent, '9+');
    assert.equal(mountMobile.textContent, '9+');

    applyMessagesBadgeCount(cibles, 0);
    assert.equal(mountRail.style.display, 'none');
    assert.equal(mountMobile.style.display, 'none');
    assert.equal(mountRail.textContent, '');
    assert.equal(mountMobile.textContent, '');
});

// ────────────────────────────────────────────────────────────────────────
// Cycle de vie : echo-ready, abonnement, resynchronisation
// ────────────────────────────────────────────────────────────────────────

test('aucun Echo pret : l\'ecoute attend bouclepro:echo-ready, aucun fetch entre-temps', () => {
    const { win, doc } = fakeBrowserGlobals();
    let appels = 0;

    const badge = messagesUnreadBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => { appels += 1; return 1; },
        win,
        doc,
    });

    assert.equal(appels, 0);
    badge.destroy();
});

test('Echo deja pret : abonnement immediat, resynchronisation au premier "subscribed"', async () => {
    const { win, doc } = fakeBrowserGlobals();
    win.Echo = fakeEcho();
    let appels = 0;

    const badge = messagesUnreadBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => { appels += 1; return 2; },
        win,
        doc,
    });

    const channel = win.Echo.connector.channels['private-user.user-1'];
    assert.ok(channel, 'le canal prive doit etre cree immediatement');

    channel._emit('_subscribed');
    await tick();

    assert.equal(appels, 1);

    badge.destroy();
});

test('evenement message.unread-count-changed recu : UNE resynchronisation HTTP, jamais une lecture du payload', async () => {
    const { win, doc, mountRail, mountMobile } = fakeBrowserGlobals();
    win.Echo = fakeEcho();
    let appels = 0;

    const badge = messagesUnreadBadgeRealtime({
        userId: 'user-1',
        // Le payload WebSocket est volontairement VIDE (MessageUnreadCountChanged
        // ne porte rien) : meme recu avec des donnees empoisonnees, seul
        // fetchUnreadCount() doit faire foi.
        fetchUnreadCount: async () => { appels += 1; return 7; },
        win,
        doc,
    });

    const channel = win.Echo.connector.channels['private-user.user-1'];
    channel._emit(EVENT_MESSAGE_UNREAD_COUNT_CHANGED, { count: 999 });
    await tick();

    assert.equal(appels, 1);
    assert.equal(mountRail.attributes['data-nav-badge-messages'], '7', 'le compteur WebSocket ne doit jamais devenir la source de verite — seule la relecture HTTP compte');
    // TASK-1853 — UNE seule relecture HTTP (appels === 1 ci-dessus), mais
    // les DEUX surfaces en recoivent le resultat : le rail et la barre
    // mobile ne doivent jamais diverger.
    assert.equal(mountMobile.attributes['data-nav-badge-messages'], '7', 'le badge mobile doit refleter le MEME compte que le rail, depuis la MEME relecture HTTP');
    assert.equal(mountMobile.style.display, '', 'le badge mobile doit devenir visible comme le rail');

    badge.destroy();
});

test('echo-ready apres coup : l\'abonnement se pose a ce moment-la, pas avant', () => {
    const { win, doc } = fakeBrowserGlobals();

    const badge = messagesUnreadBadgeRealtime({
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

    const badge = messagesUnreadBadgeRealtime({
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

test('destroy() ne quitte JAMAIS le canal user.{userId}', () => {
    const { win, doc } = fakeBrowserGlobals();
    win.Echo = fakeEcho();

    const badge = messagesUnreadBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => 0,
        win,
        doc,
    });

    badge.destroy();

    assert.deepEqual(win.Echo.leaveCalls, []);
    assert.ok(win.Echo.connector.channels['private-user.user-1'], 'le canal doit rester vivant apres destroy()');
});

// ────────────────────────────────────────────────────────────────────────
// Regroupement des requetes — meme motif que TASK-1843
// ────────────────────────────────────────────────────────────────────────

function deferred() {
    let resolve;
    const promise = new Promise((res) => { resolve = res; });
    return { promise, resolve };
}

test('plusieurs evenements rapproches pendant UNE requete en vol : une seule relecture finale, jamais une par evenement', async () => {
    const { win, doc } = fakeBrowserGlobals();
    win.Echo = fakeEcho();

    const d1 = deferred();
    let appels = 0;
    const reponses = [d1.promise];

    const badge = messagesUnreadBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => { const p = reponses[appels]; appels += 1; return p; },
        win,
        doc,
    });

    const channel = win.Echo.connector.channels['private-user.user-1'];
    channel._emit('_subscribed');
    await tick();
    assert.equal(appels, 1, 'le premier abonnement declenche une requete, encore en vol');

    const d2 = deferred();
    reponses.push(d2.promise);

    // Rafale de 5 evenements PENDANT que la premiere requete est en vol.
    for (let i = 0; i < 5; i++) {
        channel._emit(EVENT_MESSAGE_UNREAD_COUNT_CHANGED);
    }
    await tick();

    assert.equal(appels, 1, 'aucun fetch concurrent ne doit partir pendant que le premier est en vol');

    d1.resolve(3);
    await tick();

    assert.equal(appels, 2, 'exactement UNE relecture finale doit partir apres la requete en vol');

    d2.resolve(3);
    await tick();

    assert.equal(appels, 2, 'aucune troisieme relecture : rien n etait reclame apres la seconde');

    badge.destroy();
});

test('destroy() pendant une requete en vol : la reponse qui arrive ensuite n\'est jamais appliquee au DOM', async () => {
    const { win, doc, mountRail } = fakeBrowserGlobals();
    win.Echo = fakeEcho();

    const d = deferred();

    const badge = messagesUnreadBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => d.promise,
        win,
        doc,
    });

    const channel = win.Echo.connector.channels['private-user.user-1'];
    channel._emit('_subscribed');
    await tick();

    badge.destroy();
    d.resolve(9);
    await tick();

    assert.equal(mountRail.attributes['data-nav-badge-messages'], undefined, 'une reponse arrivee apres destroy() ne doit jamais toucher le DOM');
});

// ────────────────────────────────────────────────────────────────────────
// TASK-1847 — deux consommateurs INDEPENDANTS sur le MEME canal partage
// ────────────────────────────────────────────────────────────────────────

test('deux consommateurs independants (notifications + messages) partagent le MEME canal sans desabonnement croise', async () => {
    const { win: winNotif, doc: docNotif } = (function () {
        class FakeWindow extends EventTarget {}
        class FakeDocument extends EventTarget {}
        const win = new FakeWindow();
        const doc = new FakeDocument();
        doc.querySelector = () => null;
        return { win, doc };
    })();

    winNotif.Echo = fakeEcho();

    let appelsNotif = 0;
    let appelsMessages = 0;

    const badgeNotifications = notificationsBadgeRealtime({
        userId: 'user-shared',
        fetchUnreadCount: async () => { appelsNotif += 1; return 1; },
        win: winNotif,
        doc: docNotif,
    });

    const badgeMessages = messagesUnreadBadgeRealtime({
        userId: 'user-shared',
        fetchUnreadCount: async () => { appelsMessages += 1; return 2; },
        win: winNotif,
        doc: docNotif,
    });

    const channel = winNotif.Echo.connector.channels['private-user.user-shared'];
    assert.ok(channel, 'un seul canal partage doit exister pour les deux modules');
    assert.equal(channel._listenerCount('_subscribed'), 2, 'les deux modules doivent avoir pose leur propre sonde d abonnement sur le MEME canal');

    // Detruire le badge de NOTIFICATIONS ne doit jamais affecter le badge
    // de MESSAGES, toujours abonne au meme canal.
    badgeNotifications.destroy();

    channel._emit(EVENT_MESSAGE_UNREAD_COUNT_CHANGED);
    await tick();

    assert.equal(appelsMessages, 1, 'le badge de messages doit continuer a recevoir ses evenements apres la destruction du badge de notifications');
    assert.ok(winNotif.Echo.connector.channels['private-user.user-shared'], 'le canal partage doit toujours exister');

    // Et inversement : detruire le badge de MESSAGES ensuite ne doit rien
    // casser pour un evenement de notifications (meme si plus rien n ecoute
    // ici, l objet canal lui-meme doit survivre).
    badgeMessages.destroy();

    assert.deepEqual(winNotif.Echo.leaveCalls, [], 'aucun des deux modules ne doit jamais appeler Echo.leave() sur ce canal partage');

    channel._emit(EVENT_NOTIFICATION_COUNT_CHANGED);
    await tick();
    // Les deux etant detruits, aucun fetch supplementaire n est attendu —
    // mais l emission elle-meme ne doit jamais lever.
    assert.equal(appelsNotif, 0, 'le badge de notifications n a jamais recu de _subscribed ici, donc aucun fetch n etait attendu avant sa destruction');
});
