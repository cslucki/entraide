/**
 * TASK-1745 — la mécanique d'abonnement Push, partagée.
 *
 * ## Pourquoi ce fichier existe
 *
 * Deux surfaces demandent maintenant le même geste : le panneau
 * « Notifications sur cet appareil » (T1739, écran de réglages) et la
 * proposition du premier lancement (T1745, PWA installée).
 *
 * Recopier la logique dans la seconde aurait créé une seconde vérité sur du
 * code de sécurité — conversion de la clé VAPID, `userVisibleOnly`, envoi de
 * l'endpoint au serveur. Deux copies d'une règle finissent toujours par
 * diverger, et on ne s'en aperçoit que du côté qu'on regarde le moins.
 *
 * Ce n'est pas un nouveau moteur : c'est exactement le code de T1739,
 * déplacé pour avoir deux appelants au lieu d'un. Le serveur, les routes et
 * le magasin sont inchangés.
 *
 * ## Ce que ce module ne fait PAS
 *
 * Il ne demande jamais la permission de lui-même. `requestPermission()` est
 * appelé ici, mais uniquement depuis `abonner()`, que les deux appelants
 * n'invoquent que dans un gestionnaire de clic (CDC 5.3) : une demande au
 * chargement se fait refuser par réflexe, et un refus ne se rattrape pas.
 */

/** Le jeton CSRF de la page courante. */
export const jeton = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/** La clé VAPID doit partir en Uint8Array, pas en base64url. */
export const clePublique = (base64url) => {
    const bourrage = '='.repeat((4 - (base64url.length % 4)) % 4);
    const base64 = (base64url + bourrage).replace(/-/g, '+').replace(/_/g, '/');
    const brut = atob(base64);

    return Uint8Array.from([...brut].map((c) => c.charCodeAt(0)));
};

/**
 * Un libellé GROSSIER, pour que la personne reconnaisse son appareil.
 * Jamais un UA complet, jamais une signature (CDC 5.4).
 */
export const libelleAppareil = () => {
    const ua = navigator.userAgent;
    if (/Android/i.test(ua)) return 'Android';
    if (/iPhone/i.test(ua)) return 'iPhone';
    if (/iPad/i.test(ua)) return 'iPad';
    if (/Macintosh/i.test(ua)) return 'Mac';
    if (/Windows/i.test(ua)) return 'Windows';

    return null;
};

/**
 * Ce NAVIGATEUR sait-il faire du Push ? La question porte sur lui SEUL.
 *
 * TASK-1768 — elle est isolée parce qu'elle ne se confond pas avec « le Push
 * est-il proposé ici ? ». Un navigateur parfaitement capable se voyait
 * annoncer « Non prises en charge par ce navigateur » quand c'était le
 * SERVEUR qui avait le Push éteint : le message accusait le navigateur d'une
 * panne de configuration. Les deux causes se distinguent maintenant, et elles
 * se lisent à un seul endroit.
 */
export const supporteNavigateur = () =>
    'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

/** Le Push est-il utilisable ici ? Quatre conditions, aucune supposée. */
export const supporte = (actif, vapid) => actif && vapid !== '' && supporteNavigateur();

/** L'abonnement que le navigateur détient, ou `null`. */
export const abonnementCourant = async () => {
    const inscription = await navigator.serviceWorker.ready;

    return inscription.pushManager.getSubscription();
};

/* =====================================================================
 * TASK-1822 — LA COMPARAISON DE CLÉ VAPID, EN UN SEUL EXEMPLAIRE.
 *
 * Elle vivait dans `push-device.js` seulement. `push-first-run.js` — le
 * chemin du premier lancement de la PWA INSTALLÉE, donc celui des
 * téléphones réels — reconfirmait sans jamais la poser.
 *
 * Conséquence mesurée en T1821 : la reconfirmation silencieuse fait
 * RÉÉCRIRE `vapid_key_hash` côté serveur avec la clé courante. Un
 * abonnement créé sous une clé périmée redevenait donc « concordant » aux
 * yeux du serveur, le livreur le servait, le fournisseur répondait 2xx, et
 * le navigateur jetait le message EN SILENCE. La garde de T1740 s'effaçait
 * elle-même.
 *
 * Trois réponses, jamais deux : on ne confond pas « je sais qu'elle
 * diverge » avec « je ne peux pas savoir ».
 * ===================================================================== */

/** Un `ArrayBuffer` rendu dans la forme base64url des clefs VAPID. */
const enBase64Url = (tampon) =>
    btoa(String.fromCharCode(...new Uint8Array(tampon)))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');

/**
 * La clé publique RÉELLE de cet abonnement, en base64url — ou `null` quand
 * le navigateur ne l'expose pas.
 *
 * `options.applicationServerKey` est la seule source cryptographique : elle
 * est figée à la création de l'abonnement et ne change jamais. Le serveur,
 * lui, ne peut que déclarer la clé qu'il SERT aujourd'hui.
 */
export const clePubliqueDe = (abonnement) => {
    const cle = abonnement?.options?.applicationServerKey;

    return cle ? enBase64Url(cle) : null;
};

export const CLE_CONCORDE = 'concorde';
export const CLE_DIVERGE = 'diverge';
export const CLE_INOBSERVABLE = 'inobservable';

