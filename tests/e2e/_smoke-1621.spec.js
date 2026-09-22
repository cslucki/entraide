import { test, expect } from '@playwright/test';
import { login } from './helpers/auth.js';

// Smoke JETABLE : il emet de VRAIS appels provider. Il n'a pas vocation a
// entrer dans la suite — un banc qui depense du quota a chaque CI n'est pas
// un banc, c'est une facture.
test.use({ viewport: { width: 1440, height: 900 } });
test.setTimeout(240_000);

const LOOP = '01a09a4b-403c-71f1-b804-f19263ec6e2b';

for (const question of ['Windows ou Linux, que choisir ?', 'Quel CMS choisir ?']) {
    test(`smoke UI — ${question}`, async ({ page }) => {
        await login(page, process.env.TEST_ADMIN_LOGIN, process.env.TEST_ADMIN_PASSWORD);
        await page.goto(`/loops/${LOOP}`);
        await page.waitForLoadState('networkidle');

        const avant = await page.locator('[data-ai-mode="multi_ai"]').count();

        // Le geste du membre : armer, ecrire, envoyer.
        const bouton = page.locator('[data-multi-ai-toggle]:visible').first();
        await bouton.click();
        await expect(bouton).toHaveAttribute('aria-pressed', 'true');

        const champ = page.locator('textarea:visible').first();
        await champ.fill(question);
        await champ.press('Enter');

        // 1. Le message humain doit etre VISIBLE avant toute generation.
        await expect(page.getByText(question, { exact: false }).last()).toBeVisible({ timeout: 15_000 });

        // 2. Puis les deux bulles, une par role.
        await expect(page.locator('[data-ai-mode="multi_ai"]')).toHaveCount(avant + 2, { timeout: 200_000 });

        const nouvelles = page.locator('[data-ai-mode="multi_ai"]');
        const total = await nouvelles.count();

        for (const i of [total - 2, total - 1]) {
            const bulle = nouvelles.nth(i);
            const modele = await bulle.locator('[data-ai-model]').getAttribute('data-ai-model');
            const tronque = await bulle.locator('[data-ai-truncated]').count();
            const texte = (await bulle.innerText()).slice(0, 320).replace(/\s+/g, ' ');

            console.log(`\n--- bulle ${i} | modele=${modele} | tronque=${tronque}\n${texte}`);

            expect(modele, 'aucun modele Laguna ne doit etre appele').not.toContain('laguna');
            expect(modele).toContain('ling-3.0-flash-vl');
            expect(tronque, 'aucun fragment tronque').toBe(0);
        }

        // 3. Aucun encart d'echec, aucun comportement legacy.
        await expect(page.locator('[data-multi-ai-state]')).toHaveCount(0);
        await expect(page.locator('[data-composer-mode]')).toHaveCount(0);
        await expect(page.locator('[data-pour-contre-modal]')).toHaveCount(0);

        // Le mode est one-shot : il s'est desarme tout seul.
        await expect(page.locator('[data-multi-ai-toggle]:visible').first()).toHaveAttribute('aria-pressed', 'false');
    });
}
