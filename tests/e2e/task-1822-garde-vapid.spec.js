import { test, expect } from '@playwright/test';
import { loginAsMember } from './helpers/auth.js';

/**
 * TASK-1822 — la garde de clé VAPID, mesurée dans un navigateur.
 *
 * ## Pourquoi ce spec existe
 *
 * Le correctif central de T1822 vit en JavaScript : comparer la clé RÉELLE
 * de l'abonnement (`options.applicationServerKey`) avant de conclure « déjà
 * abonné » et avant toute reconfirmation silencieuse. Les tests PHP ne
 * peuvent pas l'exercer — ils mesurent le serveur, pas l'exécution du
 * script.
 *
 * J'avais d'abord écrit qu'aucun banc JavaScript n'existait dans le dépôt :
 * c'était FAUX, et la correction vient de MASTER. `tests/e2e/` en contient
 * un, et `task-1745-first-run.spec.js` double déjà exactement ce qu'il faut
 * — Service Worker, permission, abonnement, réseau Push. Ce spec en reprend
 * le harnais, à une addition près : l'abonnement doublé porte désormais une
 * `options.applicationServerKey`, puisque c'est elle que la garde lit.
 *
 * ## Ce qui est doublé, et ce qui ne l'est pas
 *
 * Doublés : `matchMedia` (standalone n'est pas pilotable, leçon T1741),
 * `Notification.permission` (toujours `denied` en headless),
 * `serviceWorker.ready` et le réseau `/notifications/push`. RIEN de la
 * logique testée : c'est le bundle réel qui tourne, contre le serveur réel.
 *
 * Aucun abonnement fictif n'est écrit en base : POST et DELETE sont
 * interceptés, et le spec mesure ce que le client DEMANDE.
 */
