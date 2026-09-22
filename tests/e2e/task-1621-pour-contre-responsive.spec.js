import { test, expect } from '@playwright/test';
import { login } from './helpers/auth.js';

/**
 * TASK-1621 — le gate responsive de « Pour / Contre ».
 *
 * Le redimensionnement de fenetre du MCP ne change PAS le viewport rendu : il
 * restait a 1920, et trois TASKs ont annonce « responsive verifie » sur cette
 * non-mesure. Seul `test.use({ viewport })` le change, et la garde ci-dessous
 * mesure le gate lui-meme.
 */
const MOBILE = { width: 390, height: 844 };
const BUREAU = { width: 1440, height: 900 };

const LOOP = '01a09a4b-403c-71f1-b804-f19263ec6e2b';

async function ouvrirLaBoucle(page) {
    await login(page, process.env.TEST_ADMIN_LOGIN, process.env.TEST_ADMIN_PASSWORD);
    await page.goto(`/loops/${LOOP}`);
    await page.waitForLoadState('networkidle');
}

test.describe('mobile 390 px', () => {
    test.use({ viewport: MOBILE });

    test('les modes sont VISIBLES et defilent, le + se voit', async ({ page }) => {
        // La garde du gate : sans elle, le test passerait a 1920.
        expect(await page.evaluate(() => window.innerWidth)).toBeLessThan(768);

        await ouvrirLaBoucle(page);

        const pourContre = page.locator('[data-multi-ai-toggle]:visible').first();
        await expect(pourContre).toBeVisible();

        // Une pastille qui se replie en colonne est deformee : sa hauteur
        // trahit le defaut mieux que l'oeil. Une pastille sur UNE ligne fait
        // ~30 px ; repliee sur trois, elle depasse 50.
        const boite = await pourContre.boundingBox();
        expect(boite.height).toBeLessThan(48);

        // La rangee defile horizontalement au lieu d'empiler.
        const rangee = page.locator('div.overflow-x-auto:has([data-multi-ai-toggle])').first();
        await expect(rangee).toBeVisible();
        const debordement = await rangee.evaluate((el) => ({
            defile: el.scrollWidth > el.clientWidth + 1,
            hauteur: el.getBoundingClientRect().height,
        }));
        expect(debordement.hauteur).toBeLessThan(64); // une seule ligne

        // Le bouton du menu `+` doit se VOIR : c'est la seule porte vers
        // l'ajout d'image et « Qui peut m'aider » sur mobile.
        const plus = page.locator('button[aria-haspopup="true"]:visible').first();
        await expect(plus).toBeVisible();
        const fond = await plus.evaluate((el) => getComputedStyle(el).backgroundColor);
        expect(fond).not.toBe('rgba(0, 0, 0, 0)');

        // Le raccourci IA muet a disparu.
        await expect(page.locator('[data-engine-quick]')).toHaveCount(0);

        // Et le mode s'arme sans rien generer.
        await pourContre.click();
        await expect(pourContre).toHaveAttribute('aria-pressed', 'true');
        await expect(page.locator('[data-composer-mode]')).toHaveCount(0);
    });

    test('une bulle Pour ou Contre reste lisible sans debordement', async ({ page }) => {
        expect(await page.evaluate(() => window.innerWidth)).toBeLessThan(768);
        await ouvrirLaBoucle(page);

        const bulles = page.locator('[data-ai-mode="multi_ai"]');
        const n = await bulles.count();
        // Non vacuite : sans bulle, ce test ne mesurerait rien.
        expect(n, 'la Boucle de recette doit porter des bulles Pour / Contre').toBeGreaterThan(0);

        // Le corps de la page ne defile jamais horizontalement.
        const deborde = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
        expect(deborde).toBe(false);
    });
});

test.describe('bureau 1440 px', () => {
    test.use({ viewport: BUREAU });

    test('la rangee revient au retour a la ligne', async ({ page }) => {
        expect(await page.evaluate(() => window.innerWidth)).toBeGreaterThan(1024);
        await ouvrirLaBoucle(page);

        const rangee = page.locator('div:has(> [data-multi-ai-actions])').first();
        await expect(page.locator('[data-multi-ai-toggle]:visible').first()).toBeVisible();

        const deborde = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
        expect(deborde).toBe(false);
    });
});
