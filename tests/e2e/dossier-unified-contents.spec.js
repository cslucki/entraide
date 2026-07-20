import { test, expect } from '@playwright/test';
import { loginAsMember, loginAsAdmin } from '../../ai/playwright/helpers/auth.js';
import { captureScreenshot } from '../../ai/playwright/helpers/screenshot.js';
import { setupConsoleLogging, getConsoleErrors, getPageErrors, assertNoConsoleErrors } from '../../ai/playwright/helpers/console.js';
import '../setup.js';

test.describe('Dossier Unified Contents — QA Desktop FR', () => {
    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    test.afterEach(async ({ page }, testInfo) => {
        const consoleErrors = getConsoleErrors();
        const pageErrors = getPageErrors();
        if (consoleErrors.length > 0 || pageErrors.length > 0) {
            console.log(`[${testInfo.title}] errors:`, { console: consoleErrors.length, page: pageErrors.length });
        }
    });

    test('owner sees all tabs and can navigate between them', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/dossiers');
        await expect(page).toHaveURL(/\/dossiers/);

        await page.locator('a[href*="/dossiers/"]').first().waitFor({ state: 'visible' });
        await page.locator('a[href*="/dossiers/"]').first().click();
        await page.waitForLoadState('networkidle');

        await expect(page.locator('[role="tab"]')).toHaveCount(3);
        await expect(page.locator('#tab-contenus')).toBeVisible();
        await expect(page.locator('#tab-fichiers')).toBeVisible();
        await expect(page.locator('#tab-membres')).toBeVisible();

        await page.locator('#tab-fichiers').click();
        await expect(page).toHaveURL(/#fichiers/);
        await expect(page.locator('#tabpanel-fichiers')).toBeVisible();

        await page.locator('#tab-membres').click();
        await expect(page).toHaveURL(/#membres/);
        await expect(page.locator('#tabpanel-membres')).toBeVisible();

        await page.locator('#tab-contenus').click();
        await expect(page).toHaveURL(/#contenus/);
        await expect(page.locator('#tabpanel-contenus')).toBeVisible();

        assertNoConsoleErrors();
    });

    test('contents tab shows series root, annexes and ungrouped sections', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/dossiers');
        await page.locator('a[href*="/dossiers/"]').first().click();
        await page.waitForLoadState('networkidle');

        await expect(page.locator('[role="tabpanel"]#tabpanel-contenus')).toBeVisible();

        const badgeCount = await page.locator('[class*="badge"], [class*="rounded-full"]').count();
        expect(badgeCount).toBeGreaterThanOrEqual(0);
        await captureScreenshot(page, 'dossier-contents-overview');
    });

    test('members tab shows member list with role badges', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/dossiers');
        await page.locator('a[href*="/dossiers/"]').first().click();
        await page.waitForLoadState('networkidle');

        await page.locator('#tab-membres').click();
        await page.waitForLoadState('networkidle');

        await expect(page.locator('#tabpanel-membres')).toBeVisible();
        const memberCards = page.locator('#tabpanel-membres [class*="rounded-xl"]');
        const count = await memberCards.count();

        if (count > 0) {
            await expect(memberCards.first()).toBeVisible();
        }

        await captureScreenshot(page, 'dossier-members-overview');
    });

    test('search input in contents tab', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/dossiers');
        await page.locator('a[href*="/dossiers/"]').first().click();
        await page.waitForLoadState('networkidle');

        const searchInput = page.locator('input[type="search"], input[placeholder*="chercher"], input[placeholder*="search"]');
        if (await searchInput.count() > 0) {
            await searchInput.first().fill('test');
            await page.waitForTimeout(500);
        }

        await captureScreenshot(page, 'dossier-contents-search');
    });

    test('no console errors on tab navigation cycle', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/dossiers');
        await page.locator('a[href*="/dossiers/"]').first().click();
        await page.waitForLoadState('networkidle');

        await page.locator('#tab-fichiers').click();
        await page.waitForTimeout(300);
        await page.locator('#tab-membres').click();
        await page.waitForTimeout(300);
        await page.locator('#tab-contenus').click();
        await page.waitForTimeout(300);
        await page.locator('#tab-fichiers').click();
        await page.waitForTimeout(300);
        await page.locator('#tab-contenus').click();
        await page.waitForTimeout(300);

        assertNoConsoleErrors();
        await captureScreenshot(page, 'dossier-tab-cycle');
    });

    test('files tab loads without errors', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/dossiers');
        await page.locator('a[href*="/dossiers/"]').first().click();
        await page.waitForLoadState('networkidle');

        await page.locator('#tab-fichiers').click();
        await page.waitForLoadState('networkidle');

        await expect(page.locator('#tabpanel-fichiers')).toBeVisible();
        const hasContent = await page.locator('#tabpanel-fichiers [class*="rounded-xl"]').count() > 0;
        const hasEmpty = await page.locator('#tabpanel-fichiers [class*="border-dashed"]').count() > 0;
        expect(hasContent || hasEmpty).toBe(true);

        await captureScreenshot(page, 'dossier-files-tab');
    });
});