test.describe('TASK-1822 — garde de clé VAPID au premier lancement', () => {
    // Sans ceci le vrai Service Worker contrôle la page et les `fetch` lui
    // échappent : les doublures réseau ne prendraient pas, et les POST
    // partiraient en base (mesuré en T1745).
    test.use({ serviceWorkers: 'block' });

    const BLOC = '[data-push-first-run]';
    const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/banc-e2e-1822';
    const ENDPOINT_RENOUVELE = 'https://fcm.googleapis.com/fcm/send/banc-e2e-1822-renouvele';

    /**
     * Les doublures, armées AVANT tout script de la page.
     *
     * `cleAbonnement` : la clé base64url que le NAVIGATEUR prétend détenir.
     * `null` simule un navigateur qui n'expose pas `applicationServerKey`
     * (cas `inobservable`) — il en existe.
     *
     * À appeler APRÈS `loginAsMember` : un init script s'applique à toutes
     * les navigations suivantes, et armé trop tôt il ferait tourner la
     * décision sur la page d'atterrissage du login (leçon T1745).
     */
    const armer = (page, options) =>
        page.addInitScript((opts) => {
            const originale = window.matchMedia.bind(window);
            window.matchMedia = (q) =>
                q.includes('display-mode: standalone')
                    ? { matches: true, media: q, onchange: null,
                        addEventListener() {}, removeEventListener() {},
                        addListener() {}, removeListener() {}, dispatchEvent: () => false }
                    : originale(q);

            Object.defineProperty(window.Notification, 'permission', {
                get: () => opts.permission,
                configurable: true,
            });

            // `requestPermission()` doit répondre sans prompt système :
            // le geste volontaire passe par elle.
            window.Notification.requestPermission = async () => opts.permission;

            /** base64url -> Uint8Array, la forme que le navigateur expose. */
            const enOctets = (base64url) => {
                const bourrage = '='.repeat((4 - (base64url.length % 4)) % 4);
                const brut = atob((base64url + bourrage).replace(/-/g, '+').replace(/_/g, '/'));

                return Uint8Array.from([...brut].map((c) => c.charCodeAt(0)));
            };

            const fabriquer = (endpoint, cle) => ({
                endpoint,
                options: cle === null ? {} : { applicationServerKey: enOctets(cle) },
                toJSON() {
                    return { endpoint: this.endpoint, keys: { p256dh: 'banc', auth: 'banc' } };
                },
                unsubscribe: async () => {
                    window.__bpDesabonnements = (window.__bpDesabonnements || 0) + 1;

                    return true;
                },
            });

            const abonnement = opts.endpoint ? fabriquer(opts.endpoint, opts.cleAbonnement) : null;

            window.__bpDesabonnements = 0;
            window.__bpAbonnementsCrees = 0;

            Object.defineProperty(navigator.serviceWorker, 'ready', {
                get: () =>
                    Promise.resolve({
                        pushManager: {
                            getSubscription: async () => (window.__bpDesabonnements > 0 ? null : abonnement),
                            // Un renouvellement produit un endpoint NEUF, comme
                            // le ferait un vrai navigateur.
                            subscribe: async () => {
                                window.__bpAbonnementsCrees += 1;

                                return fabriquer(opts.endpointRenouvele, opts.cleServeur);
                            },
                        },
                    }),
                configurable: true,
            });

            try {
                window.localStorage.removeItem('bpPushFirstRunSnoozedUntil');
            } catch (e) {}
        }, options);

    /**
     * Le serveur Push, doublé. Collecte les POST et les DELETE séparément,
     * et permet de forcer l'échec du DELETE (point 2 de la revue MASTER).
     */
    const doublerLeServeurPush = async (page, etat, journal, deleteStatus = 200) => {
        await page.route('**/notifications/push*', async (route) => {
            const requete = route.request();

            if (requete.method() === 'POST') {
                journal.posts.push(requete.postDataJSON());

                return route.fulfill({ json: { subscribed: true, device_count: 1 } });
            }

            if (requete.method() === 'DELETE') {
                journal.deletes.push(requete.postDataJSON());

                if (deleteStatus === 0) {
                    // Panne RÉSEAU, pas un code HTTP : `fetch` lève.
                    return route.abort('failed');
                }

                return route.fulfill({
                    status: deleteStatus,
                    json: { subscribed: false, removed: deleteStatus === 200 ? 1 : 0 },
                });
            }

            return route.fulfill({ json: etat });
        });
    };

    const attendreLaDecision = async (page) => {
        await expect(page.locator(BLOC)).toHaveAttribute('data-push-first-run-etat', /.+/);

        return page.locator(BLOC).getAttribute('data-push-first-run-etat');
    };

    /**
     * La clé VAPID que le SERVEUR sert réellement, lue dans le DOM.
     *
     * Jamais codée en dur : un test qui RE-DÉRIVE la valeur qu'il vérifie ne
     * vérifie rien. On la demande à la page, comme le script le fait.
     */
    const cleDuServeur = async (page) => {
        await page.goto('/boucles');

        const cle = await page.locator(BLOC).getAttribute('data-push-vapid');

        expect(cle, 'le serveur doit servir une clé VAPID publique').toBeTruthy();

        return cle;
    };

    const journalNeuf = () => ({ posts: [], deletes: [] });

    /**
     * Une clé GARANTIE différente de celle du serveur, dérivée d'elle.
     *
     * Une constante codée en dur ne vaut rien ici : mon premier jet avait
     * recopié la clé du `.env` local en croyant écrire une clé étrangère —
     * elle CONCORDAIT, et les quatre tests de divergence mesuraient le
     * contraire de ce qu'ils annonçaient. On dérive donc, pour que la
     * divergence soit vraie dans n'importe quel environnement.
     *
     * Même longueur et même alphabet : la chaîne reste décodable, donc la
     * comparaison porte bien sur la VALEUR et non sur une forme invalide.
     */
    const cleDivergenteDe = (cle) => cle.slice(0, -4) + (cle.endsWith('AAAA') ? 'BBBB' : 'AAAA');

    // ═══════════════════════════════════════════════════════════════════
    // 1. Clé CONCORDANTE — rien ne change
    // ═══════════════════════════════════════════════════════════════════

    test('clé concordante et serveur qui connaît l’abonnement : silence, aucune reconfirmation inutile', async ({ page }) => {
        await loginAsMember(page);
        const journal = journalNeuf();
        await doublerLeServeurPush(page, { enabled: true, subscribed: true, device_count: 1 }, journal);

        const cle = await cleDuServeur(page);
        await armer(page, { permission: 'granted', endpoint: ENDPOINT, cleAbonnement: cle, cleServeur: cle, endpointRenouvele: ENDPOINT_RENOUVELE });
        await page.reload();

        expect(await attendreLaDecision(page)).toBe('deja-abonne');
        await expect(page.locator(BLOC)).toBeHidden();
        expect(journal.deletes, 'aucun retrait ne doit partir sur une clé concordante').toHaveLength(0);
    });

    test('clé concordante mais serveur qui l’ignore : la resynchronisation silencieuse reste permise', async ({ page }) => {
        await loginAsMember(page);
        const journal = journalNeuf();
        await doublerLeServeurPush(page, { enabled: true, subscribed: false, device_count: 0 }, journal);

        const cle = await cleDuServeur(page);
        await armer(page, { permission: 'granted', endpoint: ENDPOINT, cleAbonnement: cle, cleServeur: cle, endpointRenouvele: ENDPOINT_RENOUVELE });
        await page.reload();

        expect(await attendreLaDecision(page)).toBe('resynchronise');
        expect(journal.posts, 'la réconciliation T1739 doit continuer de fonctionner').toHaveLength(1);
        expect(journal.posts[0].endpoint).toBe(ENDPOINT);
    });

    // ═══════════════════════════════════════════════════════════════════
    // 2. Clé DIVERGENTE — le cœur du correctif
    // ═══════════════════════════════════════════════════════════════════

    test('clé divergente : ni « déjà abonné », ni reconfirmation silencieuse', async ({ page }) => {
        await loginAsMember(page);
        const journal = journalNeuf();

        // Le serveur AFFIRME que l'abonnement est connu : avant T1822, cela
        // suffisait à conclure « déjà abonné » et à se taire.
        await doublerLeServeurPush(page, { enabled: true, subscribed: true, device_count: 1 }, journal);

        const cle = await cleDuServeur(page);
        await armer(page, {
            permission: 'granted',
            endpoint: ENDPOINT,
            // Une clé VALIDE dans sa forme, mais ce n'est pas celle du serveur.
            cleAbonnement: cleDivergenteDe(cle),
            cleServeur: cle,
            endpointRenouvele: ENDPOINT_RENOUVELE,
        });
        await page.reload();

        expect(await attendreLaDecision(page)).toBe('cle-perimee');

        // Le défaut mesuré en T1821 : la reconfirmation re-stampait le
        // serveur et effaçait la divergence. Elle ne doit PAS partir.
        expect(journal.posts, 'aucune reconfirmation silencieuse sur clé divergente').toHaveLength(0);

        // Et la personne se voit proposer un geste, avec le bon mot.
        const bloc = page.locator(BLOC);
        await expect(bloc).toBeVisible();
        await expect(bloc.locator('[data-push-first-run-body]')).toContainText(/n’a plus cours|no longer in use/);
        await expect(bloc.locator('[data-push-first-run-accept]')).toContainText(/Réactiver|Reactivate/);
    });

    // ═══════════════════════════════════════════════════════════════════
    // 3. Clé INOBSERVABLE — aucune régression silencieuse
    // ═══════════════════════════════════════════════════════════════════

    test('clé non observable : le comportement d’avant est conservé, sans concordance inventée', async ({ page }) => {
        await loginAsMember(page);
        const journal = journalNeuf();
        await doublerLeServeurPush(page, { enabled: true, subscribed: false, device_count: 0 }, journal);

        const cle = await cleDuServeur(page);
        await armer(page, {
            permission: 'granted',
            endpoint: ENDPOINT,
            // Le navigateur n'expose PAS sa clé.
            cleAbonnement: null,
            cleServeur: cle,
            endpointRenouvele: ENDPOINT_RENOUVELE,
        });
        await page.reload();

        // On ne casse pas un abonnement qui fonctionne peut-être très bien :
        // `inobservable` n'est pas `diverge`.
        expect(await attendreLaDecision(page)).toBe('resynchronise');
        expect(journal.posts).toHaveLength(1);
        expect(journal.deletes, 'aucun retrait sur une clé qu’on ne peut pas juger').toHaveLength(0);
    });

    // ═══════════════════════════════════════════════════════════════════
    // 4. Réactivation VOLONTAIRE — renouvellement propre
    // ═══════════════════════════════════════════════════════════════════

    test('réactivation volontaire : retrait serveur, désabonnement, puis NOUVEL abonnement', async ({ page }) => {
        await loginAsMember(page);
        const journal = journalNeuf();
        await doublerLeServeurPush(page, { enabled: true, subscribed: true, device_count: 1 }, journal);

        const cle = await cleDuServeur(page);
        await armer(page, {
            permission: 'granted',
            endpoint: ENDPOINT,
            cleAbonnement: cleDivergenteDe(cle),
            cleServeur: cle,
            endpointRenouvele: ENDPOINT_RENOUVELE,
        });
        await page.reload();

        expect(await attendreLaDecision(page)).toBe('cle-perimee');

        await page.locator('[data-push-first-run-accept]').click();

        await expect(page.locator(BLOC)).toHaveAttribute('data-push-first-run-etat', 'active-a-l-instant');

        // L'ORDRE, mesuré : retrait serveur d'abord…
        expect(journal.deletes, 'le retrait serveur doit partir AVANT le désabonnement').toHaveLength(1);
        expect(journal.deletes[0].endpoint).toBe(ENDPOINT);

        // …puis le désabonnement navigateur…
        expect(await page.evaluate(() => window.__bpDesabonnements)).toBe(1);

        // …puis UN nouvel abonnement, confirmé sous son NOUVEL endpoint.
        expect(await page.evaluate(() => window.__bpAbonnementsCrees)).toBe(1);
        expect(journal.posts).toHaveLength(1);
        expect(journal.posts[0].endpoint).toBe(ENDPOINT_RENOUVELE);
    });

    // ═══════════════════════════════════════════════════════════════════
    // 5. Le retrait serveur qui ÉCHOUE ne doit pas désabonner le navigateur
    // ═══════════════════════════════════════════════════════════════════

    for (const statut of [403, 419, 500]) {
        test(`un DELETE en ${statut} ne désabonne PAS le navigateur`, async ({ page }) => {
            await loginAsMember(page);
            const journal = journalNeuf();
            await doublerLeServeurPush(page, { enabled: true, subscribed: true, device_count: 1 }, journal, statut);

            const cle = await cleDuServeur(page);
            await armer(page, {
                permission: 'granted',
                endpoint: ENDPOINT,
                cleAbonnement: cleDivergenteDe(cle),
                cleServeur: cle,
                endpointRenouvele: ENDPOINT_RENOUVELE,
            });
            await page.reload();

            expect(await attendreLaDecision(page)).toBe('cle-perimee');
            await page.locator('[data-push-first-run-accept]').click();

            // Le retrait a bien été TENTÉ…
            await expect.poll(() => journal.deletes.length).toBe(1);

            // …mais il a échoué : le navigateur garde son abonnement, et
            // aucun nouveau n'est créé. Sans cette garde, le serveur
            // conserverait une ligne pour un endpoint que plus personne ne
            // détient — un appareil injoignable qu'on croit abonné.
            expect(await page.evaluate(() => window.__bpDesabonnements)).toBe(0);
            expect(await page.evaluate(() => window.__bpAbonnementsCrees)).toBe(0);
            expect(journal.posts, 'rien ne doit être confirmé après un retrait en échec').toHaveLength(0);
        });
    }

    test('une PANNE RÉSEAU sur le DELETE ne désabonne pas davantage', async ({ page }) => {
        await loginAsMember(page);
        const journal = journalNeuf();
        // `0` : la route est AVORTÉE — `fetch` lève, au lieu de rendre un
        // code. Les deux chemins doivent protéger l'abonnement.
        await doublerLeServeurPush(page, { enabled: true, subscribed: true, device_count: 1 }, journal, 0);

        const cle = await cleDuServeur(page);
        await armer(page, {
            permission: 'granted',
            endpoint: ENDPOINT,
            cleAbonnement: cleDivergenteDe(cle),
            cleServeur: cle,
            endpointRenouvele: ENDPOINT_RENOUVELE,
        });
        await page.reload();

        expect(await attendreLaDecision(page)).toBe('cle-perimee');
        await page.locator('[data-push-first-run-accept]').click();

        await expect.poll(() => journal.deletes.length).toBe(1);
        expect(await page.evaluate(() => window.__bpDesabonnements)).toBe(0);
        expect(await page.evaluate(() => window.__bpAbonnementsCrees)).toBe(0);
        expect(journal.posts).toHaveLength(0);
    });
});
