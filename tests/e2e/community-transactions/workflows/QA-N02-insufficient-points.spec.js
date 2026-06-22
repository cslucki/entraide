/**
 * QA-N02: Insufficient Points Prevention (Edge Case - P0)
 *
 * Tests that users cannot create transactions with insufficient points:
 * 1. User with low points cannot propose high-point transactions
 * 2. Appropriate error message is displayed
 * 3. Points balance is checked before transaction creation
 * 4. Backend policy enforces the rule
 *
 * Priority: P0 (Critical Edge Case)
 * Complexity: Low
 * Coverage: Points balance validation, business rule enforcement
 */

import { test, expect } from '@playwright/test';
import { login, captureScreenshot, setupConsoleLogging } from '../../../../ai/playwright/helpers/index.js';
import {
    goToService,
    goToCommunity,
    getUserPoints,
} from '../helpers/community.js';
import { TEST_VALUES } from '../helpers/config.js';
import { extractSlugFromUrl } from '../helpers/community.js';
import '../../../setup.js';

// Test users from environment
const USER_EMAIL = process.env.TEST_MEMBER1_LOGIN; // Alice
const USER_PASSWORD = process.env.TEST_MEMBER1_PASSWORD;
const USER2_EMAIL = process.env.TEST_MEMBER2_LOGIN; // Cyril (service owner)
const USER2_PASSWORD = process.env.TEST_MEMBER2_PASSWORD;

