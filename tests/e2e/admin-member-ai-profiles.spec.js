import { expect, test } from '@playwright/test';
import '../setup.js';
import { loginAsAdmin } from '../../ai/playwright/helpers/auth.js';

test.describe('Admin member AI profiles', () => {
    test('admin can open member AI profiles page', async ({ page }) => {
        await loginAsAdmin(page);

        await page.goto('/admin/member-ai-profiles');

        await expect(page).toHaveURL(/\/admin\/member-ai-profiles/);
        await expect(page.locator('h1', { hasText: 'Agents profil IA' })).toBeVisible();
    });

    test('admin can open LLM tester on profile edit page', async ({ page }) => {
        await loginAsAdmin(page);

        await page.goto('/admin/member-ai-profiles');

        const editLink = page.getByRole('link', { name: 'Modifier' }).first();
        test.skip(await editLink.count() === 0, 'No member AI profile available in this environment');

        await editLink.click();

        await expect(page.getByRole('heading', { name: 'Tester avec un LLM' })).toBeVisible();
        await expect(page.getByLabel('Provider')).toBeVisible();
        await expect(page.getByLabel('Modèle')).toBeVisible();
        await expect(page.getByLabel('Question de test')).toHaveValue("C'est quoi ta prestation ?");
    });

    test('AI menu section toggles and persists across navigation', async ({ page }) => {
        await loginAsAdmin(page);
        await page.evaluate(() => localStorage.setItem('sidebar_ia_open', 'false'));

        await page.goto('/admin/member-ai-profiles');

        const iaToggle = page.getByRole('button', { name: 'IA' });
        const agentsLink = page.getByRole('link', { name: 'Agents profil IA' });

        await expect(agentsLink).toBeVisible();

        await iaToggle.click();
        await expect(agentsLink).toBeHidden();
        await expect(page).toHaveURL(/\/admin\/member-ai-profiles/);
        await expect(page.evaluate(() => localStorage.getItem('sidebar_ia_open'))).resolves.toBe('false');

        await page.goto('/admin/dashboard');
        await expect(agentsLink).toBeHidden();

        await iaToggle.click();
        await expect(agentsLink).toBeVisible();
        await expect(page.evaluate(() => localStorage.getItem('sidebar_ia_open'))).resolves.toBe('true');

        await page.goto('/admin/dashboard');
        await expect(agentsLink).toBeVisible();
    });
});
