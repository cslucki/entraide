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

    /**
     * Un accord ne fait pas DISPARAITRE le bandeau : il l'explique.
     *
     * Ma premiere version le retirait. Elle avait tort, et le defaut
     * rapporte par Cyril le dit : apres avoir accepte le dialogue natif, la
     * personne reste dans son navigateur et ne comprend pas qu'elle doit
     * maintenant ouvrir l'application depuis son telephone. Disparaitre,
     * c'est precisement ne rien expliquer.
     */
    test('un accord REMPLACE le bandeau par une confirmation pedagogique', async ({ page }) => {
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

        const confirmation = page.locator('[data-bp-pwa-confirm]');
        await expect(confirmation).toBeVisible();

        // Ce qui doit TOUJOURS etre dit : ou on en est, et ou aller.
        await expect(confirmation).toContainText(/s’installe|is installing/);
        await expect(confirmation).toContainText(/encore dans votre navigateur|still in your browser/);

        // La troisieme phrase — « BouclePro vous proposera d'activer les
        // notifications » — est CONDITIONNELLE : elle ne s'affiche que si le
        // catalogue autorise reellement le canal (CDC 3.2). Un banc
        // navigateur ne peut pas interroger le catalogue ; cette moitie-la
        // est donc mesuree cote PHP, dans
        // `TASK1745PwaInstallShortcutTest::test_la_note_sur_les_notifications_suit_le_CATALOGUE`.

        // Et le bandeau d'invitation a cede la place — pas deux messages a la
        // fois, sinon on propose encore ce qui vient d'etre accepte.
        await expect(
            page.locator('[data-bp-pwa-install="banner"] [data-bp-pwa-action="install"]'),
        ).toBeHidden();

        const cookies = await page.context().cookies();
        expect(
            cookies.map((c) => c.name).filter((n) => n.includes('pwa')),
            'aucun cookie de renoncement apres un accord',
        ).toEqual([]);
    });

    test('un REFUS ne declenche aucune confirmation', async ({ page }) => {
        // Point 5. Expliquer comment ouvrir une application qu'on vient de
        // ne PAS installer serait absurde, et donnerait l'impression que le
        // refus n'a pas ete entendu.
        await page.addInitScript(() => {
            window.addEventListener('load', () => {
                const e = new Event('beforeinstallprompt');
                e.prompt = () => {};
                e.userChoice = Promise.resolve({ outcome: 'dismissed' });
                window.dispatchEvent(e);
            });
        });

        await page.goto('/about');
        await page.waitForLoadState('networkidle');

        await page.locator('[data-bp-pwa-install="banner"] [data-bp-pwa-action="install"]').click();
        await page.waitForTimeout(400);

        await expect(page.locator('[data-bp-pwa-confirm]')).toBeHidden();

        // Et la proposition reste offerte : un refus du dialogue natif n'est
        // pas un refus de BouclePro.
        await expect(
            page.locator('[data-bp-pwa-install="banner"] [data-bp-pwa-action="install"]'),
        ).toBeVisible();
    });

    test('appinstalled confirme aussi, et sans promettre plus', async ({ page }) => {
        // Point 4. `appinstalled` est le signal FORT — le systeme a pose
        // l'icone — mais il n'est pas emis partout. Le texte ne promet donc
        // jamais plus que le plus faible des deux signaux : « s'installe »,
        // jamais « est installe ». Meme doctrine qu'en T1740 : un accuse de
        // reception n'est pas une livraison.
        await page.goto('/about');
        await page.waitForLoadState('networkidle');

        await expect(page.locator('[data-bp-pwa-confirm]')).toBeHidden();

        await page.evaluate(() => window.dispatchEvent(new Event('appinstalled')));

        await expect(page.locator('[data-bp-pwa-confirm]')).toBeVisible();
        await expect(page.locator('[data-bp-pwa-confirm]')).not.toContainText(/est installé|is installed/);
    });
});