test.describe('QA-N02: Insufficient Points Prevention', () => {
    let serviceId = null;
    let communitySlug = null;
    let userInitialPoints = null;

    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    /**
     * SETUP: Create a high-points service and check user's balance
     */
    test('setup: create high-points service and check user balance', async ({ page }) => {
        // First, create a service with high points requirement
        await login(page, USER2_EMAIL, USER2_PASSWORD);

        // Get community slug
        const url = page.url();

        communitySlug = extractSlugFromUrl(page.url());

        // Navigate to create service
        await page.goto(`/${communitySlug}/services/create`);
        await page.waitForLoadState('networkidle');

        // Fill service form with high points
        await page.fill('input[name="title"]', 'High Points Test Service');
        await page.fill('textarea[name="description"]', 'Service with high points cost for testing insufficient balance validation.');

        const categorySelect = page.locator('select[name="category_id"]');
        if (await categorySelect.isVisible()) {
            await categorySelect.selectOption({ index: 0 });
        }

        // Set high points (more than typical user balance)
        await page.fill('input[name="points_cost"]', '9999');

        // Submit
        const submitButton = page.locator('button[type="submit"]').filter({ hasText: /Créer|Publier/ });
        await submitButton.click();

        // Get service ID
        await page.waitForURL(/\/services\/[\w-]+/);
        const urlAfter = page.url();
        const idMatch = urlAfter.match(/\/services\/([\w-]+)$/);
        if (idMatch) {
            serviceId = idMatch[1];
            console.log(`✅ High-points service created: ${serviceId}`);
        }

        // Logout and check user's actual balance
        await page.goto('/logout');

        // Login as user who will try to buy
        await login(page, USER_EMAIL, USER_PASSWORD);

        // Get current points
        userInitialPoints = await getUserPoints(page);
        console.log(`💰 User's initial points: ${userInitialPoints}`);

        // Verify user doesn't have 9999 points (for test to be valid)
        if (userInitialPoints >= 9999) {
            console.log('⚠️ WARNING: User has too many points for this test - need to adjust');
            test.skip(true, 'User has sufficient points, cannot test insufficient balance scenario');
        }

        await captureScreenshot(page, 'QA-N02-setup-balance-check');
    });

    /**
     * TEST 1: Cannot propose transaction with insufficient points
     *
     * Validates:
     * - User cannot submit transaction proposal with insufficient balance
     * - Appropriate error message is shown
     * - Transaction is NOT created
     */
    test('cannot propose transaction with insufficient points', async ({ page }) => {
        test.skip(!serviceId, 'Service ID not available');

        await login(page, USER_EMAIL, USER_PASSWORD);

        await goToService(page, serviceId, communitySlug);

        // Click propose button
        const proposeButton = page.locator('button:has-text("Proposer"), button:has-text("Échanger")').first();
        await proposeButton.click();

        // Wait for modal or form
        await page.waitForTimeout(500);

        // Check if form is in modal or separate page
        const isInModal = await page.locator('[class*="modal"], [role="dialog"]').count() > 0;

        if (isInModal) {
            // Fill form with high points
            await page.fill('input[name="points_proposed"], input[name="points"]', '9999');

            // Try to submit
            const submitButton = page.locator('button[type="submit"]').filter({ hasText: /Envoyer|Proposer/ }).first();
            await submitButton.click();

            // Wait for error
            await page.waitForTimeout(500);

            await captureScreenshot(page, 'QA-N01-error-modal');

            // Check for error message
            const hasError = await page.locator('text=insuffisant, text=Insuffisant, text=solde, text=points').count() > 0;

            if (hasError) {
                console.log('✅ Error message shown for insufficient points');
            } else {
                console.log('⚠️ WARNING: No clear error message for insufficient points - UX issue');
            }

            expect(hasError).toBe(true);
        } else {
            // Check if on transaction page
            const url = page.url();
            if (url.includes('/transactions') || url.includes('/exchange')) {
                await page.fill('input[name="points_proposed"], input[name="points"]', '9999');

                // Try to submit
                const submitButton = page.locator('button[type="submit"]').filter({ hasText: /Envoyer|Proposer/ }).first();
                await submitButton.click();

                await page.waitForTimeout(500);

                await captureScreenshot(page, 'QA-N01-error-page');

                // Check for error message or redirect
                const hasError = await page.locator('text=insuffisant, text=Insuffisant, text=solde').count() > 0;
                const isRedirectedBack = await page.locator('text=Service').count() > 0;

                if (hasError) {
                    console.log('✅ Error message shown for insufficient points');
                } else if (isRedirectedBack) {
                    console.log('✅ Redirected back (indicates validation failed)');
                }

                expect(hasError || isRedirectedBack).toBe(true);
            }
        }

        console.log('✅ TEST 1: Transaction proposal prevented with insufficient points');
    });

    /**
     * TEST 2: Points balance remains unchanged after failed proposal
     *
     * Validates:
     * - User's points are NOT deducted when proposal fails
     * - Balance remains consistent
     */
    test('points balance unchanged after failed proposal', async ({ page }) => {
        test.skip(!serviceId, 'Service ID not available');

        await login(page, USER_EMAIL, USER_PASSWORD);

        // Go to dashboard
        await goToCommunity(page, communitySlug);

        // Check current points
        const currentPoints = await getUserPoints(page);

        console.log(`💰 Points balance check: ${userInitialPoints} → ${currentPoints}`);

        // Should be unchanged
        expect(currentPoints).toBe(userInitialPoints);

        await captureScreenshot(page, 'QA-N02-balance-unchanged');

        console.log('✅ TEST 2: Points balance unchanged after failed proposal');
    });

    /**
     * TEST 3: Can propose transaction with sufficient points
     *
     * Validates:
     * - User CAN propose transaction if they have enough points
     * - This validates the error is specific to insufficient balance
     */
    test('can propose with sufficient points', async ({ page }) => {
        test.skip(!serviceId, 'Service ID not available');
        test.skip(userInitialPoints < 1, 'User has 0 points, cannot test successful proposal');

        await login(page, USER_EMAIL, USER_PASSWORD);

        await goToService(page, serviceId, communitySlug);

        // Click propose button
        const proposeButton = page.locator('button:has-text("Proposer"), button:has-text("Échanger")').first();
        await proposeButton.click();

        await page.waitForTimeout(500);

        // Check if form is in modal
        const isInModal = await page.locator('[class*="modal"], [role="dialog"]').count() > 0;

        if (isInModal) {
            // Fill form with low points (user should have at least 1)
            const pointsToPropose = Math.min(1, userInitialPoints);
            await page.fill('input[name="points_proposed"], input[name="points"]', String(pointsToPropose));

            // Submit
            const submitButton = page.locator('button[type="submit"]').filter({ hasText: /Envoyer|Proposer/ }).first();
            await submitButton.click();

            await page.waitForTimeout(500);

            // Should NOT show insufficient points error
            const hasInsufficientError = await page.locator('text=insuffisant, text=Insuffisant').count() > 0;

            await captureScreenshot(page, 'QA-N02-sufficient-points-proposal');

            if (!hasInsufficientError) {
                console.log('✅ No insufficient points error when proposing with sufficient balance');
            } else {
                console.log('⚠️ WARNING: Got insufficient points error even with sufficient balance - UX issue');
            }

            expect(hasInsufficientError).toBe(false);
        } else {
            console.log('⚠️ Modal not shown - form structure may differ');
            test.skip(true, 'Form structure different than expected');
        }

        console.log('✅ TEST 3: Proposal with sufficient points works correctly');
    });

    /**
     * TEST 4: API validates points balance
     *
     * Validates:
     * - Backend API correctly checks points balance
     * - Returns appropriate error for insufficient balance
     */
    test('api validates points balance', async ({ page }) => {
        test.skip(!serviceId, 'Service ID not available');

        await login(page, USER_EMAIL, USER_PASSWORD);

        // Try to create transaction with high points via API
        const response = await page.request.post(`/${communitySlug}/transactions`, {
            form: {
                service_id: serviceId,
                points_proposed: 9999,
            },
        });

        // Should fail
        const success = response.ok() || response.status() < 400;

        expect(success).toBe(false);

        await captureScreenshot(page, 'QA-N02-api-error');

        console.log(`✅ TEST 4: API rejected insufficient balance with status ${response.status()}`);

        // Check if response contains error message
        const responseText = await response.text();
        const hasErrorMessage = responseText.includes('insuffisant') ||
                               responseText.includes('balance') ||
                               responseText.includes('solde');

        if (hasErrorMessage) {
            console.log('✅ API returns error message about insufficient balance');
        } else {
            console.log('⚠️ WARNING: API error response unclear');
        }
    });

    /**
     * UX CHECK: Clear error message about insufficient balance
     *
     * Validates:
     * - Error message clearly states the problem
     * - User knows they need more points
     * - Error message is actionable (points to getting more points)
     */
    test('ux-check: clear error message for insufficient balance', async ({ page }) => {
        test.skip(!serviceId, 'Service ID not available');

        await login(page, USER_EMAIL, USER_PASSWORD);

        await goToService(page, serviceId, communitySlug);

        const proposeButton = page.locator('button:has-text("Proposer"), button:has-text("Échanger")').first();
        await proposeButton.click();

        await page.waitForTimeout(500);

        // Try high points
        const isInModal = await page.locator('[class*="modal"], [role="dialog"]').count() > 0;

        if (isInModal) {
            await page.fill('input[name="points_proposed"], input[name="points"]', '9999');

            const submitButton = page.locator('button[type="submit"]').filter({ hasText: /Envoyer|Proposer/ }).first();
            await submitButton.click();

            await page.waitForTimeout(500);

            await captureScreenshot(page, 'QA-N02-ux-error-clarity');

            // Check error message quality
            const errorMessage = page.locator('[class*="error"], [class*="alert"], [role="alert"]').first();

            if (await errorMessage.isVisible()) {
                const errorText = await errorMessage.textContent();
                console.log(`💬 Error message: "${errorText}"`);

                // Check if message is clear
                const isClear = errorText.toLowerCase().includes('points') ||
                               errorText.toLowerCase().includes('solde') ||
                               errorText.toLowerCase().includes('insuffisant');

                if (isClear) {
                    console.log('✅ Error message clearly explains the issue');
                } else {
                    console.log('⚠️ WARNING: Error message unclear - user may not understand the problem');
                }
            }

            // Check if there's a link to get more points (bonus UX)
            const getPointsLink = page.locator('a:has-text("points"), a:has-text("gagner")').first();
            const hasActionableLink = await getPointsLink.count() > 0;

            if (hasActionableLink) {
                console.log('✅ UX: Actionable link to get more points is shown');
            } else {
                console.log('⚠️ UX: No actionable link to resolve the issue');
            }
        } else {
            test.skip(true, 'Modal form not found');
        }
    });

    /**
     * UX CHECK: Points balance is visible during transaction proposal
     *
     * Validates:
     * - User can see their current balance before proposing
     * - Helps prevent failed proposals
     */
    test('ux-check: points balance visible during proposal', async ({ page }) => {
        test.skip(!serviceId, 'Service ID not available');

        await login(page, USER_EMAIL, USER_PASSWORD);

        await goToService(page, serviceId, communitySlug);

        const proposeButton = page.locator('button:has-text("Proposer"), button:has-text("Échanger")').first();
        await proposeButton.click();

        await page.waitForTimeout(500);

        await captureScreenshot(page, 'QA-N02-ux-balance-visibility');

        // Check if points balance is visible in the form
        const balanceDisplay = page.locator('[class*="balance"], [data-points], [class*="points"]').first();
        const hasBalanceInForm = await balanceDisplay.count() > 0;

        if (hasBalanceInForm) {
            const balanceText = await balanceDisplay.textContent();
            const balanceMatch = balanceText.match(/(\d+)/);

            if (balanceMatch) {
                console.log(`💰 Balance visible in form: ${balanceMatch[1]} points`);
                console.log('✅ UX: User can see their balance before proposing');
            }
        } else {
            console.log('⚠️ UX: User\'s balance not visible during proposal - might cause confusion');
        }
    });
});
