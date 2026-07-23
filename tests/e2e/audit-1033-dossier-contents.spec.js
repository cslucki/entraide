import { test, expect } from '@playwright/test';
import { login, logout } from '../../ai/playwright/helpers/auth.js';

const DOSSIER_ID = '019f8363-136b-72b0-8b88-2e4b2efb63cb';
const DOSSIER_URL = `/org/main/dossiers/${DOSSIER_ID}`;

const OWNER_EMAIL = 'admin@bouclepro.test';
const EDITOR_EMAIL = 'main.member2@bouclepro.test';
const READER_EMAIL = 'main.member1@bouclepro.test';
const CROSS_ORG_EMAIL = 'launchpals.member1@bouclepro.test';
const PASSWORD = 'password';

// ============================================================
// 1. OWNER VIEW — Desktop FR, Light
// ============================================================
test.describe('AUDIT-1033 Owner View — Desktop FR Light', () => {
    test.use({ viewport: { width: 1280, height: 720 }, locale: 'fr-FR' });

    test.beforeEach(async ({ page }) => {
        await login(page, OWNER_EMAIL, PASSWORD);
    });

    test('owner sees dossier and all content sections', async ({ page }) => {
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        // Dossier title
        await expect(page.getByRole('heading', { name: /AUDIT-1033/ })).toBeVisible();

        // Contents tab should be visible and active
        const contentsTab = page.getByRole('tab', { name: /contenus|contents/i });
        await expect(contentsTab).toBeVisible();

        // Root badge (indigo badge rendered by Alpine x-text)
        await expect(page.locator('.bg-indigo-100.rounded-full').filter({ hasText: 'Racine' })).toBeVisible();

        // Series title
        await expect(page.getByText('Série').first()).toBeVisible();

        // Ungrouped title
        await expect(page.getByText('Non classés').first()).toBeVisible();

        // Series root article
        await expect(page.getByText('AUDIT-1033 Article 1').first()).toBeVisible();

        // Annex articles
        await expect(page.getByText('AUDIT-1033 Article 2').first()).toBeVisible();
        await expect(page.getByText('AUDIT-1033 Article 3').first()).toBeVisible();

        // Ungrouped articles
        await expect(page.getByText('AUDIT-1033 Article 4').first()).toBeVisible();
        await expect(page.getByText('AUDIT-1033 Article 5').first()).toBeVisible();
        await expect(page.getByText('AUDIT-1033 Article 6').first()).toBeVisible();
        await expect(page.getByText('AUDIT-1033 Article 7').first()).toBeVisible();
        await expect(page.getByText('AUDIT-1033 Article 8').first()).toBeVisible();
    });

    test('owner sees add article button and menu actions', async ({ page }) => {
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        // Add article button visible
        await expect(page.getByRole('button', { name: /ajouter|add/i })).toBeVisible();

        // Menu dots visible on article cards (⋯ buttons)
        const menuButtons = page.locator('[data-article-menu] button');
        await expect(menuButtons.first()).toBeVisible();
    });

    test('owner sees tabs: Contenus, Fichiers, Membres', async ({ page }) => {
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        await expect(page.getByRole('tab', { name: /contenus|contents/i })).toBeVisible();
        await expect(page.getByRole('tab', { name: /fichiers|files/i })).toBeVisible();
        await expect(page.getByRole('tab', { name: /membres|members/i })).toBeVisible();
    });

    test('no console errors on owner view', async ({ page }) => {
        const errors = [];
        page.on('console', msg => {
            if (msg.type() === 'error') errors.push(msg.text());
        });

        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        expect(errors).toEqual([]);
    });

    test('no tenant leak: dossier belongs to main org only', async ({ page }) => {
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        // Should not contain launchpals data
        await expect(page.getByText('launchpals', { exact: false })).toHaveCount(0);
    });
});

// ============================================================
// 2. EDITOR VIEW
// ============================================================
test.describe('AUDIT-1033 Editor View — Desktop FR Light', () => {
    test.use({ viewport: { width: 1280, height: 720 }, locale: 'fr-FR' });

    test.beforeEach(async ({ page }) => {
        await login(page, EDITOR_EMAIL, PASSWORD);
    });

    test('editor sees all content and menu actions', async ({ page }) => {
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        // Content visible
        await expect(page.getByText('AUDIT-1033 Article 1').first()).toBeVisible();
        await expect(page.getByText('AUDIT-1033 Article 4').first()).toBeVisible();

        // Editor should see add button
        await expect(page.getByRole('button', { name: /ajouter|add/i })).toBeVisible();

        // Editor should see menu actions (⋯)
        const menuButtons = page.locator('[data-article-menu] button');
        await expect(menuButtons.first()).toBeVisible();
    });

    test('no console errors on editor view', async ({ page }) => {
        const errors = [];
        page.on('console', msg => {
            if (msg.type() === 'error') errors.push(msg.text());
        });

        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        expect(errors).toEqual([]);
    });
});