/**
 * Cet abonnement a-t-il été créé avec la clé que le serveur sert aujourd'hui ?
 *
 * `CLE_INOBSERVABLE` quand le navigateur n'expose pas sa clé, ou quand le
 * serveur n'en annonce aucune. C'est un état À PART ENTIÈRE, pas un repli
 * sur « concorde » : affirmer une concordance qu'on n'a pas mesurée est
 * exactement ce qui a permis au défaut de T1821 de passer inaperçu.
 *
 * Ce que l'appelant en fait diffère selon le geste :
 *  - une RECONFIRMATION silencieuse ne doit jamais partir sur `diverge` ;
 *  - un geste EXPLICITE peut renouveler l'abonnement ;
 *  - `inobservable` ne justifie ni l'un ni l'autre — on ne casse pas un
 *    abonnement qui fonctionne peut-être très bien.
 */
export const comparerCleVapid = (abonnement, vapid) => {
    const cleAbonnement = clePubliqueDe(abonnement);

    if (cleAbonnement === null || !vapid) {
        return CLE_INOBSERVABLE;
    }

    return cleAbonnement === vapid ? CLE_CONCORDE : CLE_DIVERGE;
};

/**
 * Retirer cet abonnement, côté SERVEUR d'abord, puis côté navigateur.
 *
 * L'ordre n'est pas indifférent, et c'est la doctrine posée en T1739 : si
 * l'on commençait par `unsubscribe()`, un échec réseau laisserait une ligne
 * en base pour un endpoint que le navigateur a déjà oublié — donc un
 * appareil injoignable qu'on croirait abonné.
 *
 * Ne touche QUE cet endpoint : les autres appareils de la personne ne sont
 * jamais concernés (le serveur filtre par `endpoint_hash` ET `user_id`).
 */
export const retirerEtDesabonner = async (url, abonnement) => {
    const reponse = await fetch(url, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': jeton(), Accept: 'application/json' },
        body: JSON.stringify({ endpoint: abonnement.endpoint }),
    });

    // TASK-1822 — l'ORDRE ne suffit pas, il faut encore que le premier temps
    // ait REUSSI.
    //
    // `fetch` ne lève que sur panne réseau : un 403, un 419 (jeton CSRF
    // périmé — le cas le plus probable sur une PWA restée ouverte des jours)
    // ou un 500 reviennent avec `ok === false`, et le code enchaînait quand
    // même sur `unsubscribe()`. Le navigateur oubliait alors un abonnement
    // que le serveur conserve : une ligne vivante en base pour un endpoint
    // que plus personne ne détient — exactement l'état que l'ordre
    // « serveur d'abord » existe pour empêcher, atteint par l'autre bout.
    //
    // On lève plutôt que de se désabonner : l'appelant montre son message
    // d'échec, et l'abonnement reste cohérent des deux côtés. Un geste de
    // plus vaut mieux qu'un appareil injoignable qu'on croit abonné.
    if (!reponse.ok) {
        throw new Error(`push delete ${reponse.status}`);
    }

    await abonnement.unsubscribe();
};

/**
 * Confirmer au serveur que cet appareil est bien à cette personne.
 *
 * C'est la RÉCONCILIATION du CDC 5.4 : `pushsubscriptionchange` n'est pas
 * pris en charge partout, donc on ne peut pas compter sur un évènement.
 * Côté serveur l'opération est idempotente — et c'est elle qui ramène au bon
 * compte un appareil revendiqué par un compte frère.
 */
export const confirmer = async (url, abonnement, libelle = null) => {
    const brut = abonnement.toJSON();
    const reponse = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': jeton(), Accept: 'application/json' },
        body: JSON.stringify({ endpoint: brut.endpoint, keys: brut.keys, device_label: libelle }),
    });

    if (!reponse.ok) throw new Error(`push store ${reponse.status}`);

    return reponse.json();
};

/** Ce que le SERVEUR sait de cet endpoint pour la personne connectée. */
export const etatServeur = async (url, endpoint) => {
    const reponse = await fetch(`${url}?endpoint=${encodeURIComponent(endpoint ?? '')}`, {
        headers: { Accept: 'application/json' },
    });

    return reponse.ok ? reponse.json() : null;
};

/**
 * Abonner cet appareil, de bout en bout.
 *
 * À n'appeler QUE depuis un gestionnaire de clic. Rend l'état serveur en cas
 * de succès, et lève sinon — l'appelant décide quoi montrer.
 */
export const abonner = async (url, vapid) => {
    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        throw new Error(`permission ${permission}`);
    }

    const inscription = await navigator.serviceWorker.ready;
    let existant = await inscription.pushManager.getSubscription();

    // TASK-1822 — RENOUVELLEMENT sur geste explicite.
    //
    // `abonner()` réutilisait l'abonnement existant tel quel, puis le
    // confirmait. Sur un abonnement dont la clé a cessé d'avoir cours,
    // cela ne réparait rien : le navigateur continuait de jeter les
    // messages, et la confirmation re-stampait le serveur comme si tout
    // allait bien.
    //
    // On ne renouvelle QUE sur divergence MESURÉE. `inobservable` ne
    // justifie pas de détruire un abonnement qui fonctionne peut-être.
    if (existant !== null && comparerCleVapid(existant, vapid) === CLE_DIVERGE) {
        await retirerEtDesabonner(url, existant);
        existant = null;
    }

    const abonnement =
        existant ??
        (await inscription.pushManager.subscribe({
            // Obligatoire sur Chrome : un push doit toujours produire une
            // notification visible, jamais un traitement muet.
            userVisibleOnly: true,
            applicationServerKey: clePublique(vapid),
        }));

    return confirmer(url, abonnement, libelleAppareil());
};
