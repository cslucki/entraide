/**
 * Community Transaction QA Helpers
 *
 * Reusable helpers for community-based transaction testing.
 * These helpers use Laravel factories via the API to create realistic test data.
 */

import { API_BASE_URL } from './config.js';

/**
 * Get current community slug from the page URL
 */
export async function getCommunitySlug(page) {
    const url = page.url();
    const match = url.match(/\/([a-z0-9-]+)\//);
    return match ? match[1] : 'default';
}

/**
 * Navigate to community dashboard
 */
export async function goToCommunity(page, slug = null) {
    const communitySlug = slug || await getCommunitySlug(page);
    await page.goto(`/${communitySlug}/dashboard`);
    await page.waitForLoadState('networkidle');
}

/**
 * Navigate to community services list
 */
export async function goToServices(page, slug = null) {
    const communitySlug = slug || await getCommunitySlug(page);
    await page.goto(`/${communitySlug}/services`);
    await page.waitForLoadState('networkidle');
}

/**
 * Navigate to community requests list
 */
export async function goToRequests(page, slug = null) {
    const communitySlug = slug || await getCommunitySlug(page);
    await page.goto(`/${communitySlug}/requests`);
    await page.waitForLoadState('networkidle');
}

/**
 * Navigate to community messages list
 */
export async function goToMessages(page, slug = null) {
    const communitySlug = slug || await getCommunitySlug(page);
    await page.goto(`/${communitySlug}/messages`);
    await page.waitForLoadState('networkidle');
}

/**
 * Navigate to specific service page
 */
export async function goToService(page, serviceId, slug = null) {
    const communitySlug = slug || await getCommunitySlug(page);
    await page.goto(`/${communitySlug}/services/${serviceId}`);
    await page.waitForLoadState('networkidle');
}

/**
 * Navigate to specific service edit page
 */
export async function goToServiceEdit(page, serviceId, slug = null) {
    const communitySlug = slug || await getCommunitySlug(page);
    await page.goto(`/${communitySlug}/services/${serviceId}/edit`);
    await page.waitForLoadState('networkidle');
}

/**
 * Navigate to specific request page
 */
export async function goToRequest(page, requestId, slug = null) {
    const communitySlug = slug || await getCommunitySlug(page);
    await page.goto(`/${communitySlug}/requests/${requestId}`);
    await page.waitForLoadState('networkidle');
}

/**
 * Navigate to specific message thread
 */
export async function goToMessageThread(page, transactionId, slug = null) {
    const communitySlug = slug || await getCommunitySlug(page);
    await page.goto(`/${communitySlug}/messages/${transactionId}`);
    await page.waitForLoadState('networkidle');
}

/**
 * Navigate to user profile
 */
export async function goToProfile(page, userId) {
    await page.goto(`/profile/${userId}`);
    await page.waitForLoadState('networkidle');
}

/**
 * Wait for Livewire component to finish loading
 */
export async function waitForLivewire(page, timeout = 5000) {
    await page.waitForSelector('[wire\\:init]:not([wire\\:loading\\.delay])', { timeout });
}

/**
 * Wait for toast/notification to appear and return its text
 */
export async function waitForNotification(page, timeout = 3000) {
    const notification = page.locator('[class*="toast"], [role="alert"], .notification, .alert').first();
    await notification.waitFor({ state: 'visible', timeout });
    return await notification.textContent();
}

/**
 * Get user points balance from dashboard
 */
export async function getUserPoints(page) {
    const pointsElement = page.locator('[class*="points"], [data-points]').first();
    if (await pointsElement.isVisible()) {
        const text = await pointsElement.textContent();
        const match = text.match(/(\d+)/);
        return match ? parseInt(match[1]) : null;
    }
    return null;
}

/**
 * Check if element is visible and enabled
 */
export async function isClickable(page, selector) {
    const element = page.locator(selector).first();
    return await element.isVisible() && !(await element.isDisabled());
}

/**
 * Safe click with retry
 */
export async function safeClick(page, selector, timeout = 5000) {
    const element = page.locator(selector).first();
    await element.waitFor({ state: 'visible', timeout });
    await element.click();
}