// ============================================================
// 3. READER VIEW
// ============================================================
test.describe('AUDIT-1033 Reader View — Desktop FR Light', () => {
    test.use({ viewport: { width: 1280, height: 720 }, locale: 'fr-FR' });

    test.beforeEach(async ({ page }) => {
        await login(page, READER_EMAIL, PASSWORD);
    });

    test('reader sees all content but no add button or menus', async ({ page }) => {
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        // Content visible
        await expect(page.getByText('AUDIT-1033 Article 1').first()).toBeVisible();
        await expect(page.getByText('AUDIT-1033 Article 4').first()).toBeVisible();

        // Add button should NOT be visible for reader
        await expect(page.getByRole('button', { name: /ajouter|add/i })).toHaveCount(0);

        // Menu dots should NOT be visible for reader
        const menuButtons = page.locator('[data-article-menu] button');
        await expect(menuButtons).toHaveCount(0);
    });

    test('reader sees members tab content', async ({ page }) => {
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        // Click members tab
        await page.getByRole('tab', { name: /membres|members/i }).click();
        await page.waitForTimeout(800);

        // Members section title
        await expect(page.getByText(/Membres|Members/i).first()).toBeVisible();

        // Reader cannot see management card (hidden by @canManageMembers blade guard)
        await expect(page.locator('button', { hasText: /gérer|manage/i })).toHaveCount(0);

        // But should see at least one member detail (owner badge visible to all roles)
        await expect(page.locator('span:has-text("propriétaire")').or(page.locator('span:has-text("owner")'))).toBeVisible();
    });

    test('no console errors on reader view', async ({ page }) => {
        const errors = [];
        page.on('console', msg => {
            if (msg.type() === 'error') errors.push(msg.text());
        });

        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        expect(errors).toEqual([]);
    });
});

// ============================================================
// 4. CROSS-ORG REFUSED
// ============================================================
test.describe('AUDIT-1033 Cross-Org Refused', () => {
    test('launchpals user cannot access main org dossier', async ({ page }) => {
        await login(page, CROSS_ORG_EMAIL, PASSWORD);
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        // Should show 403 or redirect — definitely NOT the dossier content
        await expect(page.getByText('AUDIT-1033 Article 1')).toHaveCount(0);
    });
});

// ============================================================
// 5. MOBILE EN 320px
// ============================================================
test.describe('AUDIT-1033 Mobile EN 320px', () => {
    test.use({ viewport: { width: 320, height: 568 }, locale: 'en-EN' });

    test.beforeEach(async ({ page }) => {
        await login(page, OWNER_EMAIL, PASSWORD);
    });

    test('full content visible on 320px', async ({ page }) => {
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        // No horizontal overflow
        const bodyWidth = await page.evaluate(() => document.body.scrollWidth);
        const viewportWidth = 320;
        expect(bodyWidth).toBeLessThanOrEqual(viewportWidth + 5);

        // Content visible
        await expect(page.getByText('AUDIT-1033 Article 1').first()).toBeVisible();
    });

    test('no console errors on mobile', async ({ page }) => {
        const errors = [];
        page.on('console', msg => {
            if (msg.type() === 'error') errors.push(msg.text());
        });

        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        expect(errors).toEqual([]);
    });
});

// ============================================================
// 6. MOBILE EN 375px
// ============================================================
test.describe('AUDIT-1033 Mobile EN 375px', () => {
    test.use({ viewport: { width: 375, height: 812 }, locale: 'en-EN' });

    test('full content visible on 375px', async ({ page }) => {
        await login(page, OWNER_EMAIL, PASSWORD);
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        const bodyWidth = await page.evaluate(() => document.body.scrollWidth);
        expect(bodyWidth).toBeLessThanOrEqual(375 + 5);

        await expect(page.getByText('AUDIT-1033 Article 1').first()).toBeVisible();
    });
});

