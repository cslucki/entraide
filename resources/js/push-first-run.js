/**
 * TASK-1745 — « Activer les notifications », au premier lancement installé.
 *
 * Quatre conditions pour apparaître, et aucune n'est supposée :
 *
 * 1. la capacité existe et la personne est connectée — posé côté SERVEUR,
 *    ce bloc n'est même pas rendu sinon ;
 * 2. l'application est lancée INSTALLÉE (`display-mode: standalone`) ;
 * 3. l'appareil n'est pas déjà abonné — la réponse vient du serveur, jamais
 *    d'une supposition locale ;
 * 4. la personne n'a pas déjà répondu « Plus tard ».
 *
 * La permission système n'est demandée QUE dans le gestionnaire de clic
 * (CDC 5.3) : une demande au chargement se fait refuser par réflexe, et un
 * refus ne se rattrape pas.
 *
 * La mécanique d'abonnement est celle de T1739, importée et non recopiée —
 * voir `push-abonnement.js`.
 */

import { abonnementCourant, abonner, etatServeur, supporte } from './push-abonnement.js';

const bloc = document.querySelector('[data-push-first-run]');

if (bloc) {
    const url = bloc.dataset.pushUrl;
    const vapid = bloc.dataset.pushVapid || '';

    /**
     * Le renoncement vit dans `localStorage`, pas dans un cookie.
     *
     * C'est un état d'APPAREIL, comme l'abonnement : il n'a aucune raison de
     * voyager dans chaque requête HTTP. Et il est par appareil, ce qui est
     * le bon grain — refuser sur sa tablette ne doit pas décider pour son
     * téléphone.
     */
    const CLEF_RENONCEMENT = 'bpPushFirstRunDismissed';

    const aRenonce = () => {
        try {
            return window.localStorage.getItem(CLEF_RENONCEMENT) === '1';
        } catch (e) {
            // Navigation privée, stockage bloqué : on ne sait pas, donc on
            // ne propose pas. Insister serait le pire des deux choix.
            return true;
        }
    };

    const renoncer = () => {
        try {
            window.localStorage.setItem(CLEF_RENONCEMENT, '1');
        } catch (e) {}
    };

    const montrer = (el, visible) => {
        if (!el) return;
        el.hidden = !visible;
        el.style.display = visible ? '' : 'none';
    };

    const fermer = () => {
        if (bloc.parentNode) bloc.parentNode.removeChild(bloc);
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

    const decider = async () => {
        if (!estInstallee() || aRenonce() || !supporte(true, vapid)) {
            return;
        }

        // Une permission déjà refusée au niveau du navigateur ne se rattrape
        // pas depuis une page : proposer un bouton qui ne peut qu'échouer
        // serait un bouton factice (CDC 3.3).
        if (Notification.permission === 'denied') {
            return;
        }

        // L'appareil est-il DÉJÀ abonné ? Les deux moitiés comptent : le
        // navigateur détient l'abonnement, le serveur sait s'il est à cette
        // personne. Un abonnement que le serveur ignore n'en est pas un.
        const abonnement = await abonnementCourant();

        if (abonnement !== null) {
            const etat = await etatServeur(url, abonnement.endpoint);

            if (etat && etat.subscribed === true) {
                return;
            }
        }

        montrer(bloc, true);
    };

    bloc.querySelector('[data-push-first-run-later]')?.addEventListener('click', () => {
        renoncer();
        fermer();
    });

    bloc.querySelector('[data-push-first-run-accept]')?.addEventListener('click', async (evenement) => {
        const bouton = evenement.currentTarget;
        bouton.disabled = true;

        try {
            // ICI, et nulle part ailleurs : la permission se demande sur un
            // geste explicite.
            await abonner(url, vapid);

            // Réussi : on se retire sans poser de renoncement. L'appareil
            // étant désormais abonné, la condition 3 suffira à ne pas
            // reproposer — un renoncement en plus serait une seconde raison
            // de se taire, donc une de trop à défaire le jour où la personne
            // se désabonne.
            fermer();
        } catch (e) {
            // Un refus système n'est pas une panne : on se retire, et on
            // s'en souvient. Redemander à chaque lancement serait du
            // harcèlement.
            renoncer();

            const aide = bloc.querySelector('[data-push-first-run-help]');

            if (aide) {
                aide.textContent = bloc.dataset.pushTextFailed ?? '';
                montrer(aide, (bloc.dataset.pushTextFailed ?? '') !== '');
            }

            window.setTimeout(fermer, 4000);
        } finally {
            bouton.disabled = false;
        }
    });

    decider();
}
