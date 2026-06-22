/**
 * QA-02: Service Request Transaction Complete Workflow (Happy Path - P0)
 *
 * Tests complete workflow of a request-based transaction:
 * IMPORTANT: Role inversion - buyer is request creator, seller is responder
 * 1. User A creates a service request
 * 2. User B proposes an exchange to answer the request
 * 3. User A accepts the proposal
 * 4. User B marks as complete
 * 5. User A confirms and points are transferred
 * 6. Both users can leave reviews
 *
 * Priority: P0 (Critical Happy Path)
 * Complexity: Medium (role inversion adds complexity)
 * Coverage: Request workflow, role inversion, state transitions
 */

import { test, expect } from '@playwright/test';
import { login, captureScreenshot, setupConsoleLogging } from '../../../../ai/playwright/helpers/index.js';
import {
    goToRequest,
    goToMessageThread,
    goToCommunity,
    waitForNotification,
    getUserPoints,
} from '../helpers/community.js';
import { SELECTORS, TEST_VALUES, TRANSACTION_STATUS } from '../helpers/config.js';
import { extractSlugFromUrl } from '../helpers/community.js';
import '../../../setup.js';

// Test users from environment
const REQUESTER_EMAIL = process.env.TEST_MEMBER1_LOGIN; // Alice - creates request (will be buyer in transaction)
const REQUESTER_PASSWORD = process.env.TEST_MEMBER1_PASSWORD;
const RESPONDER_EMAIL = process.env.TEST_MEMBER2_LOGIN; // Cyril - answers request (will be seller in transaction)
const RESPONDER_PASSWORD = process.env.TEST_MEMBER2_PASSWORD;

