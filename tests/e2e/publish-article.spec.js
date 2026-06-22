import { test, expect } from '@playwright/test';
import { loginAsMember } from '../../ai/playwright/helpers/auth.js';
import { captureScreenshot } from '../../ai/playwright/helpers/screenshot.js';
import { setupConsoleLogging, getConsoleErrors, getPageErrors } from '../../ai/playwright/helpers/console.js';
import '../setup.js';

test.describe('Publish Article Flow', () => {
    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    test.afterEach(async ({ page }, testInfo) => {
        const consoleErrors = getConsoleErrors();
        const pageErrors = getPageErrors();

        if (consoleErrors.length > 0 || pageErrors.length > 0) {
            console.log('Test completed with errors:');
            console.log('Console errors:', consoleErrors);
            console.log('Page errors:', pageErrors);
        }
    });

    test('member can create and publish an article', async ({ page }) => {
        await loginAsMember(page);

        await captureScreenshot(page, '1-after-login-dashboard');

        await page.goto('/blog/rediger/nouveau');

        await expect(page).toHaveURL(/\/blog\/rediger\/nouveau/);
        await expect(page.locator('h1')).toContainText('Écrire un article');

        await captureScreenshot(page, '2-article-create-form');

        const articleTitle = `Article de test ${Date.now()}`;
        const articleContent = 'Ceci est un contenu d\'article de test avec suffisamment de caractères pour satisfaire la validation.';
        const articleSummary = 'Résumé de test.';

        await page.fill('input[name="title"]', articleTitle);
        await page.fill('textarea[name="content"]', articleContent);
        await page.fill('textarea[name="summary"]', articleSummary);

        await captureScreenshot(page, '3-form-filled');

        await page.check('input[name="categories[]"]');
        await page.fill('input[name="tags"]', 'test, playwright, e2e');

        await captureScreenshot(page, '4-categories-tags-filled');

        await page.click('input[name="status"][value="published"]');

        await page.click('button[type="submit"]');

        await page.waitForLoadState('networkidle');

        const currentUrl = page.url();
        expect(currentUrl).toMatch(/\/blog\/[\w-]+$/);

        await expect(page.locator('h1')).toContainText(articleTitle);

        await captureScreenshot(page, '5-article-published-success');

        const successMessage = page.locator('text=publié avec succès');
        await expect(successMessage).toBeVisible();
    });

    test('member can save article as draft', async ({ page }) => {
        await loginAsMember(page);

        await page.goto('/blog/rediger/nouveau');

        const articleTitle = `Brouillon de test ${Date.now()}`;
        const articleContent = 'Contenu de brouillon de test avec au moins cinquante caractères requis.';

        await page.fill('input[name="title"]', articleTitle);
        await page.fill('textarea[name="content"]', articleContent);

        await page.click('input[name="status"][value="draft"]');
        await page.click('button[type="submit"]');

        await page.waitForLoadState('networkidle');

        const currentUrl = page.url();
        expect(currentUrl).toMatch(/\/blog\/[\w-]+$/);

        await expect(page.locator('h1')).toContainText(articleTitle);

        await captureScreenshot(page, 'draft-article-saved');

        await page.goto('/blog/mes-articles');

        await expect(page).toHaveURL(/\/blog\/mes-articles/);

        await expect(page.locator('text=' + articleTitle)).toBeVisible();
    });

    test('member can access my-articles page', async ({ page }) => {
        await loginAsMember(page);

        await page.goto('/blog/mes-articles');

        await expect(page).toHaveURL(/\/blog\/mes-articles/);

        await expect(page.locator('h1, h2').first()).toBeVisible();

        await captureScreenshot(page, 'my-articles-page');
    });
});
