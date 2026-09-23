import { test, expect } from '@playwright/test';
import { login } from './helpers/auth.js';

/**
 * TASK-1625 — la navigation mobile, MESUREE.
 *
 * Le redimensionnement de fenetre du MCP ne change PAS le viewport rendu : il
 * reste a 1920. Trois TASKs ont annonce « responsive verifie » sur cette
 * non-mesure (cf. l'en-tete de `task-1621-pour-contre-responsive.spec.js`).
 * Seul `test.use({ viewport })` le change, et la premiere assertion de chaque
 * bloc mesure le gate lui-meme avant de mesurer quoi que ce soit d'autre.
 */
const LARGEURS = [320, 375, 390, 430];

async function ouvrir(page) {
    await login(page, process.env.TEST_MEMBER1_LOGIN, process.env.TEST_MEMBER1_PASSWORD);
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');
}

for (const largeur of LARGEURS) {
    test.describe(`mobile ${largeur} px`, () => {
        test.use({ viewport: { width: largeur, height: 844 } });

        test('la barre defile, ne replie pas, et reste sous le pouce', async ({ page }) => {
            expect(await page.evaluate(() => window.innerWidth)).toBeLessThan(768);

            await ouvrir(page);

            const piste = page.locator('[data-bp-mobile-nav-track]');
            await expect(piste).toBeVisible();

            const mesure = await piste.evaluate((el) => {
                const enfants = [...el.querySelectorAll('[data-bp-nav-item]')];
                const hauts = new Set(enfants.map((e) => Math.round(e.getBoundingClientRect().top)));

                return {
                    defile: el.scrollWidth > el.clientWidth + 1,
                    // Une seule ordonnee = une seule rangee. C'est la mesure
                    // qui distingue « defile » de « se replie ».
                    rangees: hauts.size,
                    nbOnglets: enfants.length,
                    plusPetiteCible: Math.min(...enfants.map((e) => {
                        const r = e.getBoundingClientRect();
                        return Math.min(r.width, r.height);
                    })),
                    // La barre ne doit jamais pousser la PAGE de cote.
                    debordementPage: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                };
            });

            expect(mesure.nbOnglets).toBeGreaterThanOrEqual(6);
            expect(mesure.rangees).toBe(1);
            expect(mesure.defile).toBe(true);
            // 44 px : la cible tactile en dessous de laquelle le doigt rate.
            expect(mesure.plusPetiteCible).toBeGreaterThanOrEqual(44);
            expect(mesure.debordementPage).toBeLessThanOrEqual(0);
        });

        test('le premier ET le dernier onglet sont atteignables', async ({ page }) => {
            await ouvrir(page);

            const piste = page.locator('[data-bp-mobile-nav-track]');
            const onglets = page.locator('[data-bp-nav-item]');
            const dernier = onglets.last();

            await dernier.scrollIntoViewIfNeeded();
            await expect(dernier).toBeInViewport();

            await piste.evaluate((el) => { el.scrollLeft = 0; });
            await expect(onglets.first()).toBeInViewport();
        });

        test('aucune barre de defilement disgracieuse', async ({ page }) => {
            await ouvrir(page);

            const hauteurBarre = await page.locator('[data-bp-mobile-nav-track]')
                .evaluate((el) => el.offsetHeight - el.clientHeight);

            // Une scrollbar visible mangerait de la hauteur sous les libelles.
            expect(hauteurBarre).toBe(0);
        });

        test('le menu Avatar passe DEVANT les boutons flottants et la barre basse', async ({ page }) => {
            await ouvrir(page);

            await page.locator('[aria-label]').filter({ has: page.locator('img') }).first().click();

            const menu = page.locator('header [x-show="open"]').first();
            await expect(menu).toBeVisible();

            // La mesure qui compte : au CENTRE du menu, quel element le
            // navigateur designe-t-il ? Si un FAB ou la barre basse repond, le
            // menu est derriere — c'etait le defaut.
            const auPremierPlan = await menu.evaluate((el) => {
                const r = el.getBoundingClientRect();
                const cible = document.elementFromPoint(
                    Math.round(r.left + r.width / 2),
                    Math.round(r.top + Math.min(r.height, window.innerHeight - r.top) / 2),
                );

                return {
                    dansLeMenu: el.contains(cible),
                    // Le menu ne doit pas deborder du bas de l'ecran.
                    basVisible: Math.round(r.bottom) <= window.innerHeight + 1,
                    defileEnInterne: el.scrollHeight > el.clientHeight + 1,
                    hauteur: Math.round(r.height),
                };
            });

            expect(auPremierPlan.dansLeMenu).toBe(true);
            expect(auPremierPlan.basVisible).toBe(true);
            // S'il est plus grand que la place disponible, il defile LUI-MEME,
            // sinon ses dernieres entrees sont inatteignables.
            if (auPremierPlan.hauteur >= window.innerHeight - 80) {
                expect(auPremierPlan.defileEnInterne).toBe(true);
            }
        });

        test('les Notifications sont a un seul tap dans le header', async ({ page }) => {
            await ouvrir(page);

            const cloche = page.locator('[data-mobile-topbar-notifications]');
            await expect(cloche).toBeVisible();

            const boite = await cloche.boundingBox();
            expect(Math.min(boite.width, boite.height)).toBeGreaterThanOrEqual(34);

            await cloche.click();
            await page.waitForLoadState('domcontentloaded');
            expect(page.url()).toContain('/notifications');
        });
    });
}

test.describe('sombre a 390 px', () => {
    test.use({ viewport: { width: 390, height: 844 }, colorScheme: 'dark' });

    test('la barre et le header restent lisibles en mode sombre', async ({ page }) => {
        await ouvrir(page);

        await page.evaluate(() => document.documentElement.classList.add('dark'));

        const contrastes = await page.locator('[data-bp-mobile-nav]').evaluate((el) => {
            const fond = getComputedStyle(el).backgroundColor;
            const actif = el.querySelector('[data-bp-nav-active="true"] span:last-child');

            return { fond, couleurActive: actif ? getComputedStyle(actif).color : null };
        });

        // Un fond transparent laisserait le contenu de la page traverser la
        // barre : c'est le defaut le plus visible en sombre.
        expect(contrastes.fond).not.toBe('rgba(0, 0, 0, 0)');
        expect(contrastes.couleurActive).not.toBeNull();
    });
});

test.describe('bureau 1440 px — aucune regression', () => {
    test.use({ viewport: { width: 1440, height: 900 } });

    test('la barre mobile et le header mobile restent caches', async ({ page }) => {
        await ouvrir(page);

        await expect(page.locator('[data-bp-mobile-nav]')).toBeHidden();
        await expect(page.locator('[data-mobile-topbar-notifications]')).toBeHidden();
        // Le rail, lui, est bien la.
        await expect(page.locator('aside').first()).toBeVisible();
    });
});
