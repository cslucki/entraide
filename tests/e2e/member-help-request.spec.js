import { test, expect } from '@playwright/test';
import { loginAsCpmeMember } from '../../ai/playwright/helpers/auth.js';
import { setupConsoleLogging } from '../../ai/playwright/helpers/console.js';
import path from 'path';
import fs from 'fs';
import '../setup.js';

const COMMUNITY_SLUG = 'cpme';
const SCREENSHOT_DIR = 'docs/audits/T074.7-assets';

function auditDir() {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

async function auditScreenshot(page, name) {
    auditDir();
    const filepath = path.join(SCREENSHOT_DIR, `${name}.png`);
    await page.screenshot({ path: filepath, fullPage: true });
    return filepath;
}

function loopsUrl(path = '') {
    return `/${COMMUNITY_SLUG}/loops${path}`;
}

test.describe('Member Help Request (T074.7)', () => {
    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    test('desktop: complete help request flow', async ({ page }) => {
        await loginAsCpmeMember(page);

        await page.goto(loopsUrl('/create'));
        await page.waitForLoadState('networkidle');
        await page.fill('input[name="name"]', 'Aide commerciale');
        await page.fill('textarea[name="description"]', 'Loop pour T074.7 Playwright test');
        await page.click('button[type="submit"]');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Discussion')).toBeVisible();

        // Click "Qui peut m'aider ?"
        await page.getByText("Qui peut m'aider").click();
        await page.waitForTimeout(300);

        await expect(page.locator('textarea#intention')).toBeVisible();

        // Submit intention matching besoin_client_clair scenario
        await page.fill('textarea#intention', 'Je cherche des conseils pour trouver mes premiers clients');
        await page.getByText('Clarifier ma demande').click();
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Votre demande clarifiée')).toBeVisible();

        // Edit fields before publishing
        await page.fill('input#hr-title', 'Aide pour trouver mes premiers clients');
        await page.fill('textarea#hr-need', 'Je cherche des conseils pour trouver mes premiers clients dans le consulting.');

        // Publish
        await page.getByText('Publier dans la boucle').click();
        await page.waitForLoadState('networkidle');

        // Verify help request card
        await expect(page.locator('span').filter({ hasText: "Demande d'aide" }).first()).toBeVisible();
        await expect(page.getByText('Aide pour trouver mes premiers clients')).toBeVisible();

        await auditScreenshot(page, 'desktop-01-loop-help-request');
    });

    test('mobile: complete help request flow with screenshots', async ({ page }) => {
        await loginAsCpmeMember(page);

        await page.goto(loopsUrl('/create'));
        await page.waitForLoadState('networkidle');
        await page.fill('input[name="name"]', 'Aide commerciale mobile');
        await page.fill('textarea[name="description"]', 'Loop mobile T074.7 test');
        await page.click('button[type="submit"]');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Discussion')).toBeVisible();

        // Step 1: Click "Qui peut m'aider ?"
        await page.getByText("Qui peut m'aider").click();
        await page.waitForTimeout(300);

        await expect(page.locator('textarea#intention')).toBeVisible();

        // Mobile screenshot: intention floue
        await auditScreenshot(page, 'mobile-01-intention-floue');

        // Submit intention matching besoin_client_clair scenario
        await page.fill('textarea#intention', 'Je cherche des conseils pour trouver mes premiers clients');
        await page.getByText('Clarifier ma demande').click();
        await page.waitForLoadState('networkidle');

        // Mobile screenshot: demande clarifiée
        await expect(page.getByText('Votre demande clarifiée')).toBeVisible();
        await auditScreenshot(page, 'mobile-02-demande-clarifiee');

        // Edit need + publish
        await page.fill('input#hr-title', 'Aide pour trouver mes premiers clients');
        await page.fill('textarea#hr-need', 'Je cherche des conseils pour trouver mes premiers clients dans le consulting.');
        await page.getByText('Publier dans la boucle').click();
        await page.waitForLoadState('networkidle');

        // Verify published
        await expect(page.locator('span').filter({ hasText: "Demande d'aide" }).first()).toBeVisible();
        await expect(page.getByText('Aide pour trouver mes premiers clients')).toBeVisible();

        // Mobile screenshot: demande publiée
        await auditScreenshot(page, 'mobile-03-demande-publiee');
    });

    test('dark mode: help request flow visible', async ({ page }) => {
        await loginAsCpmeMember(page);

        await page.evaluate(() => {
            document.documentElement.classList.add('dark');
        });

        await page.goto(loopsUrl('/create'));
        await page.waitForLoadState('networkidle');
        await page.fill('input[name="name"]', 'Aide sombre');
        await page.fill('textarea[name="description"]', 'Dark mode test');
        await page.click('button[type="submit"]');
        await page.waitForLoadState('networkidle');

        await page.evaluate(() => {
            document.documentElement.classList.add('dark');
        });

        await page.getByText("Qui peut m'aider").click();
        await page.waitForTimeout(300);
        await page.fill('textarea#intention', 'Je cherche des conseils pour trouver mes premiers clients');
        await page.getByText('Clarifier ma demande').click();
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Votre demande clarifiée')).toBeVisible();

        await page.fill('input#hr-title', 'Aide pour trouver mes premiers clients');
        await page.fill('textarea#hr-need', 'Je cherche des conseils pour trouver mes premiers clients dans le consulting.');
        await page.getByText('Publier dans la boucle').click();
        await page.waitForLoadState('networkidle');

        await expect(page.locator('span').filter({ hasText: "Demande d'aide" }).first()).toBeVisible();
        await expect(page.getByText('Aide pour trouver mes premiers clients')).toBeVisible();

        await auditScreenshot(page, 'dark-01-loop-help-request');
    });
});