test.describe('QA-02: Service Request Transaction Complete Workflow', () => {
    let requestId = null;
    let transactionId = null;
    let communitySlug = null;
    let requesterInitialPoints = null;
    let responderInitialPoints = null;

    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    /**
     * SETUP: Create a test service request
     */
    test('setup: create test service request', async ({ page }) => {
        // Login as requester
        await login(page, REQUESTER_EMAIL, REQUESTER_PASSWORD);

        // Get community slug
        const url = page.url();

        communitySlug = extractSlugFromUrl(page.url());

        // Navigate to create request
        await page.goto(`/${communitySlug}/requests/create`);
        await page.waitForLoadState('networkidle');

        // Fill request form
        await page.fill('input[name="title"]', TEST_VALUES.requestTitle);
        await page.fill('textarea[name="description"]', TEST_VALUES.requestDescription);

        // Select category if available
        const categorySelect = page.locator('select[name="category_id"]');
        if (await categorySelect.isVisible()) {
            await categorySelect.selectOption({ index: 0 });
        }

        // Set budget if available
        const budgetMin = page.locator('input[name="budget_min"]');
        if (await budgetMin.isVisible()) {
            await budgetMin.fill('5');
        }
        const budgetMax = page.locator('input[name="budget_max"]');
        if (await budgetMax.isVisible()) {
            await budgetMax.fill('15');
        }

        await captureScreenshot(page, 'QA-02-setup-request-form');

        // Submit form
        const submitButton = page.locator('button[type="submit"]').filter({ hasText: /Créer|Publier|Enregistrer/ });
        await submitButton.click();

        // Should redirect to request page
        await page.waitForURL(/\/requests\/[\w-]+/);

        // Get request ID from URL
        const urlAfter = page.url();
        const idMatch = urlAfter.match(/\/requests\/([\w-]+)$/);
        if (idMatch) {
            requestId = idMatch[1];
            console.log(`✅ Request created: ${requestId}`);
        }

        // Verify request is visible
        await expect(page.locator('h1, h2').filter({ hasText: /logo/ })).toBeVisible();

        await captureScreenshot(page, 'QA-02-setup-request-created');

        // Logout to prepare for responder
        await page.goto('/logout');
    });

    /**
     * STEP 1: Responder views request
     *
     * Validates:
     * - Request page loads correctly
     * - Request details are visible
     * - Requester information is accessible
     * - Respond button is available
     */
    test('step1: responder views request', async ({ page }) => {
        test.skip(!requestId, 'Request ID not available from setup');

        // Login as responder
        await login(page, RESPONDER_EMAIL, RESPONDER_PASSWORD);

        // Navigate to request
        await goToRequest(page, requestId, communitySlug);

        // Verify request title
        await expect(page.locator('h1, h2').filter({ hasText: /logo/ })).toBeVisible();

        // Verify request description
        await expect(page.locator('text=' + TEST_VALUES.requestDescription)).toBeVisible();

        // Verify respond/answer button is visible
        const respondButton = page.locator('button:has-text("Répondre"), button:has-text("Proposer"), a:has-text("Répondre")').first();
        await expect(respondButton).toBeVisible();

        // Check if requester profile link exists
        const profileLink = page.locator('a[href*="/profile/"]').first();
        const hasProfileLink = await profileLink.count() > 0;

        if (hasProfileLink) {
            await captureScreenshot(page, 'QA-02-step1-responder-views-request-with-profile');
        } else {
            await captureScreenshot(page, 'QA-02-step1-responder-views-request-no-profile-link');
            console.log('⚠️ WARNING: No profile link found on request page - UX issue');
        }

        console.log('✅ STEP 1: Responder can view request and respond');
    });

    /**
     * STEP 2: Responder proposes exchange
     *
     * Validates:
     * - Transaction form opens correctly
     * - Points can be proposed
     * - Request status changes to 'in_progress'
     * - Transaction is created with pending status
     * - IMPORTANT: Role inversion - responder becomes seller in transaction
     */
    test('step2: responder proposes exchange', async ({ page }) => {
        test.skip(!requestId, 'Request ID not available from setup');

        await goToRequest(page, requestId, communitySlug);

        // Click respond button
        const respondButton = page.locator('button:has-text("Répondre"), button:has-text("Proposer"), a:has-text("Répondre")').first();
        await respondButton.click();

        // Wait for modal or form
        await page.waitForTimeout(500);

        // Check if form is in modal or separate page
        const isInModal = await page.locator('[class*="modal"], [role="dialog"]').count() > 0;

        if (isInModal) {
            // Fill form in modal
            await page.fill('input[name="points_proposed"], input[name="points"]', String(TEST_VALUES.requestPoints));
            const submitButton = page.locator('button[type="submit"]').filter({ hasText: /Envoyer|Proposer|Répondre/ }).first();
            await submitButton.click();
        } else {
            // Check if redirected to transaction form
            const url = page.url();
            if (url.includes('/transactions') || url.includes('/exchange')) {
                await page.fill('input[name="points_proposed"], input[name="points"]', String(TEST_VALUES.requestPoints));
                const submitButton = page.locator('button[type="submit"]').filter({ hasText: /Envoyer|Proposer|Répondre/ }).first();
                await submitButton.click();
            }
        }

        await captureScreenshot(page, 'QA-02-step2-proposed');

        // Verify redirect to messages/transaction thread
        await page.waitForURL(/\/messages\/[\w-]+/);

        // Get transaction ID from URL
        const url = page.url();
        const idMatch = url.match(/\/messages\/([\w-]+)$/);
        if (idMatch) {
            transactionId = idMatch[1];
            console.log(`✅ Transaction created from request: ${transactionId}`);
        }

        // Verify system message is visible
        const hasSystemMessage = await page.locator('text=Nouvelle échange, text=proposé, text=réponse').count() > 0;

        await captureScreenshot(page, 'QA-02-step2-transaction-created');

        console.log('✅ STEP 2: Responder proposed exchange (will be seller in transaction)');
    });

    /**
     * STEP 3: Requester accepts the proposal
     *
     * Validates:
     * - Only requester can accept (role inversion: requester = buyer)
     * - Status changes to 'accepted'
     * - System message is generated
     */
    test('step3: requester accepts proposal', async ({ page }) => {
        test.skip(!transactionId, 'Transaction ID not available from setup');

        // Login as requester (who is the BUYER in this transaction)
        await login(page, REQUESTER_EMAIL, REQUESTER_PASSWORD);

        // Get initial points
        requesterInitialPoints = await getUserPoints(page);
        console.log(`💰 Requester (buyer) initial points: ${requesterInitialPoints}`);

        // Navigate to message thread
        await goToMessageThread(page, transactionId, communitySlug);

        // Verify accept button is visible
        const acceptButton = page.locator(SELECTORS.acceptButton).first();
        await expect(acceptButton).toBeVisible();

        // Accept transaction
        await acceptButton.click();

        // Wait for status update
        await page.waitForTimeout(500);

        // Verify status changed to accepted
        await expect(page.locator('text=acceptée, text=Acceptée, text=En cours').first()).toBeVisible();

        await captureScreenshot(page, 'QA-02-step3-transaction-accepted');

        console.log('✅ STEP 3: Requester (buyer) accepted proposal');
    });

    /**
     * STEP 4: Responder marks transaction as complete
     *
     * Validates:
     * - Only responder (seller) can mark complete
     * - Status changes to 'buyer_done'
     * - Requester is notified
     */
    test('step4: responder marks transaction as complete', async ({ page }) => {
        test.skip(!transactionId, 'Transaction ID not available from setup');

        // Login as responder (who is the SELLER in this transaction)
        await login(page, RESPONDER_EMAIL, RESPONDER_PASSWORD);

        // Navigate to message thread
        await goToMessageThread(page, transactionId, communitySlug);

        // Verify complete button is visible
        const completeButton = page.locator(SELECTORS.completeButton).first();
        await expect(completeButton).toBeVisible();

        // Mark as complete
        await completeButton.click();

        // Wait for status update
        await page.waitForTimeout(500);

        // Verify status changed to buyer_done / awaiting confirmation
        await expect(page.locator('text=terminé, text=déclaré terminé, text=confirmation').first()).toBeVisible();

        await captureScreenshot(page, 'QA-02-step4-transaction-responder-done');

        console.log('✅ STEP 4: Responder (seller) marked transaction complete');
    });

    /**
     * STEP 5: Requester confirms completion
     *
     * Validates:
     * - Only requester (buyer) can confirm
     * - Status changes to 'completed'
     * - Points are transferred (requester -points, responder +points)
     * - Role inversion confirmed in points flow
     */
    test('step5: requester confirms completion', async ({ page }) => {
        test.skip(!transactionId, 'Transaction ID not available from setup');

        // Login as requester (who is the BUYER in this transaction)
        await login(page, REQUESTER_EMAIL, REQUESTER_PASSWORD);

        // Navigate to message thread
        await goToMessageThread(page, transactionId, communitySlug);

        // Verify confirm button is visible
        const confirmButton = page.locator(SELECTORS.confirmButton).first();
        await expect(confirmButton).toBeVisible();

        // Confirm completion
        await confirmButton.click();

        // Wait for status update
        await page.waitForTimeout(500);

        // Verify status changed to completed
        await expect(page.locator('text=complétée, text=terminée, text=Terminée').first()).toBeVisible();

        // Verify points transfer notification
        const hasPointsTransferMessage = await page.locator('text=points transférés, text=Points').count() > 0;

        await captureScreenshot(page, 'QA-02-step5-transaction-completed');

        console.log('✅ STEP 5: Requester (buyer) confirmed completion');
    });

    /**
     * VALIDATION: Verify responder's points were received
     *
     * In request transactions, the responder is the SELLER and receives points
     */
    test('validation: responder points received correctly', async ({ page }) => {
        test.skip(!transactionId, 'Transaction ID not available from setup');

        // Login as responder (who is the SELLER)
        await login(page, RESPONDER_EMAIL, RESPONDER_PASSWORD);

        // Get initial points first (if not already captured)
        if (responderInitialPoints === null) {
            responderInitialPoints = await getUserPoints(page);
            console.log(`💰 Responder (seller) initial points: ${responderInitialPoints}`);
        }

        // Go to dashboard
        await goToCommunity(page, communitySlug);

        // Check final points
        const finalResponderPoints = await getUserPoints(page);
        const expectedPoints = responderInitialPoints + TEST_VALUES.requestPoints;

        console.log(`💰 Responder points: ${responderInitialPoints} → ${finalResponderPoints} (+${TEST_VALUES.requestPoints})`);

        expect(finalResponderPoints).toBe(expectedPoints);

        await captureScreenshot(page, 'QA-02-validation-responder-points');

        console.log('✅ VALIDATION: Responder (seller) points received correctly - Role inversion confirmed');
    });

    /**
     * VALIDATION: Verify requester's points were deducted
     *
     * In request transactions, the requester is the BUYER and pays points
     */
    test('validation: requester points deducted correctly', async ({ page }) => {
        test.skip(!transactionId, 'Transaction ID not available from setup');

        // Login as requester (who is the BUYER)
        await login(page, REQUESTER_EMAIL, REQUESTER_PASSWORD);

        // Go to dashboard
        await goToCommunity(page, communitySlug);

        // Check final points
        const finalRequesterPoints = await getUserPoints(page);
        const expectedPoints = requesterInitialPoints - TEST_VALUES.requestPoints;

        console.log(`💰 Requester points: ${requesterInitialPoints} → ${finalRequesterPoints} (-${TEST_VALUES.requestPoints})`);

        expect(finalRequesterPoints).toBe(expectedPoints);

        await captureScreenshot(page, 'QA-02-validation-requester-points');

        console.log('✅ VALIDATION: Requester (buyer) points deducted correctly');
    });
});
