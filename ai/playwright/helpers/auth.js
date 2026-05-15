import { globalMember1User, adminUser, cpmeMember1User } from '../users/index.js';

/**
 * Login as a global platform member (TEST_MEMBER1)
 * Use for all global platform tests (articles, messaging, exchange workflows)
 */
export async function loginAsMember(page) {
    await page.goto('/login');
    await page.fill('input[name="email"]', globalMember1User.email);
    await page.fill('input[name="password"]', globalMember1User.password);
    await page.click('button[type="submit"]');
    await page.waitForURL(url => url.pathname.includes('/dashboard') || url.pathname.length > 1);
}

/**
 * Login as admin (TEST_ADMIN)
 * Use ONLY for admin routes (/admin/*) and admin dashboard
 */
export async function loginAsAdmin(page) {
    await page.goto('/login');
    await page.fill('input[name="email"]', adminUser.email);
    await page.fill('input[name="password"]', adminUser.password);
    await page.click('button[type="submit"]');
    await page.waitForURL(url => url.pathname.includes('/dashboard') || url.pathname.length > 1);
}

/**
 * Login as a CPME community member (TEST_MEMBER_OF_CPME1)
 * Use for community-scoped tests (loops, tenant-isolated features)
 */
export async function loginAsCpmeMember(page) {
    await page.goto('/login');
    await page.fill('input[name="email"]', cpmeMember1User.email);
    await page.fill('input[name="password"]', cpmeMember1User.password);
    await page.click('button[type="submit"]');
    await page.waitForURL(url => url.pathname.includes('/dashboard') || url.pathname.length > 1);
}

/**
 * Generic login with custom credentials
 */
export async function login(page, email, password) {
    await page.goto('/login');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await page.click('button[type="submit"]');
    // Wait for either dashboard or a community home page
    await page.waitForURL(url => url.pathname.includes('/dashboard') || url.pathname.length > 1);
}

/**
 * Logout current user
 */
export async function logout(page) {
    await page.goto('/logout');
    await page.waitForURL('/login');
}

/**
 * Assert user is logged in (on dashboard or community home)
 */
export async function assertLoggedIn(page) {
    const url = page.url();
    const isLoggedIn = url.includes('/dashboard') || (url !== 'about:blank' && !url.includes('/login'));
    expect(isLoggedIn).toBe(true);
}
