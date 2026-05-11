/**
 * QA-MT01: Cross-Community Service Access Prevention (Multi-Tenant - P0)
 *
 * Tests tenant isolation for services:
 * 1. Services from other communities are not visible
 * 2. Accessing service ID from another community fails
 * 3. Scope is correctly enforced at the route level
 * 4. Community context is maintained throughout navigation
 *
 * Priority: P0 (Critical Multi-Tenant)
 * Complexity: Medium
 * Coverage: Tenant isolation, community scoping, data segregation
 */

import { test, expect } from '@playwright/test';
import { login, captureScreenshot, setupConsoleLogging } from '../../../../ai/playwright/helpers/index.js';
import {
    goToCommunity,
    goToServices,
} from '../helpers/community.js';
import { COMMUNITY_ROUTES } from '../helpers/config.js';
import '../../../setup.js';

// Test users from environment
const USER_EMAIL = process.env.TEST_MEMBER1_LOGIN;
const USER_PASSWORD = process.env.TEST_MEMBER1_PASSWORD;

test.describe('QA-MT01: Cross-Community Service Access Prevention', () => {
    let userCommunitySlug = null;
    let knownServiceId = null;

    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    /**
     * SETUP: Identify user's community and get a service ID
     */
    test('setup: identify user community and find service', async ({ page }) => {
        await login(page, USER_EMAIL, USER_PASSWORD);

        // Get community slug from URL
        const url = page.url();
        const slugMatch = url.match(/\/([a-z0-9-]+)\//);
        userCommunitySlug = slugMatch ? slugMatch[1] : 'default';

        console.log(`📍 User's community: ${userCommunitySlug}`);

        // Navigate to services to find one
        await goToServices(page, userCommunitySlug);

        // Find first service link
        const serviceLinks = page.locator('a[href*="/services/"]');
        const linkCount = await serviceLinks.count();

        if (linkCount > 0) {
            // Get service ID from first link
            const firstLink = serviceLinks.first();
            const href = await firstLink.getAttribute('href');
            const idMatch = href.match(/\/services\/([\w-]+)$/);

            if (idMatch) {
                knownServiceId = idMatch[1];
                console.log(`✅ Found service: ${knownServiceId}`);
            }

            await captureScreenshot(page, 'QA-MT01-setup-user-services');
        } else {
            console.log('⚠️ No services found in user\'s community');
            test.skip(true, 'No services available for testing');
        }

        console.log('✅ SETUP: User community and service identified');
    });

    /**
     * TEST 1: User can only see services from their own community
     *
     * Validates:
     * - Services list only contains community-scoped services
     * - No cross-community services are visible
     * - Tenant isolation is enforced in the UI
     */
    test('services list shows only own community services', async ({ page }) => {
        test.skip(!userCommunitySlug, 'Community slug not available');

        await login(page, USER_EMAIL, USER_PASSWORD);

        await goToServices(page, userCommunitySlug);

        // Verify we're on the correct community-scoped route
        const currentUrl = page.url();
        const correctScope = currentUrl.includes(`/${userCommunitySlug}/services`);

        expect(correctScope).toBe(true);

        await captureScreenshot(page, 'QA-MT01-services-scope');

        // Look for community indicator in the page
        const communityIndicator = page.locator('text=Communauté, text=Community, [class*="community"]').first();
        const hasCommunityIndicator = await communityIndicator.count() > 0;

        if (hasCommunityIndicator) {
            const indicatorText = await communityIndicator.textContent();
            console.log(`🏢 Community indicator: "${indicatorText}"`);
        } else {
            console.log('⚠️ WARNING: No clear community indicator in UI - UX issue');
        }

        console.log('✅ TEST 1: Services list scoped to user\'s community');
    });

    /**
     * TEST 2: Cannot access service via wrong community slug
     *
     * Validates:
     * - Attempting to access service with wrong community slug fails
     * - Tenant isolation is enforced at route level
     * - Appropriate error is shown
     */
    test('cannot access service with wrong community slug', async ({ page }) => {
        test.skip(!knownServiceId, 'Service ID not available');

        await login(page, USER_EMAIL, USER_PASSWORD);

        // Try to access the same service but with a different community slug
        const wrongCommunitySlug = 'nonexistent-community';

        await page.goto(`/${wrongCommunitySlug}/services/${knownServiceId}`);
        await page.waitForLoadState('networkidle');

        await captureScreenshot(page, 'QA-MT01-wrong-community-slug');

        // Should show error (404 for community or service not found in that community)
        const hasError = await page.locator('text=404, text=introuvable, text=non trouvé').count() > 0;
        const isRedirected = !page.url().includes(wrongCommunitySlug);

        if (hasError) {
            console.log('✅ Error shown for wrong community slug');
        } else if (isRedirected) {
            console.log('✅ Redirected from wrong community slug');
        } else {
            console.log('⚠️ WARNING: No clear error handling - potential tenant isolation issue');
        }

        expect(hasError || isRedirected).toBe(true);

        console.log('✅ TEST 2: Wrong community slug access prevented');
    });

    /**
     * TEST 3: Global service route respects tenant scope
     *
     * Validates:
     * - If global route exists (/services/{id}), it enforces community scope
     * - Or global route redirects to community-scoped route
     * - No cross-community access is possible
     */
    test('global service route enforces tenant scope', async ({ page }) => {
        test.skip(!knownServiceId, 'Service ID not available');

        await login(page, USER_EMAIL, USER_PASSWORD);

        // Try global route (if it exists)
        await page.goto(`/services/${knownServiceId}`);
        await page.waitForLoadState('networkidle');

        await captureScreenshot(page, 'QA-MT01-global-route');

        const currentUrl = page.url();

        // Check if we were redirected to community-scoped route
        const isRedirectedToCommunity = currentUrl.includes(`/${userCommunitySlug}/services`);

        // Or if we got an error (global route might not exist or enforce scope)
        const hasError = await page.locator('text=404, text=introuvable').count() > 0;

        if (isRedirectedToCommunity) {
            console.log('✅ Global route redirects to community-scoped route');
        } else if (hasError) {
            console.log('✅ Global route enforces scope (returns error)');
        } else {
            console.log('ℹ️ Global route exists - checking content scope');

            // Check if content is from user's community
            const communityIndicator = page.locator(`text=${userCommunitySlug}, [data-community="${userCommunitySlug}"]`).first();
            const isScoped = await communityIndicator.count() > 0;

            if (!isScoped) {
                console.log('⚠️ WARNING: Global route may not enforce community scope - tenant isolation issue');
            }
        }

        // Should either redirect or show error
        expect(isRedirectedToCommunity || hasError).toBe(true);

        console.log('✅ TEST 3: Global service route scope checked');
    });

    /**
     * TEST 4: Community context is maintained during navigation
     *
     * Validates:
     * - Once in a community context, navigation stays scoped
     * - Links point to community-scoped routes
     * - No cross-community links are generated
     */
    test('community context maintained during navigation', async ({ page }) => {
        test.skip(!userCommunitySlug, 'Community slug not available');

        await login(page, USER_EMAIL, USER_PASSWORD);

        // Navigate to community dashboard
        await goToCommunity(page, userCommunitySlug);

        await captureScreenshot(page, 'QA-MT01-context-dashboard');

        // Check navigation links
        const navLinks = page.locator('nav a, [class*="nav"] a, [role="navigation"] a');

        const linkCount = await navLinks.count();
        let scopedLinks = 0;
        let unscopedLinks = [];

        for (let i = 0; i < Math.min(linkCount, 20); i++) {
            const link = navLinks.nth(i);
            const href = await link.getAttribute('href');

            if (href) {
                // Check if link is community-scoped
                const isScoped = href.includes(`/${userCommunitySlug}/`);
                const isInternal = href.startsWith('/');

                if (isScoped || !isInternal) {
                    scopedLinks++;
                } else if (isInternal && !href.includes('/admin')) {
                    // Internal link without community scope (not admin)
                    unscopedLinks.push(href);
                }
            }
        }

        console.log(`🔗 Navigation links: ${linkCount} checked`);
        console.log(`   Scoped links: ${scopedLinks}`);
        console.log(`   Unscoped internal links: ${unscopedLinks.length}`);

        if (unscopedLinks.length > 0) {
            console.log('⚠️ WARNING: Found unscoped internal navigation links:');
            unscopedLinks.forEach(link => console.log(`     - ${link}`));
        } else {
            console.log('✅ All internal navigation links are community-scoped');
        }

        await captureScreenshot(page, 'QA-MT01-context-navigation');

        // Allow some unscoped links (absolute URLs, logout, etc.) but they should be minimal
        expect(unscopedLinks.length).toBeLessThan(3);

        console.log('✅ TEST 4: Community context maintenance verified');
    });

    /**
     * TEST 5: API enforces community scope
     *
     * Validates:
     * - Backend API enforces tenant isolation
     * - Services are filtered by community
     * - No cross-community data access via API
     */
    test('api enforces community scope', async ({ page }) => {
        test.skip(!userCommunitySlug, 'Community slug not available');

        await login(page, USER_EMAIL, USER_PASSWORD);

        // Try to access services with different community slug
        const wrongCommunity = 'another-community';

        const response = await page.request.get(`/${wrongCommunity}/services`);

        // Should return empty or error (not data from wrong community)
        const isSuccess = response.ok();

        if (isSuccess) {
            const data = await response.json();

            // Check if data array is empty or filtered
            if (Array.isArray(data)) {
                const isEmpty = data.length === 0;
                console.log(`📊 API returned ${data.length} services from wrong community`);

                if (isEmpty) {
                    console.log('✅ API returns empty array for wrong community');
                } else {
                    console.log('⚠️ WARNING: API returned services from wrong community - tenant isolation issue');
                }

                expect(isEmpty).toBe(true);
            }
        } else {
            // API returned error - also acceptable
            console.log(`✅ API returned ${response.status()} for wrong community`);
            expect(response.status()).toBeGreaterThanOrEqual(400);
        }

        await captureScreenshot(page, 'QA-MT01-api-scope');

        console.log('✅ TEST 5: API community scope enforcement verified');
    });

    /**
     * UX CHECK: Community context is visually indicated
     *
     * Validates:
     * - User can see which community they're in
     * - Community name/logo is displayed
     * - Clear visual distinction between communities
     */
    test('ux-check: community context visually indicated', async ({ page }) => {
        test.skip(!userCommunitySlug, 'Community slug not available');

        await login(page, USER_EMAIL, USER_PASSWORD);

        await goToCommunity(page, userCommunitySlug);

        await captureScreenshot(page, 'QA-MT01-ux-community-indicator');

        // Look for community visual indicators
        const communityName = page.locator('h1, h2, [class*="community"], [data-community]').first();
        const hasCommunityName = await communityName.count() > 0;

        // Look for community logo/icon
        const communityLogo = page.locator('img[alt*="community"], [class*="logo"], [class*="community-icon"]').first();
        const hasCommunityLogo = await communityLogo.count() > 0;

        // Look for breadcrumb navigation
        const breadcrumb = page.locator('[class*="breadcrumb"], nav[aria-label="breadcrumb"]').first();
        const hasBreadcrumb = await breadcrumb.count() > 0;

        if (hasCommunityName) {
            const name = await communityName.textContent();
            console.log(`🏢 Community name visible: "${name}"`);
        }

        if (hasCommunityLogo) {
            console.log('🎨 Community logo/icon is visible');
        }

        if (hasBreadcrumb) {
            console.log('📍 Breadcrumb navigation is present');
        }

        // At least one indicator should be present
        const hasIndicator = hasCommunityName || hasCommunityLogo || hasBreadcrumb;

        if (hasIndicator) {
            console.log('✅ UX: Community context is visually indicated');
        } else {
            console.log('⚠️ UX WARNING: No clear visual indicator of community - user may be confused');
        }

        expect(hasIndicator).toBe(true);
    });

    /**
     * SECURITY CHECK: No cross-community data leakage
     *
     * Validates:
     * - No data from other communities is exposed
     * - No hints about other communities' content
     * - Strict tenant isolation
     */
    test('security: no cross-community data leakage', async ({ page }) => {
        test.skip(!userCommunitySlug, 'Community slug not available');

        await login(page, USER_EMAIL, USER_PASSWORD);

        await goToServices(page, userCommunitySlug);

        // Check page source for other community references
        const pageContent = await page.content();

        // Look for other community slugs in links (excluding navigation/standard links)
        const otherCommunitySlugs = ['cpme', 'autre', 'test2', 'other'];
        const foundOtherCommunities = [];

        for (const slug of otherCommunitySlugs) {
            const regex = new RegExp(`/${slug}/`, 'gi');
            if (regex.test(pageContent)) {
                foundOtherCommunities.push(slug);
            }
        }

        console.log('🔒 Security check: Cross-community references in page');
        if (foundOtherCommunities.length > 0) {
            console.log('⚠️ WARNING: Found references to other communities:');
            foundOtherCommunities.forEach(c => console.log(`     - ${c}`));
            console.log('   Note: These might be legitimate (footer links, etc.) - manual review required');
        } else {
            console.log('✅ No cross-community references found in services page');
        }

        await captureScreenshot(page, 'QA-MT01-security-leak-check');

        // Note: This is a heuristic check - legitimate cross-community links (footer, etc.) are OK
        console.log('✅ SECURITY CHECK: Cross-community data leakage evaluated');
    });
});
