/**
 * TASK-1839 — revue de code avant fusion de la PR #847 : `resources/js/
 * echo.js` instanciait `new Echo(...)` INCONDITIONNELLEMENT, meme sans
 * Reverb configure. Sur tout poste ou environnement sans `VITE_REVERB_*`
 * (soit tout le monde sauf ce pilote local, PROD compris), le navigateur
 * tentait en boucle une connexion WebSocket vers un hote et une clef
 * `undefined`.
 *
 * `shouldEnableRealtime()` est la garde extraite pour rester testable sans
 * navigateur ni build Vite — exactement le motif deja suivi par
 * `resources/js/editor/panneaux.js` (voir `editor-panneaux.test.mjs`).
 *
 * Lancer avec : node --test tests/js/echo-realtime-guard.test.mjs
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { shouldEnableRealtime } from '../../resources/js/echo.js';

const CONFIG_COMPLETE = {
    VITE_LOOP_CHAT_REALTIME_ENABLED: 'true',
    VITE_REVERB_APP_KEY: 'une-clef',
    VITE_REVERB_HOST: 'test.laravel',
};

test('etat PROD aujourd\'hui (aucune variable posee) : jamais de connexion', () => {
    assert.equal(shouldEnableRealtime({}), false);
});

test('drapeau absent, meme avec la configuration Reverb complete : jamais de connexion', () => {
    const env = { ...CONFIG_COMPLETE };
    delete env.VITE_LOOP_CHAT_REALTIME_ENABLED;

    assert.equal(shouldEnableRealtime(env), false);
});

test('drapeau explicitement a FAUX : jamais de connexion', () => {
    assert.equal(
        shouldEnableRealtime({ ...CONFIG_COMPLETE, VITE_LOOP_CHAT_REALTIME_ENABLED: 'false' }),
        false,
    );
});

test('drapeau actif mais clef Reverb absente : jamais de connexion', () => {
    const env = { ...CONFIG_COMPLETE };
    delete env.VITE_REVERB_APP_KEY;

    assert.equal(shouldEnableRealtime(env), false);
});

test('drapeau actif mais hote Reverb absent : jamais de connexion', () => {
    const env = { ...CONFIG_COMPLETE };
    delete env.VITE_REVERB_HOST;

    assert.equal(shouldEnableRealtime(env), false);
});

test('drapeau actif ET configuration Reverb complete : connexion autorisee', () => {
    assert.equal(shouldEnableRealtime(CONFIG_COMPLETE), true);
});

test('une chaine tronquee ("tru", "1") ne vaut pas "true" : jamais de connexion', () => {
    // Vite fige les variables VITE_* en CHAINES ; un pilote mal configure
    // (ex. `VITE_LOOP_CHAT_REALTIME_ENABLED=1`) ne doit pas passer par
    // accident une comparaison trop permissive.
    assert.equal(shouldEnableRealtime({ ...CONFIG_COMPLETE, VITE_LOOP_CHAT_REALTIME_ENABLED: '1' }), false);
});

// TASK-1843 — `window.Echo` est une connexion PARTAGEE par ChatLoop ET le
// badge de notifications : elle doit s'instancier des que L'UN des deux
// drapeaux est actif, chacun restant independant et FAUX par defaut.

test('TASK-1843 — drapeau notifications SEUL actif, ChatLoop desactive : connexion autorisee', () => {
    const env = { ...CONFIG_COMPLETE };
    delete env.VITE_LOOP_CHAT_REALTIME_ENABLED;
    env.VITE_NOTIFICATIONS_REALTIME_ENABLED = 'true';

    assert.equal(shouldEnableRealtime(env), true);
});

test('TASK-1843 — les deux drapeaux absents : jamais de connexion', () => {
    assert.equal(shouldEnableRealtime({ ...CONFIG_COMPLETE, VITE_LOOP_CHAT_REALTIME_ENABLED: undefined }), false);
});

test('TASK-1843 — drapeau notifications actif mais clef Reverb absente : jamais de connexion', () => {
    const env = { ...CONFIG_COMPLETE, VITE_NOTIFICATIONS_REALTIME_ENABLED: 'true' };
    delete env.VITE_LOOP_CHAT_REALTIME_ENABLED;
    delete env.VITE_REVERB_APP_KEY;

    assert.equal(shouldEnableRealtime(env), false);
});

test('TASK-1843 — les deux drapeaux actifs simultanement : connexion autorisee (une seule, partagee)', () => {
    assert.equal(
        shouldEnableRealtime({ ...CONFIG_COMPLETE, VITE_NOTIFICATIONS_REALTIME_ENABLED: 'true' }),
        true,
    );
});
