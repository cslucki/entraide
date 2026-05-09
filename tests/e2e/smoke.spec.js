import { test, expect } from '@playwright/test';
import path from 'path';
import fs from 'fs';
import { loginAsMember, loginAsAdmin } from '../../ai/playwright/helpers/auth.js';
import { captureScreenshot } from '../../ai/playwright/helpers/screenshot.js';
import { setupConsoleLogging, getConsoleErrors, getPageErrors } from '../../ai/playwright/helpers/console.js';
import '../setup.js';

const SCREENSHOT_DIR = 'ai/playwright/screenshots';

test.describe('Global Platform Smoke Tests', () => {
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

    test('global member login and dashboard', async ({ page }) => {
        // Uses TEST_MEMBER1 (Alice) - global platform member
        await loginAsMember(page);

        await expect(page).toHaveURL(/\/dashboard/);
        await expect(page.locator('h1, h2').first()).toBeVisible();
        await expect(page.locator('nav')).toBeVisible();

        await captureScreenshot(page, 'global-member-dashboard');
    });

    test('admin dashboard access', async ({ page }) => {
        // Uses TEST_ADMIN - admin user only for admin routes
        await loginAsAdmin(page);

        await page.goto('/admin/dashboard');
        await expect(page).toHaveURL(/\/admin\/dashboard/);
        await expect(page.locator('main, [class*="content"], h1, h2').first()).toBeVisible();

        await captureScreenshot(page, 'admin-dashboard');
    });

    test('admin messages list', async ({ page }) => {
        // Uses TEST_ADMIN - admin user only for admin routes
        await loginAsAdmin(page);

        await page.goto('/admin/messages');
        await expect(page).toHaveURL(/\/admin\/messages/);

        // La page charge sans erreur
        await expect(page.locator('main')).toBeVisible();

        // Présence du tableau
        await expect(page.locator('table')).toBeVisible();

        await captureScreenshot(page, 'admin-messages');
    });
});
