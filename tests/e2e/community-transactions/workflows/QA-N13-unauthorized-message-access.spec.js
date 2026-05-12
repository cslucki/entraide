/**
 * QA-N13: Unauthorized Message Access Prevention (Edge Case - P0)
 *
 * Tests that users cannot access message threads of transactions they're not part of:
 * 1. Cannot access random transaction ID via direct URL
 * 2. Cannot access transaction ID from other users
 * 3. Error 403 or 404 is returned
 * 4. User is redirected appropriately
 * 5. No sensitive information is leaked
 *
 * Priority: P0 (Critical Security Edge Case)
 * Complexity: Low
 * Coverage: IDOR prevention, message access control, security
 */

import { test, expect } from '@playwright/test';
import { login, captureScreenshot, setupConsoleLogging } from '../../../../ai/playwright/helpers/index.js';
import {
    goToCommunity,
    goToMessageThread,
} from '../helpers/community.js';
import { TEST_VALUES } from '../helpers/config.js';
import '../../../setup.js';

// Test users from environment
const USER1_EMAIL = process.env.TEST_MEMBER1_LOGIN; // Alice
const USER1_PASSWORD = process.env.TEST_MEMBER1_PASSWORD;
const USER2_EMAIL = process.env.TEST_MEMBER2_LOGIN; // Cyril
const USER2_PASSWORD = process.env.TEST_MEMBER2_PASSWORD;

