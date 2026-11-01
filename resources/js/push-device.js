/**
 * TASK-1739 — « Notifications sur cet appareil », côté navigateur.
 *
 * ## Ce que ce script ne fait jamais
 *
 * Il ne demande PAS la permission au chargement. `requestPermission()`
 * n'apparaît que dans le gestionnaire du bouton (CDC 5.3) : une demande
 * spontanée se fait refuser par réflexe, et un refus ne se rattrape pas —
 * il faut alors passer par les réglages du navigateur.
 *
 * Il ne fabrique aucun texte : tous les libellés sont traduits côté serveur
 * et lus dans des attributs de données.
 *
 * Il ne touche PAS au Service Worker au-delà de `pushManager` : aucun
 * écouteur `push`, aucune écriture dans le Cache Storage. Le durcissement
 * T1692 est donc intact — et il le restera, parce que recevoir une
 * notification appartient au chantier d'envoi.
 *
 * ## L'état affiché vient de DEUX moitiés
 *
 * Le navigateur sait s'il a la permission et s'il détient un abonnement. Le
 * serveur sait si CET endpoint lui est connu pour CETTE personne. Une seule
 * moitié ne suffit pas : une permission accordée sans ligne en base n'est pas
 * une activation, et une ligne en base que le navigateur a oubliée n'en est
 * plus une.
 */

const bloc = document.querySelector('[data-push-device]');

