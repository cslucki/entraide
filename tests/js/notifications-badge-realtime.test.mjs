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

/**
 * Meme banc que chatloop-adaptive-polling.test.mjs (TASK-1841), complete
 * d'un `document.querySelector` minimal : `cibles()` (module source) en a
 * besoin pour atteindre `applyBadgeCount()` sans lever — sans quoi l'erreur
 * serait avalee par le MEME `catch` que celui de `fetchUnreadCount()`, et
 * aucune assertion sur l'etat du DOM ne prouverait rien.
 */
function fakeBrowserGlobals() {
    class FakeWindow extends EventTarget {}
    class FakeDocument extends EventTarget {}

    const win = new FakeWindow();
    const doc = new FakeDocument();
    win.Echo = undefined;

    const mountRail = fakeElement();
    const staticRail = fakeElement();
    const mountMobile = fakeElement();
    const staticMobile = fakeElement();

    const elements = {
        '[data-realtime-badge-mount="notifications-rail"]': mountRail,
        '[data-nav-badge-notifications]': staticRail,
        '[data-realtime-badge-mount="notifications-mobile"]': mountMobile,
        '[data-mobile-topbar-notifications-unread]': staticMobile,
    };

    doc.querySelector = (selector) => elements[selector] ?? null;

    return { win, doc, mountRail, staticRail, mountMobile, staticMobile };
}

/** Promesse dont la resolution est declenchee depuis l'exterieur, pour controler precisement l'ordre d'arrivee de plusieurs reponses HTTP simulees. */
function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

/** `await` un nombre donne de microtasks, pour laisser les `.then()` deja planifies se derouler avant d'observer leur effet. */
async function tick(n = 2) {
    for (let i = 0; i < n; i++) {
        await Promise.resolve();
    }
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

test('TASK-1847 — destroy() ne quitte JAMAIS le canal user.{userId} (un second consommateur independant peut le partager)', () => {
    const { win, doc } = fakeBrowserGlobals();
    win.Echo = fakeEcho();

    const badge = notificationsBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => 0,
        win,
        doc,
    });

    badge.destroy();

    assert.deepEqual(win.Echo.leaveCalls, [], 'ce module ne doit plus jamais appeler Echo.leave() sur user.{userId} — voir TASK-1847, le canal est desormais partage avec messages-unread-badge-realtime.js');
    assert.ok(win.Echo.connector.channels['private-user.user-1'], 'le canal doit rester vivant apres destroy() : un AUTRE consommateur (le badge de messages) peut encore en avoir besoin');
});

test('TASK-1847 — destroy() ne desabonne jamais un evenement pose par un AUTRE consommateur du meme canal partage', () => {
    const { win, doc } = fakeBrowserGlobals();
    win.Echo = fakeEcho();

    const badge = notificationsBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => 0,
        win,
        doc,
    });

    // Un second consommateur (le badge de messages non lus, simule ici)
    // pose SA PROPRE ecoute sur LE MEME canal, un nom d'evenement different.
    const channel = win.Echo.connector.channels['private-user.user-1'];
    let autreEvenementRecu = 0;
    channel.listen('.message.unread-count-changed', () => { autreEvenementRecu++; });

    badge.destroy();

    channel._emit('.message.unread-count-changed');

    assert.equal(autreEvenementRecu, 1, 'detruire le badge de notifications ne doit jamais desabonner un AUTRE consommateur du meme canal partage');
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

// ── TASK-1843 (revue MASTER, controle de synchronisation) ──────────────────
// Regroupement des resynchronisations concurrentes + protection post-destroy.

test('deux evenements rapproches : UN SEUL fetch en vol, le second evenement reclame une relecture finale plutot qu un fetch concurrent', async () => {
    const { win, doc } = fakeBrowserGlobals();
    win.Echo = fakeEcho();

    const reponses = [deferred(), deferred()];
    let appels = 0;

    const badge = notificationsBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => { const d = reponses[appels]; appels += 1; return d.promise; },
        win,
        doc,
    });

    const channel = win.Echo.connector.channels['private-user.user-1'];

    channel._emit(EVENT_NOTIFICATION_COUNT_CHANGED);
    await tick();
    assert.equal(appels, 1, 'le premier evenement part immediatement');

    // Un second evenement arrive PENDANT que le premier fetch est en vol.
    channel._emit(EVENT_NOTIFICATION_COUNT_CHANGED);
    await tick();
    assert.equal(appels, 1, 'aucun second fetch concurrent : seulement une intention de relecture posee');

    badge.destroy();
});