// ============================================================
// 7. MOBILE FR 430px
// ============================================================
test.describe('AUDIT-1033 Mobile FR 430px', () => {
    test.use({ viewport: { width: 430, height: 932 }, locale: 'fr-FR' });

    test('full content visible on 430px', async ({ page }) => {
        await login(page, OWNER_EMAIL, PASSWORD);
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        const bodyWidth = await page.evaluate(() => document.body.scrollWidth);
        expect(bodyWidth).toBeLessThanOrEqual(430 + 5);

        await expect(page.getByText('AUDIT-1033 Article 1').first()).toBeVisible();
    });
});

// ============================================================
// 8. DARK MODE
// ============================================================
test.describe('AUDIT-1033 Dark Mode', () => {
    test.use({ viewport: { width: 1280, height: 720 }, locale: 'fr-FR', colorScheme: 'dark' });

    test('content renders correctly in dark mode', async ({ page }) => {
        await login(page, OWNER_EMAIL, PASSWORD);
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        // Content visible
        await expect(page.getByText('AUDIT-1033 Article 1').first()).toBeVisible();
        await expect(page.getByText('AUDIT-1033 Article 4').first()).toBeVisible();

        // No console errors
        const errors = [];
        page.on('console', msg => {
            if (msg.type() === 'error') errors.push(msg.text());
        });
        await page.reload();
        await page.waitForLoadState('networkidle');
        expect(errors).toEqual([]);
    });
});

// ============================================================
// 9. TAB NAVIGATION
// ============================================================
test.describe('AUDIT-1033 Tab Navigation', () => {
    test.use({ viewport: { width: 1280, height: 720 }, locale: 'fr-FR' });

    test('tabs switch correctly without full reload', async ({ page }) => {
        await login(page, OWNER_EMAIL, PASSWORD);
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        // Contents tab active
        const contentsTab = page.getByRole('tab', { name: /contenus|contents/i });
        await expect(contentsTab).toHaveAttribute('aria-selected', 'true');

        // Click files tab
        await page.getByRole('tab', { name: /fichiers|files/i }).click();
        await page.waitForTimeout(300);
        const filesTab = page.getByRole('tab', { name: /fichiers|files/i });
        await expect(filesTab).toHaveAttribute('aria-selected', 'true');

        // Click members tab
        await page.getByRole('tab', { name: /membres|members/i }).click();
        await page.waitForTimeout(300);
        const membersTab = page.getByRole('tab', { name: /membres|members/i });
        await expect(membersTab).toHaveAttribute('aria-selected', 'true');

        // Back to contents
        await contentsTab.click();
        await page.waitForTimeout(300);
        await expect(contentsTab).toHaveAttribute('aria-selected', 'true');
    });
});

// ============================================================
// 10. FRENCH LANGUAGE CONSISTENCY
// ============================================================
test.describe('AUDIT-1033 FR Language', () => {
    test.use({ viewport: { width: 1280, height: 720 }, locale: 'fr-FR' });

    test('all UI text is in French', async ({ page }) => {
        await login(page, OWNER_EMAIL, PASSWORD);
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        // Check key French labels
        await expect(page.getByRole('tab', { name: 'Contenus' })).toBeVisible();
        await expect(page.getByRole('tab', { name: 'Fichiers' })).toBeVisible();
        await expect(page.getByRole('tab', { name: 'Membres' })).toBeVisible();
    });
});

// ============================================================
// 11. ENGLISH LANGUAGE CONSISTENCY
// ============================================================
test.describe('AUDIT-1033 EN Language', () => {
    test.use({ viewport: { width: 1280, height: 720 }, locale: 'fr-FR' });

    test('all UI text is in English after switching', async ({ page }) => {
        await login(page, OWNER_EMAIL, PASSWORD);
        await page.goto(DOSSIER_URL);
        await page.waitForLoadState('networkidle');

        // Click EN language switcher
        const enButton = page.getByRole('button', { name: 'EN', exact: true });
        if (await enButton.isVisible()) {
            await enButton.click();
            await page.waitForLoadState('networkidle');
        }

        // Check key English labels
        await expect(page.getByRole('tab', { name: 'Contents' })).toBeVisible();
        await expect(page.getByRole('tab', { name: 'Files' })).toBeVisible();
        await expect(page.getByRole('tab', { name: 'Members' })).toBeVisible();
    });
});
