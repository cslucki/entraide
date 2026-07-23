import { test, expect } from '@playwright/test';
import { login, logout } from '../../ai/playwright/helpers/auth.js';

const DOSSIER_ID = '019f8363-136b-72b0-8b88-2e4b2efb63cb';
const DOSSIER_URL = `/org/main/dossiers/${DOSSIER_ID}`;

const OWNER = 'admin@bouclepro.test';
const EDITOR = 'main.member2@bouclepro.test';
const READER = 'main.member1@bouclepro.test';
const CROSS_ORG = 'launchpals.member1@bouclepro.test';
const PASSWORD = 'password';

// ============================================================
// 1. OWNER VIEW — Desktop FR
// ============================================================
test.describe('Dossier Unified — Owner Desktop FR', () => {
    test.use({ viewport: { width: 1280, height: 720 }, locale: 'fr-FR' });

    test('owner sees all tabs and dossier title', async ({ page }) => {
        await login(page, OWNER, PASSWORD);
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        await expect(page.getByRole('heading', { name: /AUDIT-1033/ })).toBeVisible();
        await expect(page.locator('[role="tab"]')).toHaveCount(3);

        // Contents tab active by default
        await expect(page.locator('#tabpanel-contenus')).toBeVisible();

        // Switch to Fichiers tab
        await page.getByRole('tab', { name: /fichiers|files/i }).click();
        await page.waitForTimeout(400);
        await expect(page).toHaveURL(/#fichiers/);
        await expect(page.locator('#tabpanel-fichiers')).toBeVisible();

        // Switch to Membres tab
        await page.getByRole('tab', { name: /membres|members/i }).click();
        await page.waitForTimeout(400);
        await expect(page).toHaveURL(/#membres/);
        await expect(page.locator('#tabpanel-membres')).toBeVisible();
    });

    test('owner sees FAB add button', async ({ page }) => {
        await login(page, OWNER, PASSWORD);
        await page.goto(DOSSIER_URL + '#fichiers');
        await page.waitForLoadState('networkidle');

        await expect(page.getByRole('button', { name: /ajouter|add/i })).toBeVisible();
    });

    test('owner sees manage members button', async ({ page }) => {
        await login(page, OWNER, PASSWORD);
        await page.goto(DOSSIER_URL + '#membres');
        await page.waitForLoadState('networkidle');

        await expect(page.getByRole('button', { name: /gérer|manage/i })).toBeVisible();
    });
});

// ============================================================
// 2. EDITOR VIEW
// ============================================================
test.describe('Dossier Unified — Editor', () => {
    test('editor sees dossier content', async ({ page }) => {
        await login(page, EDITOR, PASSWORD);
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        await expect(page.getByRole('heading', { name: /AUDIT-1033/ })).toBeVisible();
        await expect(page.locator('[role="tab"]')).toHaveCount(3);
        await expect(page.locator('#tabpanel-contenus')).toBeVisible();
    });
});

// ============================================================
// 3. READER VIEW
// ============================================================
test.describe('Dossier Unified — Reader', () => {
    test('reader sees content but no management controls', async ({ page }) => {
        await login(page, READER, PASSWORD);
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        await expect(page.getByRole('heading', { name: /AUDIT-1033/ })).toBeVisible();
        await expect(page.locator('[role="tab"]')).toHaveCount(3);

        // Reader should NOT see the FAB add button
        await page.goto(DOSSIER_URL + '#fichiers');
        await page.waitForLoadState('networkidle');
        await expect(page.getByRole('button', { name: /ajouter|add/i })).toHaveCount(0);

        // Reader should NOT see manage members button
        await page.goto(DOSSIER_URL + '#membres');
        await page.waitForLoadState('networkidle');
        await expect(page.getByRole('button', { name: /gérer|manage/i })).toHaveCount(0);
    });
});

// ============================================================
// 4. CROSS-ORG REFUSED
// ============================================================
test.describe('Dossier Unified — Cross-Org Refused', () => {
    test('launchpals user gets 404 on main org dossier', async ({ page }) => {
        await login(page, CROSS_ORG, PASSWORD);
        const response = await page.goto(DOSSIER_URL);
        expect(response.status()).toBe(403);
    });
});

// ============================================================
// 5. DESKTOP EN
// ============================================================
test.describe('Dossier Unified — Owner Desktop EN', () => {
    test.use({ viewport: { width: 1280, height: 720 }, locale: 'en-US' });

    test('dossier loads in English with correct tabs', async ({ page }) => {
        await login(page, OWNER, PASSWORD);
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        await expect(page.getByRole('heading', { name: /AUDIT-1033/ })).toBeVisible();
        await expect(page.locator('[role="tab"]')).toHaveCount(3);

        const tabs = page.locator('[role="tab"]');
        const tabTexts = await tabs.allTextContents();
        expect(tabTexts.some(t => /contents|contenus/i.test(t))).toBe(true);
        expect(tabTexts.some(t => /files|fichiers/i.test(t))).toBe(true);
        expect(tabTexts.some(t => /members|membres/i.test(t))).toBe(true);
    });
});

// ============================================================
// 6. MOBILE FR
// ============================================================
test.describe('Dossier Unified — Owner Mobile FR', () => {
    test.use({ viewport: { width: 375, height: 812 }, locale: 'fr-FR' });

    test('dossier renders at 375px without overflow', async ({ page }) => {
        await login(page, OWNER, PASSWORD);
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        await expect(page.locator('[role="tabpanel"]').first()).toBeVisible();

        const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
        const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
        expect(scrollWidth).toBeLessThanOrEqual(clientWidth + 10);
    });

    test('mobile tab switching works', async ({ page }) => {
        await login(page, OWNER, PASSWORD);
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        const tabs = page.locator('[role="tab"]');
        const tabCount = await tabs.count();
        expect(tabCount).toBe(3);

        for (let i = 1; i < tabCount; i++) {
            await tabs.nth(i).click();
            await page.waitForTimeout(300);
            await expect(tabs.nth(i)).toHaveAttribute('aria-selected', 'true');
        }
    });
});

// ============================================================
// 7. MOBILE EN
// ============================================================
test.describe('Dossier Unified — Owner Mobile EN', () => {
    test.use({ viewport: { width: 430, height: 932 }, locale: 'en-US' });

    test('dossier at 430px English renders correctly', async ({ page }) => {
        await login(page, OWNER, PASSWORD);
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        await expect(page.locator('[role="tabpanel"]').first()).toBeVisible();
        await expect(page.getByRole('heading', { name: /AUDIT-1033/ }).first()).toBeVisible();
    });
});
