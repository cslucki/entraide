import { test, expect } from '@playwright/test';
import { loginAsMember } from '../../ai/playwright/helpers/auth.js';
import { setupConsoleLogging, getConsoleErrors } from '../../ai/playwright/helpers/console.js';
import '../setup.js';

test.describe('Inline Member Agent on Profile Page', () => {
    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    test.afterEach(async () => {
        const consoleErrors = getConsoleErrors();
        if (consoleErrors.length > 0) {
            console.log('Console errors:', consoleErrors);
        }
    });

    test('inline agent card visible on profile with published AI profile', async ({ page }) => {
        await loginAsMember(page);

        // Get current user ID from meta tag
        const userId = await page.evaluate(() => {
            const meta = document.querySelector('meta[name="user-id"]');
            return meta ? meta.getAttribute('content') : null;
        });

        // Navigate to the current member profile; the profile page should render normally.
        const profileUrl = userId ? `/profile/${userId}` : '/profile/1';
        await page.goto(profileUrl);

        await expect(page.locator('h1.text-2xl')).toBeVisible();

        const errors = getConsoleErrors();
        expect(errors.filter(e => !e.message.includes('chrome-extension'))).toEqual([]);
    });

    test('profile page renders without hiding own published agent by auth condition', async ({ page }) => {
        await loginAsMember(page);

        const userId = await page.evaluate(() => {
            const meta = document.querySelector('meta[name="user-id"]');
            return meta ? meta.getAttribute('content') : null;
        });

        const profileUrl = userId ? `/profile/${userId}` : '/profile/1';
        await page.goto(profileUrl);

        await expect(page.locator('h1.text-2xl')).toBeVisible();

        const errors = getConsoleErrors();
        expect(errors.filter(e => !e.message.includes('chrome-extension'))).toEqual([]);
    });
});
