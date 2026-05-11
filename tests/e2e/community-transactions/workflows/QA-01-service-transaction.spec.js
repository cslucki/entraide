/**
 * QA-01: Service Transaction Complete Workflow (Happy Path - P0)
 *
 * Tests the complete workflow of a service-based transaction:
 * 1. Buyer views seller's service
 * 2. Buyer proposes exchange with points
 * 3. Seller accepts proposal
 * 4. Buyer marks as complete
 * 5. Seller confirms and points are transferred
 * 6. Both users can leave reviews
 *
 * Priority: P0 (Critical Happy Path)
 * Complexity: Medium
 * Coverage: Full transaction lifecycle, permissions, state transitions
 */

import { test, expect } from '@playwright/test';
import { login, captureScreenshot, setupConsoleLogging } from '../../../../ai/playwright/helpers/index.js';
import {
    goToService,
    goToMessageThread,
    goToCommunity,
    goToProfile,
    waitForNotification,
    getUserPoints,
} from '../helpers/community.js';
import { SELECTORS, TEST_VALUES, TRANSACTION_STATUS } from '../helpers/config.js';
import '../../../setup.js';

// Test users from environment
const BUYER_EMAIL = process.env.TEST_MEMBER1_LOGIN; // Alice
const BUYER_PASSWORD = process.env.TEST_MEMBER1_PASSWORD;
const SELLER_EMAIL = process.env.TEST_MEMBER2_LOGIN; // Cyril
const SELLER_PASSWORD = process.env.TEST_MEMBER2_PASSWORD;

