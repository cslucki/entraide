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
    CLE_DIVERGE,
    clePublique,
    clePubliqueDe,
    comparerCleVapid,
    confirmer as confirmerAuServeur,
    empreinteEndpoint,
    estRevoque,
    etatServeur as interrogerServeur,
    jeton,
    libelleAppareil,
    oublierAbonnementRevoque,
    supporte as pushSupporte,
    supporteNavigateur,
    retirerEtDesabonner,
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
    // TASK-1877 — le test Web Push REEL de cet appareil (page dediee).
    const btnTest = bloc.querySelector('[data-push-test]');
    const elTestResultat = bloc.querySelector('[data-push-test-resultat]');
    const urlTest = bloc.dataset.pushTestUrl || '';

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
        // TASK-1877 — sans abonnement actif, il n'y a rien a tester ; et un
        // ancien verdict ne survit pas a un changement d'etat.
        montrer(btnTest, etat === 'on' && urlTest !== '');
        if (etat !== 'on') montrer(elTestResultat, false);

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
     * TASK-1822 — la comparaison elle-même vit désormais dans
     * `push-abonnement.js`, en un seul exemplaire, parce que
     * `push-first-run.js` en avait besoin AUSSI et ne l'avait pas. Cette
     * fonction n'est plus qu'un nom local sur la règle partagée : elle rend
     * `true` UNIQUEMENT sur une divergence mesurée — `inobservable` n'est
     * pas une divergence, on ne traite comme tel que ce qu'on a mesuré.
     */
    const cleDiverge = (abonnement) => comparerCleVapid(abonnement, vapid) === CLE_DIVERGE;

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

        // TASK-1877 — un abonnement RÉVOQUÉ par le fournisseur ne se
        // reconfirme JAMAIS : la reconfirmation ci-dessous le ferait revivre
        // côté serveur (arbitrage MASTER, R1). On tente de l'oublier ; si le
        // navigateur refuse, l'état d'erreur reste affiché, jamais « activé ».
        if (await estRevoque(abonnement)) {
            const oublie = await oublierAbonnementRevoque(abonnement);
            const etat = await etatServeur(null);
            peindre('off', etat?.device_count, oublie ? textes.textTestRevoked : textes.textTestRevokedCleanupFailed);

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
            // TASK-1822 — la séquence « retirer côté serveur PUIS se
            // désabonner » vit dans `push-abonnement.js` : `abonner()` en a
            // besoin exactement de la même, et deux copies d'une règle
            // d'ordre finissent par diverger sur celle qu'on regarde le
            // moins. L'ordre reste celui de T1739, pour la même raison :
            // commencer par `unsubscribe()` laisserait une ligne en base
            // pour un endpoint que le navigateur a déjà oublié.
            if (existant !== null && cleDiverge(existant)) {
                await retirerEtDesabonner(url, existant);
                existant = null;
            }

            // TASK-1877 — un abonnement révoqué ne se réutilise pas : on
            // l'oublie d'abord, et l'échec de cet oubli est un échec.
            if (existant !== null && (await estRevoque(existant))) {
                if (!(await oublierAbonnementRevoque(existant))) {
                    peindre('off', undefined, textes.textTestRevokedCleanupFailed);
                    return;
                }
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
                // L'ordre compte : on retire d'abord côté SERVEUR (T1739).
                // TASK-1822 — même séquence partagée qu'au renouvellement.
                await retirerEtDesabonner(url, abonnement);
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
     * TASK-1877 — test Web Push RÉEL, vers CET appareil seulement.
     *
     * Le navigateur envoie SON endpoint ; le serveur ne s'en sert que pour
     * désigner une ligne qui appartient déjà au compte connecté. Le verdict
     * ne dit jamais « reçue » : un fournisseur qui accepte n'a rien affiché,
     * c'est la personne qui regarde son écran (arbitrage MASTER, R2).
     * ===================================================================== */
    const direTest = (texte, ok) => {
        elTestResultat.textContent = texte;
        elTestResultat.className = `mt-3 text-sm font-medium ${ok ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'}`;
        montrer(elTestResultat, true);
    };

    btnTest?.addEventListener('click', async () => {
        btnTest.disabled = true;
        try {
            const abonnement = await abonnementCourant();

            if (abonnement === null) {
                await rafraichir();
                return;
            }

            const reponse = await fetch(urlTest, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': jeton(), Accept: 'application/json' },
                body: JSON.stringify({ endpoint: abonnement.endpoint }),
            });

            if (reponse.status === 429) {
                direTest(textes.textTestThrottled, false);
                return;
            }

            const corps = await reponse.json().catch(() => ({}));
            // Les détails bruts (code HTTP, motif) n'arrivent du serveur
            // qu'en local, et ne s'affichent qu'en local.
            const detail = debogage && Array.isArray(corps.detail) && corps.detail.length > 0
                ? ` [${corps.detail.join(' · ')}]`
                : '';

            if (corps.resultat === 'envoye') {
                direTest(textes.textTestSent + detail, true);
            } else if (corps.resultat === 'revoque') {
                // R1 — le serveur a déjà retiré sa ligne. Oublier aussi
                // l'abonnement côté navigateur AVANT de repeindre, sinon la
                // réconciliation le reconfirmerait aussitôt.
                const oublie = await oublierAbonnementRevoque(abonnement);
                const etat = await etatServeur(null);
                peindre('off', etat?.device_count, oublie ? textes.textTestRevoked : textes.textTestRevokedCleanupFailed);
            } else if (corps.resultat === 'inconnu') {
                direTest(textes.textTestUnknown, false);
            } else {
                direTest(textes.textTestFailed + detail, false);
            }
        } catch (e) {
            direTest(avecCause(textes.textTestFailed, e), false);
        } finally {
            btnTest.disabled = false;
        }
    });

    /* =====================================================================
     * TASK-1740 — DIAGNOSTIC. TASK-1877 — publié pour chaque membre (option
     * B de MASTER), replié, traduit. Les textes viennent du serveur ; les
     * détails bruts d'erreur ne s'affichent qu'en local (`debogage`).
     * ===================================================================== */
    const panneau = bloc.querySelector('[data-push-diag-panel]');

    if (panneau) {
        const cases = {};
        panneau.querySelectorAll('[data-push-diag-value]').forEach((el) => {
            cases[el.dataset.pushDiagValue] = el;
        });

        const elResultat = panneau.querySelector('[data-push-diag-resultat]');
        const t = panneau.querySelector('[data-push-diag-texts]').dataset;
        // `%s` remplacé dans l'ordre, comme le serveur l'a écrit.
        const remplir = (gabarit, ...valeurs) => valeurs.reduce((texte, v) => texte.replace('%s', v), gabarit);
        // Un détail brut n'est ajouté qu'en local.
        const brut = (texte, detail) => (debogage && detail ? `${texte} [${detail}]` : texte);

        const dire = (texte, ok) => {
            elResultat.textContent = texte;
            elResultat.className = `mt-2 text-[11px] font-semibold ${ok ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'}`;
            elResultat.hidden = false;
        };

        /** L'empreinte que le SERVEUR calcule : SHA-256 de l'endpoint, 8 car. */
        const empreinteDe = async (endpoint) => (await empreinteEndpoint(endpoint)).slice(0, 8);

        const diagnostiquer = async () => {
            cases.permission.textContent = 'Notification' in window ? Notification.permission : t.apiAbsent;
            cases.origine.textContent = location.origin;
            // Sans contexte sûr, ni Service Worker ni Push n'existent. Un
            // tunnel en HTTPS le fournit ; une IP en clair, non.
            cases.securise.textContent = window.isSecureContext ? t.yes : t.no;

            if (!('serviceWorker' in navigator)) {
                cases.scope.textContent = cases.version.textContent = t.apiAbsent;
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

            cases.scope.textContent = reponse?.scope ?? t.noAnswer;
            cases.version.textContent = reponse
                ? `${reponse.version}${reponse.push ? ` · ${t.pushSupported}` : ''}`
                : t.swOld;

            const abonnement = await abonnementCourant();

            if (abonnement === null) {
                cases.abonnement.textContent = t.none;
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
            cases.base.textContent = etat === null ? t.serverSilent : etat.subscribed ? t.yes : t.no;

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
            // TASK-1822 — la lecture de la clé réelle passe par la fonction
            // partagée : ce panneau en tenait une TROISIÈME copie, et un
            // diagnostic qui calculerait autrement que la garde qu'il est
            // censé expliquer ne vaudrait rien.
            const cleAbonnement = clePubliqueDe(abonnement);

            if (cleAbonnement === null) {
                cases.cle.textContent = t.keyHidden;

                return;
            }

            cases.cle.textContent = cleAbonnement === vapid
                ? remplir(t.keyMatch, cleAbonnement.slice(0, 8))
                : remplir(t.keyDiverge, cleAbonnement.slice(0, 8), vapid.slice(0, 8));
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
                    dire(brut(t.permissionRefused, permission), false);
                    return;
                }

                const inscription = await navigator.serviceWorker.ready;
                await inscription.showNotification(t.localTitle, {
                    body: t.localBody,
                    icon: '/brand/icon-192.png',
                    tag: 'bouclepro-diag-local',
                    renotify: true,
                });
                dire(t.localDone, true);
            } catch (e) {
                dire(brut(t.localFailed, e?.message), false);
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
                dire(t.simulateNeedsPermission, false);
                return;
            }

            const reponse = await interrogerServiceWorker({
                type: 'bouclepro:simuler-push',
                charge: {
                    title: t.simulateTitle,
                    body: t.simulateBody,
                    url: '/notifications',
                    tag: 'bouclepro-diag-sw',
                },
            }, 4000);

            if (reponse === null) {
                dire(t.simulateOld, false);
            } else if (reponse.ok) {
                dire(t.simulateDone, true);
            } else {
                dire(brut(t.simulateRefused, reponse.erreur), false);
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
