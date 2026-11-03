import { test, expect } from '@playwright/test';

/**
 * TASK-1745 — le bandeau installe SANS passer par l'écran dédié.
 *
 * Le contrat HTML est mesuré côté PHP (`TASK1745PwaInstallShortcutTest`) :
 * l'attribut est là, le `href` aussi. Ce que PHP ne peut pas voir, c'est ce
 * qui se passe AU CLIC — et c'est tout l'objet de la TASK.
 *
 * Deux comportements opposés, et le même élément :
 *
 * - `beforeinstallprompt` capturé → le clic appelle `prompt()` et
 *   **ne navigue pas** ;
 * - aucun évènement → le clic **navigue** vers l'écran dédié, exactement
 *   comme avant.
 *
 * Le second est le plus important à garder : c'est le parcours d'iOS et de
 * tout navigateur sans prompt natif. Un raccourci qui l'aurait cassé aurait
 * coûté plus qu'il ne rapporte, et aurait été invisible depuis un Chromium.
 */
test.describe('TASK-1745 — raccourci d’installation', () => {
    const TELEPHONE = {
        userAgent:
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36',
        viewport: { width: 412, height: 915 },
    };

    test.use(TELEPHONE);

    test('avec le prompt natif, le clic installe et ne navigue PAS', async ({ page }) => {
        // On arme une doublure de `beforeinstallprompt` AVANT tout script de
        // la page : le composant l'écoute dès son exécution, et un évènement
        // émis après coup arriverait trop tard.
        //
        // La doublure est volontairement minimale — `preventDefault`,
        // `prompt()`, `userChoice` — parce que c'est exactement la surface
        // que le composant utilise. Lui en donner plus ferait croire qu'on
        // teste le navigateur, ce qui n'est pas le cas.
        await page.addInitScript(() => {
            window.__promptAppele = 0;

            window.addEventListener('load', () => {
                const e = new Event('beforeinstallprompt');
                e.prompt = () => {
                    window.__promptAppele += 1;
                };
                e.userChoice = Promise.resolve({ outcome: 'dismissed' });
                window.dispatchEvent(e);
            });
        });

        await page.goto('/about');
        await page.waitForLoadState('networkidle');

        const bandeau = page.locator('[data-bp-pwa-install="banner"]');
        await expect(bandeau).toBeVisible();

        const urlAvant = page.url();
        await bandeau.locator('[data-bp-pwa-action="install"]').click();
        await page.waitForTimeout(400);

        expect(await page.evaluate(() => window.__promptAppele), 'prompt() doit avoir ete appele').toBe(1);
        expect(page.url(), "le clic ne doit pas quitter la page").toBe(urlAvant);
    });

    test('sans prompt natif, le clic mene a l ecran dedie', async ({ page }) => {
        // Le repli, et il n'est pas secondaire : c'est le seul parcours
        // possible sur iOS. Aucun évènement n'est émis ici — le lien doit
        // donc se comporter en lien.
        await page.goto('/about');
        await page.waitForLoadState('networkidle');

        const bandeau = page.locator('[data-bp-pwa-install="banner"]');
        await expect(bandeau).toBeVisible();

        await bandeau.locator('[data-bp-pwa-action="install"]').click();
        await page.waitForLoadState('domcontentloaded');

        expect(page.url()).toContain('/installer');
        await expect(page.locator('[data-bp-pwa-install="full"]')).toBeVisible();
    });

    test('un accord retire le bandeau, sans poser de renoncement', async ({ page }) => {
        // Installer n'est pas refuser. Le cookie de renoncement ferait
        // disparaitre la proposition sur TOUT le site pour quelqu'un qui
        // vient justement de l'accepter — et il n'y a pas de retour.
        await page.addInitScript(() => {
            window.addEventListener('load', () => {
                const e = new Event('beforeinstallprompt');
                e.prompt = () => {};
                e.userChoice = Promise.resolve({ outcome: 'accepted' });
                window.dispatchEvent(e);
            });
        });

        await page.goto('/about');
        await page.waitForLoadState('networkidle');

        await page.locator('[data-bp-pwa-install="banner"] [data-bp-pwa-action="install"]').click();

        await expect(page.locator('[data-bp-pwa-install="banner"]')).toHaveCount(0);

        const cookies = await page.context().cookies();
        expect(
            cookies.map((c) => c.name).filter((n) => n.includes('pwa')),
            'aucun cookie de renoncement apres un accord',
        ).toEqual([]);
    });
});