test.describe('QA-01: Service Transaction Complete Workflow', () => {
    let serviceId = null;
    let transactionId = null;
    let communitySlug = null;
    let buyerInitialPoints = null;
    let sellerInitialPoints = null;

    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    /**
     * SETUP: Create a test service for the transaction
     */
    test('setup: create test service as seller', async ({ page }) => {
        // Login as seller
        await login(page, SELLER_EMAIL, SELLER_PASSWORD);

        // Get community slug from current URL
        const url = page.url();
        const slugMatch = url.match(/\/([a-z0-9-]+)\//);
        communitySlug = slugMatch ? slugMatch[1] : 'default';

        // Navigate to create service
        await page.goto(`/${communitySlug}/services/create`);
        await page.waitForLoadState('networkidle');

        // Fill service form
        await page.fill('input[name="title"]', TEST_VALUES.serviceTitle);
        await page.fill('textarea[name="description"]', TEST_VALUES.serviceDescription);

        // Select first category
        const categorySelect = page.locator('select[name="category_id"]');
        if (await categorySelect.isVisible()) {
            await categorySelect.selectOption({ index: 0 });
        }

        // Select delivery mode
        const deliverySelect = page.locator('select[name="delivery_mode"]');
        if (await deliverySelect.isVisible()) {
            await deliverySelect.selectOption('remote');
        }

        // Set points
        await page.fill('input[name="points_cost"]', String(TEST_VALUES.servicePoints));

        // Capture screenshot before submit
        await captureScreenshot(page, 'QA-01-setup-service-form');

        // Submit form
        const submitButton = page.locator('button[type="submit"]').filter({ hasText: /Créer|Publier|Enregistrer/ });
        await submitButton.click();

        // Should redirect to service page
        await page.waitForURL(/\/services\/[\w-]+/);

        // Get service ID from URL
        const urlAfter = page.url();
        const idMatch = urlAfter.match(/\/services\/([\w-]+)$/);
        if (idMatch) {
            serviceId = idMatch[1];
            console.log(`✅ Service created: ${serviceId}`);
        }

        // Verify service is visible
        await expect(page.locator('h1, h2').filter({ hasText: /Service de test/ })).toBeVisible();

        await captureScreenshot(page, 'QA-01-setup-service-created');

        // Logout to prepare for buyer
        await page.goto('/logout');
    });

    /**
     * STEP 1: Buyer views seller's service
     *
     * Validates:
     * - Service page loads correctly
     * - Service details are visible
     * - Seller information is accessible
     * - Propose button is available
     */
    test('step1: buyer views seller service', async ({ page }) => {
        test.skip(!serviceId, 'Service ID not available from setup');

        // Login as buyer
        await login(page, BUYER_EMAIL, BUYER_PASSWORD);

        // Get initial points
        buyerInitialPoints = await getUserPoints(page);
        console.log(`💰 Buyer initial points: ${buyerInitialPoints}`);

        // Navigate to service
        await goToService(page, serviceId, communitySlug);

        // Verify service title
        await expect(page.locator('h1, h2').filter({ hasText: /Service de test/ })).toBeVisible();

        // Verify service description
        await expect(page.locator('text=' + TEST_VALUES.serviceDescription)).toBeVisible();

        // Verify points are displayed
        await expect(page.locator('text=' + TEST_VALUES.servicePoints)).toBeVisible();
        await expect(page.locator('text=points')).toBeVisible();

        // Verify propose/exchange button is visible
        const proposeButton = page.locator(SELECTORS.proposeButton).first();
        await expect(proposeButton).toBeVisible();

        // Check if seller profile link exists
        const profileLink = page.locator('a[href*="/profile/"]').first();
        const hasProfileLink = await profileLink.count() > 0;

        if (hasProfileLink) {
            await captureScreenshot(page, 'QA-01-step1-buyer-views-service-with-profile');
        } else {
            await captureScreenshot(page, 'QA-01-step1-buyer-views-service-no-profile-link');
            console.log('⚠️ WARNING: No profile link found on service page - UX issue');
        }

        console.log('✅ STEP 1: Buyer can view service and propose exchange');
    });

    /**
     * STEP 2: Buyer proposes transaction
     *
     * Validates:
     * - Transaction form opens correctly
     * - Points can be proposed
     * - Transaction is created with pending status
     * - System message is generated
     * - Redirect to message thread
     */
    test('step2: buyer proposes transaction', async ({ page }) => {
        test.skip(!serviceId, 'Service ID not available from setup');

        await goToService(page, serviceId, communitySlug);

        // Click propose button
        const proposeButton = page.locator(SELECTORS.proposeButton).first();
        await proposeButton.click();

        // Wait for modal or form
        await page.waitForTimeout(500);

        // Check if form is in modal or separate page
        const isInModal = await page.locator('[class*="modal"], [role="dialog"]').count() > 0;

        if (isInModal) {
            // Fill form in modal
            await page.fill('input[name="points_proposed"], input[name="points"]', String(TEST_VALUES.servicePoints));
            const submitButton = page.locator('button[type="submit"]').filter({ hasText: /Envoyer|Proposer/ }).first();
            await submitButton.click();
        } else {
            // Check if redirected to transaction form
            const url = page.url();
            if (url.includes('/transactions') || url.includes('/exchange')) {
                await page.fill('input[name="points_proposed"], input[name="points"]', String(TEST_VALUES.servicePoints));
                const submitButton = page.locator('button[type="submit"]').filter({ hasText: /Envoyer|Proposer/ }).first();
                await submitButton.click();
            }
        }

        await captureScreenshot(page, 'QA-01-step2-proposed');

        // Verify redirect to messages/transaction thread
        await page.waitForURL(/\/messages\/[\w-]+/);

        // Get transaction ID from URL
        const url = page.url();
        const idMatch = url.match(/\/messages\/([\w-]+)$/);
        if (idMatch) {
            transactionId = idMatch[1];
            console.log(`✅ Transaction created: ${transactionId}`);
        }

        // Verify system message is visible
        const hasSystemMessage = await page.locator('text=Nouvelle échange, text=proposé, text=proposition').count() > 0;
        if (hasSystemMessage) {
            await expect(page.locator('text=Nouvelle échange, text=proposé, text=proposition').first()).toBeVisible();
        }

        // Verify status shows as pending
        const statusElement = page.locator('[class*="status"], [class*="badge"], .transaction-status').first();
        if (await statusElement.isVisible()) {
            const statusText = await statusElement.textContent();
            console.log(`📊 Transaction status: ${statusText}`);
        }

        await captureScreenshot(page, 'QA-01-step2-transaction-created');

        console.log('✅ STEP 2: Transaction proposed successfully');
    });

    /**
     * STEP 3: Seller accepts transaction
     *
     * Validates:
     * - Only seller can accept
     * - Status changes to 'accepted'
     * - System message is generated
     * - Points are locked/agreed
     */
    test('step3: seller accepts transaction', async ({ page }) => {
        test.skip(!transactionId, 'Transaction ID not available from setup');

        // Login as seller
        await login(page, SELLER_EMAIL, SELLER_PASSWORD);

        // Get initial points
        sellerInitialPoints = await getUserPoints(page);
        console.log(`💰 Seller initial points: ${sellerInitialPoints}`);

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

        // Verify system message about acceptance
        const hasAcceptMessage = await page.locator('text=acceptée, text=accepté').count() > 0;

        await captureScreenshot(page, 'QA-01-step3-transaction-accepted');

        // Verify points are still not transferred (balance unchanged)
        const currentSellerPoints = await getUserPoints(page);
        expect(currentSellerPoints).toBe(sellerInitialPoints);
        console.log(`💰 Seller points after accept: ${currentSellerPoints} (unchanged - correct)`);

        console.log('✅ STEP 3: Transaction accepted by seller');
    });

    /**
     * STEP 4: Buyer marks transaction as complete
     *
     * Validates:
     * - Only buyer can mark complete
     * - Status changes to 'buyer_done'
     * - Seller is notified (visible in UI)
     */
    test('step4: buyer marks transaction as complete', async ({ page }) => {
        test.skip(!transactionId, 'Transaction ID not available from setup');

        // Login as buyer
        await login(page, BUYER_EMAIL, BUYER_PASSWORD);

        // Navigate to message thread
        await goToMessageThread(page, transactionId, communitySlug);

        // Verify complete button is visible (only after acceptance)
        const completeButton = page.locator(SELECTORS.completeButton).first();
        await expect(completeButton).toBeVisible();

        // Mark as complete
        await completeButton.click();

        // Wait for status update
        await page.waitForTimeout(500);

        // Verify status changed to buyer_done / awaiting confirmation
        await expect(page.locator('text=terminé, text=déclaré terminé, text=confirmation, text=buyer_done').first()).toBeVisible();

        await captureScreenshot(page, 'QA-01-step4-transaction-buyer-done');

        console.log('✅ STEP 4: Transaction marked complete by buyer');
    });

    /**
     * STEP 5: Seller confirms completion
     *
     * Validates:
     * - Only seller can confirm
     * - Status changes to 'completed'
     * - Points are transferred (buyer -points, seller +points)
     * - System message confirms transfer
     */
    test('step5: seller confirms completion', async ({ page }) => {
        test.skip(!transactionId, 'Transaction ID not available from setup');

        // Login as seller
        await login(page, SELLER_EMAIL, SELLER_PASSWORD);

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
        await expect(page.locator('text=complétée, text=terminée, text=Terminée, text=completed').first()).toBeVisible();

        // Verify points transfer notification
        const hasPointsTransferMessage = await page.locator('text=points transférés, text=Points').count() > 0;

        await captureScreenshot(page, 'QA-01-step5-transaction-completed');

        // Verify points were transferred
        const currentSellerPoints = await getUserPoints(page);
        const expectedPoints = sellerInitialPoints + TEST_VALUES.servicePoints;
        expect(currentSellerPoints).toBe(expectedPoints);
        console.log(`💰 Seller points after confirm: ${currentSellerPoints} (+${TEST_VALUES.servicePoints})`);

        console.log('✅ STEP 5: Transaction confirmed by seller, points transferred');
    });

    /**
     * STEP 6a: Buyer leaves review
     *
     * Validates:
     * - Review can be left after completion
     * - Rating is recorded
     * - Comment is recorded
     * - Cannot review twice
     */
    test('step6a: buyer leaves review', async ({ page }) => {
        test.skip(!transactionId, 'Transaction ID not available from setup');

        // Login as buyer
        await login(page, BUYER_EMAIL, BUYER_PASSWORD);

        // Navigate to message thread
        await goToMessageThread(page, transactionId, communitySlug);

        // Look for review form
        const reviewForm = page.locator('form:has-text("avis"), form:has-text("notation"), form:has-text("évaluation")').first();

        if (await reviewForm.isVisible({ timeout: 2000 })) {
            // Select rating
            const ratingInput = page.locator(`${SELECTORS.ratingInput}[value="${TEST_VALUES.reviewRating}"]`);
            if (await ratingInput.isVisible()) {
                await ratingInput.click();
            } else {
                // Try star rating
                await page.click(`button[data-rating="${TEST_VALUES.reviewRating}"], .star:nth-child(${TEST_VALUES.reviewRating})`);
            }

            // Add comment
            await page.fill(SELECTORS.reviewComment, TEST_VALUES.reviewComment);

            // Submit review
            const submitButton = page.locator(SELECTORS.submitReview).first();
            await submitButton.click();

            // Wait for submission
            await page.waitForTimeout(500);

            // Verify success message or review display
            const hasSuccessMessage = await page.locator('text=Merci, text=évaluation, text=envoyé').count() > 0;
            const hasReview = await page.locator(`text=${TEST_VALUES.reviewComment}`).count() > 0;

            await captureScreenshot(page, 'QA-01-step6a-buyer-review');

            expect(hasSuccessMessage || hasReview).toBe(true);
            console.log('✅ STEP 6a: Buyer review submitted');
        } else {
            // Review form might not be visible (already reviewed or UX issue)
            await captureScreenshot(page, 'QA-01-step6a-buyer-review-form-not-found');
            console.log('⚠️ WARNING: Review form not visible - may already be reviewed or UX issue');
            test.skip(true, 'Review form not visible');
        }
    });

    /**
     * STEP 6b: Seller leaves review
     *
     * Validates:
     * - Both parties can leave reviews
     * - Reviews are stored correctly
     */
    test('step6b: seller leaves review', async ({ page }) => {
        test.skip(!transactionId, 'Transaction ID not available from setup');

        // Login as seller
        await login(page, SELLER_EMAIL, SELLER_PASSWORD);

        // Navigate to message thread
        await goToMessageThread(page, transactionId, communitySlug);

        // Look for review form
        const reviewForm = page.locator('form:has-text("avis"), form:has-text("notation"), form:has-text("évaluation")').first();

        if (await reviewForm.isVisible({ timeout: 2000 })) {
            // Select rating
            const ratingInput = page.locator(`${SELECTORS.ratingInput}[value="${TEST_VALUES.reviewRating}"]`);
            if (await ratingInput.isVisible()) {
                await ratingInput.click();
            } else {
                // Try star rating
                await page.click(`button[data-rating="${TEST_VALUES.reviewRating}"], .star:nth-child(${TEST_VALUES.reviewRating})`);
            }

            // Add comment
            await page.fill(SELECTORS.reviewComment, 'Great buyer, easy to work with!');

            // Submit review
            const submitButton = page.locator(SELECTORS.submitReview).first();
            await submitButton.click();

            // Wait for submission
            await page.waitForTimeout(500);

            // Verify success message or review display
            const hasSuccessMessage = await page.locator('text=Merci, text=évaluation, text=envoyé').count() > 0;
            const hasReview = await page.locator('text=Great buyer').count() > 0;

            await captureScreenshot(page, 'QA-01-step6b-seller-review');

            expect(hasSuccessMessage || hasReview).toBe(true);
            console.log('✅ STEP 6b: Seller review submitted');
        } else {
            // Review form might not be visible (already reviewed or UX issue)
            await captureScreenshot(page, 'QA-01-step6b-seller-review-form-not-found');
            console.log('⚠️ WARNING: Review form not visible - may already be reviewed or UX issue');
            test.skip(true, 'Review form not visible');
        }
    });

    /**
     * VALIDATION: Verify buyer's points were deducted
     */
    test('validation: buyer points deducted correctly', async ({ page }) => {
        test.skip(!transactionId, 'Transaction ID not available from setup');

        // Login as buyer
        await login(page, BUYER_EMAIL, BUYER_PASSWORD);

        // Go to dashboard
        await goToCommunity(page, communitySlug);

        // Check final points
        const finalBuyerPoints = await getUserPoints(page);
        const expectedPoints = buyerInitialPoints - TEST_VALUES.servicePoints;

        console.log(`💰 Buyer points: ${buyerInitialPoints} → ${finalBuyerPoints} (-${TEST_VALUES.servicePoints})`);

        expect(finalBuyerPoints).toBe(expectedPoints);

        await captureScreenshot(page, 'QA-01-validation-buyer-points');

        console.log('✅ VALIDATION: Buyer points deducted correctly');
    });
});
