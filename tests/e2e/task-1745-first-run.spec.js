import { test, expect } from '@playwright/test';
import { loginAsMember } from './helpers/auth.js';

/**
 * TASK-1745 — la DÉCISION du premier lancement, mesurée dans un navigateur.
 *
 * ## Pourquoi ce spec existe, et pourquoi seulement maintenant
 *
 * La recette du 03/11 a échoué sur un bloc qui ne s'affichait JAMAIS — le
 * script n'était pas émis (`@push` après le `@stack`) — et AUCUN banc ne
 * pouvait le voir : les tests PHP mesurent le balisage, pas l'exécution, et
 * aucun spec navigateur ne couvrait la décision. L'émission est maintenant
 * gardée côté PHP ; ICI on mesure ce que le script DÉCIDE.
 *
 * ## Ce qui est doublé, et pourquoi c'est honnête
 *
 * `display-mode: standalone` n'est pas pilotable depuis Playwright (leçon
 * T1741), la permission système ne se raconte pas à un banc headless (elle
 * y est `denied` d'office), et un vrai abonnement FCM n'a rien à faire dans
 * un test. On double donc `matchMedia`, `Notification.permission` et
 * `serviceWorker.ready` — exactement les trois lectures que le script fait
 * de son environnement — et RIEN de sa logique : ce qui tourne est le
 * fichier compilé réel, contre le serveur réel.
 *
 * Le réseau Push (`/notifications/push`) est intercepté : le spec mesure ce
 * que le client DEMANDE, sans écrire d'endpoint factice en base.
 */
