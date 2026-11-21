/**
 * TASK-1878 — console « Diagnostic PWA ».
 *
 * MESURER, jamais corriger : ce script ne fait ni `skipWaiting()`, ni
 * `unregister()`, ni `caches.delete()`, ni `update()`, et n'appelle jamais
 * `/partage` (qui range ou efface un partage en attente en session).
 *
 * Quatre statuts, et seulement quatre : OK, ÉCHEC, NON VÉRIFIABLE (le web ne
 * peut pas le mesurer, ou la mesure n'a pas répondu), NON TESTÉ (essai pas
 * encore lancé, ou question manuelle sans réponse).
 *
 * La section Notifications est celle de T1877 (`push-device.js`) : ce script
 * ne fait que LIRE ses cases et ses résultats d'essais pour le rapport.
 */

const racine = document.querySelector('[data-pwa-diagnostic]');

if (racine) {
    const T = JSON.parse(racine.querySelector('[data-pwa-diagnostic-texts]').textContent);
    const cfg = racine.dataset;
    const CLE_MANUEL = 'bouclepro.diagnostic-pwa.manuel';

    const remplir = (gabarit, ...valeurs) => valeurs.reduce((texte, v) => texte.replace('%s', v), gabarit);
    const ouiNon = (b) => (b ? T.yes : T.no);

    /** Les résultats, par clé de ligne : la seule source du rapport. */
    const resultats = {};

    const poser = (cle, statut, valeur = null, aide = null) => {
        const ligne = racine.querySelector(`[data-diag-row="${cle}"]`);
        if (!ligne) return;

        resultats[cle] = { statut, valeur, aide };
        ligne.dataset.diagStatus = statut;
        ligne.querySelector('[data-diag-badge]').textContent = T.status[statut];

        const elValeur = ligne.querySelector('[data-diag-value]');
        if (elValeur) elValeur.textContent = valeur ?? '—';

        const elAide = ligne.querySelector('[data-diag-help]');
        if (elAide) {
            elAide.textContent = aide ?? '';
            elAide.hidden = !aide;
        }
    };

    const estInstallee = () => {
        // Même mesure que `push-first-run.js` (TASK-1745).
        try {
            if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) return true;
        } catch { /* rien */ }
        return window.navigator.standalone === true;
    };

    const avecDelai = (promesse, ms) => Promise.race([
        promesse,
        new Promise((resoudre) => setTimeout(() => resoudre(null), ms)),
    ]);

    /* ------------------------------------------------------------------ */
    /* 1. Installation (+ manifeste servi, réutilisé par le Partage)       */
    /* ------------------------------------------------------------------ */
    const mesurerInstallation = async () => {
        poser('inst_origin', 'ok', location.origin);
        poser('inst_secure', window.isSecureContext ? 'ok' : 'fail', ouiNon(window.isSecureContext),
            window.isSecureContext ? null : T.help.inst_secure);

        const installee = estInstallee();
        poser('inst_standalone', installee ? 'ok' : 'fail', ouiNon(installee), installee ? null : T.help.inst_standalone);

        const sw = 'serviceWorker' in navigator;
        const push = 'PushManager' in window;
        const notif = 'Notification' in window;
        const pressePapiers = !!navigator.clipboard;
        poser('inst_support', sw && push && notif ? 'ok' : 'fail',
            remplir(T.values.support, ouiNon(sw), ouiNon(push), ouiNon(notif), ouiNon(pressePapiers)),
            sw && push && notif ? null : T.help.inst_support);

        const lien = document.querySelector('link[rel="manifest"]');
        poser('inst_manifest_link', lien ? 'ok' : 'fail', lien ? new URL(lien.href).pathname : T.none,
            lien ? null : T.help.inst_manifest_link);

        let manifeste = null;
        let urlManifeste = null;
        const problemes = [];

        try {
            urlManifeste = new URL(lien ? lien.href : cfg.manifestPath, location.origin);
            const reponse = await fetch(urlManifeste, { credentials: 'same-origin', cache: 'no-store' });
            if (!reponse.ok) problemes.push(`HTTP ${reponse.status}`);
            else manifeste = await reponse.json();
        } catch {
            problemes.push('JSON');
        }

        if (manifeste) {
            if (!manifeste.name) problemes.push('name');
            if (!manifeste.start_url) problemes.push('start_url');
            if (!['standalone', 'fullscreen', 'minimal-ui'].includes(manifeste.display)) problemes.push('display');
            const tailles = (manifeste.icons ?? []).flatMap((i) => String(i.sizes ?? '').split(/\s+/));
            ['192x192', '512x512'].forEach((t) => { if (!tailles.includes(t)) problemes.push(`icon ${t}`); });
        }

        poser('inst_manifest_valid', problemes.length === 0 ? 'ok' : 'fail',
            problemes.length === 0 ? T.values.manifest_ok : problemes.join(', '),
            problemes.length === 0 ? null : remplir(T.help.inst_manifest_valid, problemes.join(', ')));

        if (manifeste) {
            const conforme = manifeste.name === cfg.expectedName;
            poser('inst_name', conforme ? 'ok' : 'fail', remplir(T.values.name_expected, manifeste.name ?? '—', cfg.expectedName),
                conforme ? null : T.help.inst_name);
        } else {
            poser('inst_name', 'unverifiable', T.no_answer, remplir(T.help.inst_manifest_valid, problemes.join(', ')));
        }

        return { installee, manifeste, urlManifeste };
    };

    /* ------------------------------------------------------------------ */
    /* 2. Service Worker — lecture seule, protocole `bouclepro:version`     */
    /* ------------------------------------------------------------------ */
    const mesurerServiceWorker = async () => {
        if (!('serviceWorker' in navigator)) {
            ['sw_registration', 'sw_controller', 'sw_scope', 'sw_version', 'sw_waiting']
                .forEach((cle) => poser(cle, 'fail', T.no, T.help.inst_support));
            return;
        }

        let inscription = null;
        try {
            inscription = await avecDelai(navigator.serviceWorker.getRegistration(), 3000);
        } catch { /* reste null */ }

        poser('sw_registration', inscription ? 'ok' : 'fail', ouiNon(!!inscription), inscription ? null : T.help.sw_registration);

        const controle = !!navigator.serviceWorker.controller;
        poser('sw_controller', controle ? 'ok' : 'fail', ouiNon(controle), controle ? null : T.help.sw_controller);

        if (!inscription) {
            ['sw_scope', 'sw_version', 'sw_waiting'].forEach((cle) => poser(cle, 'unverifiable', T.none, T.help.sw_registration));
            return;
        }

        const scopeAttendu = `${location.origin}/`;
        poser('sw_scope', inscription.scope === scopeAttendu ? 'ok' : 'fail', inscription.scope,
            inscription.scope === scopeAttendu ? null : T.help.sw_scope);

        // Seul l'exemplaire ACTIF répond : c'est sa version qui compte.
        const reponse = inscription.active
            ? await new Promise((resoudre) => {
                const canal = new MessageChannel();
                const minuteur = setTimeout(() => resoudre(null), 2000);
                canal.port1.onmessage = (e) => { clearTimeout(minuteur); resoudre(e.data ?? null); };
                try {
                    inscription.active.postMessage({ type: 'bouclepro:version' }, [canal.port2]);
                } catch {
                    clearTimeout(minuteur);
                    resoudre(null);
                }
            })
            : null;

        if (!reponse) {
            poser('sw_version', 'unverifiable', remplir(T.values.sw_version, T.no_answer, cfg.expectedSw || '—'), T.help.sw_version_silent);
        } else {
            const ajour = !cfg.expectedSw || reponse.version === cfg.expectedSw;
            poser('sw_version', ajour ? 'ok' : 'fail', remplir(T.values.sw_version, reponse.version, cfg.expectedSw || '—'),
                ajour ? null : T.help.sw_version);
        }

        const attente = !!inscription.waiting;
        poser('sw_waiting', attente ? 'fail' : 'ok', ouiNon(attente), attente ? T.help.sw_waiting : null);
    };

    /* ------------------------------------------------------------------ */
    /* 3. Notifications — LECTURE des cases du bloc de T1877                */
    /* ------------------------------------------------------------------ */
    const bloc = racine.querySelector('[data-push-device]');
    const panneau = bloc?.querySelector('[data-push-diag-panel]');
    const tp = panneau?.querySelector('[data-push-diag-texts]')?.dataset ?? {};
    const prefixe = (gabarit) => String(gabarit ?? '').split('%s')[0];
    const EN_COURS = ['…', '', '—'];

    const lireNotifications = () => {
        const caseDe = (cle) => panneau?.querySelector(`[data-push-diag-value="${cle}"]`)?.textContent.trim() ?? '';

        const permission = caseDe('permission');
        if (permission === 'granted') poser('push_permission', 'ok', permission);
        else if (permission === 'denied') poser('push_permission', 'fail', permission, T.help.push_permission);
        else if (permission === 'default') poser('push_permission', 'untested', permission, T.help.push_permission_default);
        else if (EN_COURS.includes(permission)) poser('push_permission', 'untested', T.measuring);
        else poser('push_permission', 'fail', permission, T.help.inst_support);

        const abonnement = caseDe('abonnement');
        if (EN_COURS.includes(abonnement)) poser('push_subscription', 'untested', T.measuring);
        else if (abonnement === tp.none) poser('push_subscription', 'fail', T.none, T.help.push_subscription);
        else poser('push_subscription', 'ok', abonnement); // l'HÔTE du fournisseur seul, jamais l'endpoint

        const base = caseDe('base');
        if (abonnement === tp.none) poser('push_server', 'untested', '—', T.help.push_subscription);
        else if (EN_COURS.includes(base)) poser('push_server', 'untested', T.measuring);
        else if (base === tp.yes) poser('push_server', 'ok', T.yes);
        else if (base === tp.no) poser('push_server', 'fail', T.no, T.help.push_server);
        else poser('push_server', 'unverifiable', base, T.help.push_server);

        const cle = caseDe('cle');
        if (abonnement === tp.none) poser('push_key', 'untested', '—', T.help.push_subscription);
        else if (EN_COURS.includes(cle)) poser('push_key', 'untested', T.measuring);
        else if (cle === tp.keyHidden) poser('push_key', 'unverifiable', cle, T.help.push_key_hidden);
        else if (prefixe(tp.keyDiverge) && cle.startsWith(prefixe(tp.keyDiverge))) poser('push_key', 'fail', cle, T.help.push_key);
        else if (prefixe(tp.keyMatch) && cle.startsWith(prefixe(tp.keyMatch))) poser('push_key', 'ok', cle);
        else poser('push_key', 'unverifiable', cle);
    };

    const evenements = Number(cfg.pushEvents || 0);
    poser('push_events', evenements > 0 ? 'ok' : 'fail', remplir(T.values.events, evenements), evenements > 0 ? null : T.help.push_events);
    ['push_local', 'push_simulate', 'push_real'].forEach((c) => poser(c, 'untested', T.values.not_run));

    if (panneau) {
        lireNotifications();
        new MutationObserver(lireNotifications)
            .observe(panneau.querySelector('[data-push-diag]'), { subtree: true, childList: true, characterData: true });

        // Les deux essais du panneau partagent UN paragraphe de résultat :
        // on retient lequel a été lancé (phase de capture, avant le script de T1877).
        let dernierEssai = null;
        panneau.querySelector('[data-push-diag-local]')?.addEventListener('click', () => { dernierEssai = 'push_local'; }, true);
        panneau.querySelector('[data-push-diag-simuler]')?.addEventListener('click', () => { dernierEssai = 'push_simulate'; }, true);

        const elEssai = panneau.querySelector('[data-push-diag-resultat]');
        new MutationObserver(() => {
            if (!dernierEssai || elEssai.hidden || !elEssai.textContent.trim()) return;
            const reussi = elEssai.className.includes('emerald');
            poser(dernierEssai, reussi ? 'ok' : 'fail', elEssai.textContent.trim(), reussi ? null : T.help[dernierEssai]);
        }).observe(elEssai, { attributes: true, childList: true, characterData: true, subtree: true });
    }

    const elTestReel = bloc?.querySelector('[data-push-test-resultat]');
    if (elTestReel) {
        new MutationObserver(() => {
            if (elTestReel.hidden || !elTestReel.textContent.trim()) return;
            // Un 2xx du fournisseur n'est PAS une réception (R2, TASK-1877) :
            // le mieux qu'on puisse dire est NON VÉRIFIABLE, à confirmer à la main.
            if (elTestReel.className.includes('emerald')) {
                poser('push_real', 'unverifiable', T.values.real_sent, T.manual.help.m_push_received);
            } else {
                poser('push_real', 'fail', elTestReel.textContent.trim(), T.help.push_real);
            }
        }).observe(elTestReel, { attributes: true, childList: true, characterData: true, subtree: true });
    }

    /* ------------------------------------------------------------------ */
    /* 4. Partage Android                                                   */
    /* ------------------------------------------------------------------ */
    const mesurerPartage = async ({ installee, manifeste, urlManifeste }) => {
        poser('share_standalone', installee ? 'ok' : 'fail', ouiNon(installee), installee ? null : T.help.share_standalone);

        if (manifeste) {
            const cible = manifeste.share_target ?? null;
            let action = null;
            try {
                action = cible?.action ? new URL(cible.action, urlManifeste) : null;
            } catch { /* action illisible */ }
            const declare = !!action && action.origin === location.origin && action.pathname === '/partage';
            poser('share_declared', declare ? 'ok' : 'fail', ouiNon(declare) + (cible?.action ? ` (${cible.action})` : ''),
                declare ? null : T.help.share_declared);

            const p = cible?.params ?? {};
            const parametres = String(cible?.method ?? 'GET').toUpperCase() === 'GET'
                && p.url === 'url' && p.text === 'text' && p.title === 'title';
            poser('share_params', parametres ? 'ok' : 'fail', ouiNon(parametres), parametres ? null : T.help.share_params);
        } else {
            poser('share_declared', 'unverifiable', T.no_answer, T.help.inst_manifest_link);
            poser('share_params', 'unverifiable', T.no_answer, T.help.inst_manifest_link);
        }

        // Le serveur vérifie SANS toucher la session : requête synthétique,
        // jamais `/partage`. C'est une simulation TECHNIQUE (sans Android).
        let v = null;
        try {
            const reponse = await fetch(cfg.verificationUrl, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (reponse.ok) v = await reponse.json();
        } catch { /* reste null */ }

        if (!v) {
            ['share_server', 'share_route', 'share_simulation', 'share_pending']
                .forEach((c) => poser(c, 'unverifiable', T.no_answer));
        } else {
            const serveur = v.manifeste_serveur?.action && v.manifeste_serveur?.method && v.manifeste_serveur?.params;
            poser('share_server', serveur ? 'ok' : 'fail', ouiNon(serveur), serveur ? null : T.help.share_server);

            const route = v.route_reception && v.route_choix;
            poser('share_route', route ? 'ok' : 'fail', ouiNon(route), route ? null : T.help.share_route);

            poser('share_simulation', v.analyse_synthetique ? 'ok' : 'fail',
                v.analyse_synthetique ? T.values.simulation_ok : T.no, v.analyse_synthetique ? null : T.help.share_simulation);

            poser('share_pending', 'ok', ouiNon(v.intention_en_attente), v.intention_en_attente ? T.help.share_pending : null);
        }

        poser('share_native', 'unverifiable', T.values.native_manual, T.help.share_native);
    };

    /* ------------------------------------------------------------------ */
    /* Vérifications manuelles — réponses gardées sur CET appareil          */
    /* ------------------------------------------------------------------ */
    const lireManuel = () => {
        try { return JSON.parse(localStorage.getItem(CLE_MANUEL) ?? '{}') ?? {}; } catch { return {}; }
    };

    const appliquerManuel = (cle, reponse) => {
        if (reponse === 'yes') poser(cle, 'ok');
        else if (reponse === 'no') poser(cle, 'fail', null, T.manual.help[cle]);
        else poser(cle, 'untested');
    };

    const manuel = lireManuel();
    racine.querySelectorAll('[data-diag-manual-question]').forEach((ligne) => {
        const cle = ligne.dataset.diagRow;
        const reponse = manuel[cle] ?? 'untested';
        const radio = ligne.querySelector(`input[value="${reponse}"]`);
        if (radio) radio.checked = true;
        appliquerManuel(cle, reponse);

        ligne.querySelectorAll('input[type="radio"]').forEach((r) => r.addEventListener('change', () => {
            appliquerManuel(cle, r.value);
            const etat = lireManuel();
            etat[cle] = r.value;
            try { localStorage.setItem(CLE_MANUEL, JSON.stringify(etat)); } catch { /* sans gravité */ }
        }));
    });

    /* ------------------------------------------------------------------ */
    /* Rapport                                                              */
    /* ------------------------------------------------------------------ */
    const plateforme = () => {
        const ua = navigator.userAgent;
        const systeme = /Android/.test(ua) ? 'Android'
            : /iPhone|iPad|iPod/.test(ua) ? 'iOS'
                : /Windows/.test(ua) ? 'Windows'
                    : /Mac OS X/.test(ua) ? 'macOS'
                        : /Linux/.test(ua) ? 'Linux' : T.report.unknown;
        const nav = ua.match(/(Edg|SamsungBrowser|Firefox|Chrome|Version)\/(\d+)/);
        const nom = nav ? ({ Edg: 'Edge', Version: 'Safari' }[nav[1]] ?? nav[1]) : T.report.unknown;
        return `${systeme} · ${nom}${nav ? ` ${nav[2]}` : ''}`;
    };

    const libelleDe = (cle) => T.rows[cle] ?? T.manual.questions[cle] ?? cle;

    const rapport = () => {
        const R = T.report;
        const lignes = [
            R.title,
            `${R.date} : ${new Date().toISOString()}`,
            `${R.environment} : ${cfg.environment} (${location.origin})`,
            `${R.version} : ${cfg.appVersion || R.unknown}`,
            `${R.platform} : ${plateforme()}`,
            `${R.install_mode} : ${estInstallee() ? R.mode_standalone : R.mode_browser}`,
            '',
        ];
        const causes = [];

        const ecrireSection = (titre, cles) => {
            lignes.push(`== ${titre} ==`);
            cles.forEach((cle) => {
                const r = resultats[cle] ?? { statut: 'untested' };
                lignes.push(`[${T.status[r.statut]}] ${libelleDe(cle)}${r.valeur ? ` : ${r.valeur}` : ''}`);
                if (r.aide && (r.statut === 'fail' || r.statut === 'unverifiable')) causes.push(`- ${libelleDe(cle)} : ${r.aide}`);
            });
            lignes.push('');
        };

        racine.querySelectorAll('[data-diag-section]').forEach((s) => {
            ecrireSection(T.sections[s.dataset.diagSection],
                [...s.querySelectorAll(':scope > ul > [data-diag-row]')].map((l) => l.dataset.diagRow));
        });
        ecrireSection(T.manual_heading,
            [...racine.querySelectorAll('[data-diag-manual-question]')].map((l) => l.dataset.diagRow));

        lignes.push(`== ${R.causes} ==`);
        lignes.push(...(causes.length ? causes : [R.no_cause]));
        lignes.push('', T.limits);

        return lignes.join('\n');
    };

    const mesures = (async () => {
        const installation = await mesurerInstallation();
        await Promise.all([mesurerServiceWorker(), mesurerPartage(installation)]);
    })();

    const btnCopier = racine.querySelector('[data-pwa-report-copy]');
    const elStatut = racine.querySelector('[data-pwa-report-status]');
    const elTexte = racine.querySelector('[data-pwa-report-text]');

    btnCopier.addEventListener('click', async () => {
        btnCopier.disabled = true;
        try {
            await mesures;
            const texte = rapport();
            elTexte.value = texte;
            elTexte.hidden = false;
            try {
                await navigator.clipboard.writeText(texte);
                elStatut.textContent = T.report.copied;
            } catch {
                elTexte.focus();
                elTexte.select();
                elStatut.textContent = T.report.copy_failed;
            }
            elStatut.hidden = false;
        } finally {
            btnCopier.disabled = false;
        }
    });
}
