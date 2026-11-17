/**
 * TASK-1839 — vrai, testable sans navigateur (voir
 * tests/js/echo-realtime-guard.test.mjs). Decide SEULEMENT a partir de ce
 * qu'on lui donne : jamais de lecture globale ici, pour rester une fonction
 * pure.
 *
 * Sans cette garde, `new Echo(...)` partait INCONDITIONNELLEMENT (mesure
 * TASK-1839, revue de code avant fusion) : sur n'importe quel poste ou
 * environnement SANS Reverb configure — soit tout le monde sauf ce pilote
 * local — le navigateur tentait en boucle une connexion WebSocket vers un
 * hote et une clef `undefined`.
 *
 * TASK-1843 — `window.Echo` reste une connexion UNIQUE, partagee par
 * TOUTES les surfaces temps reel du navigateur (audit TASK-1842, section
 * 3.2 : un seul client Reverb, jamais un par fonctionnalite). Elle doit
 * donc s'instancier des que L'UNE des surfaces l'exige — ici ChatLoop OU
 * le badge de notifications — meme si chaque drapeau reste independant et
 * FAUX par defaut.
 *
 * TASK-1846 — troisieme pilote independant (messages de conversation
 * privee, `message-thread-adaptive-polling.js`). Meme regle : s'ajoute au
 * OU, sans jamais devenir une condition requise pour les deux autres.
 */
export function shouldEnableRealtime(env) {
    return (
        env.VITE_LOOP_CHAT_REALTIME_ENABLED === 'true'
        || env.VITE_NOTIFICATIONS_REALTIME_ENABLED === 'true'
        || env.VITE_CONVERSATION_REALTIME_ENABLED === 'true'
    )
        && Boolean(env.VITE_REVERB_APP_KEY)
        && Boolean(env.VITE_REVERB_HOST);
}

(async () => {
    // `?? {}` : en dehors d'un build Vite (ex. `node --test` important ce
    // fichier pour tester `shouldEnableRealtime` seule), `import.meta.env`
    // n'existe pas — l'absence de configuration doit se comporter EXACTEMENT
    // comme une configuration absente, jamais planter l'import.
    if (!shouldEnableRealtime(import.meta.env ?? {})) {
        return;
    }

    // Import DYNAMIQUE, et non statique : `pusher-js` s'auto-enregistre sur
    // `window.Pusher` des qu'il est evalue (effet de bord propre a la
    // bibliotheque — vendor/dist/web/pusher.js, pas notre code). Un import
    // statique en tete de fichier declenchait donc CET effet de bord meme
    // pilote desactive. Avec l'import dynamique, aucun code de
    // `laravel-echo`/`pusher-js` ne s'execute — et n'est meme charge —
    // tant que la garde ci-dessus n'est pas passee.
    const [{ default: Echo }, { default: Pusher }] = await Promise.all([
        import('laravel-echo'),
        import('pusher-js'),
    ]);

    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    // TASK-1841 — `window.Echo` nait APRES cet import dynamique, de maniere
    // asynchrone : un script qui teste `window.Echo` au chargement de la page
    // (ex. `chatloop-adaptive-polling.js`) le trouverait encore absent.
    // Cet evenement est le signal de rattrapage, pose une seule fois ici.
    window.dispatchEvent(new CustomEvent('bouclepro:echo-ready'));
})();
