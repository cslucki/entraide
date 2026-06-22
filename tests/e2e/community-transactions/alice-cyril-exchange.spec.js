/**
 * QA-02: Alice & Cyril Logo Exchange (Service Request)
 *
 * User-story flavored test for the Service Request workflow.
 *
 * Scenario:
 * 1. Alice (MEMBER1) creates a service request "Création d'un logo pour mon association"
 * 2. Cyril (MEMBER2) finds the request and proposes his help (20 pts)
 * 3. Cyril (seller) approves the proposal → status 'accepted'
 * 4. Alice (buyer) declares work done → status 'buyer_done'
 * 5. Cyril (seller) confirms completion → status 'completed', points transfer
 * 6. Validation: both users see correct final balances (Alice -20, Cyril +20)
 *
 * Role inversion (request-based): requester = buyer, responder = seller
 * Users are global platform members (no community context).
 */

import { test, expect } from '@playwright/test';
import { login, captureScreenshot, setupConsoleLogging } from '../../../ai/playwright/helpers/index.js';
import { getUserPoints } from './helpers/community.js';
import { TEST_VALUES } from './helpers/config.js';
import '../../setup.js';

const ALICE_EMAIL = process.env.TEST_MEMBER1_LOGIN;
const ALICE_PASSWORD = process.env.TEST_MEMBER1_PASSWORD;
const CYRIL_EMAIL = process.env.TEST_MEMBER2_LOGIN;
const CYRIL_PASSWORD = process.env.TEST_MEMBER2_PASSWORD;

const PROPOSED_POINTS = 20;

/** Switch user: clear session cookies then login as someone else */
async function loginAs(page, email, password) {
    await page.context().clearCookies();
    await login(page, email, password);
}

test.describe('QA-02: Alice & Cyril Logo Exchange (Service Request)', () => {
    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    test('Full flow: create → propose → accept → complete → confirm → validate points', async ({ page }) => {
        // ============ STEP 1: Alice creates a request ============
        await login(page, ALICE_EMAIL, ALICE_PASSWORD);

        await page.goto('/requests/create');
        await page.waitForLoadState('networkidle');

        await page.fill('input[name="title"]', TEST_VALUES.requestTitle);
        await page.fill('textarea[name="description"]', TEST_VALUES.requestDescription);

        const categorySelect = page.locator('select[name="category_id"]');
        if (await categorySelect.isVisible()) {
            await categorySelect.selectOption({ index: 1 });
        }

        await page.locator('input[name="delivery_mode"][value="remote"]').check({ force: true });

        await page.fill('input[name="budget_min"]', String(PROPOSED_POINTS));

        await page.click('button[type="submit"]');

        await expect(page).toHaveURL(/\/dashboard/);
        await expect(page.locator('text=Demande publiée avec succès').first()).toBeVisible();

        await captureScreenshot(page, 'alice-request-created');

        // ============ STEP 2: Cyril finds request and proposes help ============
        await loginAs(page, CYRIL_EMAIL, CYRIL_PASSWORD);

        await page.goto('/explorer?tab=requests');
        await page.waitForLoadState('networkidle');

        const requestLink = page.locator(`a:has-text("${TEST_VALUES.requestTitle}")`).first();
        await expect(requestLink).toBeVisible({ timeout: 10000 });

        const href = await requestLink.getAttribute('href');
        const requestId = href.split('/').pop();

        await requestLink.click();
        await page.waitForLoadState('networkidle');

        await expect(page.locator('h1, h2').filter({ hasText: /logo/i })).toBeVisible();

        await captureScreenshot(page, 'cyril-on-request-page');

        // Fill points BEFORE clicking submit
        const pointsInput = page.locator('input[name="points_proposed"]');
        await expect(pointsInput).toBeVisible();
        await pointsInput.fill(String(PROPOSED_POINTS));

        const proposeButton = page.locator('button:has-text("Proposer mon aide")').first();
        await expect(proposeButton).toBeVisible();

        const cyrilInitialPoints = await getUserPoints(page);

        await proposeButton.click();

        await page.waitForURL(url => url.pathname.includes('/messages/'));
        await page.waitForLoadState('networkidle');

        const match = page.url().match(/\/messages\/([\w-]+)/);
        const transactionId = match ? match[1] : null;
        expect(transactionId).not.toBeNull();

        await expect(page.getByText('Nouvelle échange envoyée').first()).toBeVisible();
        await captureScreenshot(page, 'cyril-proposed-help');

        // ============ STEP 3: Cyril (seller) approves the proposal ============
        await loginAs(page, CYRIL_EMAIL, CYRIL_PASSWORD);
        await page.goto(`/messages/${transactionId}`);
        await page.waitForLoadState('networkidle');

        const approveButton = page.locator('button:has-text("Accepter")').first();
        await expect(approveButton).toBeVisible();
        await approveButton.click();

        await page.waitForTimeout(500);
        await captureScreenshot(page, 'cyril-approved-proposal');

        // ============ STEP 4: Alice (buyer) declares work done ============
        await loginAs(page, ALICE_EMAIL, ALICE_PASSWORD);
        await page.goto(`/messages/${transactionId}`);
        await page.waitForLoadState('networkidle');

        const aliceInitialPoints = await getUserPoints(page);

        const declareDoneButton = page.locator('button:has-text("Déclarer terminé")').first();
        await expect(declareDoneButton).toBeVisible();
        await declareDoneButton.click();

        await page.waitForTimeout(500);
        await captureScreenshot(page, 'alice-declared-done');

        // ============ STEP 5: Cyril (seller) confirms completion ============
        await loginAs(page, CYRIL_EMAIL, CYRIL_PASSWORD);
        await page.goto(`/messages/${transactionId}`);
        await page.waitForLoadState('networkidle');

        const confirmButton = page.locator('button:has-text("Confirmer")').first();
        await expect(confirmButton).toBeVisible();
        await confirmButton.click();

        await page.waitForTimeout(500);
        await captureScreenshot(page, 'cyril-confirmed-completion');

        // ============ VALIDATION: Cyril received points ============
        await loginAs(page, CYRIL_EMAIL, CYRIL_PASSWORD);
        await page.goto('/dashboard');
        await page.waitForLoadState('networkidle');

        const cyrilFinalPoints = await getUserPoints(page);
        const expectedCyrilPoints = cyrilInitialPoints !== null ? cyrilInitialPoints + PROPOSED_POINTS : null;
        if (expectedCyrilPoints !== null) {
            expect(cyrilFinalPoints).toBe(expectedCyrilPoints);
        }

        await captureScreenshot(page, 'validation-cyril-points');

        // ============ VALIDATION: Alice points deducted ============
        await loginAs(page, ALICE_EMAIL, ALICE_PASSWORD);
        await page.goto('/dashboard');
        await page.waitForLoadState('networkidle');

        const aliceFinalPoints = await getUserPoints(page);
        const expectedAlicePoints = aliceInitialPoints !== null ? aliceInitialPoints - PROPOSED_POINTS : null;
        if (expectedAlicePoints !== null) {
            expect(aliceFinalPoints).toBe(expectedAlicePoints);
        }

        await captureScreenshot(page, 'validation-alice-points');
    });
});
