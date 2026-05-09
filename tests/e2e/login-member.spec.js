import { test, expect } from '@playwright/test';
import { loginAsMember } from '../../ai/playwright/helpers/auth.js';
import { captureScreenshot } from '../../ai/playwright/helpers/screenshot.js';
import { setupConsoleLogging, getConsoleErrors, getPageErrors } from '../../ai/playwright/helpers/console.js';
import '../setup.js';

test.describe('Global Member Authentication', () => {
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

    test('global member login works', async ({ page }) => {
        // Uses TEST_MEMBER1 (Alice) - global platform member
        await loginAsMember(page);

        await expect(page).toHaveURL(/dashboard/);

        await captureScreenshot(page, 'global-member-dashboard');
    });
});
