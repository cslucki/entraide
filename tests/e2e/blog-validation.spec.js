import { test, expect } from '@playwright/test';
import { loginAsMember } from '../../ai/playwright/helpers/auth.js';
import { setupConsoleLogging, getConsoleErrors, getPageErrors } from '../../ai/playwright/helpers/console.js';
import '../setup.js';

function uniqueTitle(prefix) {
    return `QA-T306-${prefix}-${Date.now().toString(36)}`;
}

const LONG_CONTENT = 'Ceci est un article de blog créé par la suite de tests Playwright TASK-306. Il permet de valider le bon fonctionnement du module blog avec TipTap. Le contenu est suffisamment long pour respecter les contraintes de validation lors de la publication.';

async function fillTipTap(page, text) {
    const proseMirror = page.locator('.ProseMirror');
    await proseMirror.waitFor({ state: 'visible', timeout: 10_000 });
    await proseMirror.click();
    await page.keyboard.insertText(text);
}

test.describe('TASK-306 Blog Validation UX', () => {
    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    test('A: Publier un article valide', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/blog/rediger/nouveau');
        await expect(page.locator('input[name="title"]')).toBeVisible();

        const title = uniqueTitle('PublishA');
        await page.fill('input[name="title"]', title);
        await page.fill('textarea[name="summary"]', 'Resume de test pour publication valide.');
        await fillTipTap(page, LONG_CONTENT);
        await page.selectOption('select[name="category_id"]', { index: 1 });
        await page.fill('input[name="tags"]', 'test, tip tap, validation');

        await page.click('button[name="status"][value="published"]');

        await page.waitForURL(/\/blog\/[\w-]+$/, { timeout: 15_000 });
        await expect(page.locator('h1').first()).toContainText(title);
    });

    test('B: Enregistrer un brouillon minimal sans categorie', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/blog/rediger/nouveau');
        await expect(page.locator('input[name="title"]')).toBeVisible();

        const title = uniqueTitle('DraftB');
        await page.fill('input[name="title"]', title);

        await page.click('button[name="status"][value="draft"]');

        await page.waitForURL(/\/blog\/[\w-]+$/, { timeout: 15_000 });
        await expect(page.locator('h1').first()).toContainText(title);
    });

    test('C: Publier sans categorie -- erreur visible', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/blog/rediger/nouveau');
        await expect(page.locator('input[name="title"]')).toBeVisible();

        await page.fill('input[name="title"]', uniqueTitle('NoCatC'));
        await page.fill('textarea[name="summary"]', 'Resume sans categorie.');
        await fillTipTap(page, LONG_CONTENT);

        await page.click('button[name="status"][value="published"]');

        await page.waitForLoadState('networkidle');
        await expect(page.locator('[role="alert"]')).toBeVisible();
        await expect(page.getByText(/cat.gorie est obligatoire pour publier/)).toBeVisible();
        await expect(page.locator('input[name="title"]')).toBeVisible();
    });

    test('D: Publier sans titre -- erreur visible', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/blog/rediger/nouveau');
        await expect(page.locator('input[name="title"]')).toBeVisible();

        await fillTipTap(page, LONG_CONTENT);
        await page.fill('textarea[name="summary"]', 'Resume sans titre.');
        await page.selectOption('select[name="category_id"]', { index: 1 });

        await page.click('button[name="status"][value="published"]');

        await page.waitForLoadState('networkidle');
        await expect(page.locator('[role="alert"]')).toBeVisible();
        await expect(page.getByText(/titre est obligatoire/)).toBeVisible();
    });

    test('E: Publier sans contenu -- erreur visible', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/blog/rediger/nouveau');
        await expect(page.locator('input[name="title"]')).toBeVisible();

        await page.fill('input[name="title"]', uniqueTitle('NoContentE'));
        await page.fill('textarea[name="summary"]', 'Resume sans contenu.');
        await page.selectOption('select[name="category_id"]', { index: 1 });

        await page.click('button[name="status"][value="published"]');

        await page.waitForLoadState('networkidle');
        await expect(page.locator('[role="alert"]')).toBeVisible();
        await expect(page.getByText(/contenu est obligatoire pour publier/)).toBeVisible();
    });

    test('H: Reedit er modifier un article', async ({ page }) => {
        await loginAsMember(page);
        const title = uniqueTitle('ReeditH');

        await page.goto('/blog/rediger/nouveau');
        await page.fill('input[name="title"]', title);
        await page.fill('textarea[name="summary"]', 'Resume pour reedition.');
        await fillTipTap(page, LONG_CONTENT);
        await page.selectOption('select[name="category_id"]', { index: 1 });
        await page.click('button[name="status"][value="published"]');
        await page.waitForURL(/\/blog\/[\w-]+$/, { timeout: 15_000 });
        const slug = page.url().split('/').pop();

        await page.goto(`/blog/rediger/${slug}/modifier`);
        await page.waitForLoadState('networkidle');
        await expect(page.locator('input[name="title"]')).toHaveValue(title);

        const modifiedContent = 'Contenu modifie apres reedition. Ce paragraphe remplace l original.';
        await fillTipTap(page, modifiedContent);

        await page.getByRole('button', { name: 'Enregistrer les modifications' }).click();
        await page.waitForURL(/\/blog\/[\w-]+$/, { timeout: 15_000 });
        await expect(page.locator('h1').first()).toContainText(title);
    });
});
