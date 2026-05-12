/**
 * QA-N01: Self-Transaction Prevention (Edge Case - P0)
 *
 * Tests that users cannot create transactions with themselves:
 * 1. User cannot propose transaction on their own service
 * 2. Button is hidden or disabled on own services
 * 3. If button is clicked, error message is displayed
 * 4. Policy correctly prevents self-transaction
 *
 * Priority: P0 (Critical Edge Case)
 * Complexity: Low
 * Coverage: Self-transaction prevention, business rule validation
 */

import { test, expect } from '@playwright/test';
import { login, captureScreenshot, setupConsoleLogging } from '../../../../ai/playwright/helpers/index.js';
import {
    goToService,
    goToCommunity,
} from '../helpers/community.js';
import { TEST_VALUES } from '../helpers/config.js';
import '../../../setup.js';

// Test user from environment
const USER_EMAIL = process.env.TEST_MEMBER2_LOGIN; // Cyril
const USER_PASSWORD = process.env.TEST_MEMBER2_PASSWORD;

test.describe('QA-N01: Self-Transaction Prevention', () => {
    let serviceId = null;
    let communitySlug = null;

    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    /**
     * SETUP: Create a service owned by the test user
     */
    test('setup: create test service', async ({ page }) => {
        await login(page, USER_EMAIL, USER_PASSWORD);

        // Get community slug
        const url = page.url();
        const slugMatch = url.match(/\/([a-z0-9-]+)\//);
        communitySlug = slugMatch ? slugMatch[1] : 'default';

        // Navigate to create service
        await page.goto(`/${communitySlug}/services/create`);
        await page.waitForLoadState('networkidle');

        // Fill service form
        await page.fill('input[name="title"]', TEST_VALUES.serviceTitle);
        await page.fill('textarea[name="description"]', TEST_VALUES.serviceDescription);

        // Select category
        const categorySelect = page.locator('select[name="category_id"]');
        if (await categorySelect.isVisible()) {
            await categorySelect.selectOption({ index: 0 });
        }

        // Set points
        await page.fill('input[name="points_cost"]', String(TEST_VALUES.servicePoints));

        // Submit
        const submitButton = page.locator('button[type="submit"]').filter({ hasText: /Créer|Publier/ });
        await submitButton.click();

        // Get service ID from URL
        await page.waitForURL(/\/services\/[\w-]+/);
        const urlAfter = page.url();
        const idMatch = urlAfter.match(/\/services\/([\w-]+)$/);
        if (idMatch) {
            serviceId = idMatch[1];
            console.log(`✅ Service created: ${serviceId}`);
        }

        await captureScreenshot(page, 'QA-N01-setup-service-created');
    });

    /**
     * TEST 1: Propose button is hidden/disabled on own service
     *
     * Validates:
     * - UI correctly hides or disables propose button on own services
     * - User cannot accidentally propose transaction to themselves
     */
    test('propose button hidden on own service', async ({ page }) => {
        test.skip(!serviceId, 'Service ID not available');

        await login(page, USER_EMAIL, USER_PASSWORD);

        await goToService(page, serviceId, communitySlug);

        // Look for propose button
        const proposeButton = page.locator('button:has-text("Proposer"), button:has-text("Échanger"), a:has-text("Proposer")').first();
        const isVisible = await proposeButton.isVisible({ timeout: 2000 });

        await captureScreenshot(page, 'QA-N01-own-service-view');

        if (isVisible) {
            // Check if it's disabled
            const isDisabled = await proposeButton.isDisabled();
            if (isDisabled) {
                console.log('✅ Propose button exists but is disabled');
            } else {
                // Button might be visible but clicking it should fail
                console.log('⚠️ WARNING: Propose button is visible and enabled - UI issue');
            }
        } else {
            console.log('✅ Propose button is hidden on own service');
        }

        // Best practice: button should be hidden
        expect(isVisible).toBe(false);
    });

    /**
     * TEST 2: Direct URL access to transaction creation fails
     *
     * Validates:
     * - Even with direct URL manipulation, self-transaction is prevented
     * - Backend policy enforces the rule
     */
    test('direct url access to transaction creation fails', async ({ page }) => {
        test.skip(!serviceId, 'Service ID not available');

        await login(page, USER_EMAIL, USER_PASSWORD);

        // Try to navigate directly to transaction creation form
        await page.goto(`/${communitySlug}/transactions/create?service_id=${serviceId}`);
        await page.waitForLoadState('networkidle');

        await captureScreenshot(page, 'QA-N01-direct-url-access');

        // Check for error message or redirect
        const hasError = await page.locator('text=erreur, text=Erreur, text=Impossible, text=you-même').count() > 0;
        const isRedirected = await page.locator('text=dashboard').count() > 0;

        if (hasError) {
            console.log('✅ Error message shown for self-transaction attempt');
        } else if (isRedirected) {
            console.log('✅ Redirected away from self-transaction form');
        } else {
            console.log('⚠️ WARNING: No clear error handling for self-transaction');
        }

        // Should either show error or redirect
        expect(hasError || isRedirected).toBe(true);
    });

    /**
     * TEST 3: API call to create self-transaction returns error
     *
     * Validates:
     * - Backend API correctly rejects self-transaction
     * - Appropriate error response is returned
     */
    test('api call rejects self-transaction', async ({ page }) => {
        test.skip(!serviceId, 'Service ID not available');

        await login(page, USER_EMAIL, USER_PASSWORD);

        // Make API call to create transaction
        const response = await page.request.post(`/${communitySlug}/transactions`, {
            form: {
                service_id: serviceId,
                points_proposed: TEST_VALUES.servicePoints,
            },
        });

        await captureScreenshot(page, 'QA-N01-api-response');

        // Should fail with 4xx or redirect
        const success = response.ok() || response.status() < 400;

        expect(success).toBe(false);

        if (!success) {
            console.log(`✅ API rejected self-transaction with status ${response.status()}`);
        }
    });

    /**
     * TEST 4: Compare with other user's service (should allow proposal)
     *
     * Validates:
     * - Propose button IS visible on other user's services
     * - This confirms the issue is specifically with self-transaction prevention
     */
    test('propose button visible on other user service', async ({ page }) => {
        // Logout current user
        await login(page, USER_EMAIL, USER_PASSWORD);
        await page.goto('/logout');

        // Login as different user
        const otherUserEmail = process.env.TEST_MEMBER1_LOGIN;
        const otherUserPassword = process.env.TEST_MEMBER1_PASSWORD;

        await login(page, otherUserEmail, otherUserPassword);

        // Navigate to the service owned by first user
        await goToService(page, serviceId, communitySlug);

        // Look for propose button
        const proposeButton = page.locator('button:has-text("Proposer"), button:has-text("Échanger"), a:has-text("Proposer")').first();
        const isVisible = await proposeButton.isVisible({ timeout: 2000 });

        await captureScreenshot(page, 'QA-N01-other-user-service-view');

        if (isVisible) {
            console.log('✅ Propose button is visible on other user\'s service');
        } else {
            console.log('⚠️ WARNING: Propose button not visible even on other user\'s service - possible UI issue');
        }

        expect(isVisible).toBe(true);
    });

    /**
     * TEST 5: Verify service ownership is correctly identified
     *
     * Validates:
     * - Service page correctly identifies owner
     * - Owner information is visible
     */
    test('service ownership correctly displayed', async ({ page }) => {
        test.skip(!serviceId, 'Service ID not available');

        await login(page, USER_EMAIL, USER_PASSWORD);

        await goToService(page, serviceId, communitySlug);

        // Look for owner information
        const ownerSection = page.locator('[class*="owner"], [class*="vendeur"], [class*="author"], [data-owner]').first();
        const hasOwnerInfo = await ownerSection.count() > 0;

        // Look for "your service" indicator
        const yourServiceIndicator = page.locator('text=Votre service, text=votre service, text=Mon service, text=Modifier, text=Supprimer').first();
        const hasYourServiceIndicator = await yourServiceIndicator.count() > 0;

        await captureScreenshot(page, 'QA-N01-ownership-indicator');

        if (hasYourServiceIndicator) {
            console.log('✅ Service ownership is clearly indicated');
        } else if (hasOwnerInfo) {
            console.log('✅ Service owner information is visible');
        } else {
            console.log('⚠️ WARNING: Service ownership not clearly indicated - UX issue');
        }

        // At least one indicator should be present
        expect(hasYourServiceIndicator || hasOwnerInfo).toBe(true);
    });

    /**
     * UX CHECK: Clear messaging about self-transaction prevention
     *
     * Validates:
     * - If user tries to self-transaction, clear error message explains why
     */
    test('ux-check: clear error message for self-transaction', async ({ page }) => {
        test.skip(!serviceId, 'Service ID not available');

        await login(page, USER_EMAIL, USER_PASSWORD);

        await goToService(page, serviceId, communitySlug);

        // If propose button exists and is clickable, try clicking it
        const proposeButton = page.locator('button:has-text("Proposer"), button:has-text("Échanger")').first();

        if (await proposeButton.isVisible()) {
            await proposeButton.click();
            await page.waitForTimeout(500);

            await captureScreenshot(page, 'QA-N01-ux-error-message');

            // Check for error message
            const hasErrorMessage = await page.locator('text=you-même, text=soi-même, text=votre propre, text=Impossible').count() > 0;

            if (hasErrorMessage) {
                const errorText = await page.locator('text=you-même, text=soi-même, text=votre propre').first().textContent();
                console.log(`✅ Error message: "${errorText}"`);
            } else {
                console.log('⚠️ WARNING: No clear error message shown');
            }
        } else {
            console.log('✅ UX: Button hidden, no confusing interaction possible');
        }
    });
});
