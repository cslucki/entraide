import { test, expect } from '@playwright/test';
import { loginAsMember } from '../../ai/playwright/helpers/auth.js';
import { captureScreenshot } from '../../ai/playwright/helpers/screenshot.js';
import { setupConsoleLogging, getConsoleErrors, getPageErrors } from '../../ai/playwright/helpers/console.js';
import { mobileViewport } from '../../ai/playwright/devices/index.js';
import '../setup.js';

async function resetMemberProfile(page) {
    const csrfToken = await page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'));
    if (csrfToken) {
        await page.request.delete('/agent-ia/profile', { headers: { 'X-CSRF-TOKEN': csrfToken } });
    }
}

test.describe('Member AI Profile Wizard', () => {
    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    test.afterEach(async () => {
        const consoleErrors = getConsoleErrors();
        const pageErrors = getPageErrors();

        if (consoleErrors.length > 0 || pageErrors.length > 0) {
            console.log('Test completed with errors:');
            console.log('Console errors:', consoleErrors);
            console.log('Page errors:', pageErrors);
        }
    });

    test('loads wizard page without console errors', async ({ page }) => {
        await loginAsMember(page);
        await resetMemberProfile(page);
        await page.goto('/agent-ia');

        await expect(page.getByRole('heading', { name: 'Mon profil IA' }).first()).toBeVisible();
        await expect(page.locator('#member_profile_summary')).toBeVisible();

        const errors = getConsoleErrors();
        expect(errors.filter(e => !e.message.includes('chrome-extension'))).toEqual([]);

        await captureScreenshot(page, 'wizard-step-1');
    });

    test('target audience chips are clickable', async ({ page }) => {
        await loginAsMember(page);
        await resetMemberProfile(page);
        await page.goto('/agent-ia');

        const chip = page.locator('button:has-text("entrepreneurs")').first();
        await expect(chip).toBeVisible();

        await chip.click();
        await expect(chip).toHaveClass(/bg-indigo-600/);

        await chip.click();
        await expect(chip).not.toHaveClass(/bg-indigo-600/);
    });

    test('help type chips are clickable on step 2', async ({ page }) => {
        await loginAsMember(page);
        await resetMemberProfile(page);
        await page.goto('/agent-ia');

        await page.fill('#member_profile_summary', 'Consultant en marketing');
        await page.fill('#problems_helped_raw', 'SEO, stratégie de marque');
        await page.locator('button:has-text("entrepreneurs")').first().click();
        await page.locator('button:has-text("Continuer")').click();

        const chip = page.locator('button:has-text("avis_rapide")').first();
        await expect(chip).toBeVisible();
        await chip.click();
        await expect(chip).toHaveClass(/bg-indigo-600/);
    });

    test('boundary chips are clickable on step 3', async ({ page }) => {
        await loginAsMember(page);
        await resetMemberProfile(page);
        await page.goto('/agent-ia');

        await page.fill('#member_profile_summary', 'Consultant en marketing');
        await page.locator('button:has-text("entrepreneurs")').first().click();
        await page.fill('#problems_helped_raw', 'SEO');
        await page.locator('button:has-text("Continuer")').click();

        await page.fill('#service_scope', 'Accompagnement SEO');
        await page.fill('#skillsInput', 'SEO, Marketing');
        await page.fill('#experience_context', '5 ans');
        await page.locator('button:has-text("avis_rapide")').first().click();
        await page.locator('button:has-text("Continuer")').click();

        const chip = page.locator('button:has-text("pas_urgence")').first();
        await expect(chip).toBeVisible();
        await chip.click();
        await expect(chip).toHaveClass(/bg-indigo-600/);
    });

    test('full wizard flow on desktop', async ({ page }) => {
        await loginAsMember(page);
        await resetMemberProfile(page);
        await page.goto('/agent-ia');
        await page.waitForSelector('#member_profile_summary', { timeout: 10000 });

        // Step 1
        await page.fill('#member_profile_summary', 'Consultant en marketing digital');
        await page.locator('button:has-text("entrepreneurs")').first().click();
        await page.locator('button:has-text("independants")').first().click();
        await page.fill('#problems_helped_raw', 'Stratégie de marque\nSEO\nCréation de contenu');
        await captureScreenshot(page, 'wizard-step1-filled');

        await page.locator('button:has-text("Continuer")').click();
        await expect(page.locator('text=Ce que vous apportez').first()).toBeVisible();

        // Step 2
        await page.fill('#service_scope', 'Accompagnement sur mesure pour TPE/PME');
        await page.fill('#skillsInput', 'Marketing digital, SEO, Rédaction web');
        await page.fill('#experience_context', '5 ans en agence, spécialiste SEO');
        await page.locator('button:has-text("avis_rapide")').first().click();
        await page.locator('button:has-text("repondre_question")').first().click();
        await captureScreenshot(page, 'wizard-step2-filled');

        await page.locator('button:has-text("Continuer")').click();
        await expect(page.locator('text=Cadre et limites').first()).toBeVisible();

        // Step 3
        await page.locator('button:has-text("pas_urgence")').first().click();
        await page.locator('button:has-text("pas_travail_gratuit")').first().click();
        await page.locator('label:has(span:text("envoyer_demande_echange"))').first().click();
        await page.locator('label:has(input[value="chaleureux"])').first().click();
        await captureScreenshot(page, 'wizard-step3-filled');

        await page.locator('button:has-text("Continuer")').click();
        await expect(page.locator('text=Exemples').first()).toBeVisible();

        // Step 4
        await page.fill('input[placeholder*="Exemple"]', 'Aide pour stratégie de contenu');
        await page.locator('button:has-text("Ajouter")').first().click();
        await captureScreenshot(page, 'wizard-step4-filled');

        await page.locator('button:has-text("Continuer")').click();
        await expect(page.locator('text=Votre profil est presque prêt').first()).toBeVisible();

        await captureScreenshot(page, 'wizard-step5-review');
    });

    test('mobile viewport renders correctly', async ({ page }) => {
        await page.setViewportSize(mobileViewport.viewport);
        await loginAsMember(page);
        await resetMemberProfile(page);
        await page.goto('/agent-ia');

        await expect(page.getByRole('heading', { name: 'Mon profil IA' }).first()).toBeVisible();
        await expect(page.locator('#member_profile_summary')).toBeVisible();

        const errors = getConsoleErrors();
        expect(errors.filter(e => !e.message.includes('chrome-extension'))).toEqual([]);

        await captureScreenshot(page, 'wizard-mobile-step-1');

        // Step 1 fill and continue on mobile
        await page.fill('#member_profile_summary', 'Consultant en marketing');
        await page.locator('button:has-text("entrepreneurs")').first().click();
        await page.fill('#problems_helped_raw', 'SEO, stratégie');

        await page.locator('button:has-text("Continuer")').click();
        await expect(page.locator('text=Ce que vous apportez').first()).toBeVisible();
        await captureScreenshot(page, 'wizard-mobile-step-2');
    });
});
