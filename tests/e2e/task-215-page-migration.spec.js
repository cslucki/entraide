import { test, expect } from '@playwright/test';
import { loginAsMember } from '../../ai/playwright/helpers/auth.js';
import { captureScreenshot } from '../../ai/playwright/helpers/screenshot.js';
import {
    setupConsoleLogging,
    getConsoleErrors,
    getPageErrors,
    clearErrors,
} from '../../ai/playwright/helpers/console.js';
import '../setup.js';

test.describe('TASK-215 — Page Migration Verification', () => {
    test.beforeEach(async ({ page }) => {
        clearErrors();
        setupConsoleLogging(page);
    });

    test.afterEach(async () => {
        const consoleErrors = getConsoleErrors();
        const pageErrors = getPageErrors();

        if (consoleErrors.length > 0 || pageErrors.length > 0) {
            console.log('Console errors:', consoleErrors);
            console.log('Page errors:', pageErrors);
        }

        expect(consoleErrors.length).toBe(0);
        expect(pageErrors.length).toBe(0);
    });

    test.describe('Public pages', () => {
        test('mentions-legales loads without errors', async ({ page }) => {
            await page.goto('/mentions-legales');
            await expect(page.locator('body')).toBeVisible();
            await captureScreenshot(page, 'task-215-mentions-legales');
        });

        test('blog/mes-articles loads without errors', async ({ page }) => {
            await page.goto('/blog/mes-articles');
            await expect(page.locator('body')).toBeVisible();
            await captureScreenshot(page, 'task-215-blog-mes-articles');
        });

        test('blog/rediger/nouveau loads without errors', async ({ page }) => {
            await page.goto('/blog/rediger/nouveau');
            await expect(page.locator('body')).toBeVisible();
            await captureScreenshot(page, 'task-215-blog-rediger-nouveau');
        });
    });

    test.describe('Authenticated pages', () => {
        test('favorites loads after login', async ({ page }) => {
            await loginAsMember(page);
            await page.goto('/favorites');
            await expect(page.locator('body')).toBeVisible();
            await captureScreenshot(page, 'task-215-favorites');
        });

        test('org/profile/edit loads after login', async ({ page }) => {
            await loginAsMember(page);
            await page.goto('/org/main/profile/edit');
            await expect(page.locator('body')).toBeVisible();
            await captureScreenshot(page, 'task-215-org-profile-edit');
        });

        test('org/loops loads after login', async ({ page }) => {
            await loginAsMember(page);
            await page.goto('/org/main/loops');
            await expect(page.locator('body')).toBeVisible();
            await captureScreenshot(page, 'task-215-org-loops');
        });

        test('org/points loads after login', async ({ page }) => {
            await loginAsMember(page);
            await page.goto('/org/main/points');
            await expect(page.locator('body')).toBeVisible();
            await captureScreenshot(page, 'task-215-org-points');
        });

        test('org/services/create loads after login', async ({ page }) => {
            await loginAsMember(page);
            await page.goto('/org/main/services/create');
            await expect(page.locator('body')).toBeVisible();
            await captureScreenshot(page, 'task-215-org-services-create');
        });

        test('org/requests/create loads after login', async ({ page }) => {
            await loginAsMember(page);
            await page.goto('/org/main/requests/create');
            await expect(page.locator('body')).toBeVisible();
            await captureScreenshot(page, 'task-215-org-requests-create');
        });

        test('services/show loads after login', async ({ page }) => {
            await loginAsMember(page);
            await page.goto('/services/019e30e5-7fdf-73fd-9440-07e4232a4e38');
            await expect(page.locator('body')).toBeVisible();
            await captureScreenshot(page, 'task-215-services-show');
        });

        test('requests/show loads after login', async ({ page }) => {
            await loginAsMember(page);
            await page.goto('/requests/019e3125-078a-718d-a31c-78414f8c996f');
            await expect(page.locator('body')).toBeVisible();
            await captureScreenshot(page, 'task-215-requests-show');
        });

        test('profile/show loads after login', async ({ page }) => {
            await loginAsMember(page);
            await page.goto('/profile/019e3082-3d25-72b7-b064-94412e3498e3');
            await expect(page.locator('body')).toBeVisible();
            await captureScreenshot(page, 'task-215-profile-show');
        });

    });
});
