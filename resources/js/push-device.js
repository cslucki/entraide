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

import {
    abonnementCourant,
    clePublique,
    confirmer as confirmerAuServeur,
    etatServeur as interrogerServeur,
    jeton,
    libelleAppareil,
    supporte as pushSupporte,
    supporteNavigateur,
} from './push-abonnement.js';

const bloc = document.querySelector('[data-push-device]');

if (bloc) {
    const textes = bloc.querySelector('[data-push-texts]').dataset;
    const url = bloc.dataset.pushUrl;
    const vapid = bloc.dataset.pushVapid || '';
    const actif = bloc.dataset.pushEnabled === '1';

    const elEtat = bloc.querySelector('[data-push-state]');
    const elPoint = bloc.querySelector('[data-push-dot]');
    const elNombre = bloc.querySelector('[data-push-count]');
    const elInfoAppareil = bloc.querySelector('[data-push-device-info]');
    const elAide = bloc.querySelector('[data-push-help-box]');
    const btnActiver = bloc.querySelector('[data-push-enable]');
    const btnDesactiver = bloc.querySelector('[data-push-disable]');

    // TASK-1745 — la mecanique vit desormais dans `push-abonnement.js`,
    // partagee avec la proposition du premier lancement. Ce n'est pas un
    // nouveau moteur : c'est le code de T1739, avec deux appelants au lieu
    // d'un. Le recopier aurait cree une seconde verite sur du code de
    // securite — conversion de clef VAPID, `userVisibleOnly`, envoi de
    // l'endpoint — et deux copies divergent toujours.
    const supporte = () => pushSupporte(actif, vapid);

    /**
     * TASK-1744 — en recette LOCALE, dire POURQUOI ça a échoué.
     *
     * Les deux gestionnaires avalaient l'erreur dans un `catch` vide et
     * n'affichaient que « L'activation n'a pas abouti. Réessayez dans un
     * instant. » Sur un téléphone, c'est une impasse : on réessaie, ça
     * échoue pareil, et rien ne dit si c'est la permission, le Service
     * Worker, la clé VAPID ou le réseau.
     *
     * Mesure du 02/11 : un réabonnement a échoué trois fois de suite sans
     * laisser la moindre trace exploitable.
     *
     * La garde est la MÊME que celle du panneau de diagnostic — rendue côté
     * serveur, donc l'attribut n'existe simplement pas ailleurs qu'en local.
     * Un message d'erreur technique n'a rien à faire devant un membre.
     */
    const debogage = bloc.dataset.pushDebug === '1';

    const avecCause = (message, erreur) =>
        debogage && erreur ? `${message} [${erreur.name ?? 'Error'}: ${erreur.message ?? erreur}]` : message;

    // TASK-1744 — masquer POUR DE BON. `hidden` ne suffit pas quand une
    // classe utilitaire declare un `display` : il faut une declaration
    // d'auteur de meme niveau. On garde `hidden` pour l'accessibilite et les
    // tests, et on y ajoute le style en ligne qui, lui, s'applique.
    const montrer = (el, visible) => {
        if (!el) return;
        el.hidden = !visible;
        el.style.display = visible ? '' : 'none';
    };

    const peindre = (etat, nombre, aide, appareil) => {
        const libelles = {
            on: textes.textOn,
            off: textes.textOff,
            denied: textes.textDenied,
            unsupported: textes.textUnsupported,
            unavailable: textes.textUnavailable,
        };
        const couleurs = {
            on: 'bg-emerald-500',
            off: 'bg-gray-400',
            denied: 'bg-amber-500',
            unsupported: 'bg-gray-300',
            unavailable: 'bg-gray-300',
        };

        elEtat.textContent = libelles[etat];
        elPoint.className = `inline-block h-2.5 w-2.5 shrink-0 rounded-full ${couleurs[etat]}`;

        // TASK-1744 — `hidden` seul ne masque RIEN ici : la classe
        // `inline-flex` de Tailwind pose un `display` d'AUTEUR, qui gagne
        // contre le `[hidden] { display: none }` du navigateur. Mesuré : les
        // deux boutons restaient visibles dans tous les états. On pose donc
        // aussi la declaration d'auteur, et on la leve de meme.
        montrer(btnActiver, etat === 'off');
        montrer(btnDesactiver, etat === 'on');

        // Le nombre d'appareils n'a de sens que si le serveur en a parlé.
        if (typeof nombre === 'number') {
            elNombre.textContent =
                nombre === 0 ? textes.countZero : nombre === 1 ? textes.countOne : textes.countMany.replace('%d', nombre);
        }

        // TASK-1821 — l'info d'appareil n'arrive du serveur qu'en local, et
        // seulement pour l'endpoint de CE navigateur (jamais une ligne
        // choisie au hasard). Absente ailleurs : l'élément reste masqué.
        if (elInfoAppareil) {
            if (appareil?.fingerprint) {
                const gabarit = appareil.label ? textes.textDeviceRegistered : textes.textDeviceRegisteredUnlabeled;
                elInfoAppareil.textContent = gabarit
                    .replace('%label%', appareil.label ?? '')
                    .replace('%fingerprint%', appareil.fingerprint);
                montrer(elInfoAppareil, true);
            } else {
                montrer(elInfoAppareil, false);
            }
        }

        // Un message d'aide n'apparaît que s'il y a quelque chose à faire.
        const texteAide =
            aide ??
            (etat === 'denied'
                ? textes.textDeniedHelp
                : etat === 'unsupported'
                  ? textes.textUnsupportedHelp
                  : etat === 'unavailable'
                    ? textes.textUnavailableHelp
                    : null);
        montrer(elAide, texteAide !== null);
        if (texteAide !== null) elAide.querySelector('[data-push-help]').textContent = texteAide;
    };

    /** Un `ArrayBuffer` rendu dans la forme base64url des clefs VAPID. */
    const enBase64Url = (tampon) =>
        btoa(String.fromCharCode(...new Uint8Array(tampon)))
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=+$/, '');

    /**
     * Cet abonnement a-t-il été créé avec une AUTRE clé que celle du serveur ?
     *
     * TASK-1740 — un abonnement porte À VIE la clé publique avec laquelle le
     * navigateur l'a créé, et c'est elle que le navigateur exigera dans notre
     * signature. Si la clé du serveur change, l'abonnement SURVIT intact :
     * `getSubscription()` le rend toujours, le serveur le croit valide, le
     * fournisseur accepte l'envoi et répond `201`… et le navigateur jette le
     * message EN SILENCE.
     *
     * C'est la forme exacte de la panne qui a coûté la recette du 01/11, sous
     * une autre cause. Elle ne doit pas pouvoir se reproduire sans être vue.
     *
     * Un navigateur qui n'expose pas `options.applicationServerKey` ne permet
     * pas de conclure : on rend alors `false`, parce qu'on ne traite comme
     * divergent que ce qu'on a MESURÉ divergent.
     */
    const cleDiverge = (abonnement) => {
        const cle = abonnement?.options?.applicationServerKey;

        return !!cle && vapid !== '' && enBase64Url(cle) !== vapid;
    };

    /**
     * Interroger l'exemplaire du Service Worker RÉELLEMENT actif.
     *
     * TASK-1740 — c'est la question qui restait sans réponse quand toute la
     * chaîne serveur avait été prouvée : abonnement éligible, requête sortie,
     * FCM qui accepte, charge déchiffrable — et pourtant rien ne s'affiche.
     *
     * Un `/sw.js` téléchargeable à jour ne dit RIEN de l'exemplaire qui
     * tourne : un Service Worker enregistré avant l'ajout de l'écouteur
     * `push` reçoit le message et n'en fait rien, silencieusement. Seul
     * l'exemplaire actif peut répondre, et c'est pour cela qu'on le lui
     * demande par un canal de message plutôt que de le déduire.
     *
     * L'absence de réponse EST une réponse : un exemplaire ancien ne connaît
     * pas ce canal et laisse le délai expirer.
     */
    const interrogerServiceWorker = async (message, delai = 2000) => {
        const inscription = await navigator.serviceWorker.ready;
        const exemplaire = inscription.active;

        if (!exemplaire) return null;

        return new Promise((resoudre) => {
            const canal = new MessageChannel();
            const minuteur = setTimeout(() => resoudre(null), delai);

            canal.port1.onmessage = (e) => {
                clearTimeout(minuteur);
                resoudre(e.data ?? null);
            };

            exemplaire.postMessage(message, [canal.port2]);
        });
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
    const confirmer = (abonnement, libelle = null) => confirmerAuServeur(url, abonnement, libelle);

    const etatServeur = (endpoint) => interrogerServeur(url, endpoint);

    /** L'état réel, en composant les deux moitiés. */
    const rafraichir = async () => {
        // TASK-1768 — deux causes, deux messages. `supporte()` compose la
        // capacite du navigateur ET la disponibilite cote serveur ; quand elle
        // est fausse, on redemande au navigateur SEUL de quoi il est capable
        // plutot que de lui imputer la panne. On appelle la MEME fonction que
        // `supporte()` emploie, pour qu'aucune des deux ne puisse diverger.
        if (!supporte()) {
            peindre(supporteNavigateur() ? 'unavailable' : 'unsupported');
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

        // Un abonnement créé avec une clé qui n'a plus cours n'est PAS un
        // abonnement valide. On s'abstient surtout de le reconfirmer : le
        // reconfirmer rafraîchirait son `last_seen_at` et le ferait passer,
        // côté serveur, pour un appareil joignable qu'il n'est plus.
        //
        // On ne le supprime pas non plus de son propre chef. Le renouvellement
        // appartient à la personne : le bouton « Activer » réapparaît, et c'est
        // son clic qui le déclenchera.
        if (cleDiverge(abonnement)) {
            const etat = await etatServeur(abonnement.endpoint);
            peindre('off', etat?.device_count, textes.textKeyStale);

            return;
        }

        // Le navigateur détient un abonnement : on le reconfirme, ce qui
        // répond du même coup à « est-il à MOI ? ».
        try {
            const etat = await confirmer(abonnement);
            peindre('on', etat.device_count, textes.textReady, { label: etat.device_label, fingerprint: etat.fingerprint });
        } catch {
            const etat = await etatServeur(abonnement.endpoint);
            peindre(etat?.subscribed ? 'on' : 'off', etat?.device_count, undefined, {
                label: etat?.device_label,
                fingerprint: etat?.fingerprint,
            });
        }
    };

    // Un libellé GROSSIER, pour que la personne reconnaisse son appareil.
    // Jamais un UA complet, jamais une signature (CDC 5.4).
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
            let existant = await inscription.pushManager.getSubscription();

            // Renouvellement sur GESTE EXPLICITE, jamais en arrière-plan.
            //
            // L'ordre compte, et c'est le même qu'à la désactivation : on
            // retire d'abord côté SERVEUR. Si l'on commençait par
            // `unsubscribe()`, un échec réseau laisserait une ligne en base
            // pour un endpoint que le navigateur a déjà oublié — donc un
            // appareil injoignable qu'on croirait abonné.
            if (existant !== null && cleDiverge(existant)) {
                await fetch(url, {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': jeton(), Accept: 'application/json' },
                    body: JSON.stringify({ endpoint: existant.endpoint }),
                });
                await existant.unsubscribe();
                existant = null;
            }

            const abonnement =
                existant ??
                (await inscription.pushManager.subscribe({
                    // Obligatoire sur Chrome : un push doit toujours produire
                    // une notification visible, jamais un traitement muet.
                    userVisibleOnly: true,
                    applicationServerKey: clePublique(vapid),
                }));

            const etat = await confirmer(abonnement, libelleAppareil());
            peindre('on', etat.device_count, textes.textReady);
        } catch (e) {
            peindre('off', undefined, avecCause(textes.textEnableFailed, e));
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
        } catch (e) {
            peindre('on', undefined, avecCause(textes.textDisableFailed, e));
        } finally {
            btnDesactiver.disabled = false;
        }
    });

    /* =====================================================================
     * TASK-1740 — DIAGNOSTIC, temporaire et local seulement.
     *
     * Le panneau n'est rendu qu'en local : s'il est absent, tout ce qui suit
     * ne s'exécute pas. La garde est donc côté serveur, et il n'y a rien à
     * désactiver ici.
     * ===================================================================== */
    const panneau = bloc.querySelector('[data-push-diag-panel]');

    if (panneau) {
        const cases = {};
        panneau.querySelectorAll('[data-push-diag-value]').forEach((el) => {
            cases[el.dataset.pushDiagValue] = el;
        });

        const elResultat = panneau.querySelector('[data-push-diag-resultat]');

        const dire = (texte, ok) => {
            elResultat.textContent = texte;
            elResultat.className = `mt-2 text-[11px] font-semibold ${ok ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'}`;
            elResultat.hidden = false;
        };

        /** L'empreinte que le SERVEUR calcule : SHA-256 de l'endpoint, 8 car. */
        const empreinteDe = async (endpoint) => {
            const octets = new TextEncoder().encode(endpoint);
            const condensat = await crypto.subtle.digest('SHA-256', octets);
            return [...new Uint8Array(condensat)]
                .map((o) => o.toString(16).padStart(2, '0'))
                .join('')
                .slice(0, 8);
        };

        const diagnostiquer = async () => {
            cases.permission.textContent = 'Notification' in window ? Notification.permission : 'API absente';
            cases.origine.textContent = location.origin;
            // Sans contexte sûr, ni Service Worker ni Push n'existent. Un
            // tunnel en HTTPS le fournit ; une IP en clair, non.
            cases.securise.textContent = window.isSecureContext ? 'oui' : 'NON';

            if (!('serviceWorker' in navigator)) {
                cases.scope.textContent = cases.version.textContent = 'API absente';
                return;
            }

            // Forcer une vérification de mise à jour : sans elle, le
            // navigateur ne la fait qu'à ses propres moments, et un exemplaire
            // ancien peut rester actif longtemps.
            try {
                const inscription = await navigator.serviceWorker.getRegistration();
                if (inscription) await inscription.update();
            } catch { /* sans gravité : on rend ce qu'on trouve */ }

            const reponse = await interrogerServiceWorker({ type: 'bouclepro:version' });

            cases.scope.textContent = reponse?.scope ?? '(pas de réponse)';
            cases.version.textContent = reponse
                ? `${reponse.version}${reponse.push ? ' · push pris en charge' : ''}`
                : 'ANCIEN — ne répond pas au canal de diagnostic';

            const abonnement = await abonnementCourant();

            if (abonnement === null) {
                cases.abonnement.textContent = 'aucun';
                cases.empreinte.textContent = '—';
                cases.base.textContent = '—';
                return;
            }

            const hote = new URL(abonnement.endpoint).host;
            cases.abonnement.textContent = hote;
            cases.empreinte.textContent = await empreinteDe(abonnement.endpoint);

            // La correspondance : le serveur connaît-il CET endpoint pour
            // CETTE personne ? Une permission accordée et un abonnement
            // présent ne valent rien si la base pointe un autre appareil.
            const etat = await etatServeur(abonnement.endpoint);
            cases.base.textContent = etat === null ? '(serveur muet)' : etat.subscribed ? 'OUI' : 'NON';

            /* Le dernier maillon jamais mesuré.
             *
             * Un abonnement porte la clé VAPID avec laquelle il a été CRÉÉ, et
             * c'est elle que le navigateur exigera dans la signature. Si la
             * clé du serveur a changé depuis, l'abonnement survit tel quel —
             * `getSubscription()` le rend sans jamais comparer sa clé.
             *
             * Sur un endpoint `/fcm/send/`, FCM ne vérifie pas cette
             * signature : il répond 201 et met en file. C'est Chrome, sur
             * l'appareil, qui jette alors le message EN SILENCE. D'où le
             * symptôme exact qu'on observe — accepté, jamais affiché. */
            const cleAbonnement = abonnement.options?.applicationServerKey;

            if (!cleAbonnement) {
                cases.cle.textContent = '(non exposée par ce navigateur)';
                return;
            }

            const enBase64Url = btoa(String.fromCharCode(...new Uint8Array(cleAbonnement)))
                .replace(/\+/g, '-')
                .replace(/\//g, '_')
                .replace(/=+$/, '');

            cases.cle.textContent = enBase64Url === vapid
                ? `concorde (${enBase64Url.slice(0, 8)}…)`
                : `DIVERGE — abonnement ${enBase64Url.slice(0, 8)}… ≠ serveur ${vapid.slice(0, 8)}…`;
        };

        /**
         * Essai 1 — l'affichage, sans réseau, sans FCM, sans chiffrement.
         *
         * Si celui-ci échoue, aucun envoi distant ne pouvait aboutir, et la
         * cause est locale : permission, ou notifications coupées au niveau
         * du système.
         */
        panneau.querySelector('[data-push-diag-local]').addEventListener('click', async () => {
            try {
                // Geste explicite : la permission peut donc se demander ici.
                const permission = await Notification.requestPermission();

                if (permission !== 'granted') {
                    dire(`Permission refusée (${permission}) — rien ne peut s'afficher.`, false);
                    return;
                }

                const inscription = await navigator.serviceWorker.ready;
                await inscription.showNotification('Affichage local', {
                    body: 'Si vous voyez ceci, l’appareil sait afficher une notification.',
                    icon: '/brand/icon-192.png',
                    tag: 'bouclepro-diag-local',
                    renotify: true,
                });
                dire('Demandé. Visible ? → l’affichage système fonctionne.', true);
            } catch (e) {
                dire(`Échec de l’affichage local : ${e.message}`, false);
            } finally {
                diagnostiquer();
            }
        });

        /**
         * Essai 2 — le même chemin d'affichage que le push réel, emprunté par
         * l'exemplaire ACTIF du Service Worker, toujours sans réseau.
         *
         * S'il réussit alors que l'envoi distant échoue, notre code est hors
         * de cause et la panne est dans la LIVRAISON.
         */
        panneau.querySelector('[data-push-diag-simuler]').addEventListener('click', async () => {
            if (Notification.permission !== 'granted') {
                dire('Permission non accordée : commencez par « Tester l’affichage local ».', false);
                return;
            }

            const reponse = await interrogerServiceWorker({
                type: 'bouclepro:simuler-push',
                charge: {
                    title: 'Charge simulée',
                    body: 'Traitée par le Service Worker actif, sans réseau.',
                    url: '/notifications',
                    tag: 'bouclepro-diag-sw',
                },
            }, 4000);

            if (reponse === null) {
                dire('Le Service Worker actif ne connaît pas ce canal : exemplaire ANCIEN.', false);
            } else if (reponse.ok) {
                dire('Le Service Worker actif a affiché la charge. Chemin de réception intact.', true);
            } else {
                dire(`Le Service Worker a refusé : ${reponse.erreur}`, false);
            }
        });

        diagnostiquer();
    }

    rafraichir();

    // La réouverture de l'application installée ne recharge pas forcément la
    // page : `visibilitychange` est le seul signal fiable pour reconcilier au
    // retour (CDC 5.4).
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') rafraichir();
    });
}