test('reponses HTTP inversees : la valeur du fetch le plus RECENT fait toujours foi, jamais une valeur perimee restee en vol', async () => {
    const { win, doc, mountRail } = fakeBrowserGlobals();
    win.Echo = fakeEcho();

    const reponses = [deferred(), deferred()];
    let appels = 0;

    const badge = notificationsBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => { const d = reponses[appels]; appels += 1; return d.promise; },
        win,
        doc,
    });

    const channel = win.Echo.connector.channels['private-user.user-1'];

    channel._emit(EVENT_NOTIFICATION_COUNT_CHANGED);
    await tick();
    channel._emit(EVENT_NOTIFICATION_COUNT_CHANGED);
    await tick();
    assert.equal(appels, 1, 'toujours un seul fetch en vol a ce stade');

    // Le premier fetch (perime : un second evenement est deja survenu
    // entre-temps) resout en PREMIER, avec une valeur STALE.
    reponses[0].resolve(5);
    await tick();

    assert.equal(mountRail.textContent, '5', 'la valeur intermediaire s applique — ce n est pas elle qui doit survivre');
    assert.equal(appels, 2, 'la relecture finale reclamee part des que la premiere requete se termine');

    // Le second fetch (la relecture finale) resout ENSUITE, avec la valeur
    // a jour — jamais ecrasee par une reponse arrivee plus tard mais
    // partie plus tot.
    reponses[1].resolve(9);
    await tick();

    assert.equal(mountRail.textContent, '9', 'la derniere requete PARTIE fait foi, jamais une valeur plus ancienne');

    badge.destroy();
});

test('plusieurs evenements rapproches pendant UNE requete en vol : une seule relecture finale, jamais une par evenement', async () => {
    const { win, doc } = fakeBrowserGlobals();
    win.Echo = fakeEcho();

    const reponses = [deferred(), deferred()];
    let appels = 0;

    const badge = notificationsBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => { const d = reponses[appels]; appels += 1; return d.promise; },
        win,
        doc,
    });

    const channel = win.Echo.connector.channels['private-user.user-1'];

    channel._emit(EVENT_NOTIFICATION_COUNT_CHANGED);
    await tick();
    assert.equal(appels, 1);

    // Quatre evenements de plus, tous recus PENDANT que le premier fetch
    // est encore en vol.
    channel._emit(EVENT_NOTIFICATION_COUNT_CHANGED);
    channel._emit(EVENT_NOTIFICATION_COUNT_CHANGED);
    channel._emit(EVENT_NOTIFICATION_COUNT_CHANGED);
    channel._emit(EVENT_NOTIFICATION_COUNT_CHANGED);
    await tick();
    assert.equal(appels, 1, 'toujours un seul fetch en vol, quel que soit le nombre d evenements recus pendant son attente');

    reponses[0].resolve(3);
    await tick();
    assert.equal(appels, 2, 'UNE seule relecture finale est declenchee — jamais quatre');

    reponses[1].resolve(3);
    await tick();
    assert.equal(appels, 2, 'plus aucune relecture : la valeur finale etait deja a jour');

    badge.destroy();
});

test('destroy() pendant une requete en vol : la reponse qui arrive ensuite n est jamais appliquee au DOM', async () => {
    const { win, doc, mountRail } = fakeBrowserGlobals();
    win.Echo = fakeEcho();

    const reponse = deferred();

    const badge = notificationsBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => reponse.promise,
        win,
        doc,
    });

    const channel = win.Echo.connector.channels['private-user.user-1'];

    channel._emit(EVENT_NOTIFICATION_COUNT_CHANGED);
    await tick();

    const etatAvant = mountRail.textContent;

    badge.destroy();

    // La requete, deja partie AVANT destroy(), se termine APRES : son
    // resultat ne doit jamais atteindre un composant qui n'est plus actif.
    assert.doesNotThrow(() => reponse.resolve(42));
    await tick();

    assert.equal(mountRail.textContent, etatAvant, 'le DOM n a jamais ete touche par une reponse arrivee apres destroy()');
});

test('destroy() pendant une requete en vol, avec une relecture deja reclamee : aucune relance ne part apres destroy()', async () => {
    const { win, doc } = fakeBrowserGlobals();
    win.Echo = fakeEcho();

    const reponse = deferred();
    let appels = 0;

    const badge = notificationsBadgeRealtime({
        userId: 'user-1',
        fetchUnreadCount: async () => { appels += 1; return reponse.promise; },
        win,
        doc,
    });

    const channel = win.Echo.connector.channels['private-user.user-1'];

    channel._emit(EVENT_NOTIFICATION_COUNT_CHANGED);
    await tick();
    assert.equal(appels, 1);

    // Un second evenement reclame une relecture finale AVANT la destruction.
    channel._emit(EVENT_NOTIFICATION_COUNT_CHANGED);
    await tick();

    badge.destroy();

    reponse.resolve(7);
    await tick();

    assert.equal(appels, 1, 'la relecture reclamee avant destroy() ne part jamais apres');
});
