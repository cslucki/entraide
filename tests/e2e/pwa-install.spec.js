// TASK-1737 — l'ecran d'installation, dans un vrai navigateur.
//
// Ce que les tests PHP ne peuvent pas voir : la CSS appliquee, le bouton
// reellement visible ou non, le cookie pose par le navigateur, la touche
// Echap. Les tests feature mesurent le HTML EMIS ; ceux-ci mesurent ce que
// quelqu'un VOIT.
//
// Les dialogues natifs d'installation (Chrome, iOS) restent hors de portee de
// toute automatisation : ils appartiennent au systeme. La recette sur appareil
// reel, consignee dans le TASK file, est la seule a pouvoir les couvrir.
//
// Usage : npx playwright test tests/e2e/pwa-install.spec.js

import { test, expect } from '@playwright/test';

const UA_TELEPHONE =
    'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';

test.describe('TASK-1737 ecran d\'installation', () => {
    test.use({
        viewport: { width: 390, height: 844 },
        deviceScaleFactor: 2,
        isMobile: true,
        hasTouch: true,
        // `DeviceKind` lit l'user-agent cote serveur : `isMobile` seul ne le
        // change pas, et le balisage ne serait pas emis.
        userAgent: UA_TELEPHONE,
    });

    test('l\'ecran plein format s\'affiche sur /installer', async ({ page }) => {
        await page.goto('/installer');

        const ecran = page.locator('[data-bp-pwa-install="full"]');
        await expect(ecran).toBeVisible();

        // Le repli du mode installe, lui, reste masque dans un onglet.
        await expect(page.locator('.bp-installer-fallback')).toBeHidden();
    });

    test('aucun bouton d\'installation tant qu\'aucun prompt n\'existe', async ({ page }) => {
        await page.goto('/installer');

        // CDC 3.3 : « ne jamais afficher un bouton qui ne fonctionne pas ».
        // Chromium sans critere d'installabilite n'emet pas
        // `beforeinstallprompt` : le bouton doit rester invisible.
        await expect(page.locator('.bp-pwa__cta')).toBeHidden();

        // Ce sont les instructions de la plateforme qui tiennent l'ecran.
        await expect(page.locator('[data-bp-pwa-platform="generic"]')).toBeVisible();
    });

    test('le bouton apparait quand un prompt existe reellement', async ({ page }) => {
        await page.goto('/installer');
        await expect(page.locator('.bp-pwa__cta')).toBeHidden();

        // On simule l'evenement que le navigateur emettrait s'il jugeait la
        // page installable. On ne simule PAS l'installation elle-meme : elle
        // appartient au systeme.
        await page.evaluate(() => {
            const evenement = new Event('beforeinstallprompt');
            evenement.prompt = () => Promise.resolve();
            evenement.userChoice = Promise.resolve({ outcome: 'dismissed' });
            window.dispatchEvent(evenement);
        });

        await expect(page.locator('.bp-pwa__cta')).toBeVisible();

        // Et les instructions manuelles s'effacent : une seule voie a la fois.
        await expect(page.locator('.bp-pwa__manual')).toBeHidden();
    });

    test('« Continuer dans le navigateur » ferme, et ne revient pas', async ({ page, context }) => {
        await page.goto('/installer');
        await expect(page.locator('[data-bp-pwa-install="full"]')).toBeVisible();

        await page.locator('[data-bp-pwa-action="dismiss"]').click();
        await expect(page.locator('[data-bp-pwa-install]')).toHaveCount(0);

        // Le cookie est pose EN CLAIR : c'est le serveur qui doit pouvoir le
        // lire, et c'est pour cela qu'il est exempte de `EncryptCookies`.
        const cookies = await context.cookies();
        const renoncement = cookies.find((c) => c.name === 'bp_pwa_dismissed');
        expect(renoncement).toBeTruthy();
        expect(renoncement.value).toBe('1');

        // Le vrai test du renoncement : trois navigations plus tard, rien.
        for (const chemin of ['/about', '/org/main', '/installer']) {
            await page.goto(chemin);
            await expect(page.locator('[data-bp-pwa-install]')).toHaveCount(0);
        }
    });

    test('le bandeau s\'affiche sur une vitrine, et mene a l\'ecran dedie', async ({ page }) => {
        await page.goto('/about');

        const bandeau = page.locator('[data-bp-pwa-install="banner"]');
        await expect(bandeau).toBeVisible();

        // Non bloquant : le contenu de la page reste atteignable derriere.
        await expect(page.locator('body')).toBeVisible();

        await bandeau.locator('a.bp-pwa__bar-action').click();
        await expect(page).toHaveURL(/\/installer$/);
        await expect(page.locator('[data-bp-pwa-install="full"]')).toBeVisible();
    });

    test('une application installee ne voit jamais l\'ecran', async ({ page }) => {
        await page.goto('/installer');
        await expect(page.locator('[data-bp-pwa-install="full"]')).toBeVisible();

        // La media query `display-mode: standalone` n'est pas pilotable depuis
        // Playwright. On exerce donc la CEINTURE — le chemin
        // `navigator.standalone` des iOS anciens — qui pose le meme attribut et
        // declenche la meme regle CSS.
        await page.evaluate(() => {
            document.documentElement.setAttribute('data-bp-standalone', '1');
        });

        await expect(page.locator('[data-bp-pwa-install="full"]')).toBeHidden();
    });

    test('Echap ferme l\'ecran plein format', async ({ page }) => {
        await page.goto('/installer');
        await expect(page.locator('[data-bp-pwa-install="full"]')).toBeVisible();

        await page.keyboard.press('Escape');
        await expect(page.locator('[data-bp-pwa-install]')).toHaveCount(0);
    });

    test('le focus part sur le titre et ne sort pas de l\'ecran', async ({ page }) => {
        await page.goto('/installer');

        // Le lecteur d'ecran doit annoncer le dialogue, pas la page derriere.
        await expect(page.locator('#bp-pwa-title')).toBeFocused();

        // La tabulation reste DANS le dialogue tant qu'il couvre la page.
        await page.keyboard.press('Tab');
        const dansLEcran = await page.evaluate(() =>
            !!document.activeElement?.closest('[data-bp-pwa-install]')
        );
        expect(dansLEcran).toBe(true);
    });

    test('aucune entree HTML privee n\'entre dans le Cache Storage', async ({ page }) => {
        // Garde de non-regression TASK-1692, exercee dans un vrai navigateur :
        // le Service Worker est enregistre sur ces pages depuis cette TASK, et
        // il ne doit toujours mettre en cache que des assets statiques.
        await page.goto('/org/main');
        await page.goto('/about');
        await page.goto('/installer');

        const entrees = await page.evaluate(async () => {
            if (!('caches' in window)) { return []; }
            const noms = await caches.keys();
            const toutes = [];
            for (const nom of noms) {
                const cache = await caches.open(nom);
                for (const requete of await cache.keys()) {
                    toutes.push(requete.url);
                }
            }
            return toutes;
        });

        for (const url of entrees) {
            const chemin = new URL(url).pathname;
            expect(
                /\.(js|css|png|jpe?g|gif|svg|ico|webp|woff2?)$|^\/site\.webmanifest$/.test(chemin),
                `Le Cache Storage contient [${chemin}], qui n'est pas un asset statique.`
            ).toBe(true);
        }
    });
});