test.describe('TASK-1745 — décision du premier lancement', () => {
    // Sans ceci, le VRAI Service Worker de la PWA contrôle la page, et tout
    // `fetch` transite par lui — hors de portée de `page.route`. Mesuré le
    // 03/11 : les doublures réseau ne prenaient pas, les POST partaient au
    // serveur réel et ÉCRIVAIENT des endpoints factices dans la base de dev
    // (deux lignes `banc-e2e-*`, nettoyées). Le spec n'a pas besoin du vrai
    // SW : `serviceWorker.ready` est doublé dans `armer()`.
    test.use({ serviceWorkers: 'block' });

    const BLOC = '[data-push-first-run]';
    const CLEF = 'bpPushFirstRunSnoozedUntil';

    /**
     * Arme les trois doublures AVANT tout script de la page.
     *
     * `options` : { standalone, permission, subscription: null|{endpoint},
     * reportJusqua } — tout est explicite, rien n'hérite d'un défaut du banc
     * (le `denied` d'office du headless a précisément coûté une heure de
     * recette ailleurs).
     *
     * À appeler APRÈS `loginAsMember`, jamais avant — mesuré le 03/11 : un
     * init script s'applique à TOUTES les navigations suivantes, donc armé
     * avant la connexion il faisait tourner le premier lancement sur la page
     * d'atterrissage du login, AVANT la pose des doublures réseau — et la
     * resynchronisation écrivait de vrais endpoints factices en base de dev.
     */
    const armer = (page, options) =>
        page.addInitScript((opts) => {
            if (opts.standalone) {
                const originale = window.matchMedia.bind(window);
                window.matchMedia = (q) =>
                    q.includes('display-mode: standalone')
                        ? { matches: true, media: q, onchange: null,
                            addEventListener() {}, removeEventListener() {},
                            addListener() {}, removeListener() {}, dispatchEvent: () => false }
                        : originale(q);
            }

            if (opts.permission) {
                Object.defineProperty(window.Notification, 'permission', {
                    get: () => opts.permission,
                    configurable: true,
                });
            }

            const abonnement = opts.subscription
                ? {
                      endpoint: opts.subscription.endpoint,
                      toJSON() {
                          return { endpoint: this.endpoint, keys: { p256dh: 'banc', auth: 'banc' } };
                      },
                      unsubscribe: async () => true,
                  }
                : null;

            Object.defineProperty(navigator.serviceWorker, 'ready', {
                get: () =>
                    Promise.resolve({
                        pushManager: {
                            getSubscription: async () => abonnement,
                            subscribe: async () => abonnement,
                        },
                    }),
                configurable: true,
            });

            if (opts.reportJusqua !== undefined) {
                try {
                    window.localStorage.setItem('bpPushFirstRunSnoozedUntil', String(opts.reportJusqua));
                } catch (e) {}
            } else {
                try {
                    window.localStorage.removeItem('bpPushFirstRunSnoozedUntil');
                } catch (e) {}
            }
        }, options);

    /** Le serveur Push, doublé : `etat` répond au GET, `posts` collecte. */
    const doublerLeServeurPush = async (page, etat, posts = []) => {
        await page.route('**/notifications/push*', async (route) => {
            const requete = route.request();

            if (requete.method() === 'POST') {
                posts.push(requete.postDataJSON());

                return route.fulfill({ json: { stored: true, subscribed: true, device_count: 1 } });
            }

            return route.fulfill({ json: etat });
        });
    };

    /** Le panneau ambre est un `<details>` replié par défaut (T1744). */
    const deplierLeDiagnostic = async (page) => {
        const resume = page.locator('[data-push-diag-panel] summary');

        if (await resume.count()) await resume.click();
    };

    const attendreLaDecision = async (page) => {
        await expect(page.locator(BLOC)).toHaveAttribute('data-push-first-run-etat', /.+/);

        return page.locator(BLOC).getAttribute('data-push-first-run-etat');
    };

    test('dans un onglet ordinaire, la proposition se tait — et DIT pourquoi', async ({ page }) => {
        await loginAsMember(page);
        await armer(page, { standalone: false, permission: 'default', subscription: null });
        await page.goto('/boucles');

        expect(await attendreLaDecision(page)).toBe('onglet-ordinaire');
        await expect(page.locator(BLOC)).toBeHidden();
    });

    test('installée, jamais abonnée, permission default : la proposition APPARAÎT', async ({ page }) => {
        await loginAsMember(page);
        await armer(page, { standalone: true, permission: 'default', subscription: null });
        await page.goto('/boucles');

        expect(await attendreLaDecision(page)).toBe('proposition');

        const bloc = page.locator(BLOC);
        await expect(bloc).toBeVisible();
        await expect(bloc.locator('[data-push-first-run-accept]')).toBeVisible();
        await expect(bloc.locator('[data-push-first-run-later]')).toBeVisible();
    });

    test('« Plus tard » pose un report DATÉ, qui tient au relancement', async ({ page }) => {
        await loginAsMember(page);
        await armer(page, { standalone: true, permission: 'default', subscription: null });
        await page.goto('/boucles');

        await expect(page.locator(BLOC)).toBeVisible();
        await page.locator('[data-push-first-run-later]').click();
        await expect(page.locator(BLOC)).toBeHidden();

        // La clé porte une ÉCHÉANCE future, pas un `1` éternel : c'est le
        // correctif du point 2 — un report n'est pas un refus permanent.
        const valeur = await page.evaluate((clef) => window.localStorage.getItem(clef), CLEF);
        expect(Number(valeur), 'la valeur doit être une échéance epoch-ms').toBeGreaterThan(Date.now());
        expect(Number(valeur)).toBeLessThanOrEqual(Date.now() + 8 * 24 * 3600 * 1000);

        // Relancement : le report tient. (`reload` garde le localStorage ;
        // l'init script de CE test n'écrase pas la clé posée par le clic.)
        await armer(page, { standalone: true, permission: 'default', subscription: null, reportJusqua: Number(valeur) });
        await page.reload();

        expect(await attendreLaDecision(page)).toBe('report-en-cours');
        await expect(page.locator(BLOC)).toBeHidden();
    });

    test('un report EXPIRÉ ne se tait plus', async ({ page }) => {
        await loginAsMember(page);
        await armer(page, {
            standalone: true,
            permission: 'default',
            subscription: null,
            reportJusqua: Date.now() - 1000,
        });
        await page.goto('/boucles');

        expect(await attendreLaDecision(page)).toBe('proposition');
        await expect(page.locator(BLOC)).toBeVisible();
    });

    test('abonnement navigateur inconnu du serveur + permission accordée : resynchronisation SILENCIEUSE', async ({ page }) => {
        // Le scénario exact de la recette du 03/11 : l'appareil « oublié »
        // dans le SuperAdmin. Le navigateur détient toujours l'abonnement et
        // la permission : les notifications doivent repartir SANS un geste,
        // sans un écran — par la réconciliation T1739 (point 3).
        const endpoint = 'https://fcm.googleapis.com/fcm/send/banc-e2e-resync';
        const posts = [];

        await loginAsMember(page);
        await armer(page, { standalone: true, permission: 'granted', subscription: { endpoint } });
        await doublerLeServeurPush(page, { enabled: true, subscribed: false, device_count: 0 }, posts);
        await page.goto('/boucles');

        expect(await attendreLaDecision(page)).toBe('resynchronise');
        await expect(page.locator(BLOC)).toBeHidden();

        expect(posts, 'UNE reconfirmation doit être partie').toHaveLength(1);
        expect(posts[0].endpoint).toBe(endpoint);
    });

    test('appareil déjà abonné et connu du serveur : silence', async ({ page }) => {
        await loginAsMember(page);
        await armer(page, {
            standalone: true,
            permission: 'granted',
            subscription: { endpoint: 'https://fcm.googleapis.com/fcm/send/banc-e2e-connu' },
        });
        await doublerLeServeurPush(page, { enabled: true, subscribed: true, device_count: 1 });
        await page.goto('/boucles');

        expect(await attendreLaDecision(page)).toBe('deja-abonne');
        await expect(page.locator(BLOC)).toBeHidden();
    });

    test('permission accordée sans abonnement : activation SIMPLE, corps adapté', async ({ page }) => {
        // Point 4 : la permission est là (accordée autrefois, ou depuis les
        // contrôles du site — Chrome 155+). Le corps doit dire qu'aucune
        // nouvelle demande ne partira.
        await loginAsMember(page);
        await armer(page, { standalone: true, permission: 'granted', subscription: null });
        await page.goto('/boucles');

        expect(await attendreLaDecision(page)).toBe('activation-simple');

        const bloc = page.locator(BLOC);
        await expect(bloc).toBeVisible();
        await expect(bloc.locator('[data-push-first-run-accept]')).toBeVisible();
        await expect(bloc.locator('[data-push-first-run-body]')).toContainText(
            /déjà accordée|already granted/,
        );
    });

    test('le bouton de recette efface le report, REDÉCIDE, et nomme le vrai blocage', async ({ page }) => {
        // Recette n°2 du 03/11 : « ça ne marche pas ». Le bouton effaçait
        // bien le report — mais le report n'était pas ce qui bloquait :
        // l'appareil était DÉJÀ abonné, état où la proposition ne revient
        // jamais (règle voulue). Le bouton doit répondre avec l'état RÉEL.
        const posts = [];

        await loginAsMember(page);
        await armer(page, {
            standalone: true,
            permission: 'granted',
            subscription: { endpoint: 'https://fcm.googleapis.com/fcm/send/banc-e2e-reset' },
            reportJusqua: Date.now() + 1000 * 3600,
        });
        await doublerLeServeurPush(page, { enabled: true, subscribed: true, device_count: 1 }, posts);
        await page.goto('/notifications/preferences');

        // Avant le clic : le report gouverne, et la ligne de diagnostic
        // du panneau ambre LE DIT — c'est elle qui manquait à la recette.
        expect(await attendreLaDecision(page)).toBe('report-en-cours');
        await expect(page.locator('[data-push-diag-value="premier-lancement"]')).toHaveText('report-en-cours');

        // Le panneau est un <details> REPLIÉ par défaut (T1744) : le
        // bouton n'est cliquable qu'après l'avoir déplié — exactement le
        // geste de la recette réelle.
        await deplierLeDiagnostic(page);

        const bouton = page.locator('[data-push-first-run-reset]');
        await bouton.click();

        // Le report est EFFACÉ pour de vrai…
        expect(await page.evaluate((clef) => window.localStorage.getItem(clef), CLEF)).toBeNull();

        // …la décision est reprise, et le bouton nomme le vrai blocage :
        // appareil déjà abonné, la proposition ne reviendra pas ainsi.
        await expect(page.locator(BLOC)).toHaveAttribute('data-push-first-run-etat', 'deja-abonne');
        await expect(page.locator('[data-push-diag-value="premier-lancement"]')).toHaveText('deja-abonne');
        await expect(bouton).toContainText(/DÉJÀ abonné/);
        await expect(bouton).toBeDisabled();
        await expect(page.locator(BLOC)).toBeHidden();
    });

    test('le bouton de recette, cliqué dans un ONGLET, dit d’ouvrir l’application', async ({ page }) => {
        await loginAsMember(page);
        await armer(page, { standalone: false, permission: 'default', subscription: null });
        await page.goto('/notifications/preferences');

        expect(await attendreLaDecision(page)).toBe('onglet-ordinaire');

        await deplierLeDiagnostic(page);

        const bouton = page.locator('[data-push-first-run-reset]');
        await bouton.click();

        await expect(bouton).toContainText(/onglet de navigateur/);
    });

    test('permission REFUSÉE : une explication, et aucun bouton qui ne peut qu’échouer', async ({ page }) => {
        await loginAsMember(page);
        await armer(page, { standalone: true, permission: 'denied', subscription: null });
        await page.goto('/boucles');

        expect(await attendreLaDecision(page)).toBe('permission-refusee');

        const bloc = page.locator(BLOC);
        await expect(bloc).toBeVisible();

        // Pas de bouton d'activation : il ne pourrait qu'échouer (CDC 3.3).
        await expect(bloc.locator('[data-push-first-run-accept]')).toBeHidden();

        // L'explication nomme le chemin de retour, et la sortie dit
        // « Compris » — on ne « reporte » pas une explication.
        await expect(bloc.locator('[data-push-first-run-body]')).toContainText(/bloquées|blocked/);
        await expect(bloc.locator('[data-push-first-run-later]')).toHaveText(/Compris|Got it/);
    });
});