if (bloc) {
    const textes = bloc.querySelector('[data-push-texts]').dataset;
    const url = bloc.dataset.pushUrl;
    const vapid = bloc.dataset.pushVapid || '';
    const actif = bloc.dataset.pushEnabled === '1';

    const elEtat = bloc.querySelector('[data-push-state]');
    const elPoint = bloc.querySelector('[data-push-dot]');
    const elNombre = bloc.querySelector('[data-push-count]');
    const elAide = bloc.querySelector('[data-push-help]');
    const btnActiver = bloc.querySelector('[data-push-enable]');
    const btnDesactiver = bloc.querySelector('[data-push-disable]');

    const jeton = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    /** La clé VAPID doit partir en Uint8Array, pas en base64url. */
    const clePublique = (base64url) => {
        const bourrage = '='.repeat((4 - (base64url.length % 4)) % 4);
        const base64 = (base64url + bourrage).replace(/-/g, '+').replace(/_/g, '/');
        const brut = atob(base64);
        return Uint8Array.from([...brut].map((c) => c.charCodeAt(0)));
    };

    const supporte = () =>
        actif && vapid !== '' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

    const peindre = (etat, nombre, aide) => {
        const libelles = {
            on: textes.textOn,
            off: textes.textOff,
            denied: textes.textDenied,
            unsupported: textes.textUnsupported,
        };
        const couleurs = {
            on: 'bg-emerald-500',
            off: 'bg-gray-400',
            denied: 'bg-amber-500',
            unsupported: 'bg-gray-300',
        };

        elEtat.textContent = libelles[etat];
        elPoint.className = `inline-block h-2.5 w-2.5 shrink-0 rounded-full ${couleurs[etat]}`;

        btnActiver.hidden = etat !== 'off';
        btnDesactiver.hidden = etat !== 'on';

        // Le nombre d'appareils n'a de sens que si le serveur en a parlé.
        if (typeof nombre === 'number') {
            elNombre.textContent =
                nombre === 0 ? textes.countZero : nombre === 1 ? textes.countOne : textes.countMany.replace('%d', nombre);
        }

        // Un message d'aide n'apparaît que s'il y a quelque chose à faire.
        const texteAide =
            aide ?? (etat === 'denied' ? textes.textDeniedHelp : etat === 'unsupported' ? textes.textUnsupportedHelp : null);
        elAide.hidden = texteAide === null;
        if (texteAide !== null) elAide.textContent = texteAide;
    };

    const abonnementCourant = async () => {
        const inscription = await navigator.serviceWorker.ready;
        return inscription.pushManager.getSubscription();
    };

    /**
     * Confirmer au serveur que cet appareil est bien à cette personne.
     *
     * C'est la RÉCONCILIATION du CDC 5.4, et elle tourne à chaque affichage
     * de cette page : `pushsubscriptionchange` n'est pas pris en charge
     * partout, donc on ne peut pas compter sur un événement. Côté serveur
     * l'opération est idempotente — et c'est elle qui ramène au bon compte un
     * appareil revendiqué par un compte frère.
     */
    const confirmer = async (abonnement, libelle = null) => {
        const brut = abonnement.toJSON();
        const reponse = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': jeton(), Accept: 'application/json' },
            body: JSON.stringify({ endpoint: brut.endpoint, keys: brut.keys, device_label: libelle }),
        });
        if (!reponse.ok) throw new Error(`push store ${reponse.status}`);
        return reponse.json();
    };

    const etatServeur = async (endpoint) => {
        const reponse = await fetch(`${url}?endpoint=${encodeURIComponent(endpoint ?? '')}`, {
            headers: { Accept: 'application/json' },
        });
        return reponse.ok ? reponse.json() : null;
    };

    /** L'état réel, en composant les deux moitiés. */
    const rafraichir = async () => {
        if (!supporte()) {
            peindre('unsupported');
            return;
        }

        if (Notification.permission === 'denied') {
            peindre('denied');
            return;
        }

        const abonnement = await abonnementCourant();

        if (abonnement === null) {
            const etat = await etatServeur(null);
            peindre('off', etat?.device_count);
            return;
        }

        // Le navigateur détient un abonnement : on le reconfirme, ce qui
        // répond du même coup à « est-il à MOI ? ».
        try {
            const etat = await confirmer(abonnement);
            peindre('on', etat.device_count, textes.textNotYet);
        } catch {
            const etat = await etatServeur(abonnement.endpoint);
            peindre(etat?.subscribed ? 'on' : 'off', etat?.device_count);
        }
    };

    // Un libellé GROSSIER, pour que la personne reconnaisse son appareil.
    // Jamais un UA complet, jamais une signature (CDC 5.4).
    const libelleAppareil = () => {
        const ua = navigator.userAgent;
        if (/Android/i.test(ua)) return 'Android';
        if (/iPhone/i.test(ua)) return 'iPhone';
        if (/iPad/i.test(ua)) return 'iPad';
        if (/Macintosh/i.test(ua)) return 'Mac';
        if (/Windows/i.test(ua)) return 'Windows';
        return null;
    };

    btnActiver.addEventListener('click', async () => {
        btnActiver.disabled = true;
        try {
            // ICI, et nulle part ailleurs : la permission se demande sur un
            // geste explicite.
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                peindre(permission === 'denied' ? 'denied' : 'off');
                return;
            }

            const inscription = await navigator.serviceWorker.ready;
            const abonnement =
                (await inscription.pushManager.getSubscription()) ??
                (await inscription.pushManager.subscribe({
                    // Obligatoire sur Chrome : un push doit toujours produire
                    // une notification visible, jamais un traitement muet.
                    userVisibleOnly: true,
                    applicationServerKey: clePublique(vapid),
                }));

            const etat = await confirmer(abonnement, libelleAppareil());
            peindre('on', etat.device_count, textes.textNotYet);
        } catch {
            peindre('off', undefined, textes.textEnableFailed);
        } finally {
            btnActiver.disabled = false;
        }
    });

    btnDesactiver.addEventListener('click', async () => {
        btnDesactiver.disabled = true;
        try {
            const abonnement = await abonnementCourant();

            if (abonnement !== null) {
                // L'ordre compte : on retire d'abord côté SERVEUR. Si l'on
                // commençait par `unsubscribe()`, un échec réseau laisserait
                // une ligne en base pour un endpoint que le navigateur a déjà
                // oublié — donc un appareil injoignable qu'on croirait abonné.
                await fetch(url, {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': jeton(), Accept: 'application/json' },
                    body: JSON.stringify({ endpoint: abonnement.endpoint }),
                });
                await abonnement.unsubscribe();
            }

            const etat = await etatServeur(null);
            peindre('off', etat?.device_count);
        } catch {
            peindre('on', undefined, textes.textDisableFailed);
        } finally {
            btnDesactiver.disabled = false;
        }
    });

    rafraichir();

    // La réouverture de l'application installée ne recharge pas forcément la
    // page : `visibilitychange` est le seul signal fiable pour reconcilier au
    // retour (CDC 5.4).
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') rafraichir();
    });
}
