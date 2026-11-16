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
    STATE_CONNECTING,
    STATE_SUBSCRIBED,
    STATE_LOST,
    POLL_INTERVAL_DEFAULT_MS,
    POLL_INTERVAL_SAFETY_MS,
} from '../../resources/js/chatloop-adaptive-polling.js';

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
