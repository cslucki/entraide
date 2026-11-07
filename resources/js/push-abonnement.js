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
    const abonnement =
        (await inscription.pushManager.getSubscription()) ??
        (await inscription.pushManager.subscribe({
            // Obligatoire sur Chrome : un push doit toujours produire une
            // notification visible, jamais un traitement muet.
            userVisibleOnly: true,
            applicationServerKey: clePublique(vapid),
        }));

    return confirmer(url, abonnement, libelleAppareil());
};