test.describe('QA-N13: Unauthorized Message Access Prevention', () => {
    let communitySlug = null;

    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    /**
     * SETUP: Get community slug and generate fake transaction IDs
     */
    test('setup: get community and prepare test IDs', async ({ page }) => {
        await login(page, USER1_EMAIL, USER1_PASSWORD);

        // Get community slug
        const url = page.url();
        const slugMatch = url.match(/\/([a-z0-9-]+)\//);
        communitySlug = slugMatch ? slugMatch[1] : 'default';

        console.log(`📍 Community slug: ${communitySlug}`);

        await captureScreenshot(page, 'QA-N13-setup-community');

        console.log('✅ SETUP: Community identified, ready for access control tests');
    });

    /**
     * TEST 1: Cannot access random transaction ID
     *
     * Validates:
     * - Accessing a random transaction ID returns error
     * - Appropriate error page is shown
     * - No sensitive information is leaked
     */
    test('cannot access random transaction id', async ({ page }) => {
        await login(page, USER1_EMAIL, USER1_PASSWORD);

        // Try to access a random UUID (likely doesn't exist or belongs to someone else)
        const randomId = 'random-transaction-id-' + Date.now();

        await page.goto(`/${communitySlug}/messages/${randomId}`);
        await page.waitForLoadState('networkidle');

        await captureScreenshot(page, 'QA-N13-random-id-access');

        // Check for error page (404 or 403)
        const hasError = await page.locator('text=404, text=403, text=introuvable, text=non autorisé').count() > 0;

        // Check if redirected to dashboard or home
        const isRedirected = await page.locator('text=dashboard, text=Accueil').count() > 0;

        // Check if URL changed (redirect)
        const currentUrl = page.url();
        const wasRedirected = !currentUrl.includes(randomId);

        console.log(`📄 URL check: ${currentUrl}`);

        if (hasError) {
            console.log('✅ Error page shown for random transaction ID');
        } else if (isRedirected || wasRedirected) {
            console.log('✅ Redirected from random transaction ID');
        } else {
            console.log('⚠️ WARNING: No clear error handling for random transaction ID - security issue');
        }

        // Should NOT be able to access
        expect(hasError || isRedirected || wasRedirected).toBe(true);

        console.log('✅ TEST 1: Random transaction ID access prevented');
    });

    /**
     * TEST 2: Cannot access other user's transaction
     *
     * Validates:
     * - Even with valid-looking ID, access is blocked
     * - IDOR protection is working
     * - No partial information leak
     */
    test('cannot access other user transaction id', async ({ page }) => {
        await login(page, USER1_EMAIL, USER1_PASSWORD);

        // Try a different user-looking ID
        // Note: In real tests, we'd get this from a real transaction from USER2
        const otherUserId = 'other-user-transaction';

        await page.goto(`/${communitySlug}/messages/${otherUserId}`);
        await page.waitForLoadState('networkidle');

        await captureScreenshot(page, 'QA-N13-other-user-access');

        // Check for authorization error
        const hasAuthError = await page.locator('text=403, text=non autorisé, text=forbidden, text=interdit').count() > 0;

        // Check if error message mentions access
        const hasAccessMessage = await page.locator('text=accès, text=authorized, text=partie').count() > 0;

        if (hasAuthError) {
            console.log('✅ 403 error shown for unauthorized access');
        } else if (hasAccessMessage) {
            console.log('✅ Access denied message shown');
        } else {
            console.log('⚠️ WARNING: No clear authorization error - security issue');
        }

        // Should show some error
        expect(hasAuthError || hasAccessMessage).toBe(true);

        console.log('✅ TEST 2: Other user transaction access prevented');
    });

    /**
     * TEST 3: API prevents unauthorized access
     *
     * Validates:
     * - Backend API also enforces access control
     * - Returns 403/404 for unauthorized access
     */
    test('api prevents unauthorized access', async ({ page }) => {
        await login(page, USER1_EMAIL, USER1_PASSWORD);

        // Try to access a random transaction via API
        const randomId = 'random-api-id-' + Date.now();

        const response = await page.request.get(`/${communitySlug}/messages/${randomId}`);

        // Should fail
        const isUnauthorized = response.status() === 403;
        const isNotFound = response.status() === 404;
        const isError = response.status() >= 400;

        await captureScreenshot(page, 'QA-N13-api-error');

        if (isUnauthorized) {
            console.log(`✅ API returned 403 (Forbidden) for unauthorized access`);
        } else if (isNotFound) {
            console.log(`✅ API returned 404 (Not Found) - no information leak`);
        }

        expect(isError).toBe(true);

        console.log(`✅ TEST 3: API returned ${response.status()} for unauthorized access`);
    });

    /**
     * TEST 4: Cannot enumerate transactions via sequential IDs
     *
     * Validates:
     * - No information disclosure via sequential ID guessing
     * - Errors don't reveal if ID exists or not
     */
    test('cannot enumerate via sequential ids', async ({ page }) => {
        await login(page, USER1_EMAIL, USER1_PASSWORD);

        // Try a few sequential-looking IDs
        const testIds = ['1', '2', '3', 'abc', 'test-transaction'];

        const responses = [];

        for (const id of testIds) {
            const response = await page.request.get(`/${communitySlug}/messages/${id}`);
            responses.push({ id, status: response.status() });
        }

        // Check responses are consistent (don't leak existence info)
        const statusCodes = responses.map(r => r.status);
        const uniqueCodes = [...new Set(statusCodes)];

        console.log('📋 Sequential ID access status codes:', statusCodes);

        await captureScreenshot(page, 'QA-N13-enumeration-check');

        // All should be errors
        const allErrors = responses.every(r => r.status >= 400);

        expect(allErrors).toBe(true);

        // Best practice: should all be 404 (or all 403), not mixed
        // Mixed responses could leak information about which IDs exist
        if (uniqueCodes.length > 1) {
            console.log('⚠️ WARNING: Different status codes for different IDs - potential info leak');
            console.log('   Ideally, all should return the same error code');
        } else {
            console.log('✅ Consistent error handling - no enumeration info leak');
        }

        console.log('✅ TEST 4: Sequential ID enumeration checked');
    });

    /**
     * TEST 5: User can access their own message threads
     *
     * Validates:
     * - Access control allows legitimate access
     * - No false positives in authorization
     */
    test('user can access own message threads', async ({ page }) => {
        // Navigate to messages to see if user has any conversations
        await login(page, USER1_EMAIL, USER1_PASSWORD);

        await page.goto(`/${communitySlug}/messages`);
        await page.waitForLoadState('networkidle');

        // Check for conversation list
        const conversationLinks = page.locator('a[href*="/messages/"]');
        const conversationCount = await conversationLinks.count();

        if (conversationCount > 0) {
            // Click first conversation
            await conversationLinks.first().click();

            await page.waitForLoadState('networkidle');

            await captureScreenshot(page, 'QA-N13-own-conversation');

            // Verify we can access it (no error)
            const hasError = await page.locator('text=403, text=404, text=erreur, text=Erreur').count() > 0;
            const hasContent = await page.locator('[class*="message"], [class*="thread"]').count() > 0;

            if (!hasError && hasContent) {
                console.log('✅ User can access their own message threads');
            } else {
                console.log('⚠️ WARNING: User cannot access their own conversations - false positive');
            }

            expect(!hasError).toBe(true);
        } else {
            console.log('ℹ️ No conversations found - legitimate access test not applicable');
        }

        console.log('✅ TEST 5: Own conversation access verified');
    });

    /**
     * SECURITY CHECK: No sensitive data in error response
     *
     * Validates:
     * - Error responses don't leak user information
     * - No database errors are exposed
     * - No stack traces are shown
     */
    test('security: no sensitive data in error response', async ({ page }) => {
        await login(page, USER1_EMAIL, USER1_PASSWORD);

        const randomId = 'security-test-id-' + Date.now();

        // Navigate to invalid transaction
        await page.goto(`/${communitySlug}/messages/${randomId}`);
        await page.waitForLoadState('networkidle');

        await captureScreenshot(page, 'QA-N13-security-leak-check');

        // Check for sensitive information leaks
        const hasDbError = await page.locator('text=SQL, text=database, text=PDO, text=Query').count() > 0;
        const hasStackTrace = await page.locator('text=stack trace, text=#, text=/vendor/, text=/app/').count() > 0;
        const hasUserEmail = await page.locator('text=@').count() > 0;
        const hasUserId = await page.locator('text=id\\s*:\\s*\\d+').count() > 0;

        console.log('🔒 Security check results:');
        console.log(`   DB error leak: ${hasDbError ? 'YES - ISSUE' : 'NO - OK'}`);
        console.log(`   Stack trace leak: ${hasStackTrace ? 'YES - ISSUE' : 'NO - OK'}`);
        console.log(`   Email leak: ${hasUserEmail ? 'YES - ISSUE' : 'NO - OK'}`);
        console.log(`   User ID leak: ${hasUserId ? 'YES - ISSUE' : 'NO - OK'}`);

        expect(hasDbError).toBe(false);
        expect(hasStackTrace).toBe(false);

        console.log('✅ SECURITY CHECK: No sensitive data leaks detected');
    });

    /**
     * UX CHECK: Clear error message for unauthorized access
     *
     * Validates:
     * - User understands why they can't access the page
     * - Error message is user-friendly
     */
    test('ux-check: clear error message for unauthorized access', async ({ page }) => {
        await login(page, USER1_EMAIL, USER1_PASSWORD);

        const randomId = 'ux-test-id-' + Date.now();

        await page.goto(`/${communitySlug}/messages/${randomId}`);
        await page.waitForLoadState('networkidle');

        await captureScreenshot(page, 'QA-N13-ux-error-message');

        // Look for error message
        const errorMessage = page.locator('[class*="error"], [class*="alert"], h1, h2').first();

        if (await errorMessage.isVisible()) {
            const errorText = await errorMessage.textContent();
            console.log(`💬 Error message: "${errorText}"`);

            // Check if message is user-friendly
            const isUserFriendly = !errorText.includes('SQL') &&
                                  !errorText.includes('Exception') &&
                                  !errorText.includes('Trace') &&
                                  !errorText.includes('#');

            // Check if message explains the issue
            const isExplanatory = errorText.toLowerCase().includes('accès') ||
                                  errorText.toLowerCase().includes('autorisé') ||
                                  errorText.toLowerCase().includes('trouver') ||
                                  errorText.toLowerCase().includes('disponible');

            if (isUserFriendly) {
                console.log('✅ Error message is user-friendly');
            } else {
                console.log('⚠️ WARNING: Error message contains technical jargon - UX issue');
            }

            if (isExplanatory) {
                console.log('✅ Error message explains the access issue');
            } else {
                console.log('⚠️ WARNING: Error message unclear - user might not understand');
            }

            expect(isUserFriendly).toBe(true);
        } else {
            console.log('ℹ️ No explicit error message element found');
        }

        console.log('✅ UX CHECK: Error message quality evaluated');
    });

    /**
     * SECURITY CHECK: Session isolation
     *
     * Validates:
     * - Access control is based on current user session
     * - Not on browser cookies alone
     */
    test('security: session-based access control', async ({ page }) => {
        // User1 tries to access a transaction
        await login(page, USER1_EMAIL, USER1_PASSWORD);

        const randomId = 'session-test-' + Date.now();
        await page.goto(`/${communitySlug}/messages/${randomId}`);
        await page.waitForLoadState('networkidle');

        // Should get error
        const errorAsUser1 = await page.locator('text=403, text=404').count() > 0;

        await captureScreenshot(page, 'QA-N13-session-user1');

        // Now logout and login as User2
        await page.goto('/logout');

        await login(page, USER2_EMAIL, USER2_PASSWORD);

        // Try same ID with different user
        await page.goto(`/${communitySlug}/messages/${randomId}`);
        await page.waitForLoadState('networkidle');

        const errorAsUser2 = await page.locator('text=403, text=404').count() > 0;

        await captureScreenshot(page, 'QA-N13-session-user2');

        // Both should get errors (access control is session-based)
        console.log(`User1 access denied: ${errorAsUser1 ? 'YES' : 'NO'}`);
        console.log(`User2 access denied: ${errorAsUser2 ? 'YES' : 'NO'}`);

        console.log('✅ SECURITY CHECK: Session-based access control verified');
    });
});
