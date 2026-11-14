/**
 * TASK-1745 — « Activer les notifications », au premier lancement installé.
 *
 * ## Ce que la recette du 03/11 a appris à ce fichier
 *
 * La première version ne tournait sur AUCUNE page : son `@vite` passait par
 * `@push('scripts')`, et le composant est inclus APRÈS le `@stack` du layout
 * — la pile était déjà rendue. Le défaut est corrigé dans le gabarit, et
 * l'émission du script est désormais GARDÉE par un test. Conséquence pour ce
 * fichier : chaque décision écrit sa cause dans
 * `data-push-first-run-etat`, pour qu'un silence ne soit plus jamais
 * indiagnosticable depuis un téléphone.
 *
 * ## Les règles, dans l'ordre où elles se lisent
 *
 * 1. Le bloc n'existe que rendu côté serveur : capacité déclarée au
 *    catalogue, personne connectée. Rien à vérifier ici.
 * 2. Il ne parle qu'à l'application LANCÉE INSTALLÉE
 *    (`display-mode: standalone`).
 * 3. « Plus tard » est un REPORT de sept jours, jamais un refus éternel :
 *    la clé porte une échéance. (La v1 posait un `1` définitif — qui
 *    survivait même à une réinstallation, Android ne purgeant pas le
 *    stockage du site en désinstallant une WebAPK.)
 * 4. Un abonnement que le navigateur détient mais que le serveur ignore se
 *    RESYNCHRONISE en silence quand la permission est `granted` — c'est la
 *    réconciliation T1739, aucun prompt, aucun écran. C'est le cas d'un
 *    appareil « oublié » côté serveur, ou d'une réinstallation.
 * 5. La permission système n'est demandée QUE dans le gestionnaire de clic
 *    (CDC 5.3). `denied` n'offre aucun bouton d'activation : il EXPLIQUE la
 *    réactivation (CDC 3.3 — pas de bouton factice).
 * 6. L'état de permission n'est pas figé : Chrome Android 155+ permet de
 *    l'accorder après coup depuis les contrôles du site. On OBSERVE
 *    `navigator.permissions` et on redécide au changement.
 *
 * La mécanique d'abonnement est celle de T1739, importée et non recopiée —
 * voir `push-abonnement.js`.
 */

import {
    abonnementCourant,
    abonner,
    CLE_DIVERGE,
    comparerCleVapid,
    confirmer,
    etatServeur,
    libelleAppareil,
    supporte,
} from './push-abonnement.js';

const bloc = document.querySelector('[data-push-first-run]');

