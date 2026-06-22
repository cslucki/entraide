import { test, expect } from '@playwright/test';
import { loginAsCpmeMember } from '../../ai/playwright/helpers/auth.js';
import { captureScreenshot } from '../../ai/playwright/helpers/screenshot.js';
import { setupConsoleLogging } from '../../ai/playwright/helpers/console.js';
import '../setup.js';

const ORGANIZATION_SLUG = 'cpme';

test.describe('Member ChatLoop (T074.6)', () => {
    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    function loopsUrl(path = '') {
        return `/${ORGANIZATION_SLUG}/loops${path}`;
    }

    test('member loops index loads and displays loops', async ({ page }) => {
        await loginAsCpmeMember(page);
        await page.goto(loopsUrl());
        await page.waitForLoadState('networkidle');

        await expect(page.locator('h1').first()).toBeVisible();
        await expect(page.locator('nav')).toBeVisible();

        await captureScreenshot(page, 't074-6-loops-index-desktop');
    });

    test('member loops index empty state is calm', async ({ page }) => {
        await loginAsCpmeMember(page);
        await page.goto(loopsUrl());
        await page.waitForLoadState('networkidle');

        const hasEmpty = await page.getByText('Vous n\'avez encore aucune boucle').isVisible().catch(() => false);
        if (hasEmpty) {
            await expect(page.getByText('Créer votre première boucle')).toBeVisible();
            await captureScreenshot(page, 't074-6-loops-empty-state');
        }
    });

    test('create loop form is accessible', async ({ page }) => {
        await loginAsCpmeMember(page);
        await page.goto(loopsUrl('/create'));
        await page.waitForLoadState('networkidle');

        await expect(page.locator('h1').first()).toBeVisible();
        await expect(page.locator('input[name="name"]')).toBeVisible();
        await expect(page.locator('textarea[name="description"]')).toBeVisible();
        await expect(page.locator('button[type="submit"]')).toBeVisible();

        await captureScreenshot(page, 't074-6-loops-create-form');
    });

    test('loop show page loads with discussion section', async ({ page }) => {
        await loginAsCpmeMember(page);

        await page.goto(loopsUrl('/create'));
        await page.waitForLoadState('networkidle');
        await page.fill('input[name="name"]', 'ChatLoop Test');
        await page.fill('textarea[name="description"]', 'Playwright test loop');
        await page.click('button[type="submit"]');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Discussion')).toBeVisible();
        await expect(page.locator('textarea#body')).toBeVisible();
        await expect(page.getByText('Membres')).toBeVisible();

        await captureScreenshot(page, 't074-6-loop-show-desktop');
    });

    test('member can post a message and see it appear', async ({ page }) => {
        await loginAsCpmeMember(page);

        await page.goto(loopsUrl('/create'));
        await page.waitForLoadState('networkidle');
        await page.fill('input[name="name"]', 'Message Test Loop');
        await page.fill('textarea[name="description"]', 'Testing message posting');
        await page.click('button[type="submit"]');
        await page.waitForLoadState('networkidle');

        await expect(page.locator('textarea#body')).toBeVisible();

        const testMessage = 'Hello from Playwright!';
        await page.fill('textarea#body', testMessage);
        await page.click('button[type="submit"]');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText(testMessage)).toBeVisible();
        await expect(page.getByText('Moi')).toBeVisible();
    });
});