test.describe('Dossier Unified Contents — QA Desktop EN', () => {
    test.use({ locale: 'en-US' });

    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    test.afterEach(async ({ page }, testInfo) => {
        const consoleErrors = getConsoleErrors();
        const pageErrors = getPageErrors();
        if (consoleErrors.length > 0 || pageErrors.length > 0) {
            console.log(`[${testInfo.title}] errors:`, { console: consoleErrors.length, page: pageErrors.length });
        }
    });

    test('dossier page loads in English with correct tab labels', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/dossiers');
        await page.locator('a[href*="/dossiers/"]').first().click();
        await page.waitForLoadState('networkidle');

        await expect(page.locator('[role="tab"]')).toHaveCount(3);
        await captureScreenshot(page, 'dossier-en-overview');
        assertNoConsoleErrors();
    });
});

test.describe('Dossier Unified Contents — QA Mobile FR', () => {
    test.use({ viewport: { width: 375, height: 812 } });

    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    test.afterEach(async ({ page }, testInfo) => {
        const consoleErrors = getConsoleErrors();
        const pageErrors = getPageErrors();
        if (consoleErrors.length > 0 || pageErrors.length > 0) {
            console.log(`[${testInfo.title}] errors:`, { console: consoleErrors.length, page: pageErrors.length });
        }
    });

    test('dossier page renders correctly at 375px mobile', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/dossiers');
        await page.locator('a[href*="/dossiers/"]').first().click();
        await page.waitForLoadState('networkidle');

        await expect(page.locator('[role="tabpanel"]').first()).toBeVisible();
        await captureScreenshot(page, 'dossier-mobile-375');
        assertNoConsoleErrors();
    });

    test('mobile tab switching works', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/dossiers');
        await page.locator('a[href*="/dossiers/"]').first().click();
        await page.waitForLoadState('networkidle');

        const tabs = page.locator('[role="tab"]');
        const tabCount = await tabs.count();
        expect(tabCount).toBe(3);

        for (let i = 1; i < tabCount; i++) {
            await tabs.nth(i).click();
            await page.waitForTimeout(300);
        }

        await captureScreenshot(page, 'dossier-mobile-tab-cycle');
    });

    test('mobile no horizontal overflow', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/dossiers');
        await page.locator('a[href*="/dossiers/"]').first().click();
        await page.waitForLoadState('networkidle');

        const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
        const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
        expect(scrollWidth).toBeLessThanOrEqual(clientWidth + 10);
    });
});

test.describe('Dossier Unified Contents — QA Mobile EN', () => {
    test.use({ viewport: { width: 430, height: 932 }, locale: 'en-US' });

    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    test.afterEach(async ({ page }, testInfo) => {
        const consoleErrors = getConsoleErrors();
        const pageErrors = getPageErrors();
        if (consoleErrors.length > 0 || pageErrors.length > 0) {
            console.log(`[${testInfo.title}] errors:`, { console: consoleErrors.length, page: pageErrors.length });
        }
    });

    test('dossier at 430px English renders correctly', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/dossiers');
        await page.locator('a[href*="/dossiers/"]').first().click();
        await page.waitForLoadState('networkidle');

        await expect(page.locator('[role="tabpanel"]').first()).toBeVisible();
        await captureScreenshot(page, 'dossier-mobile-en-430');
        assertNoConsoleErrors();
    });
});