if (bloc) {
    const url = bloc.dataset.pushUrl;
    const vapid = bloc.dataset.pushVapid || '';

    /**
     * Le report vit dans `localStorage`, pas dans un cookie : c'est un état
     * d'APPAREIL, comme l'abonnement — refuser sur sa tablette ne décide pas
     * pour son téléphone, et il n'a aucune raison de voyager en HTTP.
     *
     * La valeur est une ÉCHÉANCE (epoch ms) : silence jusqu'à cette date,
     * puis la proposition redevient possible.
     */
    const CLEF_REPORT = 'bpPushFirstRunSnoozedUntil';

    /** La clé de la v1 — un `1` éternel. On la retire au passage. */
    const CLEF_V1 = 'bpPushFirstRunDismissed';

    const SEPT_JOURS = 7 * 24 * 60 * 60 * 1000;

    const enReport = () => {
        try {
            window.localStorage.removeItem(CLEF_V1);

            const jusqua = Number(window.localStorage.getItem(CLEF_REPORT));

            return Number.isFinite(jusqua) && jusqua > Date.now();
        } catch (e) {
            // Navigation privée, stockage bloqué : on ne saurait pas retenir
            // un refus, donc on ne propose pas. Insister serait pire.
            return true;
        }
    };

    const reporter = () => {
        try {
            window.localStorage.setItem(CLEF_REPORT, String(Date.now() + SEPT_JOURS));
        } catch (e) {}
    };

    const montrer = (el, visible) => {
        if (!el) return;
        el.hidden = !visible;
        el.style.display = visible ? '' : 'none';
    };

    /**
     * Chaque décision écrit sa cause sur le bloc. C'est le diagnostic qui a
     * manqué le 03/11 : « rien ne s'affiche » ne disait pas POURQUOI, et le
     * pourquoi n'était lisible nulle part depuis l'appareil.
     *
     * La cause est AUSSI recopiée dans la ligne « Premier lancement » du
     * panneau de diagnostic des réglages (local uniquement) : un attribut
     * de données ne se lit pas depuis un téléphone, une ligne à l'écran si.
     */
    const dire = (cause) => {
        bloc.dataset.pushFirstRunEtat = cause;

        const ligne = document.querySelector('[data-push-diag-value="premier-lancement"]');

        if (ligne) ligne.textContent = cause;

        return cause;
    };

    /** L'application est-elle lancée INSTALLÉE ? */
    const estInstallee = () => {
        try {
            if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) {
                return true;
            }
        } catch (e) {}

        // Ceinture iOS ancien, où `display-mode` n'était pas lu.
        return window.navigator.standalone === true;
    };

    const accepterBtn = bloc.querySelector('[data-push-first-run-accept]');
    const plusTardBtn = bloc.querySelector('[data-push-first-run-later]');
    const corps = bloc.querySelector('[data-push-first-run-body]');
    const aide = bloc.querySelector('[data-push-first-run-help]');

    // L'état rendu par le serveur, à restaurer quand on redécide : la
    // permission peut changer sous nos pieds (règle 6).
    const corpsParDefaut = corps ? corps.textContent : '';
    const plusTardParDefaut = plusTardBtn ? plusTardBtn.textContent : '';
    const accepterParDefaut = accepterBtn ? accepterBtn.textContent : '';

    /**
     * Habille le bloc pour UN état, puis le montre.
     *
     * TASK-1822 — `cle-perimee` s'ajoute aux états de permission. Ce n'en
     * est pas un : la permission peut être parfaitement accordée, c'est
     * l'ABONNEMENT qui est devenu inopérant. Il a donc son propre texte,
     * et son propre libellé de bouton (« réactiver », pas « activer ») :
     * dire « activez les notifications » à quelqu'un qui les croyait
     * actives depuis des semaines ne lui apprendrait rien.
     */
    const proposer = (etatPermission) => {
        if (corps) {
            corps.textContent =
                etatPermission === 'cle-perimee' ? (bloc.dataset.pushTextStaleKeyBody || corpsParDefaut)
                : etatPermission === 'granted' ? (bloc.dataset.pushTextGrantedBody || corpsParDefaut)
                : etatPermission === 'denied' ? (bloc.dataset.pushTextDeniedBody || corpsParDefaut)
                : corpsParDefaut;
        }

        if (accepterBtn) {
            accepterBtn.textContent =
                etatPermission === 'cle-perimee'
                    ? (bloc.dataset.pushTextStaleKeyAction || accepterParDefaut)
                    : accepterParDefaut;
        }

        // `denied` : aucun bouton d'activation — il ne pourrait qu'échouer.
        // Et « Plus tard » devient « Compris » : on ne reporte pas une
        // explication, on en prend acte.
        montrer(accepterBtn, etatPermission !== 'denied');

        if (plusTardBtn) {
            plusTardBtn.textContent =
                etatPermission === 'denied'
                    ? (bloc.dataset.pushTextUnderstood || plusTardParDefaut)
                    : plusTardParDefaut;
        }

        montrer(aide, false);
        montrer(bloc, true);
    };

    const decider = async () => {
        montrer(bloc, false);

        if (!estInstallee()) return dire('onglet-ordinaire');
        if (!supporte(true, vapid)) return dire('capacite-absente');
        if (enReport()) return dire('report-en-cours');

        // L'appareil est-il DÉJÀ abonné ? Les deux moitiés comptent : le
        // navigateur détient l'abonnement, le serveur sait s'il est à cette
        // personne.
        let abonnement = null;

        try {
            abonnement = await abonnementCourant();
        } catch (e) {
            return dire('service-worker-illisible');
        }

        if (abonnement !== null) {
            /* TASK-1822 — LA CLÉ D'ABORD, avant toute conclusion.
             *
             * Ce bloc concluait « déjà abonné » sur la seule foi du serveur,
             * puis reconfirmait en silence. Or un abonnement créé avec une
             * clé VAPID qui n'a plus cours survit intact : le navigateur le
             * rend toujours, et c'est lui qui jettera les messages EN
             * SILENCE. Les deux issues étaient donc fausses :
             *
             *  - « déjà abonné » déclarait opérationnel un appareil qui ne
             *    recevra jamais rien ;
             *  - la reconfirmation faisait RÉÉCRIRE `vapid_key_hash` côté
             *    serveur avec la clé courante (défaut mesuré en T1821), ce
             *    qui effaçait la seule trace de la divergence et faisait
             *    passer l'abonnement pour éligible auprès du livreur.
             *
             * On ne conclut donc rien, et on ne reconfirme rien, tant que la
             * clé réelle du navigateur n'a pas été comparée. `inobservable`
             * n'est PAS une divergence : un navigateur qui n'expose pas sa
             * clé garde le comportement d'avant, on ne casse pas un
             * abonnement qui fonctionne peut-être très bien. */
            if (comparerCleVapid(abonnement, vapid) === CLE_DIVERGE) {
                // Aucune reconfirmation, aucune déclaration « déjà abonné » :
                // la seule sortie honnête est un geste VOLONTAIRE. La
                // proposition revient, avec son propre texte — « réactiver »
                // plutôt que « activer », parce que la personne croyait de
                // bonne foi être abonnée. `abonner()` renouvellera
                // proprement l'abonnement au clic, sans toucher aux autres
                // appareils de la personne.
                proposer('cle-perimee');

                return dire('cle-perimee');
            }

            let etat = null;

            try {
                etat = await etatServeur(url, abonnement.endpoint);
            } catch (e) {}

            if (etat && etat.subscribed === true) {
                return dire('deja-abonne');
            }

            // Règle 4 — l'abonnement existe, le serveur l'ignore, la
            // permission est accordée : on RECONFIRME en silence, par la
            // réconciliation T1739 (idempotente côté magasin). C'est le cas
            // « appareil oublié dans le SuperAdmin » de la recette du 03/11 :
            // les notifications repartent sans un seul geste.
            //
            // TASK-1822 — ce chemin n'est désormais atteint que si la clé
            // concorde, ou n'est pas observable.
            if (Notification.permission === 'granted') {
                try {
                    await confirmer(url, abonnement, libelleAppareil());

                    return dire('resynchronise');
                } catch (e) {
                    // Le serveur n'a pas pris : on retombe sur la
                    // proposition visible — au moins un geste reste offert.
                }
            }
        }

        if (Notification.permission === 'denied') {
            proposer('denied');

            return dire('permission-refusee');
        }

        proposer(Notification.permission);

        return dire(Notification.permission === 'granted' ? 'activation-simple' : 'proposition');
    };

    plusTardBtn?.addEventListener('click', () => {
        reporter();
        montrer(bloc, false);
        dire('report-en-cours');
    });

    accepterBtn?.addEventListener('click', async () => {
        if (!accepterBtn || accepterBtn.disabled) return;
        accepterBtn.disabled = true;

        try {
            // ICI, et nulle part ailleurs : la permission se demande sur un
            // geste explicite. Si elle est déjà `granted`, aucun prompt ne
            // repart — `requestPermission()` résout immédiatement.
            await abonner(url, vapid);

            // Réussi : on se retire SANS poser de report. L'appareil étant
            // abonné, « déjà abonné » suffira à se taire — un report en plus
            // serait une seconde raison de silence, une de trop à défaire le
            // jour où la personne se désabonne.
            montrer(bloc, false);
            dire('active-a-l-instant');
        } catch (e) {
            if (Notification.permission === 'denied') {
                // La personne vient de refuser au prompt système : on le
                // respecte — explication du chemin de retour, et un report
                // pour ne pas redemander à chaque lancement.
                reporter();
                proposer('denied');
                dire('permission-refusee');
            } else {
                // Panne technique (réseau, serveur) : PAS de report — un
                // incident passager ne doit pas acheter sept jours de
                // silence. On le dit, et on se retire.
                if (aide) {
                    aide.textContent = bloc.dataset.pushTextFailed ?? '';
                    montrer(aide, (bloc.dataset.pushTextFailed ?? '') !== '');
                }

                window.setTimeout(() => montrer(bloc, false), 4000);
            }
        } finally {
            accepterBtn.disabled = false;
        }
    });

    // Règle 6 — Chrome Android 155+ : la permission peut être accordée (ou
    // retirée) après coup depuis les contrôles du site, sans rechargement.
    // On redécide au changement : un `granted` tout frais déclenche la
    // resynchronisation ou l'activation simple, un `denied` remplace le
    // bouton par l'explication.
    const observerLaPermission = () => {
        if (!navigator.permissions || typeof navigator.permissions.query !== 'function') return;

        navigator.permissions
            .query({ name: 'notifications' })
            .then((statut) => {
                statut.addEventListener('change', () => {
                    decider();
                });
            })
            .catch(() => {});
    };

    /**
     * Recette UNIQUEMENT (point 6 de la demande du 03/11) : le bouton
     * `data-push-first-run-reset` — rendu par le panneau de diagnostic des
     * réglages, qui n'existe qu'en environnement `local` — efface le report
     * sans toucher à rien d'autre. JAMAIS « vider le cache de la PWA » :
     * ce geste détruit l'abonnement Push lui-même.
     *
     * Recette n°2 (03/11) : effacer ne suffit pas à RÉPONDRE. Sur l'appareil
     * de Cyril, le blocage n'était pas le report mais « déjà abonné » — la
     * proposition ne s'affiche jamais sur un appareil abonné (règle voulue),
     * et « fermez et rouvrez » promettait donc un retour impossible. Le
     * bouton REDÉCIDE après l'effacement et rend le message de l'état réel :
     * abonné → désactiver d'abord ; onglet → ouvrir l'application ; sinon →
     * fermer et rouvrir suffit.
     */
    document.querySelector('[data-push-first-run-reset]')?.addEventListener('click', async (evenement) => {
        const bouton = evenement.currentTarget;
        bouton.disabled = true;

        try {
            window.localStorage.removeItem(CLEF_REPORT);
            window.localStorage.removeItem(CLEF_V1);
        } catch (e) {}

        const cause = await decider();

        bouton.textContent =
            (cause === 'deja-abonne' || cause === 'resynchronise'
                ? bouton.dataset.resetDoneSubscribed
                : cause === 'onglet-ordinaire'
                  ? bouton.dataset.resetDoneBrowserTab
                  : bouton.dataset.resetDone) || bouton.textContent;
    });

    decider();
    observerLaPermission();
}
