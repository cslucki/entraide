/**
 * Community Transaction QA - Service Transaction Workflow
 *
 * Tests the complete workflow of a service-based transaction:
 * 1. User A views User B's service
 * 2. User A proposes an exchange with points
 * 3. User B accepts the proposal
 * 4. User A marks as complete
 * 5. User B confirms and points are transferred
 * 6. Both users can leave reviews
 */

import { test, expect } from '@playwright/test';
import { login, captureScreenshot, setupConsoleLogging } from '../../../ai/playwright/helpers/index.js';
import '../../setup.js';

// Test users - must exist in database with sufficient points
const BUYER_EMAIL = process.env.TEST_MEMBER1_LOGIN; // Alice
const BUYER_PASSWORD = process.env.TEST_MEMBER1_PASSWORD;
const SELLER_EMAIL = process.env.TEST_MEMBER2_LOGIN; // Cyril
const SELLER_PASSWORD = process.env.TEST_MEMBER2_PASSWORD;

test.describe('Service Transaction Workflow', () => {
    let serviceId = null;
    let transactionId = null;

    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    test('setup: find or create test service', async ({ page }) => {
        // Login as seller to ensure a service exists
        await login(page, SELLER_EMAIL, SELLER_PASSWORD);

        // Navigate to create service (global platform route)
        await page.goto('/services/create');

        // Fill service form
        await page.fill('input[name="title"]', 'Test Service for Transaction QA');
        await page.fill('textarea[name="description"]', 'This is a test service created for QA transaction testing.');
        await page.selectOption('select[name="category_id"]', '1'); // First category
        await page.selectOption('select[name="delivery_mode"]', 'remote');
        await page.fill('input[name="points_cost"]', '10');

        // Submit
        await page.click('button[type="submit"]');

        // Get service ID from URL or create a new one
        await expect(page).toHaveURL(/\/services\/[\w-]+/);

        const urlAfter = page.url();
        const idMatch = urlAfter.match(/\/services\/([\w-]+)/);
        if (idMatch) {
            serviceId = idMatch[1];
        }

        await captureScreenshot(page, 'service-created');

        await login(page, BUYER_EMAIL, BUYER_PASSWORD);
    });

    test('step1: buyer views seller service', async ({ page }) => {
        test.skip(!serviceId, 'Service ID not available');

        await page.goto(`/services/${serviceId}`);

        // Verify service details are visible
        await expect(page.locator('h1, h2').filter({ hasText: /Test Service/ })).toBeVisible();
        await expect(page.locator('text=points')).toBeVisible();

        await captureScreenshot(page, 'buyer-views-service');
    });

    test('step2: buyer proposes transaction', async ({ page }) => {
        test.skip(!serviceId, 'Service ID not available');

        await page.goto(`/services/${serviceId}`);

        // Find and click propose transaction button
        const proposeButton = page.locator('button:has-text("Proposer"), button:has-text("Échanger"), button:has-text("Demander")').first();
        if (await proposeButton.isVisible()) {
            await proposeButton.click();
        } else {
            // Try alternative selector
            await page.click('a:has-text("Proposer"), a:has-text("Échanger")');
        }

        // Fill transaction form
        await page.fill('input[name="points_proposed"]', '10');

        // Submit
        await page.click('button[type="submit"]:has-text("Envoyer"), button:has-text("Proposer")');

        // Should redirect to messages
        await expect(page).toHaveURL(/\/messages\/[\w-]+/);

        // Get transaction ID from URL
        const url = page.url();
        const idMatch = url.match(/\/messages\/([\w-]+)$/);
        if (idMatch) {
            transactionId = idMatch[1];
        }

        // Verify system message
        await expect(page.locator('text=Nouvelle échange')).toBeVisible();

        await captureScreenshot(page, 'transaction-proposed');
    });

    test('step3: seller accepts transaction', async ({ page }) => {
        test.skip(!transactionId, 'Transaction ID not available');

        await login(page, SELLER_EMAIL, SELLER_PASSWORD);
        await page.goto(`/messages/${transactionId}`);

        // Click accept button
        const acceptButton = page.locator('button:has-text("Accepter"), button:has-text("Acceptée")').first();
        await acceptButton.click();

        // Verify status change
        await expect(page.locator('text=acceptée')).toBeVisible();
        await expect(page.locator('text=En cours')).toBeVisible();

        await captureScreenshot(page, 'transaction-accepted');
    });

    test('step4: seller can adjust points (pending state only)', async ({ page }) => {
        test.skip(!transactionId, 'Transaction ID not available');
        test.skip(true, 'This test requires a fresh pending transaction');

        // This test would verify point adjustment functionality
        // Not run after acceptance since adjust only works in pending state
    });

    test('step5: buyer marks transaction as complete', async ({ page }) => {
        test.skip(!transactionId, 'Transaction ID not available');

        await login(page, BUYER_EMAIL, BUYER_PASSWORD);
        await page.goto(`/messages/${transactionId}`);

        // Click complete/terminé button
        const completeButton = page.locator('button:has-text("Terminer"), button:has-text("Compléter"), button:has-text("Terminé")').first();
        await completeButton.click();

        // Verify status change to buyer_done
        await expect(page.locator('text=déclaré terminé')).toBeVisible();
        await expect(page.locator('text=confirmation')).toBeVisible();

        await captureScreenshot(page, 'transaction-buyer-done');
    });

    test('step6: seller confirms completion', async ({ page }) => {
        test.skip(!transactionId, 'Transaction ID not available');

        await login(page, SELLER_EMAIL, SELLER_PASSWORD);
        await page.goto(`/messages/${transactionId}`);

        // Click confirm button
        const confirmButton = page.locator('button:has-text("Confirmer"), button:has-text("Valider")').first();
        await confirmButton.click();

        // Verify completion
        await expect(page.locator('text=complétée')).toBeVisible();
        await expect(page.locator('text=points transférés')).toBeVisible();

        await captureScreenshot(page, 'transaction-completed');
    });

    test('step7: buyer leaves review', async ({ page }) => {
        test.skip(!transactionId, 'Transaction ID not available');

        await login(page, BUYER_EMAIL, BUYER_PASSWORD);
        await page.goto(`/messages/${transactionId}`);

        // Look for review form
        const reviewForm = page.locator('form:has-text("avis"), form:has-text("notation")').first();

        if (await reviewForm.isVisible()) {
            // Select rating (5 stars)
            await page.click('input[name="rating"][value="5"]');

            // Add comment
            await page.fill('textarea[name="comment"]', 'Excellent service, highly recommend!');

            // Submit review
            await page.click('button:has-text("Envoyer"), button:has-text("Soumettre")');

            // Verify review submitted
            await expect(page.locator('text=Merci pour votre évaluation')).toBeVisible();

            await captureScreenshot(page, 'review-submitted-buyer');
        } else {
            test.skip(true, 'Review form not visible - may already be reviewed');
        }
    });

    test('step8: seller leaves review', async ({ page }) => {
        test.skip(!transactionId, 'Transaction ID not available');

        await login(page, SELLER_EMAIL, SELLER_PASSWORD);
        await page.goto(`/messages/${transactionId}`);

        // Look for review form
        const reviewForm = page.locator('form:has-text("avis"), form:has-text("notation")').first();

        if (await reviewForm.isVisible()) {
            // Select rating (5 stars)
            await page.click('input[name="rating"][value="5"]');

            // Add comment
            await page.fill('textarea[name="comment"]', 'Great buyer, easy to work with!');

            // Submit review
            await page.click('button:has-text("Envoyer"), button:has-text("Soumettre")');

            // Verify review submitted
            await expect(page.locator('text=Merci pour votre évaluation')).toBeVisible();

            await captureScreenshot(page, 'review-submitted-seller');
        } else {
            test.skip(true, 'Review form not visible - may already be reviewed');
        }
    });

    test('negative: cannot propose self-transaction', async ({ page }) => {
        test.skip(!serviceId, 'Service ID not available');

        await login(page, SELLER_EMAIL, SELLER_PASSWORD);
        await page.goto(`/services/${serviceId}`);

        // Try to propose transaction on own service
        const proposeButton = page.locator('button:has-text("Proposer"), button:has-text("Échanger")').first();

        // Button might be hidden or disabled for own services
        if (await proposeButton.isVisible()) {
            await proposeButton.click();

            // Fill and submit
            await page.fill('input[name="points_proposed"]', '10');
            await page.click('button[type="submit"]');

            // Should show error
            await expect(page.locator('text=vous-même')).toBeVisible();
        } else {
            // Button not shown - also correct behavior
            await captureScreenshot(page, 'self-transaction-button-hidden');
        }
    });

    test('negative: insufficient points prevents transaction', async ({ page }) => {
        // This would require a user with low balance
        test.skip(true, 'Requires test user with insufficient points');

        // Implementation would verify error message when balance < proposed points
    });

    test('cleanup: delete test service', async ({ page }) => {
        test.skip(!serviceId, 'Service ID not available');

        await login(page, SELLER_EMAIL, SELLER_PASSWORD);
        await page.goto(`/services/${serviceId}/edit`);

        // Click delete button
        const deleteButton = page.locator('button:has-text("Supprimer"), button:has-text("Delete")').first();

        if (await deleteButton.isVisible()) {
            await deleteButton.click();

            // Confirm deletion if modal appears
            const confirmButton = page.locator('button:has-text("Confirmer"), button:has-text("Oui")').first();
            if (await confirmButton.isVisible()) {
                await confirmButton.click();
            }

            // Verify redirect
            await expect(page).toHaveURL(/\/dashboard|\/services/);
        }
    });
});
